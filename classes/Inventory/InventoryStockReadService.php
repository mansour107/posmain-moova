<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../includes/pos_default_accounts.php';

class InventoryStockReadService
{
    private InventoryFeatureFlags $flags;

    public function __construct(?InventoryFeatureFlags $flags = null)
    {
        $this->flags = $flags ?: new InventoryFeatureFlags();
    }

    public function shouldReadLedger(mysqli $conn): bool
    {
        return $this->flags->mode() === 'live'
            && $this->tableExists($conn, 'inventory_item_balances')
            && $this->tableExists($conn, 'inventory_movements');
    }

    public function stockSource(mysqli $conn): string
    {
        return $this->shouldReadLedger($conn) ? 'ledger' : 'legacy';
    }

    public function defaultScope(mysqli $conn, array $scope = []): array
    {
        $config = method_exists($this->flags, 'appConfig') ? $this->flags->appConfig() : [];
        $branch = is_array($config['branch'] ?? null) ? $config['branch'] : [];
        $inventory = is_array($config['inventory'] ?? null) ? $config['inventory'] : [];

        $base = [
            'pos_tenant' => $this->nonNegativeInt($scope['pos_tenant'] ?? $scope['tenant'] ?? $branch['pos_tenant'] ?? 0),
            'pos_branch' => $this->nonNegativeInt($scope['pos_branch'] ?? $scope['branch'] ?? $branch['pos_branch'] ?? 0),
            'store_id' => $this->positiveInt($scope['store_id'] ?? $inventory['default_store_id'] ?? 0)
                ?: $this->defaultStoreId($conn),
        ];

        if (function_exists('posmain_resolve_store_scope_for_read')) {
            return posmain_resolve_store_scope_for_read($conn, array_merge($base, $scope));
        }

        return array_merge($base, $scope);
    }

    public function decorateItems(mysqli $conn, array $items, array $scope = []): array
    {
        if (!$items) {
            return [];
        }

        if (!$this->shouldReadLedger($conn)) {
            foreach ($items as &$item) {
                $item['legacy_itmqty'] = InventoryDecimal::normalize($item['itmqty'] ?? '0');
                $item['stock_qty_source'] = 'legacy';
                $item['stock_qty_display'] = $item['legacy_itmqty'];
            }
            unset($item);

            return $items;
        }

        $scope = $this->defaultScope($conn, $scope);
        $balances = $this->balancesForItems($conn, $this->itemIds($items), $scope);
        foreach ($items as &$item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $balance = $balances[$itemId] ?? null;
            $item['legacy_itmqty'] = InventoryDecimal::normalize($item['itmqty'] ?? '0');
            $item['stock_qty_source'] = 'ledger';
            $item['stock_store_id'] = (int) ($scope['store_id'] ?? 0);
            $item['ledger_qty_on_hand'] = InventoryDecimal::normalize($balance['qty_on_hand'] ?? '0');
            $item['ledger_qty_reserved'] = InventoryDecimal::normalize($balance['qty_reserved'] ?? '0');
            $item['ledger_qty_available'] = InventoryDecimal::normalize($balance['qty_available'] ?? $item['ledger_qty_on_hand']);
            $item['ledger_moving_average_cost'] = InventoryDecimal::normalize($balance['moving_average_cost'] ?? $item['cost_price'] ?? '0');
            $item['itmqty'] = $item['ledger_qty_on_hand'];
            $item['stock_qty_display'] = $item['ledger_qty_on_hand'];
        }
        unset($item);

        return $items;
    }

