<?php

final class ItemInventoryUnitSync
{
    public static function syncPurchaseUnitPreference(mysqli $conn, int $itemId, int $purchaseUnitId): void
    {
        if ($itemId < 1 || $purchaseUnitId < 1) {
            return;
        }

        if (!self::columnExists($conn, 'inventory_item_stock_levels', 'preferred_purchase_unit_id')) {
            return;
        }

        $stmt = $conn->prepare('
            UPDATE inventory_item_stock_levels
            SET preferred_purchase_unit_id = ?
            WHERE item_id = ?
        ');
        $stmt->bind_param('ii', $purchaseUnitId, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    private static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }
}
