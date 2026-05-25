<?php

require_once __DIR__ . '/Repository/RecipeRepositoryBase.php';

class RecipeDependencyResolverService extends RecipeRepositoryBase
{
    public function recipeIdsAffectedByIngredient(mysqli $conn, int $ingredientItemId, int $maxDepth = 20): array
    {
        if ($ingredientItemId < 1 || !$this->tableExists($conn, 'recipe_lines')) {
            return [];
        }

        $seen = [];
        $frontier = $this->directRecipeIdsForIngredient($conn, $ingredientItemId);
        foreach ($frontier as $recipeId) {
            $seen[$recipeId] = true;
        }

        $depth = 0;
        while ($frontier && $depth < $maxDepth) {
            $parents = $this->parentRecipeIdsForSubRecipes($conn, $frontier);
            $frontier = [];
            foreach ($parents as $recipeId) {
                if (isset($seen[$recipeId])) {
                    continue;
                }
                $seen[$recipeId] = true;
                $frontier[] = $recipeId;
            }
            $depth++;
        }

        $ids = array_map('intval', array_keys($seen));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function tableExists(mysqli $conn, string $table): bool
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

    private function directRecipeIdsForIngredient(mysqli $conn, int $ingredientItemId): array
    {
        $rows = $this->fetchAll(
            $conn,
            "
SELECT DISTINCT recipe_id
FROM recipe_lines
WHERE ingredient_item_id = ?
ORDER BY recipe_id",
            [$ingredientItemId]
        );

        return $this->idsFromRows($rows, 'recipe_id');
    }

    private function parentRecipeIdsForSubRecipes(mysqli $conn, array $recipeIds): array
    {
        $recipeIds = $this->normalisedIds($recipeIds);
        if (!$recipeIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($recipeIds), '?'));
        $rows = $this->fetchAll(
            $conn,
            "
SELECT DISTINCT recipe_id
FROM recipe_lines
WHERE line_type = 'sub_recipe'
  AND sub_recipe_id IN (" . $placeholders . ")
ORDER BY recipe_id",
            $recipeIds
        );

        return $this->idsFromRows($rows, 'recipe_id');
    }

    private function idsFromRows(array $rows, string $column): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$column] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $ids = array_map('intval', array_keys($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function normalisedIds(array $ids): array
    {
        $normalised = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalised[$id] = true;
            }
        }

        $ids = array_map('intval', array_keys($normalised));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
