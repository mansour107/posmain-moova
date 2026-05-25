<?php

require_once __DIR__ . '/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/DTO/RecipeScope.php';
require_once __DIR__ . '/ExternalOrderLineIdentityService.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeSettingsService.php';
require_once __DIR__ . '/Repository/RecipeOrderLineUsageRepository.php';

class LegacyInvoiceRecipeLifecycleBridge
{
    private RecipeOrderLifecycleService $recipeLifecycleService;
    private RecipeFeatureFlags $flags;
    private RecipeSettingsService $settings;
    private RecipeOrderLineUsageRepository $usageRepository;

    public function __construct(
        ?RecipeOrderLifecycleService $recipeLifecycleService = null,
        ?RecipeFeatureFlags $flags = null,
        ?RecipeSettingsService $settings = null,
        ?RecipeOrderLineUsageRepository $usageRepository = null
    )
    {
        $this->recipeLifecycleService = $recipeLifecycleService ?: new RecipeOrderLifecycleService();
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->settings = $settings ?: new RecipeSettingsService();
        $this->usageRepository = $usageRepository ?: new RecipeOrderLineUsageRepository();
    }

    public function currentLineContexts(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        array $context = []
    ): array {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT fd.id, fd.item_id, fd.qty_in, fd.qty_out, fd.u_val, fd.det_store, mi.group1 AS item_category_id
            FROM fat_details fd
            LEFT JOIN myitems mi ON mi.id = fd.item_id
            WHERE fd.fatid = ?
              AND fd.isdeleted = 0
              AND fd.qty_out > fd.qty_in
            ORDER BY fd.id ASC
        ");
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare legacy invoice recipe line query: ' . $conn->error);
        }

        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $quantity = $this->lineQuantity($row);
            if (RecipeDecimal::compare($quantity, '0') <= 0) {
                continue;
            }

