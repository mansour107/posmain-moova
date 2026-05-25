<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class RecipeRepository extends RecipeRepositoryBase
{
    private const RECIPE_TYPES = ['make_to_order', 'batch_prepared', 'hybrid', 'packaging_bundle', 'modifier_only', 'sub_recipe'];
    private const STATUSES = ['draft', 'active', 'archived'];
    private const COSTING_METHODS = ['item_cost_price', 'moving_average', 'last_purchase', 'manual_snapshot'];

    public function createHeader(mysqli $conn, array $data): int
    {
        $defaults = [
            'recipe_uuid' => '',
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'recipe_type' => 'make_to_order',
            'status' => 'draft',
            'version_number' => 1,
            'yield_qty' => '1.000000',
            'yield_unit_id' => null,
            'default_wastage_percent' => '0.0000',
            'effective_from' => null,
            'effective_to' => null,
            'costing_method' => 'item_cost_price',
            'requires_recipe_for_sale' => 0,
            'allow_sale_without_stock' => 0,
            'created_by' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];

        $row = $this->normalizeHeaderRow(array_merge($defaults, $data));

        return $this->insertRow($conn, 'recipe_headers', $row);
    }

    public function findHeaderById(mysqli $conn, int $recipeId): ?array
    {
        return $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE id = ? LIMIT 1', [$recipeId]);
    }

    public function findHeaderByIdForUpdate(mysqli $conn, int $recipeId): ?array
    {
        return $this->fetchOne($conn, 'SELECT * FROM recipe_headers WHERE id = ? LIMIT 1 FOR UPDATE', [$recipeId]);
    }

    public function findActiveHeaderForItem(mysqli $conn, int $posTenant, int $posBranch, int $itemId): ?array
    {
        return $this->fetchOne(
            $conn,
            "
SELECT *
FROM recipe_headers
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND sellable_item_id = ?
  AND status = 'active'
  AND (effective_from IS NULL OR effective_from <= CURRENT_TIMESTAMP)
  AND (effective_to IS NULL OR effective_to > CURRENT_TIMESTAMP)
ORDER BY version_number DESC
LIMIT 1",
            [$posTenant, $posBranch, $itemId]
        );
    }

    public function updateStatus(mysqli $conn, int $recipeId, string $status, ?int $approvedBy = null): int
    {
        if ($recipeId < 1) {
            throw new InvalidArgumentException('Recipe header id must be positive.');
        }
        $status = strtolower(trim($status));
        $this->assertEnum($status, self::STATUSES, 'Recipe header status is invalid.');
        if ($approvedBy !== null && $approvedBy < 1) {
            throw new InvalidArgumentException('Recipe header approved_by must be positive when provided.');
        }

        return $this->executeStatement(
            $conn,
            "
UPDATE recipe_headers
SET status = ?,
    approved_by = COALESCE(?, approved_by),
    approved_at = CASE WHEN ? = 'active' THEN COALESCE(approved_at, CURRENT_TIMESTAMP) ELSE approved_at END
WHERE id = ?",
            [$status, $approvedBy, $status, $recipeId]
        );
    }

    public function updateDraft(mysqli $conn, int $recipeId, array $data): int
    {
        if ($recipeId < 1) {
            throw new InvalidArgumentException('Recipe header id must be positive.');
        }

        $allowed = [
            'recipe_name',
            'recipe_type',
            'yield_qty',
            'yield_unit_id',
            'default_wastage_percent',
            'effective_from',
            'effective_to',
            'costing_method',
            'requires_recipe_for_sale',
            'allow_sale_without_stock',
        ];
        $data = $this->normalizeDraftUpdateData($data);

        $updates = [];
        $params = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $updates[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $data[$column];
        }

        if (!$updates) {
            return 0;
        }

        $params[] = $recipeId;
        return $this->executeStatement(
            $conn,
            'UPDATE recipe_headers SET ' . implode(', ', $updates) . ' WHERE id = ? AND status = \'draft\'',
            $params
        );
    }

    public function archiveActiveForItem(mysqli $conn, int $posTenant, int $posBranch, int $sellableItemId, int $exceptRecipeId): int
    {
        if ($posTenant < 0 || $posBranch < 0) {
            throw new InvalidArgumentException('Recipe header scope cannot be negative.');
        }
        foreach (['sellable_item_id' => $sellableItemId, 'except_recipe_id' => $exceptRecipeId] as $field => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Recipe header ' . $field . ' must be positive.');
            }
        }

        return $this->executeStatement(
            $conn,
            "
UPDATE recipe_headers
SET status = 'archived',
    effective_to = COALESCE(effective_to, CURRENT_TIMESTAMP)
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND sellable_item_id = ?
  AND status = 'active'
  AND id <> ?",
            [$posTenant, $posBranch, $sellableItemId, $exceptRecipeId]
        );
    }

    public function maxVersionForItem(mysqli $conn, int $posTenant, int $posBranch, int $sellableItemId): int
    {
        if ($posTenant < 0 || $posBranch < 0) {
            throw new InvalidArgumentException('Recipe header scope cannot be negative.');
        }
        if ($sellableItemId < 1) {
            throw new InvalidArgumentException('Recipe header sellable_item_id must be positive.');
        }

        $row = $this->fetchOne(
            $conn,
            "
SELECT COALESCE(MAX(version_number), 0) AS max_version
FROM recipe_headers
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND sellable_item_id = ?",
            [$posTenant, $posBranch, $sellableItemId]
        );

        return (int) ($row['max_version'] ?? 0);
    }

    private function normalizeHeaderRow(array $row): array
    {
        $row['recipe_uuid'] = $this->requiredTrimmed($row['recipe_uuid'] ?? '', 36, 'Recipe header UUID is required.');
        $row['branch_uuid'] = $this->nullableTrimmed($row['branch_uuid'] ?? null, 36, 'branch_uuid');
        $row['recipe_name'] = $this->requiredTrimmed($row['recipe_name'] ?? '', 255, 'Recipe header name is required.');
        $row['recipe_type'] = strtolower(trim((string) ($row['recipe_type'] ?? '')));
        $row['status'] = strtolower(trim((string) ($row['status'] ?? '')));
        $row['costing_method'] = strtolower(trim((string) ($row['costing_method'] ?? '')));
        $this->assertValidHeaderRow($row);
        $row['yield_qty'] = RecipeDecimal::normalize($row['yield_qty'], 6);
        $row['default_wastage_percent'] = RecipeDecimal::normalize($row['default_wastage_percent'], 4);
        $row['requires_recipe_for_sale'] = $this->boolFlag($row['requires_recipe_for_sale'], 'requires_recipe_for_sale');
        $row['allow_sale_without_stock'] = $this->boolFlag($row['allow_sale_without_stock'], 'allow_sale_without_stock');

        return $row;
    }

    private function normalizeDraftUpdateData(array $data): array
    {
        if (array_key_exists('recipe_name', $data)) {
            $data['recipe_name'] = $this->requiredTrimmed($data['recipe_name'], 255, 'Recipe header name is required.');
        }
        if (array_key_exists('recipe_type', $data)) {
            $data['recipe_type'] = strtolower(trim((string) $data['recipe_type']));
            $this->assertEnum($data['recipe_type'], self::RECIPE_TYPES, 'Recipe header recipe_type is invalid.');
        }
        if (array_key_exists('yield_qty', $data)) {
            $this->assertDecimal($data['yield_qty'], 'yield_qty');
            if (RecipeDecimal::compare($data['yield_qty'], '0') <= 0) {
                throw new InvalidArgumentException('Recipe header yield_qty must be positive.');
            }
            $data['yield_qty'] = RecipeDecimal::normalize($data['yield_qty'], 6);
        }
        if (array_key_exists('yield_unit_id', $data)) {
            $this->assertOptionalPositiveInt($data, 'yield_unit_id');
        }
        if (array_key_exists('default_wastage_percent', $data)) {
            $this->assertDecimal($data['default_wastage_percent'], 'default_wastage_percent');
            if (RecipeDecimal::compare($data['default_wastage_percent'], '0', 4) < 0) {
                throw new InvalidArgumentException('Recipe header default_wastage_percent cannot be negative.');
            }
            $data['default_wastage_percent'] = RecipeDecimal::normalize($data['default_wastage_percent'], 4);
        }
        if (array_key_exists('costing_method', $data)) {
            $data['costing_method'] = strtolower(trim((string) $data['costing_method']));
            $this->assertEnum($data['costing_method'], self::COSTING_METHODS, 'Recipe header costing_method is invalid.');
        }
        foreach (['requires_recipe_for_sale', 'allow_sale_without_stock'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->boolFlag($data[$field], $field);
            }
        }

        return $data;
    }

    private function assertValidHeaderRow(array $row): void
    {
        foreach (['pos_tenant', 'pos_branch'] as $field) {
            if ((int) ($row[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('Recipe header ' . $field . ' cannot be negative.');
            }
        }
        foreach (['sellable_item_id', 'version_number'] as $field) {
            if ((int) ($row[$field] ?? 0) < 1) {
                throw new InvalidArgumentException('Recipe header ' . $field . ' must be positive.');
            }
        }
        foreach (['yield_unit_id', 'created_by', 'approved_by'] as $field) {
            $this->assertOptionalPositiveInt($row, $field);
        }
        $this->assertEnum((string) ($row['recipe_type'] ?? ''), self::RECIPE_TYPES, 'Recipe header recipe_type is invalid.');
        $this->assertEnum((string) ($row['status'] ?? ''), self::STATUSES, 'Recipe header status is invalid.');
        $this->assertEnum((string) ($row['costing_method'] ?? ''), self::COSTING_METHODS, 'Recipe header costing_method is invalid.');
        $this->assertDecimal($row['yield_qty'] ?? null, 'yield_qty');
        if (RecipeDecimal::compare($row['yield_qty'], '0') <= 0) {
            throw new InvalidArgumentException('Recipe header yield_qty must be positive.');
        }
        $this->assertDecimal($row['default_wastage_percent'] ?? null, 'default_wastage_percent');
        if (RecipeDecimal::compare($row['default_wastage_percent'], '0', 4) < 0) {
            throw new InvalidArgumentException('Recipe header default_wastage_percent cannot be negative.');
        }
    }

    private function assertEnum(string $value, array $allowed, string $message): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
    }

    private function assertOptionalPositiveInt(array $row, string $field): void
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return;
        }
        if ((int) $row[$field] < 1) {
            throw new InvalidArgumentException('Recipe header ' . $field . ' must be positive when provided.');
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Recipe header ' . $field . ' must be a decimal value.');
        }
    }

    private function boolFlag($value, string $field): int
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return 0;
        }

        throw new InvalidArgumentException('Recipe header ' . $field . ' must be 0 or 1.');
    }

    private function requiredTrimmed($value, int $maxLength, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($message);
        }
        if (strlen($text) > $maxLength) {
            throw new InvalidArgumentException(str_replace(' is required.', ' is too long.', $message));
        }

        return $text;
    }

    private function nullableTrimmed($value, int $maxLength, string $field): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $maxLength) {
            throw new InvalidArgumentException('Recipe header ' . $field . ' is too long.');
        }

        return $text;
    }
}
