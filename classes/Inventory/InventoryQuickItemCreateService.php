<?php

require_once __DIR__ . '/../Items/ItemRecipeCatalogService.php';
require_once __DIR__ . '/../Sync/MenuItemSyncRecorder.php';

class InventoryQuickItemCreateService
{
    public function create(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertTables($conn);
        $payload = $this->normalize($conn, $request, $context);

        $conn->begin_transaction();
        try {
            $this->assertUniqueName($conn, $payload['iname']);
            $this->assertUniqueBarcode($conn, $payload['barcode']);
            $this->assertUnitExists($conn, $payload['unit_id']);

            $itemId = $this->insertItem($conn, $payload);
            $this->insertUnit($conn, $itemId, $payload);
            (new ItemRecipeCatalogService())->saveMetadata($conn, $itemId, $payload);
            $this->saveStockDefaults($conn, $itemId, $payload, $context);
            $this->recordProcess($conn);
            posmain_record_menu_item_sync($conn, $itemId, 'inventory_receiving_quick_create');

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        return [
            'success' => true,
            'item' => [
                'id' => $itemId,
                'iname' => $payload['iname'],
                'barcode' => $payload['barcode'],
                'cost_price' => $payload['cost_price'],
                'item_type' => $payload['item_type'],
                'track_stock' => 1,
            ],
            'unit' => [
                'item_id' => $itemId,
                'unit_id' => $payload['unit_id'],
                'u_val' => '1.000000',
                'cost_price' => $payload['cost_price'],
                'uname' => $this->unitName($conn, $payload['unit_id']),
            ],
        ];
    }

    private function normalize(mysqli $conn, array $request, array $context): array
    {
        $name = trim((string) ($request['iname'] ?? $request['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('ITEM_NAME_REQUIRED');
        }
        if ($this->textLength($name) > 200) {
            throw new InvalidArgumentException('ITEM_NAME_TOO_LONG');
        }

        $itemType = strtolower(trim((string) ($request['item_type'] ?? 'ingredient')));
        if (!in_array($itemType, ['ingredient', 'packaging', 'sellable'], true)) {
            throw new InvalidArgumentException('ITEM_TYPE_INVALID');
        }

        $unitId = $this->positiveInt($request['unit_id'] ?? $request['base_unit_id'] ?? 0);
        if ($unitId < 1) {
            $unitId = $this->defaultUnitId($conn);
        }

        $costPrice = $this->decimal($request['cost_price'] ?? $request['unit_cost'] ?? 0);
        if ((float) $costPrice < 0) {
            throw new InvalidArgumentException('ITEM_COST_INVALID');
        }

        $barcode = trim((string) ($request['barcode'] ?? ''));
        if ($barcode === '') {
            $barcode = $this->nextBarcode($conn);
        }
        if ($this->textLength($barcode) > 100) {
            throw new InvalidArgumentException('ITEM_BARCODE_TOO_LONG');
        }

        $userId = $this->positiveInt($context['user_id'] ?? $request['user_id'] ?? 0);
        if ($userId < 1) {
            $userId = 1;
        }

        $storeId = $this->positiveInt($request['store_id'] ?? $request['destination_store_id'] ?? 0);
        if (function_exists('posmain_assert_operational_store_id')) {
            require_once __DIR__ . '/../../includes/pos_default_accounts.php';
            $storeId = posmain_assert_operational_store_id($conn, $storeId);
        } elseif ($storeId < 1 && function_exists('posmain_operational_store_id')) {
            require_once __DIR__ . '/../../includes/pos_default_accounts.php';
            $storeId = posmain_operational_store_id($conn);
        }

        return [
            'iname' => $name,
            'name2' => trim((string) ($request['name2'] ?? '')),
            'code' => $this->positiveInt($request['code'] ?? 0),
            'barcode' => $barcode,
            'info' => trim((string) ($request['info'] ?? $request['notes'] ?? '')),
            'item_type' => $itemType,
            'track_stock' => 1,
            'preferred_unit_id' => $unitId,
            'market_price' => '0.000000',
            'cost_price' => $costPrice,
            'price1' => '0.000000',
            'price2' => '0.000000',
            'price3' => '0.000000',
            'group1' => $this->positiveInt($request['group1'] ?? $request['category_id'] ?? 0),
            'group2' => $this->positiveInt($request['group2'] ?? 0),
            'user' => $userId,
            'unit_id' => $unitId,
            'unit_barcode' => trim((string) ($request['unit_barcode'] ?? $barcode)),
            'store_id' => $storeId,
            'supplier_account_id' => $this->positiveInt($request['supplier_account_id'] ?? 0),
        ];
    }

    private function insertItem(mysqli $conn, array $payload): int
    {
        $columns = $this->columns($conn, 'myitems');
        $candidateValues = [
            'iname' => $payload['iname'],
            'name2' => $payload['name2'],
            'code' => $payload['code'],
            'barcode' => $payload['barcode'],
            'info' => $payload['info'],
            'market_price' => $payload['market_price'],
            'cost_price' => $payload['cost_price'],
            'last_price' => $payload['cost_price'],
            'price1' => $payload['price1'],
            'price2' => $payload['price2'],
            'price3' => $payload['price3'],
            'sprice' => $payload['price1'],
            'group1' => $payload['group1'],
            'group2' => $payload['group2'],
            'itmqty' => '0.000000',
            'item_type' => $payload['item_type'],
            'track_stock' => 1,
            'preferred_unit_id' => $payload['preferred_unit_id'],
            'isdeleted' => 0,
            'user' => $payload['user'],
            'tenant' => 0,
            'branch' => 0,
            'crtime' => date('Y-m-d H:i:s'),
            'mdtime' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $insert = [];
        foreach ($candidateValues as $column => $value) {
            if (isset($columns[$column])) {
                $insert[$column] = $value;
            }
        }
        if (!isset($insert['iname'])) {
            throw new RuntimeException('ITEM_SCHEMA_INCOMPATIBLE');
        }

        $quotedColumns = array_map([$this, 'quoteIdentifier'], array_keys($insert));
        $placeholders = implode(', ', array_fill(0, count($insert), '?'));
        $stmt = $conn->prepare('INSERT INTO myitems (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')');
        $this->bindParams($stmt, array_values($insert));
        $stmt->execute();
        $itemId = (int) $conn->insert_id;
        $stmt->close();

        if ($itemId < 1) {
            throw new RuntimeException('ITEM_CREATE_FAILED');
        }

        return $itemId;
    }

    private function insertUnit(mysqli $conn, int $itemId, array $payload): void
    {
        $columns = $this->columns($conn, 'item_units');
        $candidateValues = [
            'item_id' => $itemId,
            'unit_id' => $payload['unit_id'],
            'u_val' => '1.000000',
            'unit_barcode' => $payload['unit_barcode'],
            'cost_price' => $payload['cost_price'],
            'price1' => $payload['price1'],
            'price2' => $payload['price2'],
            'price3' => $payload['price3'],
            'isdeleted' => 0,
        ];

        $insert = [];
        foreach ($candidateValues as $column => $value) {
            if (isset($columns[$column])) {
                $insert[$column] = $value;
            }
        }
        if (!isset($insert['item_id'], $insert['unit_id'], $insert['u_val'])) {
            throw new RuntimeException('ITEM_UNIT_SCHEMA_INCOMPATIBLE');
        }

        $quotedColumns = array_map([$this, 'quoteIdentifier'], array_keys($insert));
        $placeholders = implode(', ', array_fill(0, count($insert), '?'));
        $stmt = $conn->prepare('INSERT INTO item_units (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')');
        $this->bindParams($stmt, array_values($insert));
        $stmt->execute();
        $stmt->close();
    }

    private function saveStockDefaults(mysqli $conn, int $itemId, array $payload, array $context): void
    {
        if ($payload['store_id'] < 1 || !$this->tableExists($conn, 'inventory_item_stock_levels')) {
            return;
        }

        $columns = $this->columns($conn, 'inventory_item_stock_levels');
        $nowUser = $this->positiveInt($context['user_id'] ?? $payload['user'] ?? 0);
        $insert = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'store_id' => $payload['store_id'],
            'item_id' => $itemId,
            'minimum_level' => '0.000000',
            'reorder_level' => '0.000000',
            'par_level' => '0.000000',
            'maximum_level' => '0.000000',
            'safety_stock_qty' => '0.000000',
            'preferred_count_unit_id' => $payload['unit_id'],
            'preferred_purchase_unit_id' => $payload['unit_id'],
            'default_supplier_account_id' => $payload['supplier_account_id'] > 0 ? $payload['supplier_account_id'] : null,
            'is_active' => 1,
            'created_by' => $nowUser > 0 ? $nowUser : null,
            'updated_by' => $nowUser > 0 ? $nowUser : null,
        ];

        $filtered = [];
        foreach ($insert as $column => $value) {
            if (isset($columns[$column])) {
                $filtered[$column] = $value;
            }
        }
        if (!isset($filtered['store_id'], $filtered['item_id'])) {
            return;
        }

        $updates = [];
        foreach (['preferred_count_unit_id', 'preferred_purchase_unit_id', 'default_supplier_account_id', 'is_active', 'updated_by'] as $column) {
            if (array_key_exists($column, $filtered)) {
                $updates[] = $this->quoteIdentifier($column) . ' = VALUES(' . $this->quoteIdentifier($column) . ')';
            }
        }

        $quotedColumns = array_map([$this, 'quoteIdentifier'], array_keys($filtered));
        $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
        $sql = 'INSERT INTO inventory_item_stock_levels (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')';
        if ($updates) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, array_values($filtered));
        $stmt->execute();
        $stmt->close();
    }

    private function assertTables(mysqli $conn): void
    {
        foreach (['myitems', 'myunits', 'item_units'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('ITEM_SCHEMA_INCOMPATIBLE');
            }
        }
    }

    private function assertUniqueName(mysqli $conn, string $name): void
    {
        $deletedFilter = $this->columnExists($conn, 'myitems', 'isdeleted') ? ' AND COALESCE(isdeleted, 0) = 0' : '';
        $row = $this->fetchOne($conn, "SELECT id FROM myitems WHERE iname = ?" . $deletedFilter . " LIMIT 1", [$name]);
        if ($row) {
            throw new InvalidArgumentException('ITEM_NAME_DUPLICATE');
        }
    }

    private function assertUniqueBarcode(mysqli $conn, string $barcode): void
    {
        if ($barcode === '') {
            return;
        }
        if (!$this->columnExists($conn, 'myitems', 'barcode')) {
            return;
        }
        $deletedFilter = $this->columnExists($conn, 'myitems', 'isdeleted') ? ' AND COALESCE(isdeleted, 0) = 0' : '';
        $row = $this->fetchOne($conn, "SELECT id FROM myitems WHERE barcode = ?" . $deletedFilter . " LIMIT 1", [$barcode]);
        if ($row) {
            throw new InvalidArgumentException('ITEM_BARCODE_DUPLICATE');
        }
    }

    private function assertUnitExists(mysqli $conn, int $unitId): void
    {
        $deletedFilter = $this->columnExists($conn, 'myunits', 'isdeleted') ? ' AND COALESCE(isdeleted, 0) = 0' : '';
        $row = $this->fetchOne($conn, "SELECT id FROM myunits WHERE id = ?" . $deletedFilter . " LIMIT 1", [$unitId]);
        if (!$row) {
            throw new InvalidArgumentException('ITEM_UNIT_REQUIRED');
        }
    }

    private function defaultUnitId(mysqli $conn): int
    {
        $deletedFilter = $this->columnExists($conn, 'myunits', 'isdeleted') ? ' WHERE COALESCE(isdeleted, 0) = 0' : '';
        $row = $conn->query("SELECT id FROM myunits" . $deletedFilter . " ORDER BY id LIMIT 1")->fetch_assoc();
        if ($row) {
            return (int) $row['id'];
        }

        $unitName = 'قطعة';
        $stmt = $conn->prepare('INSERT INTO myunits (uname) VALUES (?)');
        $stmt->bind_param('s', $unitName);
        $stmt->execute();
        $unitId = (int) $conn->insert_id;
        $stmt->close();

        return $unitId;
    }

    private function nextBarcode(mysqli $conn): string
    {
        if (!$this->columnExists($conn, 'myitems', 'barcode')) {
            return '';
        }
        $row = $conn->query("SELECT MAX(CAST(barcode AS UNSIGNED)) AS max_barcode FROM myitems WHERE barcode REGEXP '^[0-9]+$'")->fetch_assoc();

        return (string) (((int) ($row['max_barcode'] ?? 0)) + 1);
    }

    private function unitName(mysqli $conn, int $unitId): string
    {
        $row = $this->fetchOne($conn, 'SELECT uname FROM myunits WHERE id = ? LIMIT 1', [$unitId]);

        return (string) ($row['uname'] ?? ('وحدة ' . $unitId));
    }

    private function recordProcess(mysqli $conn): void
    {
        if (!$this->tableExists($conn, 'process') || !$this->columnExists($conn, 'process', 'type')) {
            return;
        }

        $type = 'quick inventory item';
        $stmt = $conn->prepare('INSERT INTO process(type) VALUES (?)');
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $stmt->close();
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

    private function columns(mysqli $conn, string $table): array
    {
        $stmt = $conn->prepare("
SELECT COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['COLUMN_NAME']] = true;
        }
        $stmt->close();

        return $columns;
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
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
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

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function positiveInt($value): int
    {
        return max(0, (int) $value);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function decimal($value): string
    {
        if (!is_numeric($value)) {
            return '0.000000';
        }

        return number_format((float) $value, 6, '.', '');
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
