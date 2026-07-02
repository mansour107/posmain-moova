<?php

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
            $leftFactor = self::positiveFactor($left['u_val'] ?? 1);
            $rightFactor = self::positiveFactor($right['u_val'] ?? 1);

            if ($leftFactor !== $rightFactor) {
                return $rightFactor <=> $leftFactor;
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

        foreach ($sorted as $index => $row) {
            $smallerRow = $index > 0 ? $sorted[$index - 1] : null;
            $options[] = [
                'row' => $row,
                'label' => self::formatSelectLabel($row, $smallerRow),
                'value' => self::formatFactorValue($row['u_val'] ?? 1),
            ];
        }

        return $options;
    }

  /**
     * Large unit × count smaller unit, e.g. كرتونة × 12 كوباية
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $smallerRow
     */
    public static function formatSelectLabel(array $row, ?array $smallerRow): string
    {
        $name = trim((string) ($row['uname'] ?? ''));
        if ($name === '') {
            $name = 'الوحدة الأساسية';
        }

        if ($smallerRow === null) {
            return $name;
        }

        $largerFactor = self::positiveFactor($row['u_val'] ?? 1);
        $smallerFactor = self::positiveFactor($smallerRow['u_val'] ?? 1);
        $count = $smallerFactor / $largerFactor;
        if ($count <= 1.000001) {
            return $name;
        }

        $smallerName = trim((string) ($smallerRow['uname'] ?? ''));
        if ($smallerName === '') {
            return $name;
        }

        return $name . ' × ' . self::formatCount($count) . ' ' . $smallerName;
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

    private static function formatFactorValue($value): string
    {
        $factor = self::positiveFactor($value);
        $formatted = number_format($factor, 6, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '1' : $formatted;
    }

    private static function formatCount(float $count): string
    {
        $rounded = round($count, 6);
        if (abs($rounded - (int) $rounded) < 0.000001) {
            return (string) (int) $rounded;
        }

        $formatted = number_format($rounded, 6, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '1' : $formatted;
    }
}
