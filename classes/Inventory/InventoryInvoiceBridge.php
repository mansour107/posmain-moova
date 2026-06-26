<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryAccountingService.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/InventoryScopeResolver.php';

class InventoryInvoiceBridge
{
    public const TYPE_SALES = 3;
    public const TYPE_PURCHASE = 4;
    public const TYPE_POS = 9;
    public const TYPE_PURCHASE_RETURN = 10;
    public const TYPE_SALES_RETURN = 11;
    public const TYPE_PURCHASE_ORDER = 12;
    public const TYPE_SALES_ORDER = 13;
    public const TYPE_OFFER = 14;

    private InventoryFeatureFlags $flags;
    private InventoryLedgerService $ledger;
    private InventoryScopeResolver $scopeResolver;
    private InventoryAccountingService $accounting;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?InventoryLedgerService $ledger = null,
        ?InventoryScopeResolver $scopeResolver = null,
        ?InventoryAccountingService $accounting = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->ledger = $ledger ?: new InventoryLedgerService($this->flags);
        $this->scopeResolver = $scopeResolver ?: new InventoryScopeResolver($this->flags->appConfig());
        $this->accounting = $accounting ?: new InventoryAccountingService($this->flags);
    }

    public function recordInvoiceLines(mysqli $conn, int $invoiceType, int $invoiceId, array $lines, array $context = []): array
    {
        $result = [
            'success' => true,
            'noop' => !$this->flags->canWriteShadowLedger(),
            'mode' => $this->flags->mode(),
            'invoice_type' => $invoiceType,
            'invoice_id' => $invoiceId,
            'shadow_write' => $this->flags->isShadowMode(),
            'movements' => [],
            'skipped' => [],
            'errors' => [],
        ];

        if (!$this->flags->canWriteShadowLedger()) {
            $result['skipped'][] = ['reason' => 'inventory_invoice_bridge_disabled'];
            return $result;
        }

        foreach (array_values($lines) as $index => $line) {
            try {
                $movement = $this->movementForInvoiceLine($invoiceType, $invoiceId, $line, $context, $index);
                if (!$movement) {
                    $result['skipped'][] = [
                        'line_index' => $index,
                        'fat_detail_id' => (int) ($line['fat_detail_id'] ?? $line['detail_id'] ?? $line['id'] ?? 0),
                        'reason' => 'line_does_not_affect_stock',
                    ];
                    continue;
                }

                $item = is_array($line['item'] ?? null) ? $line['item'] : $this->loadItem($conn, (int) $movement['item_id']);
                $result['movements'][] = $this->writeMovementWithSavepoint($conn, $movement, $item, $index);
            } catch (Throwable $exception) {
                $result['success'] = false;
                $result['errors'][] = [
                    'line_index' => $index,
                    'fat_detail_id' => (int) ($line['fat_detail_id'] ?? $line['detail_id'] ?? $line['id'] ?? 0),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $result['noop'] = $result['movements'] === [];
        $result['accounting'] = $this->postAccountingForMovements($conn, $result['movements'], $context);

        return $result;
    }

    public function recordInvoiceReversalLines(mysqli $conn, int $invoiceType, int $invoiceId, array $lines, string $reason, array $context = []): array
    {
        $result = [
            'success' => true,
            'noop' => !$this->flags->canWriteShadowLedger(),
            'mode' => $this->flags->mode(),
            'invoice_type' => $invoiceType,
            'invoice_id' => $invoiceId,
            'reason' => $reason,
            'shadow_write' => $this->flags->isShadowMode(),
            'movements' => [],
            'skipped' => [],
            'errors' => [],
        ];

        if (!$this->flags->canWriteShadowLedger()) {
            $result['skipped'][] = ['reason' => 'inventory_invoice_bridge_disabled'];
            return $result;
        }

        foreach (array_values($lines) as $index => $line) {
            try {
                $original = $this->movementForInvoiceLine($invoiceType, $invoiceId, $line, $context, $index);
                if (!$original) {
                    $result['skipped'][] = [
                        'line_index' => $index,
                        'fat_detail_id' => (int) ($line['fat_detail_id'] ?? $line['detail_id'] ?? $line['id'] ?? 0),
                        'reason' => 'line_does_not_affect_stock',
                    ];
                    continue;
                }
                if (!$this->existingMovementFor($conn, $original)) {
                    $result['skipped'][] = [
                        'line_index' => $index,
                        'fat_detail_id' => (int) ($line['fat_detail_id'] ?? $line['detail_id'] ?? $line['id'] ?? 0),
                        'reason' => 'original_shadow_movement_missing',
                    ];
                    continue;
                }

                $reversal = $original;
                $reversal['qty_in'] = $original['qty_out'];
                $reversal['qty_out'] = $original['qty_in'];
                $reversal['movement_type'] = $this->reversalMovementTypeForOriginal(
                    (string) ($original['movement_type'] ?? ''),
                    $reversal['qty_in'],
                    $reversal['qty_out']
                );
                $reversal['idempotency_key'] = str_replace(
                    'inventory-invoice-bridge:v1',
                    'inventory-invoice-bridge-reversal:v1',
                    $original['idempotency_key']
                ) . ':reason:' . $this->token($reason, 'reversal');
                $reversal['metadata'] = array_merge($original['metadata'] ?? [], [
                    'bridge_action' => 'reverse',
                    'reason' => $reason,
                    'reversal_of_idempotency_key' => $original['idempotency_key'],
                    'legacy_qty_in' => $reversal['qty_in'],
                    'legacy_qty_out' => $reversal['qty_out'],
                ]);
                $movementQty = InventoryDecimal::isPositive($reversal['qty_in']) ? $reversal['qty_in'] : $reversal['qty_out'];
                $reversal['total_cost'] = InventoryDecimal::multiply($movementQty, $reversal['unit_cost']);

                $item = is_array($line['item'] ?? null) ? $line['item'] : $this->loadItem($conn, (int) $reversal['item_id']);
                $result['movements'][] = $this->writeMovementWithSavepoint($conn, $reversal, $item, $index);
            } catch (Throwable $exception) {
                $result['success'] = false;
                $result['errors'][] = [
                    'line_index' => $index,
                    'fat_detail_id' => (int) ($line['fat_detail_id'] ?? $line['detail_id'] ?? $line['id'] ?? 0),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $result['noop'] = $result['movements'] === [];
        $result['accounting'] = $this->postAccountingForMovements($conn, $result['movements'], $context);

        return $result;
    }

    public function movementForInvoiceLine(int $invoiceType, int $invoiceId, array $line, array $context = [], int $lineIndex = 0): ?array
    {
        $qtyIn = InventoryDecimal::normalize($line['qty_in'] ?? '0');
        $qtyOut = InventoryDecimal::normalize($line['qty_out'] ?? '0');
        if (!InventoryDecimal::isPositive($qtyIn) && !InventoryDecimal::isPositive($qtyOut)) {
            return null;
        }

        $movementType = $this->movementTypeForInvoice($invoiceType, $qtyIn, $qtyOut);
        if ($movementType === null) {
            return null;
        }

        $detailId = (int) ($line['fat_detail_id'] ?? $line['detail_id'] ?? $line['id'] ?? 0);
        $itemId = (int) ($line['item_id'] ?? $line['itmname'] ?? 0);
        $scope = $this->scopeResolver->resolve(array_merge($context, [
            'store_id' => $line['store_id'] ?? $line['det_store'] ?? $context['store_id'] ?? 0,
        ]));
        $unitConversion = InventoryDecimal::normalize($line['u_val'] ?? $line['unit_conversion_to_base'] ?? '1', 8);
        $unitCost = InventoryDecimal::normalize($line['cost_price'] ?? $line['unit_cost'] ?? '0');
        $movementQty = InventoryDecimal::isPositive($qtyIn) ? $qtyIn : $qtyOut;

        $orderLineUuid = $this->nullableString($line['order_line_uuid'] ?? $line['line_uuid'] ?? null);

        return [
            'scope' => $scope,
            'item_id' => $itemId,
            'movement_type' => $movementType,
            'source_type' => $detailId > 0 ? 'fat_details' : 'invoice',
            'source_id' => $detailId > 0 ? $detailId : $invoiceId,
            'source_uuid' => $this->nullableString($line['source_uuid'] ?? $line['detail_uuid'] ?? null),
            'order_id' => $invoiceId,
            'fat_detail_id' => $detailId > 0 ? $detailId : null,
            'order_line_uuid' => $orderLineUuid,
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'unit_id' => isset($line['unit_id']) ? (int) $line['unit_id'] : null,
            'unit_conversion_to_base' => $unitConversion,
            'unit_cost' => $unitCost,
            'total_cost' => InventoryDecimal::multiply($movementQty, $unitCost),
            'idempotency_key' => $this->idempotencyKey($scope, $invoiceType, $invoiceId, $detailId, $lineIndex, $orderLineUuid),
            'metadata' => [
                'bridge' => 'inventory_invoice_bridge',
                'invoice_type' => $invoiceType,
                'invoice_id' => $invoiceId,
                'line_index' => $lineIndex,
                'legacy_qty_in' => $qtyIn,
                'legacy_qty_out' => $qtyOut,
            ],
            'created_by' => isset($context['user_id']) ? (int) $context['user_id'] : null,
        ];
    }

    private function movementTypeForInvoice(int $invoiceType, string $qtyIn, string $qtyOut): ?string
    {
        if (in_array($invoiceType, [self::TYPE_PURCHASE_ORDER, self::TYPE_SALES_ORDER, self::TYPE_OFFER], true)) {
            return null;
        }
        if (!in_array($invoiceType, [
            self::TYPE_SALES,
            self::TYPE_PURCHASE,
            self::TYPE_POS,
            self::TYPE_PURCHASE_RETURN,
            self::TYPE_SALES_RETURN,
        ], true)) {
            return null;
        }
        if (InventoryDecimal::isPositive($qtyIn)) {
            return $invoiceType === self::TYPE_SALES_RETURN ? 'refund_reversal' : 'purchase';
        }
        if (InventoryDecimal::isPositive($qtyOut)) {
            if ($invoiceType === self::TYPE_PURCHASE_RETURN) {
                return 'purchase_return';
            }
            return in_array($invoiceType, [self::TYPE_SALES, self::TYPE_POS], true) ? 'sale_direct' : 'adjustment';
        }

        return null;
    }

    private function idempotencyKey(array $scope, int $invoiceType, int $invoiceId, int $detailId, int $lineIndex, ?string $orderLineUuid = null): string
    {
        $lineToken = $orderLineUuid !== null && $orderLineUuid !== ''
            ? 'line_uuid:' . substr(hash('sha256', $orderLineUuid), 0, 24)
            : ($detailId > 0 ? 'detail:' . $detailId : 'line:' . $lineIndex);

        return implode(':', [
            'inventory-invoice-bridge',
            'v1',
            'tenant',
            (int) ($scope['pos_tenant'] ?? 0),
            'branch',
            (int) ($scope['pos_branch'] ?? 0),
            'store',
            (int) ($scope['store_id'] ?? 0),
            'invoice',
            $invoiceType,
            $invoiceId,
            $lineToken,
        ]);
    }

    private function loadItem(mysqli $conn, int $itemId): array
    {
        if ($itemId < 1) {
            return ['item_id' => 0, 'item_type' => 'sellable', 'track_stock' => 0];
        }

        $columns = ['id'];
        foreach (['item_type', 'track_stock', 'base_unit_id'] as $column) {
            if ($this->columnExists($conn, 'myitems', $column)) {
                $columns[] = $column;
            }
        }

        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM myitems WHERE id = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $item ?: ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 0];
    }

    private function existingMovementFor(mysqli $conn, array $movement): bool
    {
        $scope = is_array($movement['scope'] ?? null) ? $movement['scope'] : [];
        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $storeId = (int) ($scope['store_id'] ?? 0);
        $idempotencyKey = (string) ($movement['idempotency_key'] ?? '');

        $stmt = $conn->prepare("
SELECT id
FROM inventory_movements
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND idempotency_key = ?
LIMIT 1");
        $stmt->bind_param('iiis', $posTenant, $posBranch, $storeId, $idempotencyKey);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }

    private function writeMovementWithSavepoint(mysqli $conn, array $movement, array $item, int $index): array
    {
        $savepoint = 'inventory_invoice_bridge_' . $index;
        $hasSavepoint = false;
        try {
            $conn->query('SAVEPOINT ' . $savepoint);
            $hasSavepoint = true;
        } catch (Throwable $exception) {
            // Some legacy flows reach the bridge after implicit commits; the movement write is still authoritative.
        }

        try {
            $writeOptions = ['manage_transaction' => false];
            $write = $this->flags->isShadowMode()
                ? $this->ledger->recordShadowMovement($conn, $movement, $item, $writeOptions)
                : $this->ledger->recordMovement($conn, $movement, $item, $writeOptions);
            if ($hasSavepoint) {
                try {
                    $conn->query('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $exception) {
                    // The caller owns the broader transaction; implicit commits may already have released this savepoint.
                }
            }

            $write['movement_type'] = (string) ($movement['movement_type'] ?? '');

            return $write;
        } catch (Throwable $exception) {
            if ($hasSavepoint) {
                try {
                    $conn->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $conn->query('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $rollbackException) {
                    // Keep the original bridge error visible; the caller still owns the outer transaction.
                }
            }
            throw $exception;
        }
    }

    private function postAccountingForMovements(mysqli $conn, array $movements, array $context): array
    {
        if (!$this->flags->isAccountingEnabled()) {
            return ['noop' => true, 'reason' => 'inventory accounting is disabled'];
        }
        if (!$this->flags->canWriteLedger()) {
            return ['noop' => true, 'reason' => 'inventory ledger mode is not accounting-authoritative'];
        }

        $saleMovementIds = $this->movementIdsByType($movements, 'sale_direct');
        $refundMovementIds = $this->movementIdsByType($movements, 'refund_reversal');
        if (!$saleMovementIds && !$refundMovementIds) {
            return ['noop' => true, 'reason' => 'no sale or refund movements to post'];
        }

        $result = [
            'noop' => false,
            'sale_cogs' => null,
            'refund_reversal' => null,
            'errors' => [],
        ];

        if ($saleMovementIds) {
            try {
                $result['sale_cogs'] = $this->accounting->postSaleCogs($conn, $context, $saleMovementIds);
            } catch (Throwable $exception) {
                $result['errors'][] = [
                    'posting' => 'sale_cogs',
                    'message' => $exception->getMessage(),
                    'movement_ids' => $saleMovementIds,
                ];
            }
        }

        if ($refundMovementIds) {
            try {
                $result['refund_reversal'] = $this->accounting->postRefundReversal($conn, $context, $refundMovementIds);
            } catch (Throwable $exception) {
                $result['errors'][] = [
                    'posting' => 'refund_reversal',
                    'message' => $exception->getMessage(),
                    'movement_ids' => $refundMovementIds,
                ];
            }
        }

        return $result;
    }

    private function movementIdsByType(array $movements, string $movementType): array
    {
        $ids = [];
        foreach ($movements as $movement) {
            if (!empty($movement['noop']) || (string) ($movement['movement_type'] ?? '') !== $movementType) {
                continue;
            }
            $movementId = (int) ($movement['movement_id'] ?? 0);
            if ($movementId > 0) {
                $ids[] = $movementId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function reversalMovementTypeForOriginal(string $originalMovementType, string $qtyIn, string $qtyOut): string
    {
        if ($originalMovementType === 'purchase') {
            return 'purchase_return';
        }
        if ($originalMovementType === 'purchase_return') {
            return 'purchase';
        }
        if ($originalMovementType === 'sale_direct') {
            return 'refund_reversal';
        }
        if ($originalMovementType === 'refund_reversal') {
            return 'adjustment';
        }

        return InventoryDecimal::isPositive($qtyIn) ? 'refund_reversal' : 'adjustment';
    }

    private function token(string $value, string $default): string
    {
        $token = strtolower(trim($value));
        $token = str_replace(['-', ' '], '_', $token);

        return preg_match('/^[a-z0-9_]{1,60}$/', $token) === 1 ? $token : $default;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
