<?php

class InventoryLegacyMirrorService
{
    public function refreshItemOpeningBalanceSummary(mysqli $conn, int $itemId, string $qty, string $costPrice): bool
    {
        if (!$this->tableExists($conn, 'myitems') || !$this->columnExists($conn, 'myitems', 'itmqty')) {
            return true;
        }

        $hasCostPrice = $this->columnExists($conn, 'myitems', 'cost_price');
        $hasIsDeleted = $this->columnExists($conn, 'myitems', 'isdeleted');
        if ($hasCostPrice) {
            $sql = 'UPDATE myitems SET itmqty = ?, cost_price = ? WHERE id = ?'
                . ($hasIsDeleted ? ' AND isdeleted = 0' : '');
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssi', $qty, $costPrice, $itemId);
        } else {
            $sql = 'UPDATE myitems SET itmqty = ? WHERE id = ?'
                . ($hasIsDeleted ? ' AND isdeleted = 0' : '');
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $qty, $itemId);
        }

        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    public function refreshItemQtySummary(mysqli $conn, int $itemId, string $qty): bool
    {
        if (!$this->tableExists($conn, 'myitems') || !$this->columnExists($conn, 'myitems', 'itmqty')) {
            return true;
        }

        $sql = 'UPDATE myitems SET itmqty = ? WHERE id = ?'
            . ($this->columnExists($conn, 'myitems', 'isdeleted') ? ' AND isdeleted = 0' : '');
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $qty, $itemId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    public function refreshAllItemQtySummariesFromFatDetails(mysqli $conn): bool
    {
        if (!$this->tableExists($conn, 'myitems') || !$this->columnExists($conn, 'myitems', 'itmqty')) {
            return true;
        }
        if (!$this->tableExists($conn, 'fat_details')) {
            return true;
        }

        return (bool) $conn->query("
UPDATE myitems
SET itmqty = (
    SELECT COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0)
    FROM fat_details
    WHERE item_id = myitems.id AND isdeleted = 0
)");
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (int) ($stmt->get_result()->fetch_assoc()['table_count'] ?? 0) > 0;
        $stmt->close();

        return $exists;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (int) ($stmt->get_result()->fetch_assoc()['column_count'] ?? 0) > 0;
        $stmt->close();

        return $exists;
    }
}
