<?php

require_once __DIR__ . '/ItemCostSourceSupport.php';

final class ItemRecipeCatalogService
{
    public function saveMetadata(mysqli $conn, int $itemId, array $payload): void
    {
        if ($itemId < 1) {
            throw new InvalidArgumentException('Item id is required.');
        }

        $assignments = [];
        $params = [];

        if ($this->columnExists($conn, 'myitems', 'item_type')) {
            $assignments[] = 'item_type = ?';
            $params[] = $this->itemType($payload['item_type'] ?? 'sellable');
        }
        if ($this->columnExists($conn, 'myitems', 'track_stock')) {
            $assignments[] = 'track_stock = ?';
            $params[] = $this->trackStock($payload);
        }
        if ($this->columnExists($conn, 'myitems', 'preferred_unit_id')) {
            $assignments[] = 'preferred_unit_id = ?';
            $preferredUnitId = (int) ($payload['preferred_unit_id'] ?? 0);
            $params[] = $preferredUnitId > 0 ? $preferredUnitId : null;
        }
        if ($this->columnExists($conn, 'myitems', ItemCostSourceSupport::COLUMN)) {
            ItemCostSourceSupport::ensureColumn($conn);
            $assignments[] = ItemCostSourceSupport::COLUMN . ' = ?';
            $params[] = ItemCostSourceSupport::normalize($payload['cost_source'] ?? 'direct');
        }

        if (!$assignments) {
            return;
        }

        $params[] = $itemId;
        $stmt = $conn->prepare('UPDATE myitems SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $stmt->close();
    }

    private function itemType($value): string
    {
        $type = strtolower(trim((string) $value));

        return in_array($type, ['sellable', 'ingredient', 'packaging', 'service'], true) ? $type : 'sellable';
    }

    private function trackStock(array $payload): int
    {
        if ($this->itemType($payload['item_type'] ?? 'sellable') === 'service') {
            return 0;
        }

        return !empty($payload['track_stock']) ? 1 : 0;
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
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }

    private function bindParams(mysqli_stmt $stmt, array $params): void
    {
        if (!$params) {
            return;
        }

        $types = '';
        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : 's';
        }

        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = $value;
        }

        $bind = [$types];
        foreach ($refs as $index => $_) {
            $bind[] = &$refs[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}
