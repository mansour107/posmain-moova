<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

final class ItemUnitConversion
{
    public const INVENTORY_SCALE = 8;
    public const DISPLAY_SCALE = 6;

    /**
     * Convert stored UI factor ("1 left = N right") into inventory multiplier (base per entered unit).
     */
    public static function inventoryFactorDecimal(
        $storedFactor,
        bool $swapped,
        string $role,
        int $scale = self::INVENTORY_SCALE
    ): string {
        $stored = RecipeDecimal::normalize($storedFactor, $scale);
        if (RecipeDecimal::compare($stored, '0', $scale) <= 0) {
            return RecipeDecimal::normalize('1', $scale);
        }

        if ($role === 'sell') {
            return $swapped ? $stored : RecipeDecimal::divide('1', $stored, $scale);
        }

        return $swapped ? RecipeDecimal::divide('1', $stored, $scale) : $stored;
    }

    /**
     * Preferred stock math: avoids multiply-by-inverse rounding for sell conversions.
     */
    public static function stockQuantityFromEnteredQty(
        $enteredQty,
        $storedFactor,
        bool $swapped,
        string $role,
        int $scale = self::INVENTORY_SCALE
    ): string {
        $qty = RecipeDecimal::normalize($enteredQty, $scale);
        $stored = RecipeDecimal::normalize($storedFactor, $scale);
        if (RecipeDecimal::compare($stored, '0', $scale) <= 0) {
            return RecipeDecimal::normalize('0', $scale);
        }

        if ($role === 'sell') {
            return $swapped
                ? RecipeDecimal::multiply($qty, $stored, $scale)
                : RecipeDecimal::divide($qty, $stored, $scale);
        }

        return $swapped
            ? RecipeDecimal::divide($qty, $stored, $scale)
            : RecipeDecimal::multiply($qty, $stored, $scale);
    }

    public static function inventoryFactorFromRow(array $row, string $role, bool $hasSwapColumn, int $scale = self::INVENTORY_SCALE): string
    {
        $stored = RecipeDecimal::normalize($row['u_val'] ?? '1', $scale);
        if (RecipeDecimal::compare($stored, '0', $scale) <= 0) {
            return RecipeDecimal::normalize('1', $scale);
        }

        if (!$hasSwapColumn) {
            return $stored;
        }

        $swapped = (int) ($row['conversion_swapped'] ?? 0) === 1;
        if (!$swapped && $role === 'sell' && RecipeDecimal::compare($stored, '1', $scale) < 0) {
            return $stored;
        }

        return self::inventoryFactorDecimal($stored, $swapped, $role, $scale);
    }

    /**
     * @return array{factor: string, swapped: bool}
     */
    public static function displayProfileFromRow(
        ?array $row,
        ?array $stock,
        string $role,
        bool $hasSwapColumn,
        int $scale = self::DISPLAY_SCALE
    ): array {
        if ($row === null || $stock === null || (int) ($row['unit_id'] ?? 0) === (int) ($stock['unit_id'] ?? 0)) {
            return ['factor' => RecipeDecimal::normalize('1', $scale), 'swapped' => false];
        }

        $stored = RecipeDecimal::normalize($row['u_val'] ?? '1', $scale);
        if (RecipeDecimal::compare($stored, '0', $scale) <= 0) {
            return ['factor' => RecipeDecimal::normalize('1', $scale), 'swapped' => false];
        }

        $swapped = $hasSwapColumn && (int) ($row['conversion_swapped'] ?? 0) === 1;
        if ($hasSwapColumn && !$swapped && $role === 'sell' && RecipeDecimal::compare($stored, '1', $scale) < 0) {
            return ['factor' => self::snapDisplayFromLegacyDecimal($stored, $scale), 'swapped' => false];
        }

        return ['factor' => $stored, 'swapped' => $swapped];
    }

    /** @deprecated Use inventoryFactorDecimal(); float wrapper for legacy callers. */
    public static function inventoryFactor(float $storedFactor, bool $swapped, string $role): float
    {
        return (float) self::inventoryFactorDecimal((string) $storedFactor, $swapped, $role, self::DISPLAY_SCALE);
    }

    /** @deprecated Use inventoryFactorFromRow() returning string. */
    public static function inventoryFactorFromRowFloat(array $row, string $role, bool $hasSwapColumn): float
    {
        return (float) self::inventoryFactorFromRow($row, $role, $hasSwapColumn, self::DISPLAY_SCALE);
    }

    private static function snapDisplayFromLegacyDecimal(string $inventoryFactor, int $scale): string
    {
        $display = RecipeDecimal::divide('1', $inventoryFactor, $scale + 2);
        $nearest = (int) round((float) $display);
        if ($nearest >= 1) {
            $reconstructed = RecipeDecimal::divide('1', (string) $nearest, $scale + 2);
            if (RecipeDecimal::compare(
                RecipeDecimal::subtract($inventoryFactor, $reconstructed, $scale + 2),
                '0',
                $scale + 2
            ) === 0 || RecipeDecimal::compare(
                RecipeDecimal::subtract($reconstructed, $inventoryFactor, $scale + 2),
                '0.001',
                $scale + 2
            ) <= 0) {
                return RecipeDecimal::normalize((string) $nearest, $scale);
            }
        }

        return RecipeDecimal::normalize($display, $scale);
    }
}
