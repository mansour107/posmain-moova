<?php

require_once __DIR__ . '/DTO/RecipeExplosionResult.php';
require_once __DIR__ . '/DTO/RecipeMovementResult.php';
require_once __DIR__ . '/DTO/RecipeScope.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/Repository/InventoryMovementRepository.php';
require_once dirname(__DIR__) . '/Inventory/InventoryFeatureFlags.php';
require_once dirname(__DIR__) . '/Inventory/InventoryLedgerService.php';

class RecipeInventoryMovementService
{
    private $flags;
    private $movements;
    private $balances;
    private InventoryFeatureFlags $inventoryFlags;
    private InventoryLedgerService $inventoryLedger;

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?InventoryMovementRepository $movements = null,
        ?InventoryBalanceRepository $balances = null,
        ?InventoryFeatureFlags $inventoryFlags = null,
        ?InventoryLedgerService $inventoryLedger = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->movements = $movements ?: new InventoryMovementRepository();
        $this->balances = $balances ?: new InventoryBalanceRepository();
        $this->inventoryFlags = $inventoryFlags ?: new InventoryFeatureFlags();
        $this->inventoryLedger = $inventoryLedger ?: new InventoryLedgerService($this->inventoryFlags);
    }

    public function recordRecipeConsumption(mysqli $conn, RecipeExplosionResult $explosion, array $orderContext): RecipeMovementResult
    {
        $scope = $this->scopeFromOrderContext($orderContext);
        if (!$this->flags->isConsumptionEnabledForItem($scope, $explosion->sellableItemId, $this->itemCategoryId($orderContext))) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $movementIds = [];
        $lockedBalances = $this->lockRequirementBalances($conn, $scope, $explosion->requirements);
        foreach ($explosion->requirements as $requirement) {
            $idempotencyKey = $this->idempotencyKey('consume', $scope, $explosion, $requirement, $orderContext);
            $existing = $this->movements->findByIdempotencyKey(
                $conn,
                $scope->posTenant,
                $scope->posBranch,
                $scope->storeId,
                $idempotencyKey
            );
            if ($existing) {
                $movementIds[] = (int) $existing['id'];
                continue;
            }

            $balance = $lockedBalances[$requirement->ingredientItemId]
                ?? $this->lockBalance($conn, $scope, $requirement->ingredientItemId);
            $newOnHand = RecipeDecimal::subtract($balance['qty_on_hand'], $requirement->requiredQtyBase);
            if (RecipeDecimal::compare($newOnHand, '0') < 0) {
                // Warn-only (strict stock is OFF): record a tagged warning so owners have
                // visibility into which sales drove negative ingredient stock, but still
                // allow the sale to proceed. Do NOT throw — throwing would be strict stock.
                // Prefer the app-level warn logger (posmain_log_warn_event) so the event lands
                // in logs/recipe_negative_stock.log regardless of PHP-FPM error_log routing;
                // fall back to error_log() if the helper is not loaded (e.g. some CLI paths).
                $warnFields = [
                    'item_id' => (int) $requirement->ingredientItemId,
                    'required' => (string) $requirement->requiredQtyBase,
                    'balance' => (string) ($balance['qty_on_hand'] ?? '0'),
                    'new_on_hand' => (string) $newOnHand,
                    'order_id' => (string) ($orderContext['order_id'] ?? ''),
                    'recipe_id' => (string) ($explosion->recipeId ?? ''),
                    'order_line_uuid' => (string) ($orderContext['order_line_uuid'] ?? ''),
                ];
                if (function_exists('posmain_log_warn_event')) {
                    posmain_log_warn_event('recipe_negative_stock.log', 'recipe_negative_stock', $warnFields);
                } else {
                    error_log('[recipe_negative_stock]' . implode('', array_map(
                        static fn($k, $v) => " {$k}={$v}",
                        array_keys($warnFields),
                        $warnFields,
                    )));
                }
            }
            $newReserved = (bool) ($orderContext['consume_reserved'] ?? false)
                ? RecipeDecimal::subtract($balance['qty_reserved'], $requirement->requiredQtyBase)
                : $balance['qty_reserved'];
            if (RecipeDecimal::compare($newReserved, '0') < 0) {
                $newReserved = RecipeDecimal::zero();
            }
            $newAvailable = RecipeDecimal::subtract($newOnHand, $newReserved);
            $movementId = $this->movements->createMovement($conn, [
                'movement_uuid' => $this->uuid(),
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $requirement->ingredientItemId,
                'movement_type' => 'recipe_consumption',
                'source_type' => 'recipe_order_line_usage',
                'source_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                'order_id' => $orderContext['order_id'] ?? null,
                'fat_detail_id' => $orderContext['fat_detail_id'] ?? null,
                'order_line_uuid' => $orderContext['order_line_uuid'] ?? null,
                'recipe_order_line_usage_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                'recipe_id' => $explosion->recipeId,
                'recipe_cost_snapshot_id' => $explosion->costSnapshotId,
                'qty_out' => $requirement->requiredQtyBase,
                'unit_id' => $requirement->unitId,
                'unit_conversion_to_base' => $requirement->unitConversionToBase,
                'unit_cost' => $requirement->unitCost,
                'total_cost' => $requirement->totalCost,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $orderContext['created_by'] ?? null,
            ]);
            $this->balances->putBalance($conn, [
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $requirement->ingredientItemId,
                'qty_on_hand' => $newOnHand,
                'qty_reserved' => $newReserved,
                'qty_available' => $newAvailable,
                'moving_average_cost' => $balance['moving_average_cost'],
                'last_movement_id' => $movementId,
            ]);
            $movementIds[] = $movementId;
        }

        return new RecipeMovementResult(['movement_ids' => $movementIds]);
    }

    public function recordReservationMovement(mysqli $conn, RecipeExplosionResult $explosion, array $orderContext): RecipeMovementResult
    {
        $scope = $this->scopeFromOrderContext($orderContext);
        if (!$this->flags->isReservationEnabledForItem($scope, $explosion->sellableItemId, $this->itemCategoryId($orderContext))) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $movementIds = [];
        $lockedBalances = $this->lockRequirementBalances($conn, $scope, $explosion->requirements);
        foreach ($explosion->requirements as $requirement) {
            $idempotencyKey = $this->idempotencyKey('reservation', $scope, $explosion, $requirement, $orderContext);
            $existing = $this->movements->findByIdempotencyKey(
                $conn,
                $scope->posTenant,
                $scope->posBranch,
                $scope->storeId,
                $idempotencyKey
            );
            if ($existing) {
                $movementIds[] = (int) $existing['id'];
                continue;
            }

            $balance = $lockedBalances[$requirement->ingredientItemId]
                ?? $this->lockBalance($conn, $scope, $requirement->ingredientItemId);
            $newReserved = RecipeDecimal::add($balance['qty_reserved'], $requirement->requiredQtyBase);
            $newAvailable = RecipeDecimal::subtract($balance['qty_on_hand'], $newReserved);
            $movementId = $this->movements->createMovement($conn, [
                'movement_uuid' => $this->uuid(),
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $requirement->ingredientItemId,
                'movement_type' => 'reservation',
                'source_type' => 'reservation',
                'source_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                'order_id' => $orderContext['order_id'] ?? null,
                'fat_detail_id' => $orderContext['fat_detail_id'] ?? null,
                'order_line_uuid' => $orderContext['order_line_uuid'] ?? null,
                'recipe_order_line_usage_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                'recipe_id' => $explosion->recipeId,
                'qty_in' => '0.000000',
                'qty_out' => '0.000000',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $orderContext['created_by'] ?? null,
            ]);
            $this->balances->putBalance($conn, [
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $requirement->ingredientItemId,
                'qty_on_hand' => $balance['qty_on_hand'],
                'qty_reserved' => $newReserved,
                'qty_available' => $newAvailable,
                'moving_average_cost' => $balance['moving_average_cost'],
                'last_movement_id' => $movementId,
            ]);
            $movementIds[] = $movementId;
        }

        return new RecipeMovementResult(['movement_ids' => $movementIds]);
    }

    public function recordReservationRelease(mysqli $conn, array $reservations, string $reason = 'release'): RecipeMovementResult
    {
        $movementIds = [];
        foreach ($reservations as $reservation) {
            $scope = new RecipeScope(
                (int) ($reservation['pos_tenant'] ?? 0),
                (int) ($reservation['pos_branch'] ?? 0),
                $reservation['branch_uuid'] ?? null,
                (int) ($reservation['store_id'] ?? 0),
                'pos',
                'takeaway',
                'recipe'
            );
            $itemId = (int) ($reservation['ingredient_item_id'] ?? 0);
            $qty = (string) ($reservation['qty_reserved'] ?? '0');
            $idempotencyKey = 'reservation-release:' . $scope->posTenant . ':' . $scope->posBranch . ':store:' . $scope->storeId . ':reservation:' . (int) $reservation['id'] . ':' . $reason;
            $existing = $this->movements->findByIdempotencyKey($conn, $scope->posTenant, $scope->posBranch, $scope->storeId, $idempotencyKey);
            if ($existing) {
                $movementIds[] = (int) $existing['id'];
                continue;
            }

            $balance = $this->lockBalance($conn, $scope, $itemId);
            $newReserved = RecipeDecimal::subtract($balance['qty_reserved'], $qty);
            if (RecipeDecimal::compare($newReserved, '0') < 0) {
                $newReserved = RecipeDecimal::zero();
            }
            $newAvailable = RecipeDecimal::subtract($balance['qty_on_hand'], $newReserved);
            $movementId = $this->movements->createMovement($conn, [
                'movement_uuid' => $this->uuid(),
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $itemId,
                'movement_type' => 'reservation_release',
                'source_type' => 'reservation',
                'source_id' => (int) $reservation['id'],
                'order_id' => $reservation['order_id'] ?? null,
                'fat_detail_id' => $reservation['fat_detail_id'] ?? null,
                'order_line_uuid' => $reservation['order_line_uuid'] ?? null,
                'recipe_order_line_usage_id' => $reservation['recipe_order_line_usage_id'] ?? null,
                'recipe_id' => $reservation['recipe_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->balances->putBalance($conn, [
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $itemId,
                'qty_on_hand' => $balance['qty_on_hand'],
                'qty_reserved' => $newReserved,
                'qty_available' => $newAvailable,
                'moving_average_cost' => $balance['moving_average_cost'],
                'last_movement_id' => $movementId,
            ]);
            $movementIds[] = $movementId;
        }

        return new RecipeMovementResult(['movement_ids' => $movementIds]);
    }

    public function recordProductionInput(mysqli $conn, RecipeExplosionResult $explosion, array $batchContext): RecipeMovementResult
    {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $scope = $this->scopeFromOrderContext($batchContext);
        if ($this->inventoryFlags->canWriteLedger()) {
            return $this->recordProductionInputThroughInventoryLedger($conn, $scope, $explosion, $batchContext);
        }

        $movementIds = [];
        $lockedBalances = $this->lockRequirementBalances($conn, $scope, $explosion->requirements);
        foreach ($explosion->requirements as $requirement) {
            $idempotencyKey = $this->productionIdempotencyKey('production-input', $scope, $requirement, $batchContext);
            $existing = $this->movements->findByIdempotencyKey(
                $conn,
                $scope->posTenant,
                $scope->posBranch,
                $scope->storeId,
                $idempotencyKey
            );
            if ($existing) {
                $movementIds[] = (int) $existing['id'];
                continue;
            }

            $balance = $lockedBalances[$requirement->ingredientItemId]
                ?? $this->lockBalance($conn, $scope, $requirement->ingredientItemId);
            $newOnHand = RecipeDecimal::subtract($balance['qty_on_hand'], $requirement->requiredQtyBase);
            $newAvailable = RecipeDecimal::subtract($newOnHand, $balance['qty_reserved']);
            $movementId = $this->movements->createMovement($conn, [
                'movement_uuid' => $this->uuid(),
                'movement_group_uuid' => $batchContext['batch_uuid'] ?? null,
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $requirement->ingredientItemId,
                'movement_type' => 'production_input',
                'source_type' => 'production_batch',
                'source_id' => $batchContext['batch_id'] ?? null,
                'source_uuid' => $batchContext['batch_uuid'] ?? null,
                'recipe_id' => $explosion->recipeId,
                'production_batch_id' => $batchContext['batch_id'] ?? null,
                'qty_out' => $requirement->requiredQtyBase,
                'unit_id' => $requirement->unitId,
                'unit_conversion_to_base' => $requirement->unitConversionToBase,
                'unit_cost' => $requirement->unitCost,
                'total_cost' => $requirement->totalCost,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $batchContext['created_by'] ?? null,
            ]);
            $this->balances->putBalance($conn, [
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $requirement->ingredientItemId,
                'qty_on_hand' => $newOnHand,
                'qty_reserved' => $balance['qty_reserved'],
                'qty_available' => $newAvailable,
                'moving_average_cost' => $balance['moving_average_cost'],
                'last_movement_id' => $movementId,
            ]);
            $movementIds[] = $movementId;
        }

        return new RecipeMovementResult(['movement_ids' => $movementIds]);
    }

    public function recordProductionOutput(
        mysqli $conn,
        array $batchContext,
        int $outputItemId,
        string $outputQty,
        string $totalCost
    ): RecipeMovementResult {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $scope = $this->scopeFromOrderContext($batchContext);
        if ($this->inventoryFlags->canWriteLedger()) {
            return $this->recordProductionOutputThroughInventoryLedger($conn, $scope, $batchContext, $outputItemId, $outputQty, $totalCost);
        }

        $idempotencyKey = 'production-output'
            . ':' . $scope->posTenant
            . ':' . $scope->posBranch
            . ':store:' . $scope->storeId
            . ':batch:' . (string) ($batchContext['batch_uuid'] ?? $batchContext['batch_id'] ?? '0')
            . ':item:' . $outputItemId;
        $existing = $this->movements->findByIdempotencyKey($conn, $scope->posTenant, $scope->posBranch, $scope->storeId, $idempotencyKey);
        if ($existing) {
            return new RecipeMovementResult(['movement_ids' => [(int) $existing['id']]]);
        }

        $balance = $this->lockBalance($conn, $scope, $outputItemId);
        $newOnHand = RecipeDecimal::add($balance['qty_on_hand'], $outputQty);
        $newAvailable = RecipeDecimal::subtract($newOnHand, $balance['qty_reserved']);
        $unitCost = RecipeDecimal::compare($outputQty, '0') > 0
            ? RecipeDecimal::divide($totalCost, $outputQty)
            : RecipeDecimal::zero();
        $movementId = $this->movements->createMovement($conn, [
            'movement_uuid' => $this->uuid(),
            'movement_group_uuid' => $batchContext['batch_uuid'] ?? null,
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'item_id' => $outputItemId,
            'movement_type' => 'production_output',
            'source_type' => 'production_batch',
            'source_id' => $batchContext['batch_id'] ?? null,
            'source_uuid' => $batchContext['batch_uuid'] ?? null,
            'recipe_id' => $batchContext['recipe_id'] ?? null,
            'production_batch_id' => $batchContext['batch_id'] ?? null,
            'qty_in' => $outputQty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $batchContext['created_by'] ?? null,
        ]);
        $this->balances->putBalance($conn, [
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'item_id' => $outputItemId,
            'qty_on_hand' => $newOnHand,
            'qty_reserved' => $balance['qty_reserved'],
            'qty_available' => $newAvailable,
            'moving_average_cost' => $unitCost,
            'last_movement_id' => $movementId,
        ]);

        return new RecipeMovementResult(['movement_ids' => [$movementId]]);
    }

    public function recordRefundReversal(mysqli $conn, array $originalMovements, array $refundContext): RecipeMovementResult
    {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $policy = (string) ($refundContext['policy'] ?? 'waste');
        if ($policy !== 'return_to_stock') {
            return new RecipeMovementResult([
                'noop' => true,
                'warnings' => ['Refund policy does not return ingredients to stock.'],
            ]);
        }

        $movementIds = [];
        foreach ($originalMovements as $movement) {
            if (($movement['movement_type'] ?? '') !== 'recipe_consumption') {
                continue;
            }
            $movementId = (int) ($movement['id'] ?? 0);
            $lockedMovement = $this->movements->lockById($conn, $movementId);
            if (!$lockedMovement || ($lockedMovement['movement_type'] ?? '') !== 'recipe_consumption') {
                continue;
            }
            $movement = $lockedMovement;
            $originalQty = RecipeDecimal::normalize($movement['qty_out'] ?? '0');
            if (!RecipeDecimal::isPositive($originalQty)) {
                continue;
            }

            $scope = new RecipeScope(
                (int) ($movement['pos_tenant'] ?? 0),
                (int) ($movement['pos_branch'] ?? 0),
                $movement['branch_uuid'] ?? null,
                (int) ($movement['store_id'] ?? 0),
                'pos',
                'takeaway',
                'recipe'
            );
            $refundUuid = trim((string) ($refundContext['refund_uuid'] ?? ''));
            $idempotencyKey = 'refund-reversal:'
                . ($refundUuid !== '' ? $refundUuid . ':' : '')
                . (string) ($movement['movement_uuid'] ?? $movement['id']);
            $existing = $this->movements->findByIdempotencyKey($conn, $scope->posTenant, $scope->posBranch, $scope->storeId, $idempotencyKey);
            if ($existing) {
                $movementIds[] = (int) $existing['id'];
                continue;
            }

            $alreadyRefunded = $this->movements->refundedQuantityForMovement($conn, $movementId);
            $remainingQty = RecipeDecimal::subtract($originalQty, $alreadyRefunded);
            if (!RecipeDecimal::isPositive($remainingQty)) {
                continue;
            }

            $requestedOrderQty = RecipeDecimal::normalize(
                $refundContext['refund_order_quantity'] ?? $refundContext['original_order_quantity'] ?? '0'
            );
            $originalOrderQty = RecipeDecimal::normalize($refundContext['original_order_quantity'] ?? '0');
            if (!RecipeDecimal::isPositive($requestedOrderQty) || !RecipeDecimal::isPositive($originalOrderQty)) {
                throw new InvalidArgumentException('REFUND_RECIPE_QUANTITY_REQUIRED');
            }
            $qty = RecipeDecimal::multiply(
                $originalQty,
                RecipeDecimal::divide($requestedOrderQty, $originalOrderQty)
            );
            if (RecipeDecimal::compare($qty, $remainingQty) > 0) {
                $qty = $remainingQty;
            }
            if (!RecipeDecimal::isPositive($qty)) {
                continue;
            }

            $itemId = (int) $movement['item_id'];
            $balance = $this->lockBalance($conn, $scope, $itemId);
            $newOnHand = RecipeDecimal::add($balance['qty_on_hand'], $qty);
            $newAvailable = RecipeDecimal::subtract($newOnHand, $balance['qty_reserved']);
            $unitCost = RecipeDecimal::normalize($movement['unit_cost'] ?? '0');
            $totalCost = RecipeDecimal::multiply($qty, $unitCost);
            $movementId = $this->movements->createMovement($conn, [
                'movement_uuid' => $this->uuid(),
                'movement_group_uuid' => $refundUuid !== '' ? $refundUuid : null,
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $itemId,
                'movement_type' => 'refund_reversal',
                'source_type' => 'order_line',
                'source_id' => $movement['source_id'] ?? null,
                'source_uuid' => $refundUuid !== '' ? $refundUuid : null,
                'order_id' => $movement['order_id'] ?? null,
                'fat_detail_id' => $movement['fat_detail_id'] ?? null,
                'order_line_uuid' => $movement['order_line_uuid'] ?? null,
                'recipe_order_line_usage_id' => $movement['recipe_order_line_usage_id'] ?? null,
                'recipe_id' => $movement['recipe_id'] ?? null,
                'recipe_cost_snapshot_id' => $movement['recipe_cost_snapshot_id'] ?? null,
                'qty_in' => $qty,
                'unit_id' => $movement['unit_id'] ?? null,
                'unit_conversion_to_base' => $movement['unit_conversion_to_base'] ?? '1.00000000',
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'idempotency_key' => $idempotencyKey,
                'reversed_movement_id' => $movement['id'] ?? null,
                'created_by' => $refundContext['created_by'] ?? null,
            ]);
            $this->balances->putBalance($conn, [
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'item_id' => $itemId,
                'qty_on_hand' => $newOnHand,
                'qty_reserved' => $balance['qty_reserved'],
                'qty_available' => $newAvailable,
                'moving_average_cost' => $balance['moving_average_cost'],
                'last_movement_id' => $movementId,
            ]);
            $movementIds[] = $movementId;
        }

        return new RecipeMovementResult([
            'movement_ids' => $movementIds,
            'noop' => $movementIds === [],
        ]);
    }

    public function recordWaste(mysqli $conn, array $wasteContext): RecipeMovementResult
    {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $scope = $this->scopeFromOrderContext($wasteContext);
        $itemId = (int) ($wasteContext['item_id'] ?? 0);
        $qty = RecipeDecimal::normalize($wasteContext['qty'] ?? $wasteContext['qty_out'] ?? '0');
        if ($itemId < 1 || !RecipeDecimal::isPositive($qty)) {
            throw new InvalidArgumentException('Waste movement requires item_id and positive qty.');
        }

        $idempotencyKey = trim((string) ($wasteContext['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $source = trim((string) ($wasteContext['waste_uuid'] ?? $wasteContext['source_uuid'] ?? $wasteContext['waste_id'] ?? ''));
            if ($source === '') {
                throw new InvalidArgumentException('Waste movement requires a deterministic idempotency key or waste/source UUID.');
            }
            $idempotencyKey = 'waste:' . $scope->posTenant . ':' . $scope->posBranch . ':store:' . $scope->storeId . ':item:' . $itemId . ':source:' . $source;
        }

        $existing = $this->movements->findByIdempotencyKey($conn, $scope->posTenant, $scope->posBranch, $scope->storeId, $idempotencyKey);
        if ($existing) {
            return new RecipeMovementResult(['movement_ids' => [(int) $existing['id']]]);
        }

        $unitCost = RecipeDecimal::normalize($wasteContext['unit_cost'] ?? '0');
        $totalCost = array_key_exists('total_cost', $wasteContext)
            ? RecipeDecimal::normalize($wasteContext['total_cost'])
            : RecipeDecimal::multiply($qty, $unitCost);
        $balance = $this->lockBalance($conn, $scope, $itemId);
        $newOnHand = RecipeDecimal::subtract($balance['qty_on_hand'], $qty);
        $newAvailable = RecipeDecimal::subtract($newOnHand, $balance['qty_reserved']);
        $movementId = $this->movements->createMovement($conn, [
            'movement_uuid' => $this->uuid(),
            'movement_group_uuid' => $wasteContext['waste_uuid'] ?? null,
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'item_id' => $itemId,
            'movement_type' => 'waste',
            'source_type' => $wasteContext['source_type'] ?? 'manual',
            'source_id' => $wasteContext['waste_id'] ?? $wasteContext['source_id'] ?? null,
            'source_uuid' => $wasteContext['waste_uuid'] ?? $wasteContext['source_uuid'] ?? null,
            'recipe_id' => $wasteContext['recipe_id'] ?? null,
            'production_batch_id' => $wasteContext['production_batch_id'] ?? null,
            'qty_out' => $qty,
            'unit_id' => $wasteContext['unit_id'] ?? null,
            'unit_conversion_to_base' => $wasteContext['unit_conversion_to_base'] ?? '1.00000000',
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $wasteContext['created_by'] ?? null,
        ]);
        $this->balances->putBalance($conn, [
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'item_id' => $itemId,
            'qty_on_hand' => $newOnHand,
            'qty_reserved' => $balance['qty_reserved'],
            'qty_available' => $newAvailable,
            'moving_average_cost' => $balance['moving_average_cost'],
            'last_movement_id' => $movementId,
        ]);

        return new RecipeMovementResult(['movement_ids' => [$movementId]]);
    }

    public function recordAdjustment(mysqli $conn, array $adjustmentContext): RecipeMovementResult
    {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $scope = $this->scopeFromOrderContext($adjustmentContext);
        $itemId = (int) ($adjustmentContext['item_id'] ?? 0);
        if ($itemId < 1) {
            throw new InvalidArgumentException('Stock adjustment requires item_id.');
        }

        $qtyIn = RecipeDecimal::normalize($adjustmentContext['qty_in'] ?? '0');
        $qtyOut = RecipeDecimal::normalize($adjustmentContext['qty_out'] ?? '0');
        if (!RecipeDecimal::isPositive($qtyIn) && !RecipeDecimal::isPositive($qtyOut)) {
            $qty = RecipeDecimal::normalize($adjustmentContext['qty'] ?? '0');
            $direction = strtolower(trim((string) ($adjustmentContext['direction'] ?? '')));
            if ($direction === 'increase') {
                $qtyIn = $qty;
            } elseif ($direction === 'decrease') {
                $qtyOut = $qty;
            }
        }

        $hasQtyIn = RecipeDecimal::isPositive($qtyIn);
        $hasQtyOut = RecipeDecimal::isPositive($qtyOut);
        if ($hasQtyIn === $hasQtyOut) {
            throw new InvalidArgumentException('Stock adjustment requires exactly one positive qty_in or qty_out.');
        }

        $idempotencyKey = trim((string) ($adjustmentContext['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $source = trim((string) ($adjustmentContext['adjustment_uuid'] ?? $adjustmentContext['source_uuid'] ?? $adjustmentContext['adjustment_id'] ?? ''));
            if ($source === '') {
                throw new InvalidArgumentException('Stock adjustment requires a deterministic idempotency key or adjustment/source UUID.');
            }
            $idempotencyKey = 'adjustment:' . $scope->posTenant . ':' . $scope->posBranch . ':store:' . $scope->storeId . ':item:' . $itemId . ':source:' . $source;
        }

        $existing = $this->movements->findByIdempotencyKey($conn, $scope->posTenant, $scope->posBranch, $scope->storeId, $idempotencyKey);
        if ($existing) {
            return new RecipeMovementResult(['movement_ids' => [(int) $existing['id']]]);
        }

        $movementQty = $hasQtyIn ? $qtyIn : $qtyOut;
        $unitCost = RecipeDecimal::normalize($adjustmentContext['unit_cost'] ?? '0');
        $totalCost = array_key_exists('total_cost', $adjustmentContext)
            ? RecipeDecimal::normalize($adjustmentContext['total_cost'])
            : RecipeDecimal::multiply($movementQty, $unitCost);
        $balance = $this->lockBalance($conn, $scope, $itemId);
        $newOnHand = $hasQtyIn
            ? RecipeDecimal::add($balance['qty_on_hand'], $qtyIn)
            : RecipeDecimal::subtract($balance['qty_on_hand'], $qtyOut);
        $newAvailable = RecipeDecimal::subtract($newOnHand, $balance['qty_reserved']);
        $movementId = $this->movements->createMovement($conn, [
            'movement_uuid' => $this->uuid(),
            'movement_group_uuid' => $adjustmentContext['adjustment_uuid'] ?? null,
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'item_id' => $itemId,
            'movement_type' => 'adjustment',
            'source_type' => $adjustmentContext['source_type'] ?? 'manual',
            'source_id' => $adjustmentContext['adjustment_id'] ?? $adjustmentContext['source_id'] ?? null,
            'source_uuid' => $adjustmentContext['adjustment_uuid'] ?? $adjustmentContext['source_uuid'] ?? null,
            'recipe_id' => $adjustmentContext['recipe_id'] ?? null,
            'production_batch_id' => $adjustmentContext['production_batch_id'] ?? null,
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'unit_id' => $adjustmentContext['unit_id'] ?? null,
            'unit_conversion_to_base' => $adjustmentContext['unit_conversion_to_base'] ?? '1.00000000',
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $adjustmentContext['created_by'] ?? null,
        ]);
        $this->balances->putBalance($conn, [
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'item_id' => $itemId,
            'qty_on_hand' => $newOnHand,
            'qty_reserved' => $balance['qty_reserved'],
            'qty_available' => $newAvailable,
            'moving_average_cost' => $balance['moving_average_cost'],
            'last_movement_id' => $movementId,
        ]);

        return new RecipeMovementResult(['movement_ids' => [$movementId]]);
    }

    private function recordProductionInputThroughInventoryLedger(
        mysqli $conn,
        RecipeScope $scope,
        RecipeExplosionResult $explosion,
        array $batchContext
    ): RecipeMovementResult {
        $movementIds = [];
        foreach ($explosion->requirements as $requirement) {
            if (!$requirement instanceof IngredientRequirement) {
                continue;
            }

            $movement = $this->inventoryLedger->recordMovement($conn, [
                'scope' => $this->ledgerScope($scope),
                'movement_group_uuid' => $batchContext['batch_uuid'] ?? null,
                'item_id' => $requirement->ingredientItemId,
                'movement_type' => 'production_input',
                'source_type' => 'production_batch',
                'source_id' => $batchContext['batch_id'] ?? null,
                'source_uuid' => $batchContext['batch_uuid'] ?? null,
                'recipe_id' => $explosion->recipeId,
                'production_batch_id' => $batchContext['batch_id'] ?? null,
                'qty_out' => $requirement->requiredQtyBase,
                'unit_id' => $requirement->unitId,
                'unit_conversion_to_base' => $requirement->unitConversionToBase,
                'unit_cost' => $requirement->unitCost,
                'total_cost' => $requirement->totalCost,
                'idempotency_key' => $this->productionIdempotencyKey('production-input', $scope, $requirement, $batchContext),
                'metadata' => [
                    'source' => 'recipe_production',
                    'batch_uuid' => $batchContext['batch_uuid'] ?? null,
                    'recipe_id' => $explosion->recipeId,
                    'output_item_id' => $batchContext['output_item_id'] ?? null,
                ],
                'created_by' => $batchContext['created_by'] ?? null,
            ], null, ['manage_transaction' => false]);
            if (empty($movement['noop'])) {
                $movementIds[] = (int) ($movement['movement_id'] ?? 0);
            }
        }

        return new RecipeMovementResult([
            'movement_ids' => array_values(array_filter($movementIds)),
            'noop' => $movementIds === [],
        ]);
    }

    private function recordProductionOutputThroughInventoryLedger(
        mysqli $conn,
        RecipeScope $scope,
        array $batchContext,
        int $outputItemId,
        string $outputQty,
        string $totalCost
    ): RecipeMovementResult {
        $unitCost = RecipeDecimal::compare($outputQty, '0') > 0
            ? RecipeDecimal::divide($totalCost, $outputQty)
            : RecipeDecimal::zero();
        $idempotencyKey = 'production-output'
            . ':' . $scope->posTenant
            . ':' . $scope->posBranch
            . ':store:' . $scope->storeId
            . ':batch:' . (string) ($batchContext['batch_uuid'] ?? $batchContext['batch_id'] ?? '0')
            . ':item:' . $outputItemId;

        $movement = $this->inventoryLedger->recordMovement($conn, [
            'scope' => $this->ledgerScope($scope),
            'movement_group_uuid' => $batchContext['batch_uuid'] ?? null,
            'item_id' => $outputItemId,
            'movement_type' => 'production_output',
            'source_type' => 'production_batch',
            'source_id' => $batchContext['batch_id'] ?? null,
            'source_uuid' => $batchContext['batch_uuid'] ?? null,
            'recipe_id' => $batchContext['recipe_id'] ?? null,
            'production_batch_id' => $batchContext['batch_id'] ?? null,
            'qty_in' => $outputQty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'idempotency_key' => $idempotencyKey,
            'metadata' => [
                'source' => 'recipe_production',
                'batch_uuid' => $batchContext['batch_uuid'] ?? null,
                'recipe_id' => $batchContext['recipe_id'] ?? null,
                'output_item_id' => $outputItemId,
            ],
            'created_by' => $batchContext['created_by'] ?? null,
        ], null, ['manage_transaction' => false]);

        if (!empty($movement['noop'])) {
            return new RecipeMovementResult(['noop' => true]);
        }

        return new RecipeMovementResult(['movement_ids' => [(int) ($movement['movement_id'] ?? 0)]]);
    }

    private function ledgerScope(RecipeScope $scope): array
    {
        return [
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
        ];
    }

    private function lockBalance(mysqli $conn, RecipeScope $scope, int $itemId): array
    {
        $this->ensureBalanceExists($conn, $scope, $itemId);

        $stmt = $conn->prepare("
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1
FOR UPDATE");
        $stmt->bind_param('iiii', $scope->posTenant, $scope->posBranch, $scope->storeId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '0.000000',
            'moving_average_cost' => '0.000000',
        ];
    }

    private function lockRequirementBalances(mysqli $conn, RecipeScope $scope, array $requirements): array
    {
        $itemIds = [];
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof IngredientRequirement || $requirement->ingredientItemId < 1) {
                continue;
            }
            $itemIds[$requirement->ingredientItemId] = $requirement->ingredientItemId;
        }

        return $this->lockBalances($conn, $scope, array_values($itemIds));
    }

    private function lockBalances(mysqli $conn, RecipeScope $scope, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static function (int $itemId): bool {
            return $itemId > 0;
        })));
        sort($itemIds, SORT_NUMERIC);
        if (!$itemIds) {
            return [];
        }

        foreach ($itemIds as $itemId) {
            $this->ensureBalanceExists($conn, $scope, $itemId);
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $conn->prepare("
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id IN ({$placeholders})
ORDER BY item_id ASC
FOR UPDATE");
        $params = array_merge([$scope->posTenant, $scope->posBranch, $scope->storeId], $itemIds);
        $this->bindParams($stmt, str_repeat('i', count($params)), $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $balances = [];
        while ($row = $result->fetch_assoc()) {
            $balances[(int) $row['item_id']] = $row;
        }
        $stmt->close();

        foreach ($itemIds as $itemId) {
            if (!isset($balances[$itemId])) {
                $balances[$itemId] = [
                    'qty_on_hand' => '0.000000',
                    'qty_reserved' => '0.000000',
                    'qty_available' => '0.000000',
                    'moving_average_cost' => '0.000000',
                ];
            }
        }

        return $balances;
    }

    private function ensureBalanceExists(mysqli $conn, RecipeScope $scope, int $itemId): void
    {
        $stmt = $conn->prepare("
INSERT IGNORE INTO inventory_item_balances
  (pos_tenant, pos_branch, branch_uuid, store_id, item_id)
VALUES (?, ?, ?, ?, ?)");
        $branchUuid = $scope->branchUuid;
        $stmt->bind_param('iisii', $scope->posTenant, $scope->posBranch, $branchUuid, $scope->storeId, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array $params): void
    {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = $value;
        }
        $bind = [$types];
        foreach ($refs as $index => $_) {
            $bind[] = &$refs[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    private function scopeFromOrderContext(array $orderContext): RecipeScope
    {
        return new RecipeScope(
            (int) ($orderContext['pos_tenant'] ?? 0),
            (int) ($orderContext['pos_branch'] ?? 0),
            $orderContext['branch_uuid'] ?? null,
            (int) ($orderContext['store_id'] ?? 0),
            (string) ($orderContext['channel'] ?? 'pos'),
            (string) ($orderContext['order_type'] ?? 'takeaway'),
            'recipe'
        );
    }

    private function itemCategoryId(array $context): ?int
    {
        $categoryId = (int) (
            $context['item_category_id']
            ?? $context['sellable_item_category_id']
            ?? $context['category_id']
            ?? $context['group1']
            ?? 0
        );

        return $categoryId > 0 ? $categoryId : null;
    }

    private function idempotencyKey(
        string $prefix,
        RecipeScope $scope,
        RecipeExplosionResult $explosion,
        IngredientRequirement $requirement,
        array $orderContext
    ): string {
        $orderId = (string) ($orderContext['order_id'] ?? '0');
        $lineId = (string) (
            $orderContext['recipe_order_line_usage_id']
            ?? $orderContext['order_line_uuid']
            ?? $orderContext['source_line_uuid']
            ?? $orderContext['fat_detail_id']
            ?? '0'
        );
        $recipeId = (string) ($explosion->recipeId ?? '0');
        $version = (string) ($explosion->recipeVersion ?? '0');

        return $prefix
            . ':' . $scope->posTenant
            . ':' . $scope->posBranch
            . ':store:' . $scope->storeId
            . ':order:' . $orderId
            . ':line:' . $lineId
            . ':recipe:' . $recipeId
            . ':item:' . $requirement->ingredientItemId
            . ':v:' . $version;
    }

    private function productionIdempotencyKey(
        string $prefix,
        RecipeScope $scope,
        IngredientRequirement $requirement,
        array $batchContext
    ): string {
        return $prefix
            . ':' . $scope->posTenant
            . ':' . $scope->posBranch
            . ':store:' . $scope->storeId
            . ':batch:' . (string) ($batchContext['batch_uuid'] ?? $batchContext['batch_id'] ?? '0')
            . ':item:' . $requirement->ingredientItemId;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