            $lines[] = array_merge($this->baseContext($conn, $orderId, (int) ($row['det_store'] ?? 0), $channel, $orderType, $context), [
                'fat_detail_id' => (int) $row['id'],
                'sellable_item_id' => (int) $row['item_id'],
                'item_id' => (int) $row['item_id'],
                'item_category_id' => (int) ($row['item_category_id'] ?? 0),
                'quantity' => $quantity,
                'qty' => $quantity,
            ]);
        }

        $stmt->close();

        return $lines;
    }

    public function recordCurrentLinesAdded(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        array $context = []
    ): array {
        $results = [];
        foreach ($this->currentLineContexts($conn, $orderId, $channel, $orderType, $context) as $line) {
            $results[] = $this->recipeLifecycleService->onOrderLineAdded($line);
        }

        return $results;
    }

    public function recordExistingLinesCancelled(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        string $reason,
        array $context = []
    ): array {
        $results = [];
        foreach ($this->currentLineContexts($conn, $orderId, $channel, $orderType, $context) as $line) {
            $results[] = $this->recipeLifecycleService->onOrderLineCancelled($line, $reason);
        }

        return $results;
    }

    public function recordCurrentOrderPaid(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        array $context = []
    ): ?array {
        $lines = $this->currentLineContexts($conn, $orderId, $channel, $orderType, $context);
        if (!$lines) {
            return null;
        }

        $orderContext = $this->baseContext($conn, $orderId, 0, $channel, $orderType, $context);
        $orderContext['lines'] = $lines;

        return $this->recipeLifecycleService->onOrderPaid($orderContext);
    }

    public function recordCurrentOrderDeleted(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        array $context = []
    ): ?array {
        if ($orderId <= 0 || !$this->flags->isEnabled() || !$this->tableExists($conn, 'recipe_order_line_usage')) {
            return null;
        }

        $hasFinalOrConsumedUsage = false;
        $hasOpenUsage = false;
        foreach ($this->usageRepository->findForOrder($conn, $orderId) as $usage) {
            $status = (string) ($usage['status'] ?? '');
            if (in_array($status, ['consumed', 'voided', 'refunded', 'wasted'], true)) {
                $hasFinalOrConsumedUsage = true;
            } elseif (in_array($status, ['previewed', 'reserved'], true)) {
                $hasOpenUsage = true;
            }
        }

        if ($hasFinalOrConsumedUsage) {
            return $this->recordCurrentOrderVoided($conn, $orderId, $channel, $orderType, $context);
        }

        if ($hasOpenUsage) {
            $cancelled = $this->recordExistingLinesCancelled(
                $conn,
                $orderId,
                $channel,
                $orderType,
                'legacy_invoice_deleted',
                $context
            );
            $releasedPreviewed = $this->releasePreviewedUsageForOrder($conn, $orderId);

            return [
                'cancelled' => $cancelled,
                'released_previewed' => $releasedPreviewed,
            ];
        }

        return null;
    }

    public function recordCurrentOrderVoided(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        array $context = []
    ): ?array {
        $lines = $this->currentLineContexts($conn, $orderId, $channel, $orderType, $context);
        if (!$lines) {
            return null;
        }

        $orderContext = $this->baseContext($conn, $orderId, 0, $channel, $orderType, $context);
        $orderContext['lines'] = $lines;

        $reverseContext = $context;
        $reverseContext['policy'] = $this->settings->refundStockPolicy($context);
        $reverseContext['refund_uuid'] = $this->nullableString(
            $context['refund_uuid']
            ?? $context['void_uuid']
            ?? ('legacy-invoice-delete:' . $orderId)
        );
        if (!isset($reverseContext['created_by']) && isset($context['user_id'])) {
            $reverseContext['created_by'] = (int) $context['user_id'];
        }

        return $this->recipeLifecycleService->onOrderVoided($orderContext, $reverseContext);
    }

    public function recordCurrentOrderRefunded(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        array $context = []
    ): ?array {
        $lines = $this->currentLineContexts($conn, $orderId, $channel, $orderType, $context);
        if (!$lines) {
            return null;
        }

        $orderContext = $this->baseContext($conn, $orderId, 0, $channel, $orderType, $context);
        $orderContext['lines'] = $lines;

        $reverseContext = $context;
        $reverseContext['policy'] = $this->resolveRefundPolicy($context);
        $reverseContext['refund_uuid'] = $this->nullableString(
            $context['refund_uuid']
            ?? ('legacy-invoice-refund:' . $orderId)
        );
        if (!isset($reverseContext['created_by']) && isset($context['user_id'])) {
            $reverseContext['created_by'] = (int) $context['user_id'];
        }

        return $this->recipeLifecycleService->onOrderRefunded($orderContext, $reverseContext);
    }

    public function externalLineContexts(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        string $externalOrderId,
        array $externalLines,
        array $context = []
    ): array {
        $externalOrderId = trim($externalOrderId);
        if ($orderId <= 0 || $externalOrderId === '' || !$externalLines) {
            return [];
        }

        $identity = new ExternalOrderLineIdentityService();
        $canRegisterIdentity = $this->tableExists($conn, 'external_order_line_map')
            && in_array($channel, ['moova', 'cofe', 'api', 'sync'], true);
        $recipeScope = new RecipeScope(
            (int) ($context['tenant'] ?? $context['pos_tenant'] ?? 0),
            (int) ($context['branch'] ?? $context['pos_branch'] ?? 0),
            $this->nullableString($context['branch_uuid'] ?? null),
            (int) ($context['store_id'] ?? 0),
            $channel,
            $orderType,
            $channel
        );

        $lines = [];
        foreach ($externalLines as $fallbackIndex => $line) {
            if (!is_array($line)) {
                continue;
            }

            $itemId = $this->positiveInt($line['item_id'] ?? $line['id'] ?? $line['sellable_item_id'] ?? null);
            $qty = $this->decimalString($line['quantity'] ?? $line['qty'] ?? '0');
            if ($itemId < 1 || RecipeDecimal::compare($qty, '0') <= 0) {
                continue;
            }

            $sourceLine = is_array($line['source_line'] ?? null) ? $line['source_line'] : $line;
            $identityLine = $sourceLine;
            $identityLine['item_id'] = $itemId;
            $sourceLineIndex = (int) ($line['source_line_index'] ?? $sourceLine['source_line_index'] ?? $fallbackIndex);
            $variantId = $this->positiveInt(
                $identityLine['variant_id']
                ?? $identityLine['variantId']
                ?? $identityLine['variant_item_id']
                ?? $identityLine['variantItemId']
                ?? null
            );
            $modifiers = $this->lineModifiers($identityLine);
            $externalLineId = $identity->externalLineId(
                $identityLine,
                $sourceLineIndex,
                $itemId,
                $variantId > 0 ? $variantId : null,
                $identity->modifiersHash($modifiers)
            );
            $fatDetailId = $this->positiveInt($line['fat_detail_id'] ?? null);
            $storeId = $this->positiveInt($line['store_id'] ?? $line['det_store'] ?? $context['store_id'] ?? null);

            if ($canRegisterIdentity) {
                $registered = $identity->registerLine(
                    $conn,
                    $recipeScope,
                    $channel,
                    $externalOrderId,
                    $identityLine,
                    $sourceLineIndex,
                    [
                        'order_id' => $orderId,
                        'fat_detail_id' => $fatDetailId > 0 ? $fatDetailId : null,
                        'order_line_uuid' => $this->nullableString($line['order_line_uuid'] ?? null),
                        'line_status' => (string) ($line['line_status'] ?? 'active'),
                    ]
                );
                $externalLineId = (string) ($registered['external_line_id'] ?? $externalLineId);
            }

            $recipeContext = array_merge($this->baseContext($conn, $orderId, $storeId, $channel, $orderType, $context), [
                'fat_detail_id' => $fatDetailId > 0 ? $fatDetailId : null,
                'order_line_uuid' => $this->nullableString($line['order_line_uuid'] ?? null),
                'sellable_item_id' => $itemId,
                'item_id' => $itemId,
                'item_category_id' => $this->positiveInt(
                    $line['item_category_id']
                    ?? $line['sellable_item_category_id']
                    ?? $line['category_id']
                    ?? $line['group1']
                    ?? null
                ),
                'quantity' => $qty,
                'qty' => $qty,
                'source_order_uuid' => $externalOrderId,
                'source_line_uuid' => substr($channel . ':' . $externalLineId, 0, 128),
            ]);
            if ($variantId > 0) {
                $recipeContext['variant_id'] = $variantId;
            }
            if ($modifiers) {
                $recipeContext['modifiers'] = $modifiers;
            }

            $lines[] = $recipeContext;
        }

        return $lines;
    }

    public function recordExternalLinesAdded(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        string $externalOrderId,
        array $externalLines,
        array $context = []
    ): array {
        $results = [];
        foreach ($this->externalLineContexts($conn, $orderId, $channel, $orderType, $externalOrderId, $externalLines, $context) as $line) {
            $results[] = $this->recipeLifecycleService->onOrderLineAdded($line);
        }

        return $results;
    }

    public function recordExternalOrderPaid(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        string $externalOrderId,
        array $externalLines,
        array $context = []
    ): ?array {
        $lines = $this->externalLineContexts($conn, $orderId, $channel, $orderType, $externalOrderId, $externalLines, $context);
        if (!$lines) {
            return null;
        }

        $orderContext = $this->baseContext($conn, $orderId, 0, $channel, $orderType, $context);
        $orderContext['source_order_uuid'] = $externalOrderId;
        $orderContext['lines'] = $lines;

        return $this->recipeLifecycleService->onOrderPaid($orderContext);
    }

    public function assertLegacyEditAllowed(mysqli $conn, int $orderId): void
    {
        if ($orderId <= 0 || !$this->flags->isEnabled() || !$this->tableExists($conn, 'recipe_order_line_usage')) {
            return;
        }

        foreach ($this->usageRepository->findForOrder($conn, $orderId) as $usage) {
            if (in_array((string) ($usage['status'] ?? ''), ['consumed', 'voided', 'refunded', 'wasted'], true)) {
                throw new RuntimeException(
                    'Recipe stock was already consumed for this invoice. Use the refund/void flow instead of legacy invoice edit.'
                );
            }
        }
    }

    private function baseContext(mysqli $conn, int $orderId, int $storeId, string $channel, string $orderType, array $context): array
    {
        $branchUuid = $this->nullableString(
            $context['branch_uuid']
            ?? ($context['config']['sync']['branch_uuid'] ?? null)
            ?? getenv('POSMAIN_BRANCH_UUID')
            ?: null
        );

        return [
            'conn' => $conn,
            'tenant' => (int) ($context['tenant'] ?? $context['pos_tenant'] ?? 0),
            'branch' => (int) ($context['branch'] ?? $context['pos_branch'] ?? 0),
            'branch_uuid' => $branchUuid,
            'store_id' => max(0, $storeId),
            'order_id' => $orderId,
            'channel' => $channel,
            'order_type' => $orderType,
            'source_order_uuid' => $this->nullableString($context['source_order_uuid'] ?? null),
            'source_event_uuid' => $this->nullableString($context['source_event_uuid'] ?? null),
            'requested_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function lineQuantity(array $row): string
    {
        $uVal = $this->decimalString($row['u_val'] ?? '1');
        if (RecipeDecimal::compare($uVal, '0') <= 0) {
            $uVal = '1.000000';
        }

        $qtyOut = $this->nonNegativeDecimal($row['qty_out'] ?? '0');
        $qtyIn = $this->nonNegativeDecimal($row['qty_in'] ?? '0');
        $difference = $this->absoluteDecimalDifference($qtyOut, $qtyIn);

        return $this->divideScaledDecimal($difference, $uVal);
    }

    private function decimalString($value): string
    {
        return RecipeDecimal::normalize($value);
    }

    private function nonNegativeDecimal($value): string
    {
        $decimal = $this->decimalString($value);

        return RecipeDecimal::compare($decimal, '0') < 0 ? '0.000000' : $decimal;
    }

    private function absoluteDecimalDifference(string $left, string $right): string
    {
        $leftInt = $this->scaledInteger($left);
        $rightInt = $this->scaledInteger($right);
        if ($this->compareIntegerStrings($leftInt, $rightInt) < 0) {
            [$leftInt, $rightInt] = [$rightInt, $leftInt];
        }

        return $this->decimalFromScaledInteger($this->subtractIntegerStrings($leftInt, $rightInt));
    }

    private function divideScaledDecimal(string $left, string $right): string
    {
        if (RecipeDecimal::compare($right, '0') <= 0) {
            throw new InvalidArgumentException('Cannot divide legacy recipe quantity by zero.');
        }

        $dividend = $this->scaledInteger($left) . '000000';
        $divisor = $this->scaledInteger($right);

        return $this->decimalFromScaledInteger($this->divideIntegerRounded($dividend, $divisor));
    }

    private function scaledInteger(string $decimal): string
    {
        $digits = str_replace(['-', '.'], '', RecipeDecimal::normalize($decimal));

        return ltrim($digits, '0') ?: '0';
    }

    private function decimalFromScaledInteger(string $scaled, int $scale = 6): string
    {
        $scaled = ltrim($scaled, '0') ?: '0';
        if (strlen($scaled) <= $scale) {
            return RecipeDecimal::normalize('0.' . str_pad($scaled, $scale, '0', STR_PAD_LEFT), $scale);
        }

        return RecipeDecimal::normalize(
            substr($scaled, 0, -$scale) . '.' . substr($scaled, -$scale),
            $scale
        );
    }

    private function divideIntegerRounded(string $dividend, string $divisor): string
    {
        $dividend = ltrim($dividend, '0') ?: '0';
        $divisor = ltrim($divisor, '0') ?: '0';
        if ($divisor === '0') {
            throw new InvalidArgumentException('Cannot divide legacy recipe quantity by zero.');
        }

        $quotient = '';
        $remainder = '0';
        $length = strlen($dividend);
        for ($i = 0; $i < $length; $i++) {
            $remainder = ltrim($remainder . $dividend[$i], '0') ?: '0';
            $digit = 0;
            while ($this->compareIntegerStrings($remainder, $divisor) >= 0) {
                $remainder = $this->subtractIntegerStrings($remainder, $divisor);
                $digit++;
            }
            $quotient .= (string) $digit;
        }

        if ($this->compareIntegerStrings($this->addIntegerStrings($remainder, $remainder), $divisor) >= 0) {
            $quotient = $this->addIntegerStrings($quotient, '1');
        }

        return ltrim($quotient, '0') ?: '0';
    }

    private function compareIntegerStrings(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }

        return $left <=> $right;
    }

    private function subtractIntegerStrings(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        while ($i >= 0) {
            $digit = (int) $left[$i] - $borrow;
            $subtrahend = $j >= 0 ? (int) $right[$j] : 0;
            if ($digit < $subtrahend) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) ($digit - $subtrahend) . $result;
            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function addIntegerStrings(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += (int) $left[$i--];
            }
            if ($j >= 0) {
                $sum += (int) $right[$j--];
            }
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function lineModifiers(array $line): array
    {
        foreach (['modifiers', 'modifier_options', 'selected_modifiers', 'options'] as $modifierKey) {
            if (isset($line[$modifierKey]) && is_array($line[$modifierKey])) {
                return $line[$modifierKey];
            }
        }

        return [];
    }

    private function positiveInt($value): int
    {
        if (is_string($value) && preg_match('/(\d+)$/', $value, $matches)) {
            $value = $matches[1];
        }

        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function nullableString($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function resolveRefundPolicy(array $context): string
    {
        $configured = $this->settings->refundStockPolicy($context);
        if ($configured !== 'manager_choice') {
            return $configured;
        }

        $requested = strtolower(trim((string) (
            $context['refund_stock_policy']
            ?? $context['policy']
            ?? ''
        )));

        return in_array($requested, ['waste', 'return_to_stock'], true) ? $requested : 'waste';
    }

    private function releasePreviewedUsageForOrder(mysqli $conn, int $orderId): int
    {
        $released = 0;
        foreach ($this->usageRepository->findForOrder($conn, $orderId) as $usage) {
            if ((string) ($usage['status'] ?? '') !== 'previewed') {
                continue;
            }

            $released += $this->usageRepository->markPreviewedReleased($conn, (int) $usage['id']);
        }

        return $released;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) ($row['table_count'] ?? 0) > 0;
    }
}
