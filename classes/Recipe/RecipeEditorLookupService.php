<?php

require_once __DIR__ . '/../Items/ItemUnitResolver.php';

class RecipeEditorLookupService
{
    public function searchItems(mysqli $conn, string $query, string $kind = 'any', int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || !$this->tableExists($conn, 'myitems')) {
            return [];
        }

        $limit = $this->limit($limit);
        $columns = $this->columns($conn, 'myitems');
        $nameColumn = isset($columns['iname']) ? 'iname' : 'id';
        $select = [
            'id',
            isset($columns['iname']) ? 'iname' : 'CAST(id AS CHAR) AS iname',
            isset($columns['barcode']) ? 'barcode' : 'NULL AS barcode',
            isset($columns['name2']) ? 'name2' : 'NULL AS name2',
            isset($columns['group1']) ? 'group1' : 'NULL AS group1',
            isset($columns['item_type']) ? 'item_type' : "'unknown' AS item_type",
            isset($columns['track_stock']) ? 'track_stock' : 'NULL AS track_stock',
            isset($columns['preferred_unit_id']) ? 'preferred_unit_id' : 'NULL AS preferred_unit_id',
        ];

        $where = [];
        $params = [];
        if (isset($columns['isdeleted'])) {
            $where[] = 'COALESCE(isdeleted, 0) != 1';
        }

        $itemTypes = $this->itemTypesForKind($kind);
        if ($itemTypes && isset($columns['item_type'])) {
            $where[] = 'item_type IN (' . implode(', ', array_fill(0, count($itemTypes), '?')) . ')';
            foreach ($itemTypes as $itemType) {
                $params[] = $itemType;
            }
        }
        if ($kind === 'sellable' && $this->tableExists($conn, 'item_variants')) {
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM item_variants iv_child
                WHERE iv_child.variant_item_id = myitems.id
                  AND iv_child.is_active = 1
            )';
        }

        $search = [];
        $like = '%' . $query . '%';
        if (ctype_digit($query)) {
            $search[] = 'id = ?';
            $params[] = (int) $query;
        }
        foreach (['iname', 'name2', 'barcode'] as $column) {
            if (!isset($columns[$column])) {
                continue;
            }
            $search[] = $column . ' LIKE ?';
            $params[] = $like;
        }
        if (!$search) {
            return [];
        }
        $where[] = '(' . implode(' OR ', $search) . ')';

        return array_map(
            function (array $row) use ($conn): array {
                return $this->itemRow($conn, $row);
            },
            $this->fetchAll(
                $conn,
                'SELECT ' . implode(', ', $select) . ' FROM myitems WHERE '
                    . implode(' AND ', $where)
                    . ' ORDER BY ' . $nameColumn . ' ASC, id ASC LIMIT ' . $limit,
                $params
            )
        );
    }

    public function searchSubRecipes(mysqli $conn, string $query, int $posTenant = 0, int $posBranch = 0, int $excludeRecipeId = 0, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || !$this->tableExists($conn, 'recipe_headers')) {
            return [];
        }

        $params = [$posTenant, $posBranch];
        $where = [
            'pos_tenant = ?',
            'pos_branch = ?',
            "recipe_type = 'sub_recipe'",
            "status IN ('draft', 'active')",
        ];
        if ($excludeRecipeId > 0) {
            $where[] = 'id != ?';
            $params[] = $excludeRecipeId;
        }
        if (ctype_digit($query)) {
            $where[] = '(id = ? OR recipe_name LIKE ?)';
            $params[] = (int) $query;
            $params[] = '%' . $query . '%';
        } else {
            $where[] = 'recipe_name LIKE ?';
            $params[] = '%' . $query . '%';
        }

        return array_map(
            [$this, 'subRecipeRow'],
            $this->fetchAll(
                $conn,
                'SELECT id, recipe_name, status, version_number, sellable_item_id FROM recipe_headers WHERE '
                    . implode(' AND ', $where)
                    . ' ORDER BY status = \'active\' DESC, recipe_name ASC, version_number DESC LIMIT ' . $this->limit($limit),
                $params
            )
        );
    }

    public function searchModifierOptions(mysqli $conn, string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || !$this->tableExists($conn, 'modifier_options')) {
            return [];
        }

        $hasGroups = $this->tableExists($conn, 'modifier_groups');
        $sql = "
SELECT
  mo.id,
  mo.group_id,
  mo.name_ar,
  mo.name_en,"
            . ($hasGroups ? ' mg.name_ar AS group_name_ar, mg.name_en AS group_name_en' : ' NULL AS group_name_ar, NULL AS group_name_en') . "
FROM modifier_options mo "
            . ($hasGroups ? 'LEFT JOIN modifier_groups mg ON mg.id = mo.group_id' : '') . "