    public function decoratePublicItemPayload(mysqli $conn, array $items, array $scope = []): array
    {
        if (!$items) {
            return [];
        }

        $stockRows = [];
        foreach ($items as $item) {
            $stockRows[] = [
                'id' => (int) ($item['id'] ?? 0),
                'itmqty' => (string) ($item['quantity'] ?? '0'),
                'cost_price' => '0.000000',
            ];
        }

        $decoratedRows = $this->decorateItems($conn, $stockRows, $scope);
        $stockByItemId = [];
        foreach ($decoratedRows as $row) {
            $stockByItemId[(int) ($row['id'] ?? 0)] = $row;
        }

        foreach ($items as &$item) {
            $stock = $stockByItemId[(int) ($item['id'] ?? 0)] ?? null;
            if (!is_array($stock)) {
                continue;
            }

            $item['quantity'] = (float) ($stock['stock_qty_display'] ?? $item['quantity'] ?? 0);
            $item['stock_quantity_source'] = (string) ($stock['stock_qty_source'] ?? 'legacy');
            if (($stock['stock_qty_source'] ?? '') === 'ledger') {
                $item['available_quantity'] = (float) ($stock['ledger_qty_available'] ?? $stock['stock_qty_display'] ?? 0);
                $item['reserved_quantity'] = (float) ($stock['ledger_qty_reserved'] ?? 0);
                $item['stock_store_id'] = (int) ($stock['stock_store_id'] ?? 0);
            }
        }
        unset($item);

        return $items;
    }

    public function movementHistoryForItem(mysqli $conn, int $itemId, array $scope = [], array $filters = []): array
    {
        $itemId = $this->positiveInt($itemId);
        if ($itemId < 1 || !$this->shouldReadLedger($conn)) {
            return [
                'source' => 'legacy',
                'rows' => [],
                'total_in' => InventoryDecimal::zero(),
                'total_out' => InventoryDecimal::zero(),
                'balance' => InventoryDecimal::zero(),
                'total_count' => 0,
            ];
        }

        $scope = $this->defaultScope($conn, $scope);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $conditions = ['im.item_id = ?', 'im.pos_tenant = ?', 'im.pos_branch = ?'];
        $params = [$itemId, (int) $scope['pos_tenant'], (int) $scope['pos_branch']];
        if ((int) $scope['store_id'] > 0) {
            $conditions[] = 'im.store_id = ?';
            $params[] = (int) $scope['store_id'];
        }
        if (!empty($filters['from'])) {
            $conditions[] = 'im.created_at >= ?';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $conditions[] = 'im.created_at <= ?';
            $params[] = (string) $filters['to'] . ' 23:59:59';
        }
        $where = implode(' AND ', $conditions);

        $rows = $this->fetchAll($conn, "
SELECT
  im.id,
  im.created_at,
  im.movement_type,
  im.source_type,
  im.source_id,
  im.store_id,
  im.qty_in,
  im.qty_out,
  im.unit_cost,
  im.total_cost,
  im.accounting_journal_id,
  im.idempotency_key,
  COALESCE(store.aname, '') AS store_name,
  COALESCE(item.barcode, '') AS item_barcode
FROM inventory_movements im
LEFT JOIN acc_head store ON store.id = im.store_id
LEFT JOIN myitems item ON item.id = im.item_id
WHERE {$where}
ORDER BY im.created_at ASC, im.id ASC
LIMIT {$limit} OFFSET {$offset}", $params);

        $summary = $this->fetchOne($conn, "
SELECT
  COUNT(*) AS total_count,
  COALESCE(SUM(im.qty_in), 0) AS total_in,
  COALESCE(SUM(im.qty_out), 0) AS total_out
FROM inventory_movements im
WHERE {$where}", $params) ?: [];

        return [
            'source' => 'ledger',
            'rows' => $rows,
            'total_in' => InventoryDecimal::normalize($summary['total_in'] ?? '0'),
            'total_out' => InventoryDecimal::normalize($summary['total_out'] ?? '0'),
            'balance' => InventoryDecimal::subtract($summary['total_in'] ?? '0', $summary['total_out'] ?? '0'),
            'total_count' => (int) ($summary['total_count'] ?? 0),
            'scope' => $scope,
        ];
    }

    public function itemListLedgerJoin(mysqli $conn, string $itemAlias = 'mi', array $scope = []): array
    {
        if (!$this->shouldReadLedger($conn)) {
            return [
                'join_sql' => '',
                'qty_expr' => $itemAlias . '.itmqty',
                'cost_expr' => $itemAlias . '.cost_price',
                'source' => 'legacy',
            ];
        }

        $scope = $this->defaultScope($conn, $scope);
        $tenant = (int) $scope['pos_tenant'];
        $branch = (int) $scope['pos_branch'];
        $storeId = (int) $scope['store_id'];
        $storeCondition = $storeId > 0 ? ' AND store_id = ' . $storeId : '';
        $joinSql = "
LEFT JOIN (
  SELECT
    item_id,
    SUM(qty_on_hand) AS qty_on_hand,
    SUM(qty_available) AS qty_available,
    CASE
      WHEN SUM(qty_on_hand) = 0 THEN 0
      ELSE SUM(qty_on_hand * moving_average_cost) / SUM(qty_on_hand)
    END AS moving_average_cost
  FROM inventory_item_balances
  WHERE pos_tenant = {$tenant}
    AND pos_branch = {$branch}
    {$storeCondition}
  GROUP BY item_id
) ledger_stock ON ledger_stock.item_id = {$itemAlias}.id";

        return [
            'join_sql' => $joinSql,
            'qty_expr' => 'COALESCE(ledger_stock.qty_on_hand, 0)',
            'cost_expr' => 'COALESCE(ledger_stock.moving_average_cost, ' . $itemAlias . '.cost_price)',
            'source' => 'ledger',
            'scope' => $scope,
        ];
    }

    private function balancesForItems(mysqli $conn, array $itemIds, array $scope): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$itemIds) {
            return [];
        }

        $conditions = ['pos_tenant = ?', 'pos_branch = ?', 'item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')'];
        $params = [(int) $scope['pos_tenant'], (int) $scope['pos_branch']];
        foreach ($itemIds as $itemId) {
            $params[] = $itemId;
        }
        if ((int) $scope['store_id'] > 0) {
            $conditions[] = 'store_id = ?';
            $params[] = (int) $scope['store_id'];
        }

        $rows = $this->fetchAll($conn, "
SELECT
  item_id,
  SUM(qty_on_hand) AS qty_on_hand,
  SUM(qty_reserved) AS qty_reserved,
  SUM(qty_available) AS qty_available,
  CASE
    WHEN SUM(qty_on_hand) = 0 THEN 0
    ELSE SUM(qty_on_hand * moving_average_cost) / SUM(qty_on_hand)
  END AS moving_average_cost
FROM inventory_item_balances
WHERE " . implode(' AND ', $conditions) . "
GROUP BY item_id", $params);

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row['item_id']] = $row;
        }

