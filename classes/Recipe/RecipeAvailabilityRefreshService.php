<?php

require_once __DIR__ . '/RecipeAvailabilityService.php';
require_once __DIR__ . '/RecipeDependencyResolverService.php';
require_once __DIR__ . '/RecipeScopeResolver.php';

class RecipeAvailabilityRefreshService
{
    private RecipeAvailabilityService $availability;
    private RecipeDependencyResolverService $dependencies;

    public function __construct(
        ?RecipeAvailabilityService $availability = null,
        ?RecipeDependencyResolverService $dependencies = null
    ) {
        $this->availability = $availability ?: new RecipeAvailabilityService();
        $this->dependencies = $dependencies ?: new RecipeDependencyResolverService();
    }

    public function run(mysqli $conn, array $options = []): array
    {
        $targets = $this->targets($conn, $options);
        $apply = !empty($options['apply']);
        $scopeContext = [
            'store_id' => (int) ($options['store_id'] ?? 0),
            'order_type' => $this->orderType($options['order_type'] ?? 'takeaway'),
            'channel' => $this->channel($options['channel'] ?? 'pos'),
        ];
        if (isset($options['pos_tenant'])) {
            $scopeContext['pos_tenant'] = (int) $options['pos_tenant'];
        }
        if (isset($options['pos_branch'])) {
            $scopeContext['pos_branch'] = (int) $options['pos_branch'];
        }
        $scope = (new RecipeScopeResolver())->resolveForConn($conn, $scopeContext, 'read');
        $context = [
            'store_id' => $scope->storeId,
            'order_type' => $scope->orderType,
            'channel' => $scope->channel,
        ];

        $results = [];
        if ($apply) {
            foreach ($targets as $target) {
                $availability = $this->availability->refreshForRecipe($conn, (int) $target['recipe_id'], $context);
                $results[] = [
                    'recipe_id' => (int) $target['recipe_id'],
                    'sellable_item_id' => (int) $target['sellable_item_id'],
                    'recipe_name' => (string) ($target['recipe_name'] ?? ''),
                    'refreshed' => $availability !== null,
                    'availability' => $availability ? $availability->toArray() : null,
                ];
            }
        }

        return [
            'ok' => true,
            'applied' => $apply,
            'context' => $context,
            'targets_count' => count($targets),
            'refreshed_count' => count(array_filter($results, static function (array $row): bool {
                return !empty($row['refreshed']);
            })),
            'targets' => $targets,
            'results' => $results,
        ];
    }

    public function targets(mysqli $conn, array $options = []): array
    {
        if (!$this->tableExists($conn, 'recipe_headers')) {
            return [];
        }

        $conditions = [
            "rh.status = 'active'",
            '(rh.effective_from IS NULL OR rh.effective_from <= CURRENT_TIMESTAMP)',
            '(rh.effective_to IS NULL OR rh.effective_to > CURRENT_TIMESTAMP)',
        ];
        $params = [];

        $ingredientId = (int) ($options['ingredient_id'] ?? 0);
        $recipeId = (int) ($options['recipe_id'] ?? 0);
        $itemId = (int) ($options['sellable_item_id'] ?? $options['item_id'] ?? 0);

        if ($ingredientId > 0) {
            $affectedRecipeIds = $this->dependencies->recipeIdsAffectedByIngredient($conn, $ingredientId);
            if (!$affectedRecipeIds) {
                return [];
            }
            $conditions[] = 'rh.id IN (' . implode(', ', array_fill(0, count($affectedRecipeIds), '?')) . ')';
            foreach ($affectedRecipeIds as $recipeId) {
                $params[] = (int) $recipeId;
            }
        } elseif ($recipeId > 0) {
            $conditions[] = 'rh.id = ?';
            $params[] = $recipeId;
        } elseif ($itemId > 0) {
            $conditions[] = 'rh.sellable_item_id = ?';
            $params[] = $itemId;
        } elseif (empty($options['all_active'])) {
            throw new InvalidArgumentException('Choose --ingredient-id, --recipe-id, --item-id, or --all-active.');
        }

        foreach (['pos_tenant', 'pos_branch'] as $column) {
            if (isset($options[$column]) && $options[$column] !== '' && (int) $options[$column] >= 0) {
                $conditions[] = 'rh.' . $column . ' = ?';
                $params[] = (int) $options[$column];
            }
        }

        $limit = max(1, min(5000, (int) ($options['limit'] ?? 500)));

        return $this->fetchAll(
            $conn,
            "
SELECT
  rh.id AS recipe_id,
  rh.pos_tenant,
  rh.pos_branch,
  rh.branch_uuid,
  rh.sellable_item_id,
  rh.recipe_name,
  rh.recipe_type,
  rh.version_number,
  rh.updated_at
FROM recipe_headers rh
WHERE " . implode(' AND ', $conditions) . "
ORDER BY rh.pos_tenant, rh.pos_branch, rh.sellable_item_id, rh.version_number DESC
LIMIT " . $limit,
            $params
        );
    }

    private function orderType($value): string
    {
        $type = strtolower(trim((string) $value));

        return in_array($type, ['any', 'dine_in', 'takeaway', 'delivery'], true) ? $type : 'takeaway';
    }

    private function channel($value): string
    {
        $channel = strtolower(trim((string) $value));

        return in_array($channel, ['any', 'pos', 'table', 'moova', 'cofe', 'api'], true) ? $channel : 'pos';
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $row = $this->fetchOne(
            $conn,
            "
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?",
            [$table]
        );

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($conn, $sql, $params);

        return $rows[0] ?? null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        if ($params) {
            $types = '';
            foreach ($params as $value) {
                $types .= is_int($value) ? 'i' : 's';
            }
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
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
