<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class RecipeCostSnapshotRepository extends RecipeRepositoryBase
{
    public function createSnapshot(mysqli $conn, array $data): int
    {
        $defaults = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'cost_per_yield' => '0.000000',
            'cost_per_sell_unit' => '0.000000',
            'ingredient_cost_json' => null,
            'based_on_stock_cost_at' => null,
            'created_by' => null,
        ];

        $row = array_merge($defaults, $data);
        $this->assertValidSnapshot($row);
        $row['cost_per_yield'] = RecipeDecimal::normalize($row['cost_per_yield']);
        $row['cost_per_sell_unit'] = RecipeDecimal::normalize($row['cost_per_sell_unit']);

        return $this->insertRow($conn, 'recipe_cost_snapshots', $row);
    }

    public function latestForRecipe(mysqli $conn, int $recipeId): ?array
    {
        return $this->fetchOne(
            $conn,
            'SELECT * FROM recipe_cost_snapshots WHERE recipe_id = ? ORDER BY calculated_at DESC, id DESC LIMIT 1',
            [$recipeId]
        );
    }

    private function assertValidSnapshot(array $row): void
    {
        if (trim((string) ($row['snapshot_uuid'] ?? '')) === '') {
            throw new InvalidArgumentException('Recipe cost snapshot UUID is required.');
        }
        foreach (['pos_tenant', 'pos_branch'] as $field) {
            if ((int) ($row[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('Recipe cost snapshot ' . $field . ' cannot be negative.');
            }
        }
        foreach (['recipe_id', 'sellable_item_id', 'version_number'] as $field) {
            if ((int) ($row[$field] ?? 0) < 1) {
                throw new InvalidArgumentException('Recipe cost snapshot ' . $field . ' must be positive.');
            }
        }
        foreach (['created_by'] as $field) {
            if ($row[$field] !== null && (int) $row[$field] < 1) {
                throw new InvalidArgumentException('Recipe cost snapshot ' . $field . ' must be positive when provided.');
            }
        }
        foreach (['cost_per_yield', 'cost_per_sell_unit'] as $field) {
            $this->assertDecimal($row[$field] ?? null, $field);
            if (RecipeDecimal::compare($row[$field], '0') < 0) {
                throw new InvalidArgumentException('Recipe cost snapshot ' . $field . ' cannot be negative.');
            }
        }
        if (trim((string) ($row['calculated_at'] ?? '')) === '') {
            throw new InvalidArgumentException('Recipe cost snapshot calculated_at is required.');
        }
        if ($row['ingredient_cost_json'] !== null) {
            $json = (string) $row['ingredient_cost_json'];
            json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Recipe cost snapshot ingredient_cost_json must be valid JSON.');
            }
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Recipe cost snapshot ' . $field . ' must be a decimal value.');
        }
    }
}
