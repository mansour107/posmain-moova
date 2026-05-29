<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class RecipeVariantLineRepository extends RecipeRepositoryBase
{
    private const LINE_TYPES = ['ingredient', 'packaging', 'sub_recipe', 'labor_placeholder'];
    private const ORDER_TYPES = ['any', 'dine_in', 'takeaway', 'delivery'];
    private const CHANNELS = ['any', 'pos', 'table', 'moova', 'cofe', 'api'];

    public function findLinesForVariant(mysqli $conn, int $recipeId, int $variantItemId): array
    {
        if ($recipeId < 1 || $variantItemId < 1 || !$this->tableExists($conn)) {
            return [];
        }

        return $this->fetchAll(
            $conn,
            'SELECT * FROM recipe_variant_lines WHERE recipe_id = ? AND variant_item_id = ? ORDER BY sort_order, id',
            [$recipeId, $variantItemId]
        );
    }

    public function findLinesGroupedByRecipe(mysqli $conn, int $recipeId): array
    {
        if ($recipeId < 1 || !$this->tableExists($conn)) {
            return [];
        }

        $rows = $this->fetchAll(
            $conn,
            'SELECT * FROM recipe_variant_lines WHERE recipe_id = ? ORDER BY variant_item_id, sort_order, id',
            [$recipeId]
        );
        $grouped = [];
        foreach ($rows as $row) {
            $variantItemId = (int) ($row['variant_item_id'] ?? 0);
            if ($variantItemId < 1) {
                continue;
            }
            if (!isset($grouped[$variantItemId])) {
                $grouped[$variantItemId] = [];
            }
            $grouped[$variantItemId][] = $row;
        }

        return $grouped;
    }

    public function replaceLinesForVariant(mysqli $conn, int $recipeId, int $variantItemId, array $lines): void
    {
        if ($recipeId < 1) {
            throw new InvalidArgumentException('Recipe id is required.');
        }
        if ($variantItemId < 1) {
            throw new InvalidArgumentException('Variation is required.');
        }

        $this->executeStatement(
            $conn,
            'DELETE FROM recipe_variant_lines WHERE recipe_id = ? AND variant_item_id = ?',
            [$recipeId, $variantItemId]
        );

        foreach (array_values($lines) as $index => $line) {
            $this->createLine($conn, array_merge($line, [
                'recipe_id' => $recipeId,
                'variant_item_id' => $variantItemId,
                'sort_order' => array_key_exists('sort_order', $line) ? $line['sort_order'] : $index + 1,
            ]));
        }
    }

    public function createLine(mysqli $conn, array $data): int
    {
        $defaults = [
            'base_line_id' => null,
            'ingredient_item_id' => null,
            'sub_recipe_id' => null,
            'line_type' => 'ingredient',
            'ingredient_item_type_snapshot' => null,
            'unit_id' => null,
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.0000',
            'is_required' => 1,
            'order_type' => 'any',
            'channel' => 'any',
            'sort_order' => 0,
            'notes' => null,
        ];

        $row = $this->normalizeLineRow(array_merge($defaults, $data));

        return $this->insertRow($conn, 'recipe_variant_lines', $row);
    }

    private function normalizeLineRow(array $row): array
    {
        $row['line_uuid'] = $this->requiredTrimmed($row['line_uuid'] ?? '', 36, 'Variation recipe line UUID is required.');
        $row['line_type'] = strtolower(trim((string) ($row['line_type'] ?? '')));
        $row['ingredient_item_type_snapshot'] = $this->nullableTrimmed($row['ingredient_item_type_snapshot'] ?? null, 64);
        $row['order_type'] = strtolower(trim((string) ($row['order_type'] ?? '')));
        $row['channel'] = strtolower(trim((string) ($row['channel'] ?? '')));
        $row['is_required'] = $this->boolFlag($row['is_required'] ?? 1);
        $this->assertValidLineRow($row);
        $row['qty_per_yield'] = RecipeDecimal::normalize($row['qty_per_yield'], 6);
        $row['unit_conversion_to_base'] = RecipeDecimal::normalize($row['unit_conversion_to_base'], 8);
        $row['wastage_percent'] = RecipeDecimal::normalize($row['wastage_percent'], 4);

        return $row;
    }

    private function assertValidLineRow(array $row): void
    {
        foreach (['recipe_id', 'variant_item_id'] as $field) {
            if ((int) ($row[$field] ?? 0) < 1) {
                throw new InvalidArgumentException('Variation recipe ' . $field . ' must be positive.');
            }
        }
        foreach (['base_line_id', 'ingredient_item_id', 'sub_recipe_id', 'unit_id'] as $field) {
            $this->assertOptionalPositiveInt($row, $field);
        }
        if ((int) ($row['sort_order'] ?? 0) < 0) {
            throw new InvalidArgumentException('Variation recipe sort order cannot be negative.');
        }

        $this->assertEnum((string) ($row['line_type'] ?? ''), self::LINE_TYPES, 'Variation recipe line type is invalid.');
        $this->assertEnum((string) ($row['order_type'] ?? ''), self::ORDER_TYPES, 'Variation recipe order type is invalid.');
        $this->assertEnum((string) ($row['channel'] ?? ''), self::CHANNELS, 'Variation recipe channel is invalid.');

        $lineType = (string) $row['line_type'];
        if ($lineType === 'sub_recipe') {
            if ((int) ($row['sub_recipe_id'] ?? 0) < 1) {
                throw new InvalidArgumentException('Variation recipe component is required.');
            }
        } elseif ($lineType !== 'labor_placeholder' && (int) ($row['ingredient_item_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('Variation recipe component is required.');
        }

        $this->assertDecimal($row['qty_per_yield'] ?? null, 'qty_per_yield');
        if (RecipeDecimal::compare($row['qty_per_yield'], '0') <= 0) {
            throw new InvalidArgumentException('Variation recipe amount must be positive.');
        }
        $this->assertDecimal($row['unit_conversion_to_base'] ?? null, 'unit_conversion_to_base');
        if (RecipeDecimal::compare($row['unit_conversion_to_base'], '0', 8) <= 0) {
            throw new InvalidArgumentException('Variation recipe unit conversion must be positive.');
        }
        $this->assertDecimal($row['wastage_percent'] ?? null, 'wastage_percent');
        if (RecipeDecimal::compare($row['wastage_percent'], '0', 4) < 0) {
            throw new InvalidArgumentException('Variation recipe waste cannot be negative.');
        }
    }

    private function assertOptionalPositiveInt(array $row, string $field): void
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return;
        }
        if ((int) $row[$field] < 1) {
            throw new InvalidArgumentException('Variation recipe ' . $field . ' must be positive when provided.');
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Variation recipe ' . $field . ' must be a decimal value.');
        }
    }

    private function assertEnum(string $value, array $allowed, string $message): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
    }

    private function requiredTrimmed($value, int $maxLength, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > $maxLength) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function nullableTrimmed($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $maxLength) {
            throw new InvalidArgumentException('Variation recipe text is too long.');
        }

        return $text;
    }

    private function boolFlag($value): int
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;
    }

    private function tableExists(mysqli $conn): bool
    {
        $row = $this->fetchOne(
            $conn,
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['recipe_variant_lines']
        );

        return (int) ($row['c'] ?? 0) > 0;
    }
}
