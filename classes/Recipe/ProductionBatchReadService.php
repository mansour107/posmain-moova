<?php

class ProductionBatchReadService
{
    public function listBatches(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'production_batches')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if (in_array($status, ['draft', 'committed', 'cancelled'], true)) {
            $conditions[] = 'pb.status = ?';
            $params[] = $status;
        }

        foreach (['pos_tenant', 'pos_branch', 'store_id', 'recipe_id'] as $column) {
            if (!isset($filters[$column]) || (int) $filters[$column] < 0) {
                continue;
            }
            $conditions[] = 'pb.' . $this->quoteIdentifier($column) . ' = ?';
            $params[] = (int) $filters[$column];
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(pb.batch_uuid LIKE ? OR rh.recipe_name LIKE ? OR output_item.iname LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $limit = $this->limit($filters['limit'] ?? 100);

        return $this->fetchAll(
            $conn,
            "
SELECT
  pb.*,
  rh.recipe_name,
  rh.recipe_type,
  rh.version_number AS recipe_version_number,
  output_item.iname AS output_item_name
FROM production_batches pb
LEFT JOIN recipe_headers rh ON rh.id = pb.recipe_id
LEFT JOIN myitems output_item ON output_item.id = pb.output_item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY pb.updated_at DESC, pb.id DESC
LIMIT {$limit}",
            $params
        );
    }

    public function batchDetail(mysqli $conn, int $batchId): ?array
    {
        if ($batchId < 1 || !$this->tableExists($conn, 'production_batches')) {
            return null;
        }

        $batch = $this->fetchOne(
            $conn,
            "
SELECT
  pb.*,
  rh.recipe_name,
  rh.recipe_type,
  rh.version_number AS recipe_version_number,
  output_item.iname AS output_item_name
FROM production_batches pb
LEFT JOIN recipe_headers rh ON rh.id = pb.recipe_id
LEFT JOIN myitems output_item ON output_item.id = pb.output_item_id
WHERE pb.id = ?
LIMIT 1",
            [$batchId]
        );
        if (!$batch) {
            return null;
        }

        return [
            'batch' => $batch,
            'lines' => $this->batchLines($conn, $batchId),
        ];
    }

    public function activeProductionRecipes(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'recipe_headers')) {
            return [];
        }

        $conditions = [
            "rh.status = 'active'",
            "rh.recipe_type IN ('batch_prepared', 'hybrid', 'sub_recipe')",
        ];
        $params = [];

        foreach (['pos_tenant', 'pos_branch'] as $column) {
            if (!isset($filters[$column]) || (int) $filters[$column] < 0) {
                continue;
            }
            $conditions[] = 'rh.' . $this->quoteIdentifier($column) . ' = ?';
            $params[] = (int) $filters[$column];
        }

        $limit = $this->limit($filters['limit'] ?? 200);

        return $this->fetchAll(
            $conn,
            "
SELECT
  rh.id,
  rh.recipe_name,
  rh.recipe_type,
  rh.version_number,
  rh.sellable_item_id,
  rh.yield_qty,
  output_item.iname AS output_item_name
FROM recipe_headers rh
LEFT JOIN myitems output_item ON output_item.id = rh.sellable_item_id
WHERE " . implode(' AND ', $conditions) . "
ORDER BY rh.recipe_name ASC, rh.version_number DESC, rh.id DESC
LIMIT {$limit}",
            $params
        );
    }

    private function batchLines(mysqli $conn, int $batchId): array
    {
        if (!$this->tableExists($conn, 'production_batch_lines')) {
            return [];
        }

        return $this->fetchAll(
            $conn,
            "
SELECT
  pbl.*,
  item.iname AS item_name
FROM production_batch_lines pbl
LEFT JOIN myitems item ON item.id = pbl.item_id
WHERE pbl.batch_id = ?
ORDER BY pbl.id",
            [$batchId]
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

    private function limit($value): int
    {
        return max(1, min(500, (int) ($value ?: 100)));
    }
}
