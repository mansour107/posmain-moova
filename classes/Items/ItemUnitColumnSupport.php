<?php

final class ItemUnitColumnSupport
{
    private static ?bool $hasDefFlags = null;
    private static ?bool $hasConversionSwapped = null;

    public static function hasDefFlags(mysqli $conn): bool
    {
        if (self::$hasDefFlags !== null) {
            return self::$hasDefFlags;
        }

        $result = $conn->query("SHOW COLUMNS FROM item_units LIKE 'def_stock'");
        self::$hasDefFlags = $result !== false && $result->num_rows > 0;

        return self::$hasDefFlags;
    }

    public static function hasConversionSwapped(mysqli $conn): bool
    {
        if (self::$hasConversionSwapped !== null) {
            return self::$hasConversionSwapped;
        }

        $result = $conn->query("SHOW COLUMNS FROM item_units LIKE 'conversion_swapped'");
        self::$hasConversionSwapped = $result !== false && $result->num_rows > 0;

        return self::$hasConversionSwapped;
    }

    public static function ensureDefFlags(mysqli $conn): void
    {
        if (self::hasDefFlags($conn)) {
            self::ensureUValPrecision($conn);
            self::ensureConversionSwapped($conn);
            return;
        }

        foreach (['def_sale', 'def_buy', 'def_stock'] as $column) {
            $conn->query("ALTER TABLE item_units ADD COLUMN {$column} TINYINT(1) NOT NULL DEFAULT 0");
        }

        self::$hasDefFlags = true;
        self::ensureUValPrecision($conn);
        self::ensureConversionSwapped($conn);
    }

    public static function ensureConversionSwapped(mysqli $conn): void
    {
        if (self::hasConversionSwapped($conn)) {
            return;
        }

        $conn->query('ALTER TABLE item_units ADD COLUMN conversion_swapped TINYINT(1) NOT NULL DEFAULT 0');
        self::$hasConversionSwapped = true;
    }

    public static function ensureUValPrecision(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $result = $conn->query("SHOW COLUMNS FROM item_units LIKE 'u_val'");
        if ($result === false || $result->num_rows === 0) {
            return;
        }

        $column = $result->fetch_assoc();
        $type = strtolower((string) ($column['Type'] ?? ''));
        if (preg_match('/decimal\(\d+,\s*(\d+)\)/', $type, $matches) !== 1) {
            return;
        }

        if ((int) $matches[1] >= 6) {
            return;
        }

        $conn->query('ALTER TABLE item_units MODIFY COLUMN u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000');
    }
}
