<?php

class RecipeWasteAdjustmentReadService
{
    public function recentMovements(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return [];
        }

        $conditions = ["im.movement_type IN ('waste', 'adjustment')"];
        $params = [];

        foreach (['pos_tenant', 'pos_branch', 'store_id', 'item_id'] as $column) {
            if (!isset($filters[$column]) || (int) $filters[$column] < 0) {
                continue;
            }
            $conditions[] = 'im.' . $this->quoteIdentifier($column) . ' = ?';
            $params[] = (int) $filters[$column];
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(im.idempotency_key LIKE ? OR im.source_uuid LIKE ? OR mi.iname LIKE ? OR mi.barcode LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));

        return $this->fetchAll(
            $conn,
            "
SELECT
  im.*,
  mi.iname AS item_name,
  CASE
    WHEN im.qty_in > 0 THEN im.qty_in
    ELSE im.qty_out
  END AS movement_qty,
  CASE
    WHEN im.qty_in > 0 THEN 'increase'
    WHEN im.qty_out > 0 THEN 'decrease'
    ELSE 'neutral'
  END AS movement_direction
FROM inventory_movements im
LEFT JOIN myitems mi ON mi.id = im.item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY im.created_at DESC, im.id DESC
LIMIT {$limit}",
            $params
        );
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $row = $this->fetchOne(
            $conn,
            "
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?",
            [$table]
        );

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
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

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}
