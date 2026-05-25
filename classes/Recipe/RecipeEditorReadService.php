<?php

class RecipeEditorReadService
{
    public function listRecipes(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn, 'recipe_headers')) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if (in_array($status, ['draft', 'active', 'archived'], true)) {
            $conditions[] = 'rh.status = ?';
            $params[] = $status;
        }

        foreach (['pos_tenant', 'pos_branch', 'sellable_item_id'] as $column) {
            if (!isset($filters[$column]) || (int) $filters[$column] < 0) {
                continue;
            }
            $conditions[] = 'rh.' . $this->quoteIdentifier($column) . ' = ?';
            $params[] = (int) $filters[$column];
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(rh.recipe_name LIKE ? OR mi.iname LIKE ? OR mi.barcode LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $limit = $this->limit($filters['limit'] ?? 100);

        return $this->fetchAll(
            $conn,
            "
SELECT
  rh.*,
  mi.iname AS sellable_item_name,
  mi.barcode AS sellable_item_barcode,
  mi.group1 AS sellable_item_group,
  (SELECT COUNT(*) FROM recipe_lines rl WHERE rl.recipe_id = rh.id) AS line_count,
  rcs.cost_per_yield AS latest_cost_per_yield,
  rcs.cost_per_sell_unit AS latest_cost_per_sell_unit,
  rcs.calculated_at AS latest_cost_calculated_at,
  rac.effective_available_qty AS cached_effective_available_qty,
  rac.effective_is_available AS cached_effective_is_available,
  rac.unavailable_reason AS cached_unavailable_reason,
  rac.availability_revision AS cached_availability_revision,
  rac.calculated_at AS cached_availability_calculated_at
FROM recipe_headers rh
LEFT JOIN myitems mi ON mi.id = rh.sellable_item_id
LEFT JOIN recipe_cost_snapshots rcs
  ON rcs.id = (
    SELECT id
    FROM recipe_cost_snapshots
    WHERE recipe_id = rh.id
    ORDER BY calculated_at DESC, id DESC
    LIMIT 1
  )
LEFT JOIN recipe_availability_cache rac
  ON rac.id = (
    SELECT id
    FROM recipe_availability_cache
    WHERE pos_tenant = rh.pos_tenant
      AND pos_branch = rh.pos_branch
      AND sellable_item_id = rh.sellable_item_id
    ORDER BY calculated_at DESC, id DESC
    LIMIT 1
  )
WHERE " . implode(' AND ', $conditions) . "
ORDER BY rh.updated_at DESC, rh.id DESC
LIMIT {$limit}",
            $params
        );
    }

    public function recipeDetail(mysqli $conn, int $recipeId): ?array
    {
        if ($recipeId < 1 || !$this->tableExists($conn, 'recipe_headers')) {
            return null;
        }

        $header = $this->fetchOne(
            $conn,
            "
SELECT rh.*, mi.iname AS sellable_item_name, mi.barcode AS sellable_item_barcode, mi.group1 AS sellable_item_group
FROM recipe_headers rh
LEFT JOIN myitems mi ON mi.id = rh.sellable_item_id
WHERE rh.id = ?
LIMIT 1",
            [$recipeId]
        );
        if (!$header) {
            return null;
        }

        return [
            'header' => $header,
            'lines' => $this->recipeLines($conn, $recipeId),
            'latest_cost' => $this->latestCostSnapshot($conn, $recipeId),
            'availability' => $this->availabilityRows($conn, (int) $header['pos_tenant'], (int) $header['pos_branch'], (int) $header['sellable_item_id']),
            'versions' => $this->versionRows($conn, (int) $header['pos_tenant'], (int) $header['pos_branch'], (int) $header['sellable_item_id']),
            'audit' => $this->auditRows($conn, $recipeId),
        ];
    }

    private function recipeLines(mysqli $conn, int $recipeId): array
    {
        if (!$this->tableExists($conn, 'recipe_lines')) {
            return [];
        }

        return $this->fetchAll(
            $conn,
            "
SELECT
  rl.*,
  ingredient.iname AS ingredient_item_name,
  ingredient.barcode AS ingredient_item_barcode,
  sub.recipe_name AS sub_recipe_name,
  sub.version_number AS sub_recipe_version
FROM recipe_lines rl
LEFT JOIN myitems ingredient ON ingredient.id = rl.ingredient_item_id
LEFT JOIN recipe_headers sub ON sub.id = rl.sub_recipe_id
WHERE rl.recipe_id = ?
ORDER BY rl.sort_order ASC, rl.id ASC",
            [$recipeId]
        );
    }

    private function latestCostSnapshot(mysqli $conn, int $recipeId): ?array
    {
        if (!$this->tableExists($conn, 'recipe_cost_snapshots')) {
            return null;
        }

        return $this->fetchOne(
            $conn,
            'SELECT * FROM recipe_cost_snapshots WHERE recipe_id = ? ORDER BY calculated_at DESC, id DESC LIMIT 1',
            [$recipeId]
        );
    }

    private function availabilityRows(mysqli $conn, int $posTenant, int $posBranch, int $sellableItemId): array
    {
        if (!$this->tableExists($conn, 'recipe_availability_cache')) {
            return [];
        }

        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM recipe_availability_cache
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND sellable_item_id = ?
ORDER BY calculated_at DESC, id DESC
LIMIT 20",
            [$posTenant, $posBranch, $sellableItemId]
        );
    }

    private function auditRows(mysqli $conn, int $recipeId): array
    {
        if (!$this->tableExists($conn, 'recipe_audit_log')) {
            return [];
        }

        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM recipe_audit_log
WHERE recipe_id = ?
ORDER BY created_at DESC, id DESC
LIMIT 20",
            [$recipeId]
        );
    }

    private function versionRows(mysqli $conn, int $posTenant, int $posBranch, int $sellableItemId): array
    {
        return $this->fetchAll(
            $conn,
            "
SELECT
  id,
  recipe_uuid,
  recipe_name,
  recipe_type,
  status,
  version_number,
  effective_from,
  effective_to,
  approved_at,
  created_at,
  updated_at
FROM recipe_headers
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND sellable_item_id = ?
ORDER BY version_number DESC, id DESC
LIMIT 50",
            [$posTenant, $posBranch, $sellableItemId]
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
