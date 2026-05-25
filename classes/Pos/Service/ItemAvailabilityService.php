<?php

require_once __DIR__ . '/../../Recipe/RecipeAvailabilityService.php';
require_once __DIR__ . '/../../Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../Recipe/RecipeSettingsService.php';

class ItemAvailabilityService
{
    private ?RecipeFeatureFlags $recipeFlags;
    private ?RecipeAvailabilityService $recipeAvailabilityService;
    private RecipeSettingsService $recipeSettingsService;
    private array $itemCategoryCache = [];

    public function __construct(
        ?RecipeFeatureFlags $recipeFlags = null,
        ?RecipeAvailabilityService $recipeAvailabilityService = null,
        ?RecipeSettingsService $recipeSettingsService = null
    )
    {
        $this->recipeFlags = $recipeFlags ?: new RecipeFeatureFlags();
        $this->recipeAvailabilityService = $recipeAvailabilityService ?: new RecipeAvailabilityService($this->recipeFlags);
        $this->recipeSettingsService = $recipeSettingsService ?: new RecipeSettingsService(
            method_exists($this->recipeFlags, 'appConfig') ? $this->recipeFlags->appConfig() : null
        );
    }

    public function setAvailability(
        mysqli $conn,
        int $itemId,
        bool $isAvailable,
        array $scope = [],
        ?string $reason = null,
        ?int $updatedBy = null
    ): array {
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? $scope['pos_tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? $scope['pos_branch'] ?? 0, 'BRANCH_INVALID');
        $channel = $this->channel($scope['channel'] ?? 'all');
        $reason = $this->nullableText($reason, 255);

        $isAvailableInt = $isAvailable ? 1 : 0;
        $stmt = $conn->prepare("
            INSERT INTO item_availability (
                item_id, tenant, branch, channel, is_available,
                unavailable_reason, updated_by, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_available = VALUES(is_available),
                unavailable_reason = VALUES(unavailable_reason),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $stmt->bind_param('iiisisi', $itemId, $tenant, $branch, $channel, $isAvailableInt, $reason, $updatedBy);
        $stmt->execute();
        $stmt->close();

        return $this->availabilityForItem($conn, $itemId, [
            'tenant' => $tenant,
            'branch' => $branch,
            'channel' => $channel,
        ]);
    }

    public function availabilityForItem(mysqli $conn, int $itemId, array $scope = []): array
    {
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? $scope['pos_tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? $scope['pos_branch'] ?? 0, 'BRANCH_INVALID');
        $channel = $this->channel($scope['channel'] ?? 'all');

        $row = $this->fetchAvailabilityRow($conn, $itemId, $tenant, $branch, $channel)
            ?: $this->fetchAvailabilityRow($conn, $itemId, $tenant, $branch, 'all');

        if (!$row) {
            return $this->withCashierPresentation($this->withRecipeAvailability(
                $conn,
                $this->defaultAvailability($itemId, $tenant, $branch, $channel),
                $scope
            ));
        }

        return $this->withCashierPresentation($this->withRecipeAvailability($conn, $this->formatAvailability($row, $tenant, $branch, $channel), $scope));
    }

    public function assertSellable(mysqli $conn, int $itemId, array $scope = []): array
    {
        $availability = $this->availabilityForItem($conn, $itemId, $scope);
        $this->assertAvailabilityCanAdd($availability);

        return $availability;
    }

    public function assertAvailabilityCanAdd(array $availability): array
    {
        if (empty($availability['is_available']) && empty($availability['availability_can_add'])) {
            throw new RuntimeException('ITEM_UNAVAILABLE');
        }

        return $availability;
    }

    public function decorateItems(mysqli $conn, array $items, array $scope = []): array
    {
        if (!$items) {
            return [];
        }

        $decorated = [];
        $scope = $this->scopeWithItemCategories($scope, $items);
        $availabilityByItem = $this->availabilityForItems($conn, $this->itemIds($items), $scope);
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $availability = $availabilityByItem[$itemId] ?? $this->availabilityForItem($conn, $itemId, $scope);
            $item['is_available'] = $availability['is_available'] ? 1 : 0;
            $item['unavailable_reason'] = $availability['unavailable_reason'];
            $item['availability_channel'] = $availability['channel'];
            foreach ([
                'manual_is_available',
                'availability_status',
                'availability_can_add',
                'availability_low_stock',
                'availability_requires_manager_override',
                'availability_override_allowed',
                'availability_override_permission',
                'recipe_enabled',
                'recipe_id',
                'recipe_computed_available_qty',
                'recipe_effective_available_qty',
                'recipe_effective_is_available',
                'recipe_unavailable_reason',
                'recipe_availability_revision',
            ] as $recipeKey) {
                if (array_key_exists($recipeKey, $availability)) {
                    $item[$recipeKey] = $availability[$recipeKey];
                }
            }
            $decorated[] = $item;
        }

        return $decorated;
    }

    public function availabilityForItems(mysqli $conn, array $itemIds, array $scope = []): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), function ($id) {
            return $id > 0;
        })));

        if (!$itemIds) {
            return [];
        }

        $tenant = $this->nonNegativeInt($scope['tenant'] ?? $scope['pos_tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? $scope['pos_branch'] ?? 0, 'BRANCH_INVALID');
        $channel = $this->channel($scope['channel'] ?? 'all');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types = str_repeat('i', count($itemIds)) . 'iis';
        $params = array_merge($itemIds, [$tenant, $branch, $channel]);

        $stmt = $conn->prepare("
            SELECT ia.*
            FROM item_availability ia
            JOIN (
                SELECT item_id, MAX(CASE WHEN channel = ? THEN 2 ELSE 1 END) AS priority
                FROM item_availability
                WHERE item_id IN ({$placeholders})
                  AND tenant = ?
                  AND branch = ?
                  AND channel IN (?, 'all')
                GROUP BY item_id
            ) chosen
              ON chosen.item_id = ia.item_id
             AND chosen.priority = CASE WHEN ia.channel = ? THEN 2 ELSE 1 END
            WHERE ia.item_id IN ({$placeholders})
              AND ia.tenant = ?
              AND ia.branch = ?
              AND ia.channel IN (?, 'all')
        ");

        $queryParams = array_merge(
            [$channel],
            $itemIds,
            [$tenant, $branch, $channel, $channel],
            $itemIds,
            [$tenant, $branch, $channel]
        );
        $queryTypes = 's' . str_repeat('i', count($itemIds)) . 'iis' . 's' . str_repeat('i', count($itemIds)) . 'iis';
        $stmt->bind_param($queryTypes, ...$queryParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $availability = [];
        foreach ($itemIds as $itemId) {
            $availability[$itemId] = $this->defaultAvailability($itemId, $tenant, $branch, $channel);
        }

        while ($row = $result->fetch_assoc()) {
            $itemId = (int) $row['item_id'];
            $availability[$itemId] = $this->formatAvailability($row, $tenant, $branch, $channel);
        }
        $stmt->close();

        foreach ($availability as $itemId => $itemAvailability) {
            $availability[$itemId] = $this->withCashierPresentation($this->withRecipeAvailability($conn, $itemAvailability, $scope));
        }

        return $availability;
    }

    private function withCashierPresentation(array $availability): array
    {
        $availability['availability_can_add'] = (bool) ($availability['is_available'] ?? false);
        $availability['availability_low_stock'] = false;
        $availability['availability_requires_manager_override'] = false;
        $availability['availability_override_allowed'] = false;
        $availability['availability_override_permission'] = '';

        if (!$availability['availability_can_add']) {
            $manualAvailable = array_key_exists('manual_is_available', $availability)
                ? (bool) $availability['manual_is_available']
                : false;
            if ($manualAvailable && !empty($availability['recipe_enabled'])) {
                $overrideAllowed = !$this->recipeFlags->isStrictStockEnabled()
                    && $this->recipeSettingsService->allowNegativeStockWithApproval();
                $availability['availability_status'] = 'recipe_unavailable';
                $availability['availability_requires_manager_override'] = true;
                $availability['availability_override_allowed'] = $overrideAllowed;
                $availability['availability_can_add'] = $overrideAllowed;
                $availability['availability_override_permission'] = 'pos.recipe_stock_override';

                return $availability;
            }

            $availability['availability_status'] = 'manual_unavailable';

            return $availability;
        }

        if (!empty($availability['recipe_enabled'])) {
            $effectiveQty = (float) ($availability['recipe_effective_available_qty'] ?? 0);
            if ($effectiveQty > 0 && $effectiveQty <= 5) {
                $availability['availability_status'] = 'recipe_low';
                $availability['availability_low_stock'] = true;

                return $availability;
            }
        }

        $availability['availability_status'] = 'available';

        return $availability;
    }

    private function withRecipeAvailability(mysqli $conn, array $availability, array $scope): array
    {
        $itemId = (int) ($availability['item_id'] ?? 0);
        $tenant = (int) ($availability['tenant'] ?? $scope['tenant'] ?? $scope['pos_tenant'] ?? 0);
        $branch = (int) ($availability['branch'] ?? $scope['branch'] ?? $scope['pos_branch'] ?? 0);
        $channel = (string) ($availability['requested_channel'] ?? $availability['channel'] ?? $scope['channel'] ?? 'all');
        $orderType = (string) ($scope['order_type'] ?? 'takeaway');
        $storeId = (int) ($scope['store_id'] ?? 0);
        $itemCategoryId = $this->itemCategoryId($conn, $itemId, $this->itemCategoryIdFromScope($scope, $itemId));

        if (
            $itemId < 1
            || !$this->recipeFlags->isAvailabilityEnabledForItem(
                new RecipeScope($tenant, $branch, $scope['branch_uuid'] ?? null, $storeId, $channel, $orderType, 'recipe'),
                $itemId,
                $itemCategoryId
            )
        ) {
            return $availability;
        }

        $availability['manual_is_available'] = (bool) $availability['is_available'];
        if (!$availability['is_available']) {
            return $availability;
        }

        if (!$this->hasActiveRecipe($conn, $itemId, $tenant, $branch)) {
            return $availability;
        }

        $recipe = $this->recipeAvailabilityService->calculateForItem($conn, $itemId, array_merge($scope, [
            'pos_tenant' => $tenant,
            'pos_branch' => $branch,
            'store_id' => $storeId,
            'channel' => $channel,
            'order_type' => $orderType,
            'item_category_id' => $itemCategoryId,
        ]));

        $availability['recipe_enabled'] = true;
        $availability['recipe_id'] = $recipe->recipeId;
        $availability['recipe_computed_available_qty'] = $recipe->computedAvailableQty;
        $availability['recipe_effective_available_qty'] = $recipe->effectiveAvailableQty;
        $availability['recipe_effective_is_available'] = $recipe->effectiveIsAvailable;
        $availability['recipe_unavailable_reason'] = $recipe->unavailableReason;
        $availability['recipe_availability_revision'] = $recipe->availabilityRevision;

        if (!$recipe->effectiveIsAvailable) {
            $availability['is_available'] = false;
            $availability['unavailable_reason'] = $recipe->unavailableReason;
        }

        return $availability;
    }

    private function fetchAvailabilityRow(mysqli $conn, int $itemId, int $tenant, int $branch, string $channel): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM item_availability
            WHERE item_id = ?
              AND tenant = ?
              AND branch = ?
              AND channel = ?
            LIMIT 1
        ");
        $stmt->bind_param('iiis', $itemId, $tenant, $branch, $channel);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function formatAvailability(array $row, int $tenant, int $branch, string $requestedChannel): array
    {
        return [
            'item_id' => (int) $row['item_id'],
            'tenant' => $tenant,
            'branch' => $branch,
            'channel' => (string) $row['channel'],
            'requested_channel' => $requestedChannel,
            'is_available' => (int) $row['is_available'] === 1,
            'unavailable_reason' => $row['unavailable_reason'] !== null ? (string) $row['unavailable_reason'] : null,
            'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function defaultAvailability(int $itemId, int $tenant, int $branch, string $channel): array
    {
        return [
            'item_id' => $itemId,
            'tenant' => $tenant,
            'branch' => $branch,
            'channel' => $channel,
            'requested_channel' => $channel,
            'is_available' => true,
            'unavailable_reason' => null,
            'updated_by' => null,
            'updated_at' => null,
        ];
    }

    private function itemIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) ($item['id'] ?? $item['item_id'] ?? 0);
        }

        return $ids;
    }

    private function scopeWithItemCategories(array $scope, array $items): array
    {
        $map = is_array($scope['item_category_map'] ?? null) ? $scope['item_category_map'] : [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $categoryId = (int) (
                $item['item_category_id']
                ?? $item['sellable_item_category_id']
                ?? $item['category_id']
                ?? $item['group1']
                ?? 0
            );
            if ($itemId > 0 && $categoryId > 0) {
                $map[$itemId] = $categoryId;
            }
        }

        $scope['item_category_map'] = $map;

        return $scope;
    }

    private function itemCategoryIdFromScope(array $scope, int $itemId): ?int
    {
        $categoryId = (int) (
            $scope['item_category_id']
            ?? $scope['sellable_item_category_id']
            ?? $scope['category_id']
            ?? $scope['group1']
            ?? 0
        );
        if ($categoryId <= 0 && isset($scope['item_category_map']) && is_array($scope['item_category_map'])) {
            $categoryId = (int) ($scope['item_category_map'][$itemId] ?? 0);
        }

        return $categoryId > 0 ? $categoryId : null;
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

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function nonNegativeInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function channel($value): string
    {
        $channel = strtolower(trim((string) $value));
        if ($channel === '') {
            return 'all';
        }

        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $channel)) {
            throw new InvalidArgumentException('CHANNEL_INVALID');
        }

        return $channel;
    }

    private function nullableText(?string $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function hasActiveRecipe(mysqli $conn, int $itemId, int $tenant, int $branch): bool
    {
        if (!$this->tableExists($conn, 'recipe_headers')) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM recipe_headers
            WHERE sellable_item_id = ?
              AND pos_tenant = ?
              AND pos_branch = ?
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('iii', $itemId, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $row;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
    }
}
