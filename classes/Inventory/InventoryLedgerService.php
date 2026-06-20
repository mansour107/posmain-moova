<?php

require_once __DIR__ . '/DTO/InventoryMovementRequest.php';
require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryItemPolicyService.php';
require_once __DIR__ . '/../Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../Recipe/Repository/InventoryMovementRepository.php';

class InventoryLedgerService
{
    private const REAL_INBOUND_TYPES = ['purchase', 'opening_balance', 'transfer_in', 'production_output', 'refund_reversal'];
    private const REAL_OUTBOUND_TYPES = ['purchase_return', 'sale_direct', 'recipe_consumption', 'transfer_out', 'production_input', 'waste'];
    private const NEUTRAL_TYPES = ['reservation', 'reservation_release'];

    private InventoryFeatureFlags $flags;
    private InventoryMovementRepository $movements;
    private InventoryBalanceRepository $balances;
    private InventoryItemPolicyService $itemPolicy;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?InventoryMovementRepository $movements = null,
        ?InventoryBalanceRepository $balances = null,
        ?InventoryItemPolicyService $itemPolicy = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->movements = $movements ?: new InventoryMovementRepository();
        $this->balances = $balances ?: new InventoryBalanceRepository();
        $this->itemPolicy = $itemPolicy ?: new InventoryItemPolicyService();
    }

    public function previewMovement(array $movement): array
    {
        return $this->result('preview_movement', $movement);
    }

    public function recordShadowMovement(mysqli $conn, array $movement, ?array $item = null, array $options = []): array
    {
        $options['shadow_write'] = true;

        return $this->recordMovement($conn, $movement, $item, $options);
    }

    public function recordMovement($connOrMovement, ?array $movement = null, ?array $item = null, array $options = []): array
    {
        if (!$connOrMovement instanceof mysqli) {
            return $this->result('record_movement', is_array($connOrMovement) ? $connOrMovement : []);
        }

        $request = InventoryMovementRequest::fromArray($movement ?? []);
        $this->validateRequest($request);

        $isShadowWrite = !empty($options['shadow_write']);
        $intendedAction = $isShadowWrite ? 'record_shadow_movement' : 'record_movement';
        $canWrite = $isShadowWrite ? $this->flags->canWriteShadowLedger() : $this->flags->canWriteLedger();

        if (!$canWrite) {
            return $this->result($intendedAction, $movement ?? [], [
                'noop' => true,
                'reason' => $isShadowWrite
                    ? 'inventory_ledger_mode_does_not_allow_shadow_writes'
                    : 'inventory_ledger_mode_does_not_allow_writes',
            ]);
        }

        $policy = $this->itemPolicy->policyForItem($item ?: [
            'item_id' => $request->itemId,
            'item_type' => 'sellable',
            'track_stock' => 1,
        ], $request->scope);
        if (empty($policy['track_stock'])) {
            return [
                'success' => true,
                'noop' => true,
                'mode' => $this->flags->mode(),
                'intended_action' => $intendedAction,
                'reason' => $policy['reason'] ?? 'non_stock_item',
                'writes' => [],
                'policy' => $policy,
                'shadow_write' => $isShadowWrite,
            ];
        }

        $manageTransaction = !array_key_exists('manage_transaction', $options) || (bool) $options['manage_transaction'];
        $enforceNegativePolicy = array_key_exists('enforce_negative_policy', $options)
            ? (bool) $options['enforce_negative_policy']
            : !$isShadowWrite;
        $conn = $connOrMovement;
        if ($manageTransaction) {
            $conn->begin_transaction();
        }

        try {
            $existing = $this->movements->findByIdempotencyKey(
                $conn,
                $request->posTenant(),
                $request->posBranch(),
                $request->storeId(),
                $request->idempotencyKey
            );
            if ($existing) {
                $this->assertExistingPayloadMatches($existing, $request);
                if ($manageTransaction) {
                    $conn->commit();
                }

                return [
                    'success' => true,
                    'noop' => false,
                    'mode' => $this->flags->mode(),
                    'idempotent_replay' => true,
                    'movement_id' => (int) $existing['id'],
                    'shadow_write' => $isShadowWrite,
                    'writes' => [
                        'inventory_movements' => [(int) $existing['id']],
                        'inventory_item_balances' => [],
                        'recipe_audit_log' => [],
                    ],
                ];
            }

            $balance = $this->lockBalance($conn, $request);
            $newBalance = $this->applyBalance($balance, $request);
            if ($enforceNegativePolicy) {
                $this->assertNegativePolicy($newBalance, $request);
            }

            $movementId = $this->movements->createMovement($conn, $request->movementRow());
            $balanceId = $this->balances->putBalance($conn, [
                'pos_tenant' => $request->posTenant(),
                'pos_branch' => $request->posBranch(),
                'branch_uuid' => $request->branchUuid(),
                'store_id' => $request->storeId(),
                'item_id' => $request->itemId,
                'qty_on_hand' => $newBalance['qty_on_hand'],
                'qty_reserved' => $newBalance['qty_reserved'],
                'qty_available' => $newBalance['qty_available'],
                'moving_average_cost' => $newBalance['moving_average_cost'],
                'last_movement_id' => $movementId,
            ]);
            $auditId = $this->writeAudit($conn, $request, $movementId, $balance, $newBalance);
            if (!$isShadowWrite && $this->flags->shouldMirrorLegacyStock()) {
                $this->updateLegacyMirror($conn, $request, $newBalance);
            }

            if ($manageTransaction) {
                $conn->commit();
            }

            if (!$isShadowWrite && !empty($movementId)) {
                require_once __DIR__ . '/../Sync/OperationalSyncRecorder.php';
                posmain_record_operational_row_sync($conn, 'inventory_movement', (int) $movementId, 'inventory_ledger');
                if (!empty($balanceId)) {
                    posmain_record_operational_row_sync($conn, 'inventory_balance', (int) $balanceId, 'inventory_ledger');
                }
            }

            return [
                'success' => true,
                'noop' => false,
                'mode' => $this->flags->mode(),
                'movement_id' => $movementId,
                'balance_id' => $balanceId,
                'balance' => $newBalance,
                'payload_hash' => $request->payloadHash,
                'shadow_write' => $isShadowWrite,
                'signals' => $this->availabilitySignals($request),
                'writes' => [
                    'inventory_movements' => [$movementId],
                    'inventory_item_balances' => [$balanceId],
                    'recipe_audit_log' => $auditId > 0 ? [$auditId] : [],
                ],
            ];
        } catch (Throwable $exception) {
            if ($manageTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function validateRequest(InventoryMovementRequest $request): void
    {
        if ($request->itemId < 1) {
            throw new InvalidArgumentException('Inventory movement requires item_id.');
        }
        if ($request->idempotencyKey === '') {
            throw new InvalidArgumentException('Inventory movement idempotency key is required.');
        }
        if (InventoryDecimal::compare($request->unitConversionToBase, '0', 8) <= 0) {
            throw new InvalidArgumentException('Inventory movement unit conversion must be positive.');
        }

        $hasQtyIn = InventoryDecimal::isPositive($request->qtyIn);
        $hasQtyOut = InventoryDecimal::isPositive($request->qtyOut);
        $hasReserved = InventoryDecimal::isPositive($request->qtyReserved);
        if ($request->isReservationMovement()) {
            if ($hasQtyIn || $hasQtyOut || !$hasReserved) {
                throw new InvalidArgumentException('Reservation movements must be neutral and include positive qty_reserved.');
            }
            return;
        }

        if (!in_array($request->movementType, array_merge(self::REAL_INBOUND_TYPES, self::REAL_OUTBOUND_TYPES, ['adjustment']), true)) {
            throw new InvalidArgumentException('Unsupported inventory movement type: ' . $request->movementType);
        }

        if ($hasQtyIn === $hasQtyOut) {
            throw new InvalidArgumentException('Real inventory movement requires exactly one positive qty_in or qty_out.');
        }
        if ($hasReserved) {
            throw new InvalidArgumentException('Real inventory movement must not include qty_reserved.');
        }
        if (in_array($request->movementType, self::REAL_INBOUND_TYPES, true) && !$hasQtyIn) {
            throw new InvalidArgumentException('Inbound inventory movement requires positive qty_in.');
        }
        if (in_array($request->movementType, self::REAL_OUTBOUND_TYPES, true) && !$hasQtyOut) {
            throw new InvalidArgumentException('Outbound inventory movement requires positive qty_out.');
        }
    }

    private function assertExistingPayloadMatches(array $existing, InventoryMovementRequest $request): void
    {
        $existingHash = trim((string) ($existing['payload_hash'] ?? ''));
        if ($existingHash !== '' && !hash_equals($existingHash, $request->payloadHash)) {
            throw new RuntimeException('Inventory movement idempotency conflict: payload hash differs.');
        }
    }

    private function lockBalance(mysqli $conn, InventoryMovementRequest $request): array
    {
        $stmt = $conn->prepare("
INSERT IGNORE INTO inventory_item_balances
  (pos_tenant, pos_branch, branch_uuid, store_id, item_id)
VALUES (?, ?, ?, ?, ?)");
        $branchUuid = $request->branchUuid();
        $posTenant = $request->posTenant();
        $posBranch = $request->posBranch();
        $storeId = $request->storeId();
        $itemId = $request->itemId;
        $stmt->bind_param('iisii', $posTenant, $posBranch, $branchUuid, $storeId, $itemId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1
FOR UPDATE");
        $stmt->bind_param('iiii', $posTenant, $posBranch, $storeId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [
            'qty_on_hand' => InventoryDecimal::zero(),
            'qty_reserved' => InventoryDecimal::zero(),
            'qty_available' => InventoryDecimal::zero(),
            'moving_average_cost' => InventoryDecimal::zero(),
        ];
    }

    private function applyBalance(array $balance, InventoryMovementRequest $request): array
    {
        $onHand = InventoryDecimal::normalize($balance['qty_on_hand'] ?? '0');
        $reserved = InventoryDecimal::normalize($balance['qty_reserved'] ?? '0');
        $averageCost = InventoryDecimal::normalize($balance['moving_average_cost'] ?? '0');
        $oldOnHand = $onHand;

        if ($request->movementType === 'reservation') {
            $reserved = InventoryDecimal::add($reserved, $request->qtyReserved);
        } elseif ($request->movementType === 'reservation_release') {
            $reserved = InventoryDecimal::subtract($reserved, $request->qtyReserved);
            if (InventoryDecimal::compare($reserved, '0') < 0) {
                $reserved = InventoryDecimal::zero();
            }
        } elseif (InventoryDecimal::isPositive($request->qtyIn)) {
            $onHand = InventoryDecimal::add($onHand, $request->qtyIn);
            $averageCost = $this->movingAverageCost($oldOnHand, $averageCost, $request);
        } else {
            $onHand = InventoryDecimal::subtract($onHand, $request->qtyOut);
        }

        return [
            'qty_on_hand' => $onHand,
            'qty_reserved' => $reserved,
            'qty_available' => InventoryDecimal::subtract($onHand, $reserved),
            'moving_average_cost' => $averageCost,
        ];
    }

    private function movingAverageCost(string $oldOnHand, string $oldAverageCost, InventoryMovementRequest $request): string
    {
        if (!in_array($request->movementType, array_merge(self::REAL_INBOUND_TYPES, ['adjustment']), true)) {
            return $oldAverageCost;
        }
        if (!InventoryDecimal::isPositive($request->qtyIn)) {
            return $oldAverageCost;
        }
        if (InventoryDecimal::compare($oldOnHand, '0') <= 0) {
            return $request->unitCost;
        }

        $oldValue = InventoryDecimal::multiply($oldOnHand, $oldAverageCost);
        $newValue = InventoryDecimal::multiply($request->qtyIn, $request->unitCost);
        $totalQty = InventoryDecimal::add($oldOnHand, $request->qtyIn);

        return InventoryDecimal::divide(InventoryDecimal::add($oldValue, $newValue), $totalQty);
    }

    private function assertNegativePolicy(array $newBalance, InventoryMovementRequest $request): void
    {
        if (!$this->flags->isStrictStockEnabled()) {
            return;
        }

        if (InventoryDecimal::compare($newBalance['qty_on_hand'], '0') < 0) {
            throw new RuntimeException('Inventory strict stock blocks negative on-hand balance.');
        }
        if (InventoryDecimal::compare($newBalance['qty_available'], '0') < 0) {
            throw new RuntimeException('Inventory strict stock blocks negative available balance.');
        }
    }

    private function writeAudit(
        mysqli $conn,
        InventoryMovementRequest $request,
        int $movementId,
        array $before,
        array $after
    ): int {
        if (!$this->tableExists($conn, 'recipe_audit_log')) {
            return 0;
        }

        $beforeJson = json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $afterJson = json_encode(array_merge($after, [
            'movement_id' => $movementId,
            'payload_hash' => $request->payloadHash,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
INSERT INTO recipe_audit_log
  (pos_tenant, pos_branch, branch_uuid, entity_type, entity_id, action, before_json, after_json, actor_user_id)
VALUES (?, ?, ?, 'inventory_movement', ?, 'record_inventory_movement', ?, ?, ?)");
        $posTenant = $request->posTenant();
        $posBranch = $request->posBranch();
        $branchUuid = $request->branchUuid();
        $createdBy = $request->createdBy;
        $stmt->bind_param('iisissi', $posTenant, $posBranch, $branchUuid, $movementId, $beforeJson, $afterJson, $createdBy);
        $stmt->execute();
        $auditId = (int) $conn->insert_id;
        $stmt->close();

        return $auditId;
    }

    private function updateLegacyMirror(mysqli $conn, InventoryMovementRequest $request, array $balance): void
    {
        if (!$this->tableExists($conn, 'myitems') || !$this->columnExists($conn, 'myitems', 'itmqty')) {
            return;
        }

        $qtyOnHand = InventoryDecimal::normalize($balance['qty_on_hand'] ?? '0');
        $movingAverageCost = InventoryDecimal::normalize($balance['moving_average_cost'] ?? '0');
        $itemId = $request->itemId;
        if ($request->movementType === 'purchase' && $this->columnExists($conn, 'myitems', 'cost_price') && $this->columnExists($conn, 'myitems', 'last_price')) {
            $stmt = $conn->prepare('UPDATE myitems SET itmqty = ?, cost_price = ?, last_price = ? WHERE id = ?');
            $stmt->bind_param('sssi', $qtyOnHand, $movingAverageCost, $request->unitCost, $itemId);
            $stmt->execute();
            $stmt->close();

            return;
        }
        if ($request->movementType === 'purchase' && $this->columnExists($conn, 'myitems', 'cost_price')) {
            $stmt = $conn->prepare('UPDATE myitems SET itmqty = ?, cost_price = ? WHERE id = ?');
            $stmt->bind_param('ssi', $qtyOnHand, $movingAverageCost, $itemId);
            $stmt->execute();
            $stmt->close();

            return;
        }

        $stmt = $conn->prepare('UPDATE myitems SET itmqty = ? WHERE id = ?');
        $stmt->bind_param('si', $qtyOnHand, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    private function availabilitySignals(InventoryMovementRequest $request): array
    {
        if (!$this->flags->isAvailabilityEnabled()) {
            return [];
        }

        return [[
            'type' => 'inventory_availability_refresh',
            'pos_tenant' => $request->posTenant(),
            'pos_branch' => $request->posBranch(),
            'store_id' => $request->storeId(),
            'item_id' => $request->itemId,
        ]];
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

    private function result(string $action, array $payload, array $overrides = []): array
    {
        return array_merge([
            'success' => true,
            'noop' => !$this->flags->canWriteLedger(),
            'mode' => $this->flags->mode(),
            'intended_action' => $action,
            'writes' => [],
            'payload' => $payload,
        ], $overrides);
    }
}
