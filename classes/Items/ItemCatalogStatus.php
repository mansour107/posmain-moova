<?php

class ItemCatalogStatus
{
    public static function hasActiveColumn(mysqli $conn): bool
    {
        static $cache = [];

        $key = spl_object_id($conn) . ':myitems.is_active';
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $result = $conn->query("SHOW COLUMNS FROM myitems LIKE 'is_active'");
        $cache[$key] = $result && $result->num_rows > 0;

        return $cache[$key];
    }

    public static function activeOnlySql(mysqli $conn, string $alias = ''): string
    {
        if (!self::hasActiveColumn($conn)) {
            return '';
        }

        $prefix = self::safeAliasPrefix($alias);

        return ' AND COALESCE(' . $prefix . 'is_active, 1) = 1';
    }

    public static function activeSelectSql(mysqli $conn, string $alias = ''): string
    {
        if (!self::hasActiveColumn($conn)) {
            return '1 AS catalog_is_active';
        }

        $prefix = self::safeAliasPrefix($alias);

        return 'COALESCE(' . $prefix . 'is_active, 1) AS catalog_is_active';
    }

    private static function safeAliasPrefix(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid SQL alias.');
        }

        return $alias . '.';
    }
}
