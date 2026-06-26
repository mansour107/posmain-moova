<?php

require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeEditorItemCostService.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

/**
 * Recomputes sellable item cost (myitems.cost_price) for every active recipe that
 * consumes a given ingredient. Used to keep item master cost in sync when an
 * ingredient's own cost changes via a purchase receipt or inventory ledger movement,
 * so item cost no longer drifts silently between recipe-editor mutations.
 *
 * The resync reuses RecipeEditorItemCostService::applyAutoItemCosts, which already
 * skips items flagged manual_cost_edit = 1 (owner override) and writes the live
 * calculated cost_per_sell_unit to myitems.cost_price.
 */
class RecipeAffectedItemCostService
{
    private $itemCosts;

    public function __construct(?RecipeEditorItemCostService $itemCosts = null)
    {
        $this->itemCosts = $itemCosts ?: new RecipeEditorItemCostService();
    }

    /**
     * Resync item cost for every active recipe whose lines reference $ingredientItemId.
     *
     * Safe to call from purchase/ledger paths: no-op when recipes are disabled or the
     * recipe tables are missing, and any per-recipe failure is caught and logged so a
     * cost-resync problem can never break a purchase receipt.
     *
     * @return int Number of recipes resynchronized.
     */
    public function resyncItemsUsingIngredient(mysqli $conn, int $ingredientItemId): int
    {
        if ($ingredientItemId < 1) {
            return 0;
        }

        $flags = new RecipeFeatureFlags();
        if (!$flags->isEnabled()) {
            return 0;
        }

        if (!$this->tableExists($conn, 'recipe_headers') || !$this->tableExists($conn, 'recipe_lines')) {
            return 0;
        }

        $recipeIds = $this->activeRecipeIdsUsingIngredient($conn, $ingredientItemId);
        if ($recipeIds === []) {
            return 0;
        }

        $resynced = 0;
        foreach ($recipeIds as $recipeId) {
            try {
                $this->itemCosts->applyAutoItemCosts($conn, (int) $recipeId, $this->previewContextForRecipe($conn, (int) $recipeId));
                $resynced++;
            } catch (Throwable $exception) {
                error_log(sprintf(
                    '[recipe_cost_resync] failed recipe_id=%d ingredient_item_id=%d: %s',
                    (int) $recipeId,
                    $ingredientItemId,
                    $exception->getMessage()
                ));
            }
        }

        return $resynced;
    }

    /**
     * @return list<int>
     */
    private function activeRecipeIdsUsingIngredient(mysqli $conn, int $ingredientItemId): array
    {
        $stmt = $conn->prepare(
            "SELECT DISTINCT rl.recipe_id
             FROM recipe_lines rl
             INNER JOIN recipe_headers rh ON rh.id = rl.recipe_id
             WHERE rl.ingredient_item_id = ?
               AND rh.status = 'active'
               AND (rh.effective_from IS NULL OR rh.effective_from <= CURRENT_TIMESTAMP)
               AND (rh.effective_to IS NULL OR rh.effective_to > CURRENT_TIMESTAMP)"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $ingredientItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['recipe_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $stmt->close();

        return $ids;
    }

    private function previewContextForRecipe(mysqli $conn, int $recipeId): array
    {
        $recipe = (new RecipeRepository())->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            return [];
        }

        return [
            'pos_tenant' => (int) ($recipe['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($recipe['pos_branch'] ?? 0),
            'branch_uuid' => $recipe['branch_uuid'] ?? null,
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
            'costing_method' => (string) ($recipe['costing_method'] ?? 'item_cost_price'),
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $result = $conn->query(sprintf("SHOW TABLES LIKE '%s'", $conn->real_escape_string($table)));

        return $result && $result->num_rows > 0;
    }
}