        return $balances;
    }

    private function defaultStoreId(mysqli $conn): int
    {
        if (function_exists('posmain_operational_store_id')) {
            $operational = posmain_operational_store_id($conn);
            if ($operational > 0) {
                return $operational;
            }
        }

        if (!$this->tableExists($conn, 'acc_head')) {
            return 0;
        }
        if (!$this->columnExists($conn, 'acc_head', 'is_stock')) {
            return 0;
        }

        $row = $this->fetchOne($conn, "SELECT id FROM acc_head WHERE COALESCE(isdeleted, 0) = 0 AND COALESCE(is_stock, 0) = 1 ORDER BY id ASC LIMIT 1");

        return (int) ($row['id'] ?? 0);
    }

    private function itemIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) ($item['id'] ?? $item['item_id'] ?? 0);
        }

        return $ids;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $row = $this->fetchOne($conn, "
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?", [$table]);

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $row = $this->fetchOne($conn, "
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?", [$table, $column]);

        return (int) ($row['column_count'] ?? 0) > 0;
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
            $this->bindParams($stmt, $params);
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

    private function bindParams(mysqli_stmt $stmt, array $params): void
    {
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

    private function positiveInt($value): int
    {
        return (int) $value > 0 ? (int) $value : 0;
    }

    private function nonNegativeInt($value): int
    {
        return max(0, (int) $value);
    }
}
