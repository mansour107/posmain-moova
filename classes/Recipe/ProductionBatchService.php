<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/DTO/RecipeScope.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeAccountingService.php';
require_once __DIR__ . '/RecipeAvailabilityService.php';
require_once __DIR__ . '/RecipeExplosionService.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeInventoryMovementService.php';
require_once __DIR__ . '/RecipePermissionService.php';
require_once __DIR__ . '/RecipeSettingsService.php';
require_once __DIR__ . '/RecipeTransactionRetryService.php';
require_once __DIR__ . '/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/Repository/ProductionBatchRepository.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

class ProductionBatchService
{
    private $flags;
    private $batches;
    private $recipes;
    private $explosion;
    private $movements;
    private $accounting;
    private $availability;
    private $settings;
    private $balances;
    private $permissions;
    private $transactionRetry;

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?ProductionBatchRepository $batches = null,
        ?RecipeRepository $recipes = null,
        ?RecipeExplosionService $explosion = null,
        ?RecipeInventoryMovementService $movements = null,
        ?RecipeAccountingService $accounting = null,
        ?InventoryBalanceRepository $balances = null,
        ?RecipePermissionService $permissions = null,
        ?RecipeTransactionRetryService $transactionRetry = null,
        ?RecipeAvailabilityService $availability = null,
        ?RecipeSettingsService $settings = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->batches = $batches ?: new ProductionBatchRepository();
        $this->recipes = $recipes ?: new RecipeRepository();
        $this->explosion = $explosion ?: new RecipeExplosionService($this->flags);
        $this->movements = $movements ?: new RecipeInventoryMovementService($this->flags);
        $this->settings = $settings ?: new RecipeSettingsService($this->flags->appConfig());
        $this->accounting = $accounting ?: new RecipeAccountingService(
            $this->flags,
            null,
            null,
            $this->settings
        );
        $this->availability = $availability ?: new RecipeAvailabilityService($this->flags, $this->explosion);
        $this->balances = $balances ?: new InventoryBalanceRepository();
        $this->permissions = $permissions ?: new RecipePermissionService();
        $this->transactionRetry = $transactionRetry ?: new RecipeTransactionRetryService();
    }

    public function createDraft(mysqli $conn, array $data, RecipeActorContext $actor): array
    {
        $this->permissions->assertCanEdit($actor);
        if (!RecipeDecimal::isPositive((string) ($data['planned_output_qty'] ?? '0'))) {
            throw new InvalidArgumentException('Production planned output quantity must be positive.');
        }
        $this->assertProductionWritesEnabled(
            $conn,
            (int) ($data['output_item_id'] ?? 0),
            (int) ($data['pos_tenant'] ?? $actor->posTenant),
            (int) ($data['pos_branch'] ?? $actor->posBranch),
            $data['branch_uuid'] ?? $actor->branchUuid,
            (int) ($data['store_id'] ?? 0)
        );

        $batchId = $this->batches->createBatch($conn, array_merge([
            'batch_uuid' => $this->uuid(),
            'pos_tenant' => $actor->posTenant,
            'pos_branch' => $actor->posBranch,
            'branch_uuid' => $actor->branchUuid,
            'store_id' => 0,
            'created_by' => $actor->userId,
        ], $data));

        return $this->requireBatch($conn, $batchId);
    }

    public function preview(mysqli $conn, int $batchId): array
    {
        $batch = $this->requireBatch($conn, $batchId);
        $explosion = $this->explodeBatch($conn, $batch);
        $requirements = $this->requirementsWithCosts($conn, $explosion->requirements);
        $totalInputCost = RecipeDecimal::zero();
        foreach ($requirements as $requirement) {
            $totalInputCost = RecipeDecimal::add($totalInputCost, $requirement->totalCost);
        }

        return [
            'batch' => $batch,
            'recipe_id' => (int) $batch['recipe_id'],
            'output_item_id' => (int) $batch['output_item_id'],
            'planned_output_qty' => (string) $batch['planned_output_qty'],
            'requirements' => array_map(static function (IngredientRequirement $requirement): array {
                return $requirement->toArray();
            }, $requirements),
            'total_input_cost' => $totalInputCost,
        ];
    }

    public function commit(mysqli $conn, int $batchId, array $actuals, RecipeActorContext $actor): array
    {
        $this->permissions->assertCanApprove($actor);
        $actualOutputQty = RecipeDecimal::normalize($actuals['actual_output_qty'] ?? '0');
        if (!RecipeDecimal::isPositive($actualOutputQty)) {
            throw new InvalidArgumentException('Actual production output quantity must be positive.');
        }

        return $this->transactionRetry->run($conn, function () use ($conn, $batchId, $actualOutputQty, $actuals, $actor): array {
            $batch = $this->batches->findBatchByIdForUpdate($conn, $batchId);
            if (!$batch) {
                throw new RuntimeException('Production batch not found.');
            }
            if ($batch['status'] !== 'draft') {
                throw new RuntimeException('Only draft production batches can be committed.');
            }
            $this->assertProductionWritesEnabled(
                $conn,
                (int) $batch['output_item_id'],
                (int) $batch['pos_tenant'],
                (int) $batch['pos_branch'],
                $batch['branch_uuid'] ?? null,
                (int) $batch['store_id']
            );
            $varianceReason = trim((string) ($actuals['variance_reason'] ?? ''));
            if (RecipeDecimal::compare($actualOutputQty, (string) $batch['planned_output_qty']) !== 0 && $varianceReason === '') {
                throw new RuntimeException('Variance reason is required when actual output differs from planned output.');
            }

            $explosion = $this->explodeBatch($conn, $batch);
            $explosion->requirements = $this->requirementsWithCosts($conn, $explosion->requirements);
            if ($this->flags->isStrictStockEnabled()) {
                $this->assertInputsAvailable($conn, $batch, $explosion->requirements);
            }

            $batchContext = $this->batchContext($batch, $actor);
            $inputResult = $this->movements->recordProductionInput($conn, $explosion, $batchContext);
            $totalInputCost = RecipeDecimal::zero();
            foreach ($explosion->requirements as $index => $requirement) {
                $totalInputCost = RecipeDecimal::add($totalInputCost, $requirement->totalCost);
                $this->batches->createBatchLine($conn, [
                    'batch_id' => $batchId,
                    'line_type' => 'input',
                    'item_id' => $requirement->ingredientItemId,
                    'planned_qty' => $requirement->requiredQtyBase,
                    'actual_qty' => $requirement->requiredQtyBase,
                    'unit_id' => $requirement->unitId,
                    'unit_cost' => $requirement->unitCost,
                    'total_cost' => $requirement->totalCost,
                    'inventory_movement_id' => $inputResult->movementIds[$index] ?? null,
                ]);
            }

            $outputTotalCost = $this->productionOutputTotalCost($batch, $actualOutputQty, $totalInputCost);
            $varianceCost = $this->absoluteDifference($totalInputCost, $outputTotalCost);
            $outputResult = $this->movements->recordProductionOutput(
                $conn,
                $batchContext,
                (int) $batch['output_item_id'],
                $actualOutputQty,
                $outputTotalCost
            );
            $this->batches->createBatchLine($conn, [
                'batch_id' => $batchId,
                'line_type' => 'output',
                'item_id' => (int) $batch['output_item_id'],
                'planned_qty' => (string) $batch['planned_output_qty'],
                'actual_qty' => $actualOutputQty,
                'unit_cost' => RecipeDecimal::compare($actualOutputQty, '0') > 0 ? RecipeDecimal::divide($outputTotalCost, $actualOutputQty) : RecipeDecimal::zero(),
                'total_cost' => $outputTotalCost,
                'inventory_movement_id' => $outputResult->movementIds[0] ?? null,
            ]);
            if (RecipeDecimal::isPositive($varianceCost)) {
                $this->batches->createBatchLine($conn, [
                    'batch_id' => $batchId,
                    'line_type' => 'variance',
                    'item_id' => (int) $batch['output_item_id'],
                    'planned_qty' => (string) $batch['planned_output_qty'],
                    'actual_qty' => $actualOutputQty,
                    'unit_cost' => RecipeDecimal::compare((string) $batch['planned_output_qty'], '0') > 0
                        ? RecipeDecimal::divide($totalInputCost, (string) $batch['planned_output_qty'])
                        : RecipeDecimal::zero(),
                    'total_cost' => $varianceCost,
                    'inventory_movement_id' => null,
                ]);
            }

            $accounting = $this->accounting->postProductionBatch(
                $conn,
                $batchContext,
                $inputResult->movementIds,
                $outputResult->movementIds
            );
            $availabilityRefreshes = $this->refreshAvailabilityForProduction($conn, $batch, $explosion->requirements);
            $this->batches->updateCommitted($conn, $batchId, $actualOutputQty, $actor->userId, $varianceReason !== '' ? $varianceReason : null);
            $committed = $this->requireBatch($conn, $batchId);
            $lines = $this->batches->findLinesByBatchId($conn, $batchId);
            return [
                'batch' => $committed,
                'lines' => $lines,
                'input_movement_ids' => $inputResult->movementIds,
                'output_movement_ids' => $outputResult->movementIds,
                'accounting' => $accounting,
                'availability_refreshes' => $availabilityRefreshes,
                'total_input_cost' => $totalInputCost,
                'output_total_cost' => $outputTotalCost,
                'variance_cost' => $varianceCost,
            ];
        });
    }

    public function cancel(mysqli $conn, int $batchId, string $reason, RecipeActorContext $actor): void
    {
        $this->permissions->assertCanEdit($actor);
        $batch = $this->requireBatch($conn, $batchId);
        $this->assertProductionWritesEnabled(
            $conn,
            (int) $batch['output_item_id'],
            (int) $batch['pos_tenant'],
            (int) $batch['pos_branch'],
            $batch['branch_uuid'] ?? null,
            (int) $batch['store_id']
        );
        if ($batch['status'] !== 'draft') {
            throw new RuntimeException('Only draft production batches can be cancelled.');
        }
        $this->batches->cancel($conn, $batchId, $reason);
    }

    private function explodeBatch(mysqli $conn, array $batch): RecipeExplosionResult
    {
        $context = new RecipeOrderLineContext([
            'pos_tenant' => (int) $batch['pos_tenant'],
            'pos_branch' => (int) $batch['pos_branch'],
            'branch_uuid' => $batch['branch_uuid'] ?? null,
            'store_id' => (int) $batch['store_id'],
            'sellable_item_id' => (int) $batch['output_item_id'],
            'quantity' => (string) $batch['planned_output_qty'],
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);

        return $this->explosion->explodeRecipeById($conn, (int) $batch['recipe_id'], $context, (string) $batch['planned_output_qty']);
    }

    private function requirementsWithCosts(mysqli $conn, array $requirements): array
    {
        foreach ($requirements as $requirement) {
            $unitCost = $this->itemCost($conn, $requirement->ingredientItemId);
            $requirement->unitCost = $unitCost;
            $requirement->totalCost = RecipeDecimal::multiply($requirement->requiredQtyBase, $unitCost);
        }

        return $requirements;
    }

    private function assertInputsAvailable(mysqli $conn, array $batch, array $requirements): void
    {
        foreach ($requirements as $requirement) {
            $balance = $this->balances->findBalance(
                $conn,
                (int) $batch['pos_tenant'],
                (int) $batch['pos_branch'],
                (int) $batch['store_id'],
                $requirement->ingredientItemId
            ) ?: [
                'qty_on_hand' => '0.000000',
                'qty_reserved' => '0.000000',
            ];
            $available = RecipeDecimal::subtract($balance['qty_on_hand'], $balance['qty_reserved']);
            if (RecipeDecimal::compare($available, $requirement->requiredQtyBase) < 0) {
                throw new RuntimeException('Insufficient production input stock for item ' . $requirement->ingredientItemId . '.');
            }
        }
    }

    private function itemCost(mysqli $conn, int $itemId): string
    {
        if (!$this->tableExists($conn, 'myitems')) {
            return RecipeDecimal::zero();
        }

        $stmt = $conn->prepare('SELECT cost_price FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return RecipeDecimal::normalize($row['cost_price'] ?? '0');
    }

    private function batchContext(array $batch, RecipeActorContext $actor): array
    {
        return [
            'pos_tenant' => (int) $batch['pos_tenant'],
            'pos_branch' => (int) $batch['pos_branch'],
            'branch_uuid' => $batch['branch_uuid'] ?? null,
            'store_id' => (int) $batch['store_id'],
            'batch_id' => (int) $batch['id'],
            'batch_uuid' => (string) $batch['batch_uuid'],
            'recipe_id' => (int) $batch['recipe_id'],
            'output_item_id' => (int) $batch['output_item_id'],
            'created_by' => $actor->userId,
        ];
    }

    private function productionOutputTotalCost(array $batch, string $actualOutputQty, string $totalInputCost): string
    {
        if ($this->settings->productionVariancePolicy() !== 'post_variance') {
            return $totalInputCost;
        }
        if (RecipeDecimal::compare($actualOutputQty, (string) $batch['planned_output_qty']) === 0) {
            return $totalInputCost;
        }

        $plannedUnitCost = RecipeDecimal::divide($totalInputCost, (string) $batch['planned_output_qty']);

        return RecipeDecimal::multiply($plannedUnitCost, $actualOutputQty);
    }

    private function absoluteDifference(string $left, string $right): string
    {
        $difference = RecipeDecimal::subtract($left, $right);
        if (RecipeDecimal::compare($difference, '0') >= 0) {
            return $difference;
        }

        return RecipeDecimal::subtract('0', $difference);
    }

    private function refreshAvailabilityForProduction(mysqli $conn, array $batch, array $requirements): array
    {
        $recipeConfig = $this->flags->config();
        if (!$this->flags->isEnabled()
            || !in_array($this->flags->mode(), ['availability_pilot', 'full'], true)
            || empty($recipeConfig['availability'])
        ) {
            return [];
        }

        $context = [
            'pos_tenant' => (int) $batch['pos_tenant'],
            'pos_branch' => (int) $batch['pos_branch'],
            'branch_uuid' => $batch['branch_uuid'] ?? null,
            'store_id' => (int) $batch['store_id'],
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ];

        $changedItemIds = [];
        foreach ($requirements as $requirement) {
            if ($requirement instanceof IngredientRequirement && $requirement->ingredientItemId > 0) {
                $changedItemIds[$requirement->ingredientItemId] = true;
            }
        }
        $outputItemId = (int) ($batch['output_item_id'] ?? 0);
        if ($outputItemId > 0) {
            $changedItemIds[$outputItemId] = true;
        }

        $refreshed = [];
        foreach (array_keys($changedItemIds) as $itemId) {
            foreach ($this->availability->refreshForIngredient($conn, (int) $itemId, $context) as $availability) {
                $key = (int) $availability->recipeId . ':' . (int) $availability->sellableItemId;
                $refreshed[$key] = $availability->toArray();
            }
        }

        return array_values($refreshed);
    }

    private function requireBatch(mysqli $conn, int $batchId): array
    {
        $batch = $this->batches->findBatchById($conn, $batchId);
        if (!$batch) {
            throw new RuntimeException('Production batch not found.');
        }

        return $batch;
    }

    private function assertProductionWritesEnabled(
        mysqli $conn,
        int $outputItemId,
        int $posTenant,
        int $posBranch,
        ?string $branchUuid,
        int $storeId
    ): void
    {
        $scope = new RecipeScope($posTenant, $posBranch, $branchUuid, $storeId, 'pos', 'takeaway', 'production');
        if ($outputItemId > 0 && $this->flags->isConsumptionEnabledForItem($scope, $outputItemId, $this->itemCategoryId($conn, $outputItemId))) {
            return;
        }

        throw new RuntimeException('Production batch writes are disabled by feature flags or pilot scope.');
    }

    private function itemCategoryId(mysqli $conn, int $itemId): ?int
    {
        if (!$this->tableExists($conn, 'myitems') || !$this->columnExists($conn, 'myitems', 'group1')) {
            return null;
        }

        $stmt = $conn->prepare('SELECT group1 FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $categoryId = (int) ($row['group1'] ?? 0);
        return $categoryId > 0 ? $categoryId : null;
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
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

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
