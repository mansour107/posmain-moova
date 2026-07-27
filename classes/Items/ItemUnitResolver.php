<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/ItemUnitColumnSupport.php';
require_once __DIR__ . '/ItemUnitConversion.php';
require_once __DIR__ . '/ItemUnitConversionFeatureFlags.php';

final class ItemUnitResolver
{
    public static function sellRowForItem(mysqli $conn, int $itemId): ?array
    {
        return self::rowByFlag($conn, $itemId, 'def_sale')
            ?? self::firstRow($conn, $itemId);
    }

    public static function stockRowForItem(mysqli $conn, int $itemId): ?array
    {
        return self::rowByFlag($conn, $itemId, 'def_stock')
            ?? self::firstRow($conn, $itemId);
    }

    public static function purchaseRowForItem(mysqli $conn, int $itemId): ?array
    {
        return self::rowByFlag($conn, $itemId, 'def_buy')
            ?? self::stockRowForItem($conn, $itemId);
    }

    public static function rowForItemUnit(mysqli $conn, int $itemId, int $unitId): ?array
    {
        if ($unitId < 1 || !self::itemUnitsTableExists($conn)) {
            return null;
        }

        $stmt = $conn->prepare('
            SELECT *
            FROM item_units
            WHERE item_id = ?
              AND unit_id = ?
              AND COALESCE(isdeleted, 0) = 0
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public static function sellPriceForItem(mysqli $conn, int $itemId, ?int $unitId = null): string
    {
        $sell = $unitId !== null && $unitId > 0
            ? self::rowForItemUnit($conn, $itemId, $unitId)
            : self::sellRowForItem($conn, $itemId);
        if ($sell !== null && RecipeDecimal::compare((string) ($sell['price1'] ?? '0'), '0', 6) > 0) {
            return RecipeDecimal::normalize((string) $sell['price1'], 6);
        }

        $stmt = $conn->prepare('SELECT price1 FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return RecipeDecimal::normalize((string) ($row['price1'] ?? '0'), 6);
    }

    public static function sellToStockFactorDecimal(mysqli $conn, int $itemId, int $scale = ItemUnitConversion::INVENTORY_SCALE): string
    {
        $sell = self::sellRowForItem($conn, $itemId);
        if ($sell === null) {
            return RecipeDecimal::normalize('1', $scale);
        }

        return self::inventoryFactorForUnitRowDecimal($conn, $sell, $scale);
    }

    public static function sellToStockFactor(mysqli $conn, int $itemId): float
    {
        return (float) self::sellToStockFactorDecimal($conn, $itemId, ItemUnitConversion::DISPLAY_SCALE);
    }

    public static function purchaseToStockFactorDecimal(mysqli $conn, int $itemId, int $scale = ItemUnitConversion::INVENTORY_SCALE): string
    {
        $purchase = self::purchaseRowForItem($conn, $itemId);
        if ($purchase === null) {
            return RecipeDecimal::normalize('1', $scale);
        }

        return self::inventoryFactorForUnitRowDecimal($conn, $purchase, $scale, 'purchase');
    }

    public static function purchaseToStockFactor(mysqli $conn, int $itemId): float
    {
        return (float) self::purchaseToStockFactorDecimal($conn, $itemId, ItemUnitConversion::DISPLAY_SCALE);
    }

    public static function inventoryFactorForUnitRowDecimal(
        mysqli $conn,
        array $row,
        int $scale = ItemUnitConversion::INVENTORY_SCALE,
        ?string $role = null
    ): string {
        $hasSwap = ItemUnitColumnSupport::hasConversionSwapped($conn);
        if ($role === null) {
            if ((int) ($row['def_buy'] ?? 0) === 1) {
                $role = 'purchase';
            } elseif ((int) ($row['def_sale'] ?? 0) === 1) {
                $role = 'sell';
            } else {
                return RecipeDecimal::normalize((string) ($row['u_val'] ?? '1'), $scale);
            }
        }

        return ItemUnitConversion::inventoryFactorFromRow($row, $role, $hasSwap, $scale);
    }

    public static function inventoryFactorForUnitRow(mysqli $conn, array $row): float
    {
        return (float) self::inventoryFactorForUnitRowDecimal(
            $conn,
            $row,
            ItemUnitConversion::DISPLAY_SCALE
        );
    }

    /**
     * Server-authoritative POS stock multiplier for an item line.
     *
     * @return array{factor_decimal: string, factor_float: float, unit_row: ?array}
     */
    public static function resolvePosStockFactor(
        mysqli $conn,
        int $itemId,
        ?int $unitId = null,
        $clientFactor = null
    ): array {
        $scale = ItemUnitConversion::INVENTORY_SCALE;
        $unitRow = null;
        $factorDecimal = RecipeDecimal::normalize('1', $scale);

        if ($unitId !== null && $unitId > 0) {
            $unitRow = self::rowForItemUnit($conn, $itemId, $unitId);
            if ($unitRow !== null) {
                $factorDecimal = self::inventoryFactorForUnitRowDecimal($conn, $unitRow, $scale, 'sell');
            }
        } else {
            $factorDecimal = self::sellToStockFactorDecimal($conn, $itemId, $scale);
            $unitRow = self::sellRowForItem($conn, $itemId);
        }

        if (!ItemUnitConversionFeatureFlags::strictPosFactorResolution() && $clientFactor !== null && $clientFactor !== '') {
            $client = RecipeDecimal::normalize($clientFactor, $scale);
            if (RecipeDecimal::compare($client, '0', $scale) > 0) {
                $factorDecimal = $client;
            }
        }

        return [
            'factor_decimal' => $factorDecimal,
            'factor_float' => (float) RecipeDecimal::normalize($factorDecimal, ItemUnitConversion::DISPLAY_SCALE),
            'unit_row' => $unitRow,
        ];
    }

    public static function stockUnitIdForItem(mysqli $conn, int $itemId): int
    {
        $stock = self::stockRowForItem($conn, $itemId);

        return (int) ($stock['unit_id'] ?? 0);
    }

    public static function purchaseUnitIdForItem(mysqli $conn, int $itemId): int
    {
        $purchase = self::purchaseRowForItem($conn, $itemId);

        return (int) ($purchase['unit_id'] ?? 0);
    }

    private static function rowByFlag(mysqli $conn, int $itemId, string $flag): ?array
    {
        if (!self::itemUnitsTableExists($conn) || !ItemUnitColumnSupport::hasDefFlags($conn)) {
            return null;
        }

        $allowed = ['def_sale', 'def_stock', 'def_buy'];
        if (!in_array($flag, $allowed, true)) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM item_units
            WHERE item_id = ?
              AND COALESCE(isdeleted, 0) = 0
              AND {$flag} = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private static function firstRow(mysqli $conn, int $itemId): ?array
    {
        if (!self::itemUnitsTableExists($conn)) {
            return null;
        }

        $stmt = $conn->prepare('
            SELECT *
            FROM item_units
            WHERE item_id = ?
              AND COALESCE(isdeleted, 0) = 0
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private static function itemUnitsTableExists(mysqli $conn): bool
    {
        static $cache = [];
        $databaseRow = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();
        $cacheKey = (string) $conn->thread_id . ':' . (string) ($databaseRow['db_name'] ?? '');
        if (!array_key_exists($cacheKey, $cache)) {
            $result = $conn->query("SHOW TABLES LIKE 'item_units'");
            $cache[$cacheKey] = $result instanceof mysqli_result && $result->num_rows > 0;
        }

        return $cache[$cacheKey];
    }
}
