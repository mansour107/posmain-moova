<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryAccountingService.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryItemPolicyService.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/InventoryReasonCodeService.php';
require_once __DIR__ . '/InventoryScopeResolver.php';

class InventoryAdjustmentService
{
    private InventoryFeatureFlags $flags;
    private InventoryLedgerService $ledger;
    private InventoryAccountingService $accounting;
    private InventoryScopeResolver $scopeResolver;
    private InventoryItemPolicyService $itemPolicy;
    private InventoryReasonCodeService $reasonCodes;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?InventoryLedgerService $ledger = null,
        ?InventoryAccountingService $accounting = null,
        ?InventoryScopeResolver $scopeResolver = null,
        ?InventoryItemPolicyService $itemPolicy = null,
        ?InventoryReasonCodeService $reasonCodes = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->ledger = $ledger ?: new InventoryLedgerService($this->flags);
        $this->accounting = $accounting ?: new InventoryAccountingService($this->flags);
        $this->scopeResolver = $scopeResolver ?: new InventoryScopeResolver($this->flags->appConfig());
        $this->itemPolicy = $itemPolicy ?: new InventoryItemPolicyService();
        $this->reasonCodes = $reasonCodes ?: new InventoryReasonCodeService();
    }

    public function recordWaste(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertCanPost();
        $normalized = $this->normalizeRequest($conn, $request, $context, 'waste');
        $normalized['qty_out'] = $normalized['qty'];
        $normalized['qty_in'] = InventoryDecimal::zero();

        return $this->record($conn, $normalized, $context, 'waste');
    }

    public function recordAdjustment(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertCanPost();
        $direction = strtolower(trim((string) ($request['direction'] ?? '')));
        if (!in_array($direction, ['increase', 'decrease'], true)) {
            throw new InvalidArgumentException('ADJUSTMENT_DIRECTION_REQUIRED');
        }

        $normalized = $this->normalizeRequest($conn, $request, $context, 'adjustment');
        $normalized['qty_in'] = $direction === 'increase' ? $normalized['qty'] : InventoryDecimal::zero();
        $normalized['qty_out'] = $direction === 'decrease' ? $normalized['qty'] : InventoryDecimal::zero();
        $normalized['direction'] = $direction;

        return $this->record($conn, $normalized, $context, 'adjustment');
    }

    private function record(mysqli $conn, array $normalized, array $context, string $movementType): array
    {
        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $movementMetadata = [
                'source' => 'inventory_adjustment_ui',
                'reason' => $normalized['reason'],
                'reason_code_id' => $normalized['reason_code_id'],
                'reason_code' => $normalized['reason_code'],
                'reason_name' => $normalized['reason_name'],
                'reason_group' => $normalized['reason_group'],
                'reason_requires_approval' => $normalized['reason_requires_approval'],
                'occurred_at' => $normalized['occurred_at'],
                'direction' => $normalized['direction'] ?? ($movementType === 'waste' ? 'decrease' : ''),
                'entered_qty' => $normalized['entered_qty'],
                'entered_unit_cost' => $normalized['entered_unit_cost'],
            ];
            if ($normalized['photo_attachment']) {
                $movementMetadata['photo_attachment'] = $normalized['photo_attachment'];
            }

            $movement = $this->ledger->recordMovement($conn, [
                'scope' => $normalized['scope'],
                'item_id' => $normalized['item_id'],
                'movement_type' => $movementType,
                'source_type' => 'adjustment',
                'source_uuid' => $movementType . ':' . $normalized['operation_uuid'],
                'qty_in' => $normalized['qty_in'],
                'qty_out' => $normalized['qty_out'],
                'unit_id' => $normalized['unit_id'],
                'unit_conversion_to_base' => $normalized['unit_conversion_to_base'],
                'unit_cost' => $normalized['unit_cost'],
                'total_cost' => $normalized['total_cost'],
                'idempotency_key' => $this->movementKey($movementType, $normalized),
                'metadata' => $movementMetadata,
                'created_by' => $this->userId($context),
            ], $normalized['item'], ['manage_transaction' => false]);

            if ($ownsTransaction) {
                $accounting = $this->postAccounting($conn, $movementType, $normalized, $context, (int) ($movement['movement_id'] ?? 0));
                $conn->commit();
            } else {
                $accounting = $this->postAccounting($conn, $movementType, $normalized, $context, (int) ($movement['movement_id'] ?? 0));
            }

            return [
                'success' => true,
                'idempotent_replay' => !empty($movement['idempotent_replay']),
                'movement_id' => (int) ($movement['movement_id'] ?? 0),
                'movement_type' => $movementType,
                'operation_uuid' => $normalized['operation_uuid'],
                'balance' => $movement['balance'] ?? null,
                'accounting' => $accounting,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function postAccounting(mysqli $conn, string $movementType, array $normalized, array $context, int $movementId): array
    {
        $accountingContext = [
            'pos_tenant' => (int) ($normalized['scope']['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($normalized['scope']['pos_branch'] ?? 0),
            'store_id' => (int) ($normalized['scope']['store_id'] ?? 0),
            'operation_id' => 0,
            'user_id' => $this->userId($context),
            'details' => $movementType === 'waste'
                ? 'Inventory waste: ' . $normalized['reason']
                : 'Inventory adjustment: ' . $normalized['reason'],
        ];

        return $movementType === 'waste'
            ? $this->accounting->postWaste($conn, $accountingContext, [$movementId])
            : $this->accounting->postAdjustment($conn, $accountingContext, [$movementId]);
    }

    private function normalizeRequest(mysqli $conn, array $request, array $context, string $movementType): array
    {
        $scope = $this->scopeResolver->resolve([
            'store_id' => $request['store_id'] ?? 0,
            'pos_tenant' => $context['pos_tenant'] ?? $request['pos_tenant'] ?? null,
            'pos_branch' => $context['pos_branch'] ?? $request['pos_branch'] ?? null,
            'branch_uuid' => $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
            'source' => 'inventory_adjustment',
        ]);
        if ((int) $scope['store_id'] < 1) {
            throw new InvalidArgumentException('STORE_REQUIRED');
        }

        $itemId = (int) ($request['item_id'] ?? 0);
        if ($itemId < 1) {
            throw new InvalidArgumentException('ITEM_REQUIRED');
        }
        $item = $this->loadItem($conn, $itemId);
        $policy = $this->itemPolicy->policyForItem($item, $scope);
        if (empty($policy['track_stock'])) {
            throw new InvalidArgumentException('NON_STOCK_ITEM_CANNOT_BE_ADJUSTED');
        }

        $enteredQty = InventoryDecimal::normalize($request['qty'] ?? '0');
        if (!InventoryDecimal::isPositive($enteredQty)) {
            throw new InvalidArgumentException('QTY_REQUIRED');
        }
        $requestedDirection = $movementType === 'waste'
            ? 'decrease'
            : strtolower(trim((string) ($request['direction'] ?? '')));
        $reasonCode = $this->reasonCodes->validateForOperation(
            $conn,
            $request['reason_code_id'] ?? null,
            $scope,
            $movementType,
            $requestedDirection,
            !empty($context['allow_reason_code_approval'])
        );
        $reason = trim((string) ($request['reason'] ?? ''));
        if ($reason === '' && $reasonCode) {
            $reason = (string) ($reasonCode['reason_name'] ?? '');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('REASON_REQUIRED');
        }
        $photoAttachment = $this->normalizePhotoAttachment($request['photo_attachment'] ?? $request['attachment'] ?? null, $movementType);

        $occurredAt = $this->dateOrNull($request['occurred_at'] ?? null);
        if ($occurredAt !== null && substr($occurredAt, 0, 10) < date('Y-m-d') && empty($context['allow_backdate'])) {
            throw new RuntimeException('BACKDATE_PERMISSION_REQUIRED');
        }

        $balance = $this->loadBalance($conn, $scope, $itemId);
        $unitId = $this->nullablePositiveInt($request['unit_id'] ?? $policy['base_unit_id'] ?? null);
        $unitConversion = $this->unitConversionForItem($conn, $itemId, $unitId);
        $qty = InventoryDecimal::multiply($enteredQty, $unitConversion);
        if (!InventoryDecimal::isPositive($qty)) {
            throw new InvalidArgumentException('INVALID_UNIT_CONVERSION');
        }
        $isOutbound = $movementType === 'waste'
            || ($movementType === 'adjustment' && strtolower(trim((string) ($request['direction'] ?? ''))) === 'decrease');
        if ($isOutbound && empty($context['allow_negative_result'])) {
            $projectedOnHand = InventoryDecimal::subtract(InventoryDecimal::normalize($balance['qty_on_hand'] ?? '0'), $qty);
            if (InventoryDecimal::compare($projectedOnHand, '0') < 0) {
                throw new RuntimeException('NEGATIVE_RESULT_APPROVAL_REQUIRED');
            }
        }

        $hasEnteredUnitCost = array_key_exists('unit_cost', $request) && trim((string) $request['unit_cost']) !== '';
        $enteredUnitCost = $hasEnteredUnitCost
            ? InventoryDecimal::normalize($request['unit_cost'])
            : InventoryDecimal::multiply(InventoryDecimal::normalize($balance['moving_average_cost'] ?? '0'), $unitConversion);
        if (InventoryDecimal::compare($enteredUnitCost, '0') < 0) {
            throw new InvalidArgumentException('UNIT_COST_INVALID');
        }
        $totalCost = array_key_exists('total_cost', $request) && trim((string) $request['total_cost']) !== ''
            ? InventoryDecimal::normalize($request['total_cost'])
            : InventoryDecimal::multiply($enteredQty, $enteredUnitCost);
        if (InventoryDecimal::compare($totalCost, '0') < 0) {
            throw new InvalidArgumentException('TOTAL_COST_INVALID');
        }
        $unitCost = InventoryDecimal::isPositive($qty)
            ? InventoryDecimal::divide($totalCost, $qty)
            : InventoryDecimal::zero();

        return [
            'scope' => $scope,
            'item_id' => $itemId,
            'item' => $item,
            'qty' => $qty,
            'entered_qty' => $enteredQty,
            'unit_id' => $unitId,
            'unit_conversion_to_base' => $unitConversion,
            'unit_cost' => $unitCost,
            'entered_unit_cost' => $enteredUnitCost,
            'total_cost' => $totalCost,
            'reason' => $this->truncate($reason),
            'reason_code_id' => $reasonCode ? (int) ($reasonCode['id'] ?? 0) : null,
            'reason_code' => $reasonCode ? (string) ($reasonCode['reason_code'] ?? '') : null,
            'reason_name' => $reasonCode ? (string) ($reasonCode['reason_name'] ?? '') : null,
            'reason_group' => $reasonCode ? (string) ($reasonCode['reason_group'] ?? '') : null,
            'reason_requires_approval' => $reasonCode ? (int) ($reasonCode['requires_approval'] ?? 0) : 0,
            'photo_attachment' => $photoAttachment,
            'occurred_at' => $occurredAt,
            'operation_uuid' => $this->uuidFromRequest($request, $movementType . '_uuid'),
            'balance' => $balance,
        ];
    }

    private function assertCanPost(): void
    {
        if (!$this->flags->canWriteLedger()) {
            throw new RuntimeException('INVENTORY_LEDGER_NOT_READY');
        }
    }

    private function movementKey(string $movementType, array $normalized): string
    {
        $scope = $normalized['scope'];

        return implode(':', [
            'inventory-adjustment',
            'v1',
            $movementType,
            'tenant',
            (int) ($scope['pos_tenant'] ?? 0),
            'branch',
            (int) ($scope['pos_branch'] ?? 0),
            'store',
            (int) ($scope['store_id'] ?? 0),
            'item',
            (int) $normalized['item_id'],
            'source',
            $normalized['operation_uuid'],
        ]);
    }

    private function loadBalance(mysqli $conn, array $scope, int $itemId): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return ['qty_on_hand' => InventoryDecimal::zero(), 'moving_average_cost' => InventoryDecimal::zero()];
        }

        $stmt = $conn->prepare("
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1");
        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $storeId = (int) ($scope['store_id'] ?? 0);
        $stmt->bind_param('iiii', $posTenant, $posBranch, $storeId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: ['qty_on_hand' => InventoryDecimal::zero(), 'moving_average_cost' => InventoryDecimal::zero()];
    }

    private function loadItem(mysqli $conn, int $itemId): array
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'myitems')) {
            return ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 0];
        }

        $columns = ['id'];
        foreach (['item_type', 'track_stock', 'base_unit_id'] as $column) {
            if ($this->columnExists($conn, 'myitems', $column)) {
                $columns[] = $column;
            }
        }

        $stmt = $conn->prepare('SELECT ' . implode(', ', $columns) . ' FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $item ?: ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 0];
    }

    private function dateOrNull($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $text) === 1) {
            return str_replace('T', ' ', strlen($text) === 16 ? $text . ':00' : $text);
        }

        throw new InvalidArgumentException('OCCURRED_AT_INVALID');
    }

    private function normalizePhotoAttachment($value, string $movementType): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($movementType !== 'waste') {
            throw new InvalidArgumentException('WASTE_PHOTO_WASTE_ONLY');
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('WASTE_PHOTO_INVALID');
        }

        $path = $this->safeAttachmentText($value['path'] ?? $value['relative_path'] ?? '');
        $mime = $this->safeAttachmentText($value['mime'] ?? '');
        $sha256 = strtolower($this->safeAttachmentText($value['sha256'] ?? ''));
        $sizeBytes = (int) ($value['size_bytes'] ?? $value['size'] ?? 0);
        if ($path === ''
            || strpos($path, 'uploads/inventory_waste/') !== 0
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            || $sizeBytes <= 0
            || $sizeBytes > 5242880
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
        ) {
            throw new InvalidArgumentException('WASTE_PHOTO_INVALID');
        }

        return [
            'kind' => 'waste_photo',
            'path' => $path,
            'file_name' => $this->safeAttachmentText($value['file_name'] ?? basename($path)),
            'original_name' => $this->safeAttachmentText($value['original_name'] ?? ''),
            'mime' => $mime,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'uploaded_at' => $this->safeAttachmentText($value['uploaded_at'] ?? date('c')),
            'storage' => $this->safeAttachmentText($value['storage'] ?? 'local_uploads'),
        ];
    }

    private function safeAttachmentText($value): string
    {
        $text = trim((string) $value);
        $text = str_replace(["\0", "\r", "\n"], '', $text);

        return $this->truncate($text);
    }

    private function uuidFromRequest(array $request, string $key): string
    {
        $uuid = trim((string) ($request[$key] ?? $request['operation_uuid'] ?? ''));
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) === 1) {
            return strtolower($uuid);
        }

        return $this->uuid();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function truncate(string $text): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 255, 'UTF-8');
        }

        return substr($text, 0, 255);
    }

    private function userId(array $context): ?int
    {
        $userId = (int) ($context['user_id'] ?? ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0));

        return $userId > 0 ? $userId : null;
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function unitConversionForItem(mysqli $conn, int $itemId, ?int $unitId): string
    {
        if (!$unitId) {
            return '1.00000000';
        }
        if ($itemId < 1 || !$this->tableExists($conn, 'item_units')) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }

        $conditions = ['item_id = ?', 'unit_id = ?'];
        if ($this->columnExists($conn, 'item_units', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        $stmt = $conn->prepare('SELECT u_val FROM item_units WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }

        $conversion = InventoryDecimal::normalize($row['u_val'] ?? '0', 8);
        if (InventoryDecimal::compare($conversion, '0', 8) <= 0) {
            throw new InvalidArgumentException('INVALID_UNIT_CONVERSION');
        }

        return $conversion;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
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
}