WHERE COALESCE(mo.is_active, 1) = 1
  AND (mo.name_ar LIKE ? OR mo.name_en LIKE ?"
            . ($hasGroups ? ' OR mg.name_ar LIKE ? OR mg.name_en LIKE ?' : '') . ')
ORDER BY mo.sort_order ASC, mo.id ASC
LIMIT ' . $this->limit($limit);

        $like = '%' . $query . '%';
        $params = $hasGroups ? [$like, $like, $like, $like] : [$like, $like];

        return array_map([$this, 'modifierOptionRow'], $this->fetchAll($conn, $sql, $params));
    }

    public function searchComponents(mysqli $conn, string $query, int $posTenant = 0, int $posBranch = 0, int $excludeRecipeId = 0, int $limit = 20): array
    {
        $limit = $this->limit($limit);
        $items = $this->searchItems($conn, $query, 'component', $limit);
        $recipes = $this->searchSubRecipes($conn, $query, $posTenant, $posBranch, $excludeRecipeId, $limit);

        $components = [];
        foreach ($items as $item) {
            $itemType = strtolower((string) ($item['item_type'] ?? 'unknown'));
            $lineType = $itemType === 'packaging' ? 'packaging' : 'ingredient';
            $components[] = array_merge($item, [
                'component_kind' => 'item',
                'component_type' => $this->componentTypeLabel($itemType),
                'line_type' => $lineType,
            ]);
        }
        foreach ($recipes as $recipe) {
            $components[] = array_merge($recipe, [
                'component_kind' => 'prepared_recipe',
                'component_type' => 'Prepared recipe',
                'line_type' => 'sub_recipe',
            ]);
        }

        return array_slice($components, 0, $limit);
    }

    private function componentTypeLabel(string $itemType): string
    {
        if ($itemType === 'ingredient') {
            return 'Ingredient';
        }
        if ($itemType === 'packaging') {
            return 'Packaging';
        }
        if ($itemType === 'sellable') {
            return 'Item';
        }

        return 'Component';
    }

    private function itemRow(mysqli $conn, array $row): array
    {
        $name = (string) ($row['iname'] ?? '');
        $barcode = (string) ($row['barcode'] ?? '');
        $label = trim($name . ($barcode !== '' ? ' - ' . $barcode : ''));

        $stockUnitId = ItemUnitResolver::stockUnitIdForItem($conn, (int) ($row['id'] ?? 0));
        if ($stockUnitId < 1 && isset($row['preferred_unit_id'])) {
            $stockUnitId = (int) $row['preferred_unit_id'];
        }

        return [
            'id' => (int) $row['id'],
            'label' => $label !== '' ? $label : 'Item #' . (int) $row['id'],
            'name' => $name,
            'barcode' => $barcode,
            'alternate_name' => $row['name2'] ?? null,
            'group' => $row['group1'] ?? null,
            'item_type' => (string) ($row['item_type'] ?? 'unknown'),
            'track_stock' => isset($row['track_stock']) ? (int) $row['track_stock'] : null,
            'stock_unit_id' => $stockUnitId,
        ];
    }

    private function subRecipeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['recipe_name'] . ' v' . (int) $row['version_number'] . ' (' . (string) $row['status'] . ')',
            'recipe_name' => (string) $row['recipe_name'],
            'status' => (string) $row['status'],
            'version_number' => (int) $row['version_number'],
            'sellable_item_id' => (int) $row['sellable_item_id'],
        ];
    }

    private function modifierOptionRow(array $row): array
    {
        $name = (string) ($row['name_en'] ?: $row['name_ar'] ?: ('Option #' . (int) $row['id']));
        $groupName = (string) ($row['group_name_en'] ?: $row['group_name_ar'] ?: ('Group #' . (int) $row['group_id']));

        return [
            'id' => (int) $row['id'],
            'group_id' => (int) $row['group_id'],
            'label' => $groupName . ' / ' . $name,
            'option_name' => $name,
            'group_name' => $groupName,
        ];
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

    private function columns(mysqli $conn, string $table): array
    {
        $rows = $this->fetchAll(
            $conn,
            "
SELECT COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?",
            [$table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['COLUMN_NAME']] = true;
        }

        return $columns;
    }

    private function itemTypesForKind(string $kind): array
    {
        $kind = strtolower(trim($kind));
        if ($kind === 'sellable') {
            return ['sellable'];
        }
        if ($kind === 'ingredient') {
            return ['ingredient'];
        }
        if ($kind === 'packaging') {
            return ['packaging'];
        }
        if ($kind === 'service') {
            return ['service'];
        }
        if ($kind === 'stock_component') {
            return ['ingredient', 'packaging'];
        }
        if ($kind === 'component') {
            return ['ingredient', 'packaging', 'sellable'];
        }

        return [];
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($conn, $sql, $params);

        return $rows[0] ?? null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        if ($params) {
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
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }
}
