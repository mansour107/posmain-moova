<?php

require_once __DIR__ . '/ItemUnitConversion.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

final class ItemUnitCatalogLabel
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sortRowsSmallToLarge(array $rows): array
    {
        $sorted = array_values($rows);
        usort($sorted, static function (array $left, array $right): int {
            $leftRank = self::roleSortRank($left);
            $rightRank = self::roleSortRank($right);

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            if ($leftRank === 3) {
                $leftFactor = self::positiveFactor($left['u_val'] ?? 1);
                $rightFactor = self::positiveFactor($right['u_val'] ?? 1);
                if ($leftFactor !== $rightFactor) {
                    return $rightFactor <=> $leftFactor;
                }
            }

            $leftId = (int) ($left['id'] ?? 0);
            $rightId = (int) ($right['id'] ?? 0);
            if ($leftId !== $rightId) {
                return $leftId <=> $rightId;
            }

            return strcmp((string) ($left['uname'] ?? ''), (string) ($right['uname'] ?? ''));
        });

        return $sorted;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function stockRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['def_stock'] ?? 0) === 1) {
                return $row;
            }
        }

        if ($rows === []) {
            return null;
        }

        $sortedLargeFirst = self::sortRowsSmallToLarge($rows);

        return $sortedLargeFirst[count($sortedLargeFirst) - 1] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{row: array<string, mixed>, label: string, value: string}>
     */
    public static function buildSelectOptions(array $rows): array
    {
        $sorted = self::sortRowsSmallToLarge($rows);
        $options = [];
        $stockRow = self::stockRow($rows);

        foreach ($sorted as $row) {
            $options[] = [
                'row' => $row,
                'label' => self::formatSelectLabel($row, $stockRow),
                'value' => self::displayFactorValue($row),
            ];
        }

        return $options;
    }

    /**
     * Label one unit row against the stock unit, without mixing unrelated sell
     * and purchase factors. Example: كوباية (1 كرتونة = 12 كوباية).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $stockRow
     */
    public static function formatSelectLabel(array $row, ?array $stockRow): string
    {
        $name = trim((string) ($row['uname'] ?? ''));
        if ($name === '') {
            $name = 'الوحدة الأساسية';
        }

        $role = self::rowRole($row);
        if ($role === 'stock' || $stockRow === null) {
            return $name;
        }

        $stockName = trim((string) ($stockRow['uname'] ?? ''));
        if ($stockName === '') {
            $stockName = 'وحدة المخزون';
        }

        $stored = self::positiveDecimal($row['u_val'] ?? '1');
        $swapped = (int) ($row['conversion_swapped'] ?? 0) === 1;

        if ($role === 'sell') {
            if (!$swapped && RecipeDecimal::compare($stored, '1', 8) < 0) {
                $count = RecipeDecimal::divide('1', $stored, ItemUnitConversion::DISPLAY_SCALE);

                return $name . ' (1 ' . $stockName . ' = ' . self::formatDecimalCount($count) . ' ' . $name . ')';
            }

            if ($swapped) {
                return $name . ' (1 ' . $name . ' = ' . self::formatDecimalCount($stored) . ' ' . $stockName . ')';
            }

            return $name . ' (1 ' . $stockName . ' = ' . self::formatDecimalCount($stored) . ' ' . $name . ')';
        }

        if ($role === 'purchase') {
            if ($swapped) {
                return $name . ' (1 ' . $stockName . ' = ' . self::formatDecimalCount($stored) . ' ' . $name . ')';
            }

            return $name . ' (1 ' . $name . ' = ' . self::formatDecimalCount($stored) . ' ' . $stockName . ')';
        }

        return $name;
    }

    /**
     * Value used by catalog dropdowns to convert from base stock quantity to the
     * selected unit: displayed_qty = base_qty / value.
     */
    public static function displayFactorValue(array $row): string
    {
        $role = self::rowRole($row);
        if ($role === 'stock') {
            return '1';
        }

        if ($role === 'sell') {
            $stored = self::positiveDecimal($row['u_val'] ?? '1');
            $swapped = (int) ($row['conversion_swapped'] ?? 0) === 1;
            if (!$swapped && RecipeDecimal::compare($stored, '1', 8) < 0) {
                return self::formatFactorValue($stored, ItemUnitConversion::INVENTORY_SCALE);
            }

            return self::formatFactorValue(
                ItemUnitConversion::inventoryFactorDecimal($stored, $swapped, 'sell'),
                ItemUnitConversion::INVENTORY_SCALE
            );
        }

        if ($role === 'purchase') {
            return self::formatFactorValue(ItemUnitConversion::inventoryFactorDecimal(
                self::positiveDecimal($row['u_val'] ?? '1'),
                (int) ($row['conversion_swapped'] ?? 0) === 1,
                'purchase'
            ), ItemUnitConversion::INVENTORY_SCALE);
        }

        return self::formatFactorValue($row['u_val'] ?? '1', ItemUnitConversion::INVENTORY_SCALE);
    }

    /**
     * Decorate flat item-unit rows for JSON payloads used by inventory screens.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function decorateRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            $itemKey = (string) ($row['item_id'] ?? '__all__');
            $groups[$itemKey][] = $row + ['__catalog_index' => $index];
        }

        $decorated = $rows;
        foreach ($groups as $groupRows) {
            foreach (self::buildSelectOptions($groupRows) as $option) {
                $row = $option['row'];
                $index = (int) ($row['__catalog_index'] ?? -1);
                if ($index < 0 || !isset($decorated[$index])) {
                    continue;
                }

                $decorated[$index]['unit_label'] = $option['label'];
                $decorated[$index]['inventory_factor'] = $option['value'];
            }
        }

        return $decorated;
    }

    private static function rowRole(array $row): string
    {
        if ((int) ($row['def_stock'] ?? 0) === 1) {
            return 'stock';
        }

        if ((int) ($row['def_sale'] ?? 0) === 1) {
            return 'sell';
        }

        if ((int) ($row['def_buy'] ?? 0) === 1) {
            return 'purchase';
        }

        return 'other';
    }

    private static function roleSortRank(array $row): int
    {
        $role = self::rowRole($row);
        if ($role === 'stock') {
            return 0;
        }
        if ($role === 'sell') {
            return 1;
        }
        if ($role === 'purchase') {
            return 2;
        }

        return 3;
    }

    private static function positiveDecimal($value, int $scale = 8): string
    {
        $decimal = RecipeDecimal::normalize($value, $scale);
        if (RecipeDecimal::compare($decimal, '0', $scale) <= 0) {
            return RecipeDecimal::normalize('1', $scale);
        }

        return $decimal;
    }

    private static function formatDecimalCount($value): string
    {
        return self::formatFactorValue($value);
    }

    private static function positiveFactor($value): float
    {
        $factor = is_numeric($value) ? (float) $value : 1.0;
        if ($factor <= 0) {
            return 1.0;
        }

        return $factor;
    }

    public static function factorValue($value): string
    {
        return self::formatFactorValue($value);
    }

    private static function formatFactorValue($value, int $scale = ItemUnitConversion::DISPLAY_SCALE): string
    {
        $factor = self::positiveFactor($value);
        $formatted = number_format($factor, $scale, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '1' : $formatted;
    }

}
