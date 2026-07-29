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
        $this->inventoryFlags = $inventoryFlags ?: new InventoryFeatureFlags($this->flags->appConfig());
        $this->inventoryLedger = $inventoryLedger ?: new InventoryLedgerService($this->inventoryFlags);
    }

    public function recordRecipeConsumption(mysqli $conn, RecipeExplosionResult $explosion, array $orderContext): RecipeMovementResult
    {
        $scope = $this->scopeFromOrderContext($orderContext);
        if (!$this->flags->isConsumptionEnabledForItem($scope, $explosion->sellableItemId, $this->itemCategoryId($orderContext))) {
            return new RecipeMovementResult(['noop' => true]);
        }
        $this->assertExplicitQuantityTrackingEnabled();

        return $this->recordRecipeConsumptionThroughInventoryLedger($conn, $scope, $explosion, $orderContext);
    }

    private function recordRecipeConsumptionThroughInventoryLedger(
        mysqli $conn,
        RecipeScope $scope,
        RecipeExplosionResult $explosion,
        array $orderContext
    ): RecipeMovementResult {
        $movementIds = [];
        foreach ($explosion->requirements as $requirement) {
            if (!$requirement instanceof IngredientRequirement) {
                continue;
            }

            if (!empty($orderContext['consume_reserved'])) {
                $this->recordReservationDeltaThroughInventoryLedger(
                    $conn,
                    $scope,
                    $requirement->ingredientItemId,
                    $requirement->requiredQtyBase,
                    'reservation_release',
                    $this->idempotencyKey('consume-reservation-release', $scope, $explosion, $requirement, $orderContext),
                    [
                        'source_type' => 'recipe_order_line_usage',
                        'source_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                        'source_uuid' => $orderContext['order_line_uuid'] ?? null,
                        'order_id' => $orderContext['order_id'] ?? null,
                        'fat_detail_id' => $orderContext['fat_detail_id'] ?? null,
                        'order_line_uuid' => $orderContext['order_line_uuid'] ?? null,
                        'recipe_order_line_usage_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                        'recipe_id' => $explosion->recipeId,
                        'created_by' => $orderContext['created_by'] ?? null,
                    ]
                );
            }

            $movement = $this->inventoryLedger->recordMovement($conn, [
                'scope' => $this->ledgerScope($scope),
                'item_id' => $requirement->ingredientItemId,
                'movement_type' => 'recipe_consumption',
                'source_type' => 'recipe_order_line_usage',
                'source_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                'source_uuid' => $orderContext['order_line_uuid'] ?? null,
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
                'idempotency_key' => $this->idempotencyKey('consume', $scope, $explosion, $requirement, $orderContext),
                'metadata' => [
                    'source' => 'recipe_sale',
                    'consume_reserved' => !empty($orderContext['consume_reserved']),
                ],
                'created_by' => $orderContext['created_by'] ?? null,
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

    public function recordReservationMovement(mysqli $conn, RecipeExplosionResult $explosion, array $orderContext): RecipeMovementResult
    {
        $scope = $this->scopeFromOrderContext($orderContext);
        if (!$this->flags->isReservationEnabledForItem($scope, $explosion->sellableItemId, $this->itemCategoryId($orderContext))) {
            return new RecipeMovementResult(['noop' => true]);
        }
        $this->assertExplicitQuantityTrackingEnabled();

        $movementIds = [];
        foreach ($explosion->requirements as $requirement) {
            if (!$requirement instanceof IngredientRequirement) {
                continue;
            }
            $movementId = $this->recordReservationDeltaThroughInventoryLedger(
                $conn,
                $scope,
                $requirement->ingredientItemId,
                $requirement->requiredQtyBase,
                'reservation',
                $this->idempotencyKey('reservation', $scope, $explosion, $requirement, $orderContext),
                [
                    'source_type' => 'reservation',
                    'source_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                    'source_uuid' => $orderContext['order_line_uuid'] ?? null,
                    'order_id' => $orderContext['order_id'] ?? null,
                    'fat_detail_id' => $orderContext['fat_detail_id'] ?? null,
                    'order_line_uuid' => $orderContext['order_line_uuid'] ?? null,
                    'recipe_order_line_usage_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                    'recipe_id' => $explosion->recipeId,
                    'created_by' => $orderContext['created_by'] ?? null,
                ]
            );
            if ($movementId > 0) {
                $movementIds[] = $movementId;
            }
        }

        return new RecipeMovementResult(['movement_ids' => $movementIds]);
    }

    public function recordReservationRelease(mysqli $conn, array $reservations, string $reason = 'release'): RecipeMovementResult
    {
        $this->assertExplicitQuantityTrackingEnabled();

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
            $movementId = $this->recordReservationDeltaThroughInventoryLedger(
                $conn,
                $scope,
                $itemId,
                $qty,
                'reservation_release',
                $idempotencyKey,
                [
                    'source_type' => 'reservation',
                    'source_id' => (int) $reservation['id'],
                    'order_id' => $reservation['order_id'] ?? null,
                    'fat_detail_id' => $reservation['fat_detail_id'] ?? null,
                    'order_line_uuid' => $reservation['order_line_uuid'] ?? null,
                    'recipe_order_line_usage_id' => $reservation['recipe_order_line_usage_id'] ?? null,
                    'recipe_id' => $reservation['recipe_id'] ?? null,
                ]
            );
            if ($movementId > 0) {
                $movementIds[] = $movementId;
            }
        }

        return new RecipeMovementResult(['movement_ids' => $movementIds]);
    }

    private function recordReservationDeltaThroughInventoryLedger(
        mysqli $conn,
        RecipeScope $scope,
        int $itemId,
        string $qty,
        string $movementType,
        string $idempotencyKey,
        array $context
    ): int {
        $movement = $this->inventoryLedger->recordMovement($conn, array_merge($context, [
            'scope' => $this->ledgerScope($scope),
            'item_id' => $itemId,
            'movement_type' => $movementType,
            'qty_reserved' => $qty,
            'idempotency_key' => $idempotencyKey,
            'metadata' => [
                'source' => 'recipe_reservation',
            ],
        ]), null, ['manage_transaction' => false]);

        return empty($movement['noop']) ? (int) ($movement['movement_id'] ?? 0) : 0;
    }

    public function recordProductionInput(mysqli $conn, RecipeExplosionResult $explosion, array $batchContext): RecipeMovementResult
    {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return new RecipeMovementResult(['noop' => true]);
        }
        $this->assertExplicitQuantityTrackingEnabled();

        $scope = $this->scopeFromOrderContext($batchContext);
        return $this->recordProductionInputThroughInventoryLedger($conn, $scope, $explosion, $batchContext);
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
        $this->assertExplicitQuantityTrackingEnabled();

        $scope = $this->scopeFromOrderContext($batchContext);
        return $this->recordProductionOutputThroughInventoryLedger(
            $conn,
            $scope,
            $batchContext,
            $outputItemId,
            $outputQty,
            $totalCost
        );
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
        $this->assertExplicitQuantityTrackingEnabled();

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
            $unitCost = RecipeDecimal::normalize($movement['unit_cost'] ?? '0');
            $totalCost = RecipeDecimal::multiply($qty, $unitCost);
            $reversal = $this->inventoryLedger->recordMovement($conn, [
                'scope' => $this->ledgerScope($scope),
                'movement_group_uuid' => $refundUuid !== '' ? $refundUuid : null,
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
                'metadata' => [
                    'source' => 'recipe_refund',
                    'refund_policy' => 'return_to_stock',
                    'refund_uuid' => $refundUuid !== '' ? $refundUuid : null,
                ],
                'created_by' => $refundContext['created_by'] ?? null,
            ], null, ['manage_transaction' => false]);
            if (empty($reversal['noop'])) {
                $movementIds[] = (int) ($reversal['movement_id'] ?? 0);
            }
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
        $this->assertExplicitQuantityTrackingEnabled();

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

        $unitCost = RecipeDecimal::normalize($wasteContext['unit_cost'] ?? '0');
        $totalCost = array_key_exists('total_cost', $wasteContext)
            ? RecipeDecimal::normalize($wasteContext['total_cost'])
            : RecipeDecimal::multiply($qty, $unitCost);
        $movement = $this->inventoryLedger->recordMovement($conn, [
            'scope' => $this->ledgerScope($scope),
            'movement_group_uuid' => $wasteContext['waste_uuid'] ?? null,
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
            'metadata' => [
                'source' => 'recipe_waste',
            ],
            'created_by' => $wasteContext['created_by'] ?? null,
        ], null, ['manage_transaction' => false]);

        return new RecipeMovementResult([
            'movement_ids' => empty($movement['noop']) ? [(int) ($movement['movement_id'] ?? 0)] : [],
            'noop' => !empty($movement['noop']),
        ]);
    }

    public function recordAdjustment(mysqli $conn, array $adjustmentContext): RecipeMovementResult
    {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return new RecipeMovementResult(['noop' => true]);
        }
        $this->assertExplicitQuantityTrackingEnabled();

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

        $movementQty = $hasQtyIn ? $qtyIn : $qtyOut;
        $unitCost = RecipeDecimal::normalize($adjustmentContext['unit_cost'] ?? '0');
        $totalCost = array_key_exists('total_cost', $adjustmentContext)
            ? RecipeDecimal::normalize($adjustmentContext['total_cost'])
            : RecipeDecimal::multiply($movementQty, $unitCost);
        $movement = $this->inventoryLedger->recordMovement($conn, [
            'scope' => $this->ledgerScope($scope),
            'movement_group_uuid' => $adjustmentContext['adjustment_uuid'] ?? null,
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
            'metadata' => [
                'source' => 'recipe_adjustment',
            ],
            'created_by' => $adjustmentContext['created_by'] ?? null,
        ], null, ['manage_transaction' => false]);

        return new RecipeMovementResult([
            'movement_ids' => empty($movement['noop']) ? [(int) ($movement['movement_id'] ?? 0)] : [],
            'noop' => !empty($movement['noop']),
        ]);
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

    /**
     * Legacy recipe-only configurations predate the explicit inventory
     * capability and keep their historical movement behavior. Once a shop
     * declares quantity_tracking, an active recipe write may never bypass it.
     */
    private function assertExplicitQuantityTrackingEnabled(): void
    {
        $inventoryConfig = $this->inventoryFlags->config();
        if (array_key_exists('quantity_tracking', $inventoryConfig)
            && !$this->inventoryFlags->isQuantityTrackingEnabled()) {
            throw new RuntimeException('RECIPE_QUANTITY_TRACKING_REQUIRED');
        }
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

}
