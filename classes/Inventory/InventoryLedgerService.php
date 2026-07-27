<?php

require_once __DIR__ . '/DTO/InventoryMovementRequest.php';
require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryMovingAverageCostCalculator.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryItemPolicyService.php';
require_once __DIR__ . '/NegativeStockSalePolicyService.php';
require_once __DIR__ . '/../Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../Recipe/Repository/InventoryMovementRepository.php';
require_once __DIR__ . '/../Recipe/RecipeAffectedItemCostService.php';
require_once __DIR__ . '/../Sync/OperationalSyncEventService.php';

class InventoryLedgerService
{
    private const REAL_INBOUND_TYPES = ['purchase', 'opening_balance', 'transfer_in', 'production_output', 'refund_reversal'];
    private const REAL_OUTBOUND_TYPES = ['purchase_return', 'sale_direct', 'recipe_consumption', 'transfer_out', 'production_input', 'waste'];
    private const NEUTRAL_TYPES = ['reservation', 'reservation_release'];

    private InventoryFeatureFlags $flags;
    private InventoryMovementRepository $movements;
    private InventoryBalanceRepository $balances;
    private InventoryItemPolicyService $itemPolicy;
    private NegativeStockSalePolicyService $negativeStockPolicy;
    private SecurityAuditLogger $securityAudit;
    private OperationalSyncEventService $syncEvents;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?InventoryMovementRepository $movements = null,
        ?InventoryBalanceRepository $balances = null,
        ?InventoryItemPolicyService $itemPolicy = null,
        ?NegativeStockSalePolicyService $negativeStockPolicy = null,
        ?SecurityAuditLogger $securityAudit = null,
        ?OperationalSyncEventService $syncEvents = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->movements = $movements ?: new InventoryMovementRepository();
        $this->balances = $balances ?: new InventoryBalanceRepository();
        $this->itemPolicy = $itemPolicy ?: new InventoryItemPolicyService();
        $this->negativeStockPolicy = $negativeStockPolicy ?: new NegativeStockSalePolicyService($this->flags->appConfig());
        $this->securityAudit = $securityAudit ?: new SecurityAuditLogger();
        $this->syncEvents = $syncEvents ?: new OperationalSyncEventService();
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
                if (!$isShadowWrite) {
                    $balance = $this->balances->findBalance(
                        $conn,
                        $request->posTenant(),
                        $request->posBranch(),
                        $request->storeId(),
                        $request->itemId
                    );
                    $this->recordSyncSnapshots(
                        $conn,
                        (int) $existing['id'],
                        (int) ($balance['id'] ?? 0),
                        max((int) $existing['id'], (int) ($balance['last_movement_id'] ?? 0))
                    );
                }
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
                        'security_audit_log' => [],
                    ],
                ];
            }

            $balance = $this->lockBalance($conn, $request);
            $newBalance = $this->applyBalance($balance, $request);
            $negativeStockWarning = false;
            if ($enforceNegativePolicy) {
                $negativeStockWarning = $this->assertNegativePolicy($conn, $newBalance, $request);
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
            $securityAuditId = $negativeStockWarning
                ? $this->writeNegativeStockWarning($conn, $request, $movementId, $balance, $newBalance)
                : 0;
            if (!$isShadowWrite && $this->flags->shouldMirrorLegacyStock()) {
                $this->updateLegacyMirror($conn, $request, $newBalance);
            }

            if (!$isShadowWrite) {
                $this->recordSyncSnapshots($conn, $movementId, $balanceId, $movementId);
            }

            if ($manageTransaction) {
                $conn->commit();
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
                    'security_audit_log' => $securityAuditId > 0 ? [$securityAuditId] : [],
                ],
            ];
        } catch (Throwable $exception) {
            if ($manageTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function recordSyncSnapshots(mysqli $conn, int $movementId, int $balanceId, int $balanceRevision): void
    {
        if ($movementId < 1) {
            return;
        }

        $config = $this->syncConfig();
        if (!$this->syncCaptureEnabled($config)) {
            return;
        }
        $usesOutbox = (string) ($config['role'] ?? 'branch') === 'branch';
        $options = [
            'source_system' => 'inventory_ledger',
            'config' => $config,
            'event_version' => $movementId,
        ];
        if (!$usesOutbox || !$this->outboxRevisionExists($conn, 'inventory_movement', $movementId, $movementId)) {
            $this->syncEvents->recordRowSnapshot($conn, 'inventory_movement', $movementId, $options);
        }

        $balanceRevision = max(1, $balanceRevision);
        if ($balanceId > 0 && (!$usesOutbox || !$this->outboxRevisionExists($conn, 'inventory_balance', $balanceId, $balanceRevision))) {
            $options['event_version'] = $balanceRevision;
            $this->syncEvents->recordRowSnapshot($conn, 'inventory_balance', $balanceId, $options);
        }
    }

    private function outboxRevisionExists(mysqli $conn, string $aggregateType, int $localId, int $revision): bool
    {
        $stmt = $conn->prepare(
            'SELECT id FROM sync_outbox'
            . ' WHERE aggregate_type = ? AND aggregate_local_id = ? AND event_version = ?'
            . ' AND branch_uuid = (SELECT branch_uuid FROM sync_branch_identity WHERE id = 1)'
            . ' LIMIT 1'
        );
        $stmt->bind_param('sii', $aggregateType, $localId, $revision);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }

    private function syncConfig(): array
    {
        $config = $this->flags->appConfig();
        if (!is_array($config)) {
            $config = [];
        }
        $config['role'] = (string) ($config['role'] ?? 'branch');
        $config['sync'] = array_merge([
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ], is_array($config['sync'] ?? null) ? $config['sync'] : []);

        return $config;
    }

    private function syncCaptureEnabled(array $config): bool
    {
        if (empty($config['sync']['operational_sync_enabled'])) {
            return false;
        }

        $role = (string) ($config['role'] ?? 'branch');
        if ($role === 'branch') {
            return !empty($config['sync']['outbox_enabled']) && !empty($config['sync']['branch_sync_enabled']);
        }

        return in_array($role, ['cloud', 'fake_cloud'], true)
            && !empty($config['sync']['cloud_to_branch_publish_enabled']);
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
            $averageCost = InventoryMovingAverageCostCalculator::nextAverageCost(
                $oldOnHand,
                $averageCost,
                $request->movementType,
                $request->qtyIn,
                $request->qtyOut,
                $request->unitCost
            );
        } else {
            $onHand = InventoryDecimal::subtract($onHand, $request->qtyOut);
            $averageCost = InventoryMovingAverageCostCalculator::nextAverageCost(
                $oldOnHand,
                $averageCost,
                $request->movementType,
                $request->qtyIn,
                $request->qtyOut,
                $request->unitCost
            );
        }

        return [
            'qty_on_hand' => $onHand,
            'qty_reserved' => $reserved,
            'qty_available' => InventoryDecimal::subtract($onHand, $reserved),
            'moving_average_cost' => $averageCost,
        ];
    }

    private function assertNegativePolicy(mysqli $conn, array $newBalance, InventoryMovementRequest $request): bool
    {
        $negativeOnHand = InventoryDecimal::compare($newBalance['qty_on_hand'], '0') < 0;
        $negativeAvailable = InventoryDecimal::compare($newBalance['qty_available'], '0') < 0;
        if (!$negativeOnHand && !$negativeAvailable) {
            return false;
        }

        if ($this->isSaleRelatedMovement($request)) {
            $policy = $this->negativeStockPolicy->resolve($conn);
            if ($policy === NegativeStockSalePolicyService::ALLOW_WITH_WARNING) {
                return true;
            }

            throw new RuntimeException('Negative-stock sale policy blocks this inventory movement.');
        }

        if ($this->flags->isStrictStockEnabled()) {
            throw new RuntimeException('Inventory strict stock blocks negative available balance.');
        }

        return false;
    }

    private function isSaleRelatedMovement(InventoryMovementRequest $request): bool
    {
        return in_array($request->movementType, ['sale_direct', 'recipe_consumption', 'reservation'], true);
    }

    private function writeNegativeStockWarning(
        mysqli $conn,
        InventoryMovementRequest $request,
        int $movementId,
        array $before,
        array $after
    ): int {
        $audit = $this->securityAudit->record($conn, 'negative_stock_sale_warning', [
            'user_id' => $request->createdBy,
            'tenant' => $request->posTenant(),
            'branch' => $request->posBranch(),
            'target_type' => 'inventory_movement',
            'target_id' => $movementId,
            'metadata' => [
                'policy' => NegativeStockSalePolicyService::ALLOW_WITH_WARNING,
                'movement_type' => $request->movementType,
                'item_id' => $request->itemId,
                'store_id' => $request->storeId(),
                'order_id' => $request->orderId,
                'source_type' => $request->sourceType,
                'source_id' => $request->sourceId,
                'idempotency_key' => $request->idempotencyKey,
                'before' => $before,
                'after' => $after,
            ],
        ]);

        return (int) ($audit['id'] ?? 0);
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

            $this->resyncRecipeItemCostsForIngredient($conn, $itemId);

            return;
        }
        if ($request->movementType === 'purchase' && $this->columnExists($conn, 'myitems', 'cost_price')) {
            $stmt = $conn->prepare('UPDATE myitems SET itmqty = ?, cost_price = ? WHERE id = ?');
            $stmt->bind_param('ssi', $qtyOnHand, $movingAverageCost, $itemId);
            $stmt->execute();
            $stmt->close();

            $this->resyncRecipeItemCostsForIngredient($conn, $itemId);

            return;
        }

        $stmt = $conn->prepare('UPDATE myitems SET itmqty = ? WHERE id = ?');
        $stmt->bind_param('si', $qtyOnHand, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Keep sellable item cost in sync when an ingredient's cost changes via a purchase
     * movement. Covers both the legacy invoice path and the ledger purchase path because
     * updateLegacyMirror is the lowest common write point. Failures are logged, never
     * propagated, so a recipe resync problem cannot break a purchase receipt.
     */
    private function resyncRecipeItemCostsForIngredient(mysqli $conn, int $ingredientItemId): void
    {
        if ($ingredientItemId < 1) {
            return;
        }

        try {
            (new RecipeAffectedItemCostService())->resyncItemsUsingIngredient($conn, $ingredientItemId);
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[recipe_cost_resync] ledger hook failed ingredient_item_id=%d: %s',
                $ingredientItemId,
                $exception->getMessage()
            ));
        }
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
