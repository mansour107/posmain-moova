<?php

require_once __DIR__ . '/ItemUnitColumnSupport.php';

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

    public static function sellPriceForItem(mysqli $conn, int $itemId): float
    {
        $sell = self::sellRowForItem($conn, $itemId);
        if ($sell !== null && (float) ($sell['price1'] ?? 0) > 0) {
            return (float) $sell['price1'];
        }

        $stmt = $conn->prepare('SELECT price1 FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (float) ($row['price1'] ?? 0);
    }

    public static function sellToStockFactor(mysqli $conn, int $itemId): float
    {
        $sell = self::sellRowForItem($conn, $itemId);
        if ($sell === null) {
            return 1.0;
        }

        return (float) ($sell['u_val'] ?? 1);
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
        if (!ItemUnitColumnSupport::hasDefFlags($conn)) {
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
}
