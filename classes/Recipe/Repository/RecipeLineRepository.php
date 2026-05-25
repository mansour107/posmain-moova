<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class RecipeLineRepository extends RecipeRepositoryBase
{
    private const LINE_TYPES = ['ingredient', 'packaging', 'sub_recipe', 'modifier_ingredient', 'labor_placeholder'];
    private const MODIFIER_BEHAVIORS = ['additive', 'substitution_remove', 'substitution_add'];
    private const ORDER_TYPES = ['any', 'dine_in', 'takeaway', 'delivery'];
    private const CHANNELS = ['any', 'pos', 'table', 'moova', 'cofe', 'api'];

    public function createLine(mysqli $conn, array $data): int
    {
        $defaults = [
            'ingredient_item_id' => null,
            'sub_recipe_id' => null,
            'line_type' => 'ingredient',
            'ingredient_item_type_snapshot' => null,
            'unit_id' => null,
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.0000',
            'is_required' => 1,
            'modifier_group_id' => null,
            'modifier_option_id' => null,
            'modifier_behavior' => 'additive',
            'substitution_group' => null,
            'order_type' => 'any',
            'channel' => 'any',
            'sort_order' => 0,
            'notes' => null,
        ];

        $row = $this->normalizeLineRow(array_merge($defaults, $data));

        return $this->insertRow($conn, 'recipe_lines', $row);
    }

    public function findLinesByRecipeId(mysqli $conn, int $recipeId): array
    {
        return $this->fetchAll(
            $conn,
            'SELECT * FROM recipe_lines WHERE recipe_id = ? ORDER BY sort_order, id',
            [$recipeId]
        );
    }

    public function findLineById(mysqli $conn, int $lineId): ?array
    {
        return $this->fetchOne($conn, 'SELECT * FROM recipe_lines WHERE id = ? LIMIT 1', [$lineId]);
    }

    public function updateLine(mysqli $conn, int $lineId, array $data): int
    {
        if ($lineId < 1) {
            throw new InvalidArgumentException('Recipe line id must be positive.');
        }

        $allowed = [
            'ingredient_item_id',
            'sub_recipe_id',
            'line_type',
            'ingredient_item_type_snapshot',
            'qty_per_yield',
            'unit_id',
            'unit_conversion_to_base',
            'wastage_percent',
            'is_required',
            'modifier_group_id',
            'modifier_option_id',
            'modifier_behavior',
            'substitution_group',
            'order_type',
            'channel',
            'sort_order',
            'notes',
        ];
        $data = $this->normalizeLineUpdateData($data);

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

        $params[] = $lineId;
        return $this->executeStatement(
            $conn,
            'UPDATE recipe_lines SET ' . implode(', ', $updates) . ' WHERE id = ?',
            $params
        );
    }

    public function removeLine(mysqli $conn, int $lineId): int
    {
        if ($lineId < 1) {
            throw new InvalidArgumentException('Recipe line id must be positive.');
        }

        return $this->executeStatement($conn, 'DELETE FROM recipe_lines WHERE id = ?', [$lineId]);
    }

    private function normalizeLineRow(array $row): array
    {
        $row['line_uuid'] = $this->requiredTrimmed($row['line_uuid'] ?? '', 36, 'Recipe line UUID is required.');
        $row['line_type'] = strtolower(trim((string) ($row['line_type'] ?? '')));
        $row['ingredient_item_type_snapshot'] = $this->nullableTrimmed($row['ingredient_item_type_snapshot'] ?? null, 64, 'ingredient_item_type_snapshot');
        $row['modifier_behavior'] = strtolower(trim((string) ($row['modifier_behavior'] ?? '')));
        $row['substitution_group'] = $this->nullableTrimmed($row['substitution_group'] ?? null, 64, 'substitution_group');
        $row['order_type'] = strtolower(trim((string) ($row['order_type'] ?? '')));
        $row['channel'] = strtolower(trim((string) ($row['channel'] ?? '')));
        $row['is_required'] = $this->boolFlag($row['is_required'], 'is_required');
        $this->assertValidLineRow($row);
        $row['qty_per_yield'] = RecipeDecimal::normalize($row['qty_per_yield'], 6);
        $row['unit_conversion_to_base'] = RecipeDecimal::normalize($row['unit_conversion_to_base'], 8);
        $row['wastage_percent'] = RecipeDecimal::normalize($row['wastage_percent'], 4);

        return $row;
    }

    private function normalizeLineUpdateData(array $data): array
    {
        if (array_key_exists('line_type', $data)) {
            $data['line_type'] = strtolower(trim((string) $data['line_type']));
            $this->assertEnum($data['line_type'], self::LINE_TYPES, 'Recipe line line_type is invalid.');
        }
        foreach (['ingredient_item_id', 'sub_recipe_id', 'unit_id', 'modifier_group_id', 'modifier_option_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->assertOptionalPositiveInt($data, $field);
            }
        }
        if (array_key_exists('ingredient_item_type_snapshot', $data)) {
            $data['ingredient_item_type_snapshot'] = $this->nullableTrimmed($data['ingredient_item_type_snapshot'], 64, 'ingredient_item_type_snapshot');
        }
        if (array_key_exists('qty_per_yield', $data)) {
            $this->assertDecimal($data['qty_per_yield'], 'qty_per_yield');
            if (RecipeDecimal::compare($data['qty_per_yield'], '0') <= 0) {
                throw new InvalidArgumentException('Recipe line qty_per_yield must be positive.');
            }
            $data['qty_per_yield'] = RecipeDecimal::normalize($data['qty_per_yield'], 6);
        }
        if (array_key_exists('unit_conversion_to_base', $data)) {
            $this->assertDecimal($data['unit_conversion_to_base'], 'unit_conversion_to_base');
            if (RecipeDecimal::compare($data['unit_conversion_to_base'], '0', 8) <= 0) {
                throw new InvalidArgumentException('Recipe line unit_conversion_to_base must be positive.');
            }
            $data['unit_conversion_to_base'] = RecipeDecimal::normalize($data['unit_conversion_to_base'], 8);
        }
        if (array_key_exists('wastage_percent', $data)) {
            $this->assertDecimal($data['wastage_percent'], 'wastage_percent');
            if (RecipeDecimal::compare($data['wastage_percent'], '0', 4) < 0) {
                throw new InvalidArgumentException('Recipe line wastage_percent cannot be negative.');
            }
            $data['wastage_percent'] = RecipeDecimal::normalize($data['wastage_percent'], 4);
        }
        if (array_key_exists('is_required', $data)) {
            $data['is_required'] = $this->boolFlag($data['is_required'], 'is_required');
        }
        if (array_key_exists('modifier_behavior', $data)) {
            $data['modifier_behavior'] = strtolower(trim((string) $data['modifier_behavior']));
            $this->assertEnum($data['modifier_behavior'], self::MODIFIER_BEHAVIORS, 'Recipe line modifier_behavior is invalid.');
        }
        if (array_key_exists('substitution_group', $data)) {
            $data['substitution_group'] = $this->nullableTrimmed($data['substitution_group'], 64, 'substitution_group');
        }
        if (array_key_exists('order_type', $data)) {
            $data['order_type'] = strtolower(trim((string) $data['order_type']));
            $this->assertEnum($data['order_type'], self::ORDER_TYPES, 'Recipe line order_type is invalid.');
        }
        if (array_key_exists('channel', $data)) {
            $data['channel'] = strtolower(trim((string) $data['channel']));
            $this->assertEnum($data['channel'], self::CHANNELS, 'Recipe line channel is invalid.');
        }
        if (array_key_exists('sort_order', $data) && (int) $data['sort_order'] < 0) {
            throw new InvalidArgumentException('Recipe line sort_order cannot be negative.');
        }

        return $data;
    }

    private function assertValidLineRow(array $row): void
    {
        $this->assertEnum((string) ($row['line_type'] ?? ''), self::LINE_TYPES, 'Recipe line line_type is invalid.');
        $this->assertEnum((string) ($row['modifier_behavior'] ?? ''), self::MODIFIER_BEHAVIORS, 'Recipe line modifier_behavior is invalid.');
        $this->assertEnum((string) ($row['order_type'] ?? ''), self::ORDER_TYPES, 'Recipe line order_type is invalid.');
        $this->assertEnum((string) ($row['channel'] ?? ''), self::CHANNELS, 'Recipe line channel is invalid.');

        if ((int) ($row['recipe_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('Recipe line recipe_id must be positive.');
        }
        if ((int) ($row['sort_order'] ?? 0) < 0) {
            throw new InvalidArgumentException('Recipe line sort_order cannot be negative.');
        }
        foreach (['ingredient_item_id', 'sub_recipe_id', 'unit_id', 'modifier_group_id', 'modifier_option_id'] as $field) {
            $this->assertOptionalPositiveInt($row, $field);
        }

        $lineType = (string) $row['line_type'];
        if ($lineType === 'sub_recipe') {
            if ((int) ($row['sub_recipe_id'] ?? 0) < 1) {
                throw new InvalidArgumentException('Recipe line sub_recipe_id must be positive.');
            }
        } elseif ($lineType !== 'labor_placeholder' && (int) ($row['ingredient_item_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('Recipe line ingredient_item_id must be positive.');
        }

        if ($lineType !== 'modifier_ingredient' && (string) ($row['modifier_behavior'] ?? 'additive') !== 'additive') {
            throw new InvalidArgumentException('Recipe line modifier_behavior can only substitute modifier ingredients.');
        }

        $this->assertDecimal($row['qty_per_yield'] ?? null, 'qty_per_yield');
        if (RecipeDecimal::compare($row['qty_per_yield'], '0') <= 0) {
            throw new InvalidArgumentException('Recipe line qty_per_yield must be positive.');
        }
        $this->assertDecimal($row['unit_conversion_to_base'] ?? null, 'unit_conversion_to_base');
        if (RecipeDecimal::compare($row['unit_conversion_to_base'], '0', 8) <= 0) {
            throw new InvalidArgumentException('Recipe line unit_conversion_to_base must be positive.');
        }
        $this->assertDecimal($row['wastage_percent'] ?? null, 'wastage_percent');
        if (RecipeDecimal::compare($row['wastage_percent'], '0', 4) < 0) {
            throw new InvalidArgumentException('Recipe line wastage_percent cannot be negative.');
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
            throw new InvalidArgumentException('Recipe line ' . $field . ' must be positive when provided.');
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Recipe line ' . $field . ' must be a decimal value.');
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

        throw new InvalidArgumentException('Recipe line ' . $field . ' must be 0 or 1.');
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
            throw new InvalidArgumentException('Recipe line ' . $field . ' is too long.');
        }

        return $text;
    }
}
