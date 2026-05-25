<?php

require_once __DIR__ . '/DTO/RecipeAvailabilityResult.php';
require_once __DIR__ . '/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeDependencyResolverService.php';
require_once __DIR__ . '/RecipeExplosionService.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/Repository/RecipeAvailabilityCacheRepository.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

class RecipeAvailabilityService
{
    private $flags;
    private $explosion;
    private $balances;
    private $cache;
    private $recipes;
    private $dependencies;
    private array $itemCategoryCache = [];

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?RecipeExplosionService $explosion = null,
        ?InventoryBalanceRepository $balances = null,
        ?RecipeAvailabilityCacheRepository $cache = null,
        ?RecipeRepository $recipes = null,
        ?RecipeDependencyResolverService $dependencies = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->explosion = $explosion ?: new RecipeExplosionService($this->flags);
        $this->balances = $balances ?: new InventoryBalanceRepository();
        $this->cache = $cache ?: new RecipeAvailabilityCacheRepository();
        $this->recipes = $recipes ?: new RecipeRepository();
        $this->dependencies = $dependencies ?: new RecipeDependencyResolverService();
    }

    public function calculateForItem(mysqli $conn, int $sellableItemId, array $context = []): RecipeAvailabilityResult
    {
        $orderContext = new RecipeOrderLineContext(array_merge($context, [
            'sellable_item_id' => $sellableItemId,
            'quantity' => '1.000000',
        ]));

        $itemCategoryId = $this->itemCategoryId($conn, $sellableItemId, $orderContext->itemCategoryId);
        if (!$this->flags->isAvailabilityEnabledForItem($this->scopeFromContext($orderContext), $sellableItemId, $itemCategoryId)) {
            return new RecipeAvailabilityResult([
                'sellable_item_id' => $sellableItemId,
                'computed_available_qty' => '0.000000',
                'effective_available_qty' => '0.000000',
                'effective_is_available' => true,
                'unavailable_reason' => null,
            ]);
        }

        $manual = $this->manualAvailability($conn, $orderContext);
        if (!$manual['available']) {
            return $this->cacheAndReturn($conn, $orderContext, new RecipeAvailabilityResult([
                'sellable_item_id' => $sellableItemId,
                'recipe_id' => null,
                'computed_available_qty' => '0.000000',
                'effective_available_qty' => '0.000000',
                'effective_is_available' => false,
                'unavailable_reason' => $manual['reason'] ?: 'Manual unavailable.',
                'availability_revision' => $this->nextRevision($conn, $orderContext),
            ]));
        }

        $recipe = $this->recipes->findActiveHeaderForItem(
            $conn,
            $orderContext->posTenant,
            $orderContext->posBranch,
            $sellableItemId
        );
        if (!$recipe) {
            return $this->cacheAndReturn($conn, $orderContext, new RecipeAvailabilityResult([
                'sellable_item_id' => $sellableItemId,
                'recipe_id' => null,
                'computed_available_qty' => '0.000000',
                'effective_available_qty' => '0.000000',
                'effective_is_available' => false,
                'unavailable_reason' => 'No active recipe.',
                'availability_revision' => $this->nextRevision($conn, $orderContext),
            ]));
        }

        if ((string) ($recipe['recipe_type'] ?? '') === 'batch_prepared') {
            return $this->cacheAndReturn(
                $conn,
                $orderContext,
                $this->availabilityFromPreparedStock(
                    $conn,
                    $orderContext,
                    $recipe,
                    RecipeDecimal::normalize($context['safety_stock'] ?? '0'),
                    $this->nextRevision($conn, $orderContext)
                )
            );
        }

        $explosion = $this->explosion->explodeRecipeById($conn, (int) $recipe['id'], $orderContext, '1.000000');
        $result = $this->availabilityFromExplosion(
            $conn,
            $orderContext,
            $explosion,
            RecipeDecimal::normalize($context['safety_stock'] ?? '0'),
            $this->nextRevision($conn, $orderContext)
        );

        return $this->cacheAndReturn($conn, $orderContext, $result);
    }

    public function assertAvailableForOrderLine(mysqli $conn, RecipeOrderLineContext $context): RecipeAvailabilityResult
    {
        $scope = $this->scopeFromContext($context);
        $itemCategoryId = $this->itemCategoryId($conn, $context->sellableItemId, $context->itemCategoryId);
        if (!$this->flags->isAvailabilityEnabledForItem($scope, $context->sellableItemId, $itemCategoryId)) {
            return new RecipeAvailabilityResult([
                'sellable_item_id' => $context->sellableItemId,
                'computed_available_qty' => '0.000000',
                'effective_available_qty' => '0.000000',
                'effective_is_available' => true,
                'unavailable_reason' => null,
            ]);
        }

        $availability = $this->calculateForItem($conn, $context->sellableItemId, $this->contextArray($context));
        if (!$this->flags->isStrictStockEnabled()) {
            return $availability;
        }

        $requestedQty = RecipeDecimal::normalize($context->quantity);
        if (!$availability->effectiveIsAvailable) {
            throw new RuntimeException($availability->unavailableReason ?: 'Recipe item is unavailable.');
        }
        if (RecipeDecimal::compare($availability->effectiveAvailableQty, $requestedQty) < 0) {
            throw new RuntimeException(
                'Only ' . RecipeDecimal::normalize($availability->effectiveAvailableQty) . ' can be made.'
            );
        }

        return $availability;
    }

    public function refreshForOrderLine(mysqli $conn, RecipeOrderLineContext $context): ?RecipeAvailabilityResult
    {
        $itemCategoryId = $this->itemCategoryId($conn, $context->sellableItemId, $context->itemCategoryId);
        if (!$this->flags->isAvailabilityEnabledForItem($this->scopeFromContext($context), $context->sellableItemId, $itemCategoryId)) {
            return null;
        }

        return $this->calculateForItem($conn, $context->sellableItemId, $this->contextArray($context));
    }

    public function refreshForRecipe(mysqli $conn, int $recipeId, array $context = []): ?RecipeAvailabilityResult
    {
        $recipe = $this->recipes->findHeaderById($conn, $recipeId);
        if (!$recipe || (string) ($recipe['status'] ?? '') !== 'active') {
            return null;
        }
        $recipeContext = $this->contextForRecipe($recipe, $context);
        $orderContext = new RecipeOrderLineContext($recipeContext);
        $itemCategoryId = $this->itemCategoryId($conn, (int) $recipe['sellable_item_id'], $orderContext->itemCategoryId);
        if (!$this->flags->isAvailabilityEnabledForItem($this->scopeFromContext($orderContext), (int) $recipe['sellable_item_id'], $itemCategoryId)) {
            return null;
        }

        return $this->calculateForItem(
            $conn,
            (int) $recipe['sellable_item_id'],
            $recipeContext
        );
    }

    public function refreshForIngredient(mysqli $conn, int $ingredientItemId, array $context = []): array
    {
        if ($ingredientItemId < 1 || !$this->tableExists($conn, 'recipe_headers') || !$this->tableExists($conn, 'recipe_lines')) {
            return [];
        }

        $rows = [];
        foreach ($this->dependencies->recipeIdsAffectedByIngredient($conn, $ingredientItemId) as $recipeId) {
            $recipe = $this->recipes->findHeaderById($conn, (int) $recipeId);
            if (!$recipe || !$this->activeRecipeApplies($recipe, $context)) {
                continue;
            }
            $availability = $this->refreshForRecipe($conn, (int) $recipeId, $context);
            if ($availability !== null) {
                $rows[] = $availability;
            }
        }

        return $rows;
    }

    public function previewForRecipe(mysqli $conn, int $recipeId, array $context = []): RecipeAvailabilityResult
    {
        $recipe = $this->recipes->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        $orderContext = new RecipeOrderLineContext($this->contextForRecipe($recipe, $context));

        $manual = $this->manualAvailability($conn, $orderContext);
        if (!$manual['available']) {
            return new RecipeAvailabilityResult([
                'sellable_item_id' => (int) $recipe['sellable_item_id'],
                'recipe_id' => $recipeId,
                'computed_available_qty' => '0.000000',
                'effective_available_qty' => '0.000000',
                'effective_is_available' => false,
                'unavailable_reason' => $manual['reason'] ?: 'Manual unavailable.',
                'availability_revision' => 0,
            ]);
        }

        $explosion = $this->explosion->explodeRecipeById($conn, $recipeId, $orderContext, '1.000000');
        if (!$explosion->hasRecipe) {
            return new RecipeAvailabilityResult([
                'sellable_item_id' => (int) $recipe['sellable_item_id'],
                'recipe_id' => $recipeId,
                'computed_available_qty' => '0.000000',
                'effective_available_qty' => '0.000000',
                'effective_is_available' => false,
                'unavailable_reason' => 'Availability preview unavailable: ' . $explosion->fallbackMode,
                'availability_revision' => 0,
            ]);
        }

        return $this->availabilityFromExplosion(
            $conn,
            $orderContext,
            $explosion,
            RecipeDecimal::normalize($context['safety_stock'] ?? '0'),
            0
        );
    }

    public function getCachedForMenu(mysqli $conn, array $itemIds, array $context = []): array
    {
        $rows = [];
        foreach ($itemIds as $itemId) {
            $orderContext = new RecipeOrderLineContext(array_merge($context, [
                'sellable_item_id' => (int) $itemId,
            ]));
            $rows[$itemId] = $this->cache->findForItem(
                $conn,
                $orderContext->posTenant,
                $orderContext->posBranch,
                $orderContext->storeId,
                (int) $itemId,
                $orderContext->orderType,
                $orderContext->channel
            );
        }

        return $rows;
    }

    private function contextForRecipe(array $recipe, array $context = []): array
    {
        return array_merge([
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ], $context, [
            'pos_tenant' => (int) $recipe['pos_tenant'],
            'pos_branch' => (int) $recipe['pos_branch'],
            'branch_uuid' => $recipe['branch_uuid'] ?? null,
            'sellable_item_id' => (int) $recipe['sellable_item_id'],
            'quantity' => '1.000000',
        ]);
    }

    private function activeRecipeApplies(array $recipe, array $context = []): bool
    {
        if ((string) ($recipe['status'] ?? '') !== 'active') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if (!empty($recipe['effective_from']) && (string) $recipe['effective_from'] > $now) {
            return false;
        }
        if (!empty($recipe['effective_to']) && (string) $recipe['effective_to'] <= $now) {
            return false;
        }
        if (array_key_exists('pos_tenant', $context) && $context['pos_tenant'] !== null && $context['pos_tenant'] !== ''
            && (int) $context['pos_tenant'] !== (int) $recipe['pos_tenant']) {
            return false;
        }
        if (array_key_exists('pos_branch', $context) && $context['pos_branch'] !== null && $context['pos_branch'] !== ''
            && (int) $context['pos_branch'] !== (int) $recipe['pos_branch']) {
            return false;
        }

        return true;
    }

    private function cacheAndReturn(mysqli $conn, RecipeOrderLineContext $context, RecipeAvailabilityResult $result): RecipeAvailabilityResult
    {
        $this->cache->putAvailability($conn, [
            'pos_tenant' => $context->posTenant,
            'pos_branch' => $context->posBranch,
            'branch_uuid' => $context->branchUuid,
            'store_id' => $context->storeId,
            'sellable_item_id' => $result->sellableItemId,
            'recipe_id' => $result->recipeId,
            'order_type' => $context->orderType,
            'channel' => $context->channel,
            'computed_available_qty' => $result->computedAvailableQty,
            'effective_available_qty' => $result->effectiveAvailableQty,
            'effective_is_available' => $result->effectiveIsAvailable ? 1 : 0,
            'blocking_item_id' => $result->blockingItemId,
            'unavailable_reason' => $result->unavailableReason,
            'availability_revision' => $result->availabilityRevision,
            'calculated_at' => date('Y-m-d H:i:s'),
        ]);

        return $result;
    }

    private function availabilityFromExplosion(
        mysqli $conn,
        RecipeOrderLineContext $orderContext,
        RecipeExplosionResult $explosion,
        string $safetyStock,
        int $availabilityRevision
    ): RecipeAvailabilityResult {
        $makeable = null;
        $blockingItemId = null;
        foreach ($explosion->requirements as $requirement) {
            if (!$requirement->isRequired) {
                continue;
            }
            $balance = $this->balances->findBalance(
                $conn,
                $orderContext->posTenant,
                $orderContext->posBranch,
                $orderContext->storeId,
                $requirement->ingredientItemId
            ) ?: [
                'qty_on_hand' => '0.000000',
                'qty_reserved' => '0.000000',
            ];
            $available = RecipeDecimal::subtract($balance['qty_on_hand'], $balance['qty_reserved']);
            $available = RecipeDecimal::subtract($available, $safetyStock);
            $candidate = RecipeDecimal::floorDivideToInt($available, $requirement->requiredQtyBase);
            if ($makeable === null || $candidate < $makeable) {
                $makeable = $candidate;
                $blockingItemId = $requirement->ingredientItemId;
            }
        }

        $makeable = $makeable ?? 0;
        $availableQty = RecipeDecimal::normalize((string) $makeable);
        $isAvailable = $makeable > 0;
        $reason = $isAvailable ? null : 'Required ingredient out of stock.';

        return new RecipeAvailabilityResult([
            'sellable_item_id' => $orderContext->sellableItemId,
            'recipe_id' => $explosion->recipeId,
            'computed_available_qty' => $availableQty,
            'effective_available_qty' => $availableQty,
            'effective_is_available' => $isAvailable,
            'blocking_item_id' => $isAvailable ? null : $blockingItemId,
            'unavailable_reason' => $reason,
            'availability_revision' => $availabilityRevision,
        ]);
    }

    private function availabilityFromPreparedStock(
        mysqli $conn,
        RecipeOrderLineContext $orderContext,
        array $recipe,
        string $safetyStock,
        int $availabilityRevision
    ): RecipeAvailabilityResult {
        $balance = $this->balances->findBalance(
            $conn,
            $orderContext->posTenant,
            $orderContext->posBranch,
            $orderContext->storeId,
            $orderContext->sellableItemId
        ) ?: [
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
        ];
        $available = RecipeDecimal::subtract($balance['qty_on_hand'], $balance['qty_reserved']);
        $available = RecipeDecimal::subtract($available, $safetyStock);
        $makeable = RecipeDecimal::floorDivideToInt($available, '1.000000');
        $makeable = max(0, $makeable);
        $availableQty = RecipeDecimal::normalize((string) $makeable);
        $isAvailable = $makeable > 0;

        return new RecipeAvailabilityResult([
            'sellable_item_id' => $orderContext->sellableItemId,
            'recipe_id' => (int) $recipe['id'],
            'computed_available_qty' => $availableQty,
            'effective_available_qty' => $availableQty,
            'effective_is_available' => $isAvailable,
            'blocking_item_id' => $isAvailable ? null : $orderContext->sellableItemId,
            'unavailable_reason' => $isAvailable ? null : 'Prepared stock is out of stock.',
            'availability_revision' => $availabilityRevision,
        ]);
    }

    private function manualAvailability(mysqli $conn, RecipeOrderLineContext $context): array
    {
        if (!$this->tableExists($conn, 'item_availability')) {
            return ['available' => true, 'reason' => null];
        }

        $stmt = $conn->prepare("
SELECT is_available, unavailable_reason
FROM item_availability
WHERE item_id = ?
  AND tenant = ?
  AND branch = ?
  AND channel IN ('all', ?)
ORDER BY CASE WHEN channel = ? THEN 0 ELSE 1 END
LIMIT 1");
        $stmt->bind_param(
            'iiiss',
            $context->sellableItemId,
            $context->posTenant,
            $context->posBranch,
            $context->channel,
            $context->channel
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['available' => true, 'reason' => null];
        }

        return [
            'available' => (int) $row['is_available'] === 1,
            'reason' => $row['unavailable_reason'] ?? null,
        ];
    }

    private function nextRevision(mysqli $conn, RecipeOrderLineContext $context): int
    {
        $stmt = $conn->prepare("
SELECT COALESCE(MAX(availability_revision), 0) + 1 AS next_revision
FROM recipe_availability_cache
WHERE pos_tenant = ?
  AND pos_branch = ?");
        $stmt->bind_param('ii', $context->posTenant, $context->posBranch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['next_revision'] ?? 1);
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

    private function contextArray(RecipeOrderLineContext $context): array
    {
        return [
            'pos_tenant' => $context->posTenant,
            'pos_branch' => $context->posBranch,
            'branch_uuid' => $context->branchUuid,
            'store_id' => $context->storeId,
            'order_id' => $context->orderId,
            'fat_detail_id' => $context->fatDetailId,
            'order_line_uuid' => $context->orderLineUuid,
            'source_order_uuid' => $context->sourceOrderUuid,
            'source_line_uuid' => $context->sourceLineUuid,
            'source_event_uuid' => $context->sourceEventUuid,
            'sellable_item_id' => $context->sellableItemId,
            'item_category_id' => $context->itemCategoryId,
            'quantity' => $context->quantity,
            'unit_id' => $context->unitId,
            'variant_id' => $context->variantId,
            'modifiers' => $context->modifiers,
            'order_type' => $context->orderType,
            'channel' => $context->channel,
            'requested_at' => $context->requestedAt,
        ];
    }

    private function scopeFromContext(RecipeOrderLineContext $context): RecipeScope
    {
        return new RecipeScope(
            $context->posTenant,
            $context->posBranch,
            $context->branchUuid,
            $context->storeId,
            $context->channel,
            $context->orderType,
            'recipe'
        );
    }

    private function itemCategoryId(mysqli $conn, int $itemId, ?int $contextCategoryId = null): ?int
    {
        if ($contextCategoryId !== null && $contextCategoryId > 0) {
            return $contextCategoryId;
        }
        if ($itemId < 1) {
            return null;
        }

        $databaseRow = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();
        $database = (string) ($databaseRow['db_name'] ?? '');
        $cacheKey = $database . ':' . $itemId;
        if (array_key_exists($cacheKey, $this->itemCategoryCache)) {
            return $this->itemCategoryCache[$cacheKey];
        }
        if (!$this->columnExists($conn, 'myitems', 'group1')) {
            $this->itemCategoryCache[$cacheKey] = null;

            return null;
        }

        $stmt = $conn->prepare('SELECT group1 FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $categoryId = (int) ($row['group1'] ?? 0);
        $this->itemCategoryCache[$cacheKey] = $categoryId > 0 ? $categoryId : null;

        return $this->itemCategoryCache[$cacheKey];
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
