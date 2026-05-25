<?php

require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/DTO/RecipeScope.php';
require_once __DIR__ . '/Repository/RecipeAvailabilityCacheRepository.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

class RecipeSyncPayloadService
{
    private $flags;
    private $recipes;
    private $availabilityCache;

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?RecipeRepository $recipes = null,
        ?RecipeAvailabilityCacheRepository $availabilityCache = null
    )
    {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->recipes = $recipes ?: new RecipeRepository();
        $this->availabilityCache = $availabilityCache ?: new RecipeAvailabilityCacheRepository();
    }

    public function menuAvailabilityPayload(array $item, ?array $availability, ?array $recipe = null): array
    {
        $payload = [
            'item_id' => (int) ($item['id'] ?? $item['item_id'] ?? 0),
            'item_uuid' => $item['uuid'] ?? $item['item_uuid'] ?? null,
            'item_type' => $item['item_type'] ?? 'sellable',
            'track_stock' => (bool) ($item['track_stock'] ?? true),
            'recipe_enabled' => $recipe !== null,
            'active_recipe_version' => $recipe !== null ? (int) ($recipe['version_number'] ?? 0) : null,
            'computed_available_qty' => $availability['computed_available_qty'] ?? null,
            'effective_available_qty' => $availability['effective_available_qty'] ?? null,
            'effective_is_available' => isset($availability['effective_is_available'])
                ? ((int) $availability['effective_is_available'] === 1)
                : true,
            'unavailable_reason' => $availability['unavailable_reason'] ?? null,
            'availability_revision' => isset($availability['availability_revision'])
                ? (int) $availability['availability_revision']
                : null,
            'updated_at' => $availability['updated_at'] ?? $item['updated_at'] ?? $item['mdtime'] ?? null,
        ];

        if ($this->flags->canExposeCostsToPayload('internal_recipe_analytics')) {
            $payload['internal_cost_per_sell_unit'] = $recipe['cost_per_sell_unit'] ?? null;
        }

        return $payload;
    }

    public function menuItemSnapshotPayload(
        mysqli $conn,
        RecipeScope $scope,
        array $item,
        string $orderType = 'delivery',
        string $channel = 'moova'
    ): ?array {
        $itemId = (int) ($item['id'] ?? $item['item_id'] ?? $item['local_item_id'] ?? 0);
        if ($itemId <= 0) {
            return null;
        }

        if (
            !$this->flags->isMoovaSyncEnabled()
            || !$this->flags->isAvailabilityEnabledForItem($scope, $itemId, $this->itemCategoryId($item))
        ) {
            return null;
        }

        $recipe = $this->recipes->findActiveHeaderForItem($conn, $scope->posTenant, $scope->posBranch, $itemId);
        $availability = $this->availabilityCache->findBestForMenu(
            $conn,
            $scope->posTenant,
            $scope->posBranch,
            $scope->storeId,
            $itemId,
            $orderType,
            $channel
        );

        return $this->menuAvailabilityPayload($item, $availability, $recipe);
    }

    private function itemCategoryId(array $item): ?int
    {
        $categoryId = (int) (
            $item['item_category_id']
            ?? $item['sellable_item_category_id']
            ?? $item['category_id']
            ?? $item['group1']
            ?? 0
        );

        return $categoryId > 0 ? $categoryId : null;
    }
}
