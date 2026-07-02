<?php

final class ItemUnitColumnSupport
{
    private static ?bool $hasDefFlags = null;

    public static function hasDefFlags(mysqli $conn): bool
    {
        if (self::$hasDefFlags !== null) {
            return self::$hasDefFlags;
        }

        $result = $conn->query("SHOW COLUMNS FROM item_units LIKE 'def_stock'");
        self::$hasDefFlags = $result !== false && $result->num_rows > 0;

        return self::$hasDefFlags;
    }

    public static function ensureDefFlags(mysqli $conn): void
    {
        if (self::hasDefFlags($conn)) {
            return;
        }

        foreach (['def_sale', 'def_buy', 'def_stock'] as $column) {
            $conn->query("ALTER TABLE item_units ADD COLUMN {$column} TINYINT(1) NOT NULL DEFAULT 0");
        }

        self::$hasDefFlags = true;
    }
}
