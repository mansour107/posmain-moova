<?php

require_once __DIR__ . '/DTO/RecipeCostContext.php';
require_once __DIR__ . '/DTO/RecipeCostResult.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeExplosionService.php';
require_once __DIR__ . '/Repository/RecipeCostSnapshotRepository.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

class RecipeCostService
{
    private $recipes;
    private $snapshots;
    private $explosion;

    public function __construct(
        ?RecipeRepository $recipes = null,
        ?RecipeCostSnapshotRepository $snapshots = null,
        ?RecipeExplosionService $explosion = null
    ) {
        $this->recipes = $recipes ?: new RecipeRepository();
        $this->snapshots = $snapshots ?: new RecipeCostSnapshotRepository();
        $this->explosion = $explosion ?: new RecipeExplosionService();
    }

    public function calculateRecipeCost(mysqli $conn, int $recipeId, ?RecipeCostContext $context = null): RecipeCostResult
    {
        $context = $context ?: new RecipeCostContext();
        $recipe = $this->recipes->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        $method = $context->costingMethod ?: (string) ($recipe['costing_method'] ?? 'item_cost_price');
        $orderContext = $context->toOrderLineContext((int) $recipe['sellable_item_id'], (string) $recipe['yield_qty']);
        $explosion = $this->explosion->explodeRecipeById($conn, $recipeId, $orderContext, (string) $recipe['yield_qty']);

        $ingredientCosts = [];
        $costPerYield = RecipeDecimal::zero();
        foreach ($explosion->requirements as $requirement) {
            $unitCost = $this->costForItem($conn, $requirement->ingredientItemId, $method, $context);
            $totalCost = RecipeDecimal::multiply($requirement->requiredQtyBase, $unitCost);
            $costPerYield = RecipeDecimal::add($costPerYield, $totalCost);
            $ingredientCosts[] = [
                'ingredient_item_id' => $requirement->ingredientItemId,
                'source_recipe_line_id' => $requirement->sourceRecipeLineId,
                'required_qty_base' => $requirement->requiredQtyBase,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'costing_method' => $method,
            ];
        }

        $costPerSellUnit = RecipeDecimal::divide($costPerYield, (string) $recipe['yield_qty']);

        return new RecipeCostResult([
            'recipe_id' => (int) $recipe['id'],
            'sellable_item_id' => (int) $recipe['sellable_item_id'],
            'version_number' => (int) $recipe['version_number'],
            'cost_per_yield' => $costPerYield,
            'cost_per_sell_unit' => $costPerSellUnit,
            'ingredient_costs' => $ingredientCosts,
        ]);
    }

    public function createSnapshot(mysqli $conn, int $recipeId, ?RecipeCostContext $context = null): array
    {
        $context = $context ?: new RecipeCostContext();
        $recipe = $this->recipes->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        $cost = $this->calculateRecipeCost($conn, $recipeId, $context);
        $snapshotId = $this->snapshots->createSnapshot($conn, [
            'snapshot_uuid' => $this->uuid(),
            'pos_tenant' => (int) $recipe['pos_tenant'],
            'pos_branch' => (int) $recipe['pos_branch'],
            'branch_uuid' => $recipe['branch_uuid'] ?? null,
            'recipe_id' => $recipeId,
            'sellable_item_id' => (int) $recipe['sellable_item_id'],
            'version_number' => (int) $recipe['version_number'],
            'cost_per_yield' => $cost->costPerYield,
            'cost_per_sell_unit' => $cost->costPerSellUnit,
            'ingredient_cost_json' => json_encode($cost->ingredientCosts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'calculated_at' => $context->calculatedAt,
            'created_by' => $context->actorUserId,
        ]);

        return $this->snapshotById($conn, $snapshotId);
    }

    public function getLatestSnapshot(mysqli $conn, int $recipeId): ?array
    {
        return $this->snapshots->latestForRecipe($conn, $recipeId);
    }

    public function getOrCreateOrderSnapshot(mysqli $conn, int $recipeId, RecipeCostContext $context): array
    {
        $latest = $this->getLatestSnapshot($conn, $recipeId);
        if ($latest && $this->snapshotHasIngredientCostRows($latest)) {
            return $latest;
        }

        return $this->createSnapshot($conn, $recipeId, $context);
    }

    private function snapshotHasIngredientCostRows(array $snapshot): bool
    {
        $json = $snapshot['ingredient_cost_json'] ?? null;
        if ($json === null || trim((string) $json) === '') {
            return true;
        }

        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return false;
        }
        if (!$this->isListArray($decoded)) {
            return false;
        }

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                return false;
            }
            if (!array_key_exists('ingredient_item_id', $row) || !array_key_exists('unit_cost', $row)) {
                return false;
            }
        }

        return true;
    }

    private function isListArray(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    private function costForItem(mysqli $conn, int $itemId, string $method, RecipeCostContext $context): string
    {
        if ($method === 'manual_snapshot') {
            if (!array_key_exists($itemId, $context->manualCosts)) {
                return RecipeDecimal::zero();
            }

            return RecipeDecimal::normalize($context->manualCosts[$itemId]);
        }

        if ($method === 'moving_average') {
            $row = $this->fetchOne(
                $conn,
                "
SELECT moving_average_cost
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1",
                [$context->posTenant, $context->posBranch, $context->storeId, $itemId]
            );

            return RecipeDecimal::normalize($row['moving_average_cost'] ?? '0');
        }

        if ($method === 'last_purchase') {
            $row = $this->fetchOne(
                $conn,
                "
SELECT unit_cost
FROM inventory_movements
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
  AND movement_type = 'purchase'
  AND qty_in > 0
ORDER BY created_at DESC, id DESC
LIMIT 1",
                [$context->posTenant, $context->posBranch, $context->storeId, $itemId]
            );

            return RecipeDecimal::normalize($row['unit_cost'] ?? '0');
        }

        $row = $this->fetchOne($conn, 'SELECT cost_price FROM myitems WHERE id = ? LIMIT 1', [$itemId]);

        return RecipeDecimal::normalize($row['cost_price'] ?? '0');
    }

    private function snapshotById(mysqli $conn, int $snapshotId): array
    {
        $row = $this->fetchOne($conn, 'SELECT * FROM recipe_cost_snapshots WHERE id = ? LIMIT 1', [$snapshotId]);
        if (!$row) {
            throw new RuntimeException('Recipe cost snapshot was not created.');
        }

        return $row;
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
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
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
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
