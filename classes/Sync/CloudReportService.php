<?php

class CloudReportService
{
    public function branchSummary(mysqli $conn, string $branchUuid, array $filters = [], string $mode = 'live_apply'): array
    {
        $from = $this->normalizeDate($filters['from'] ?? null);
        $to = $this->normalizeDate($filters['to'] ?? null);
        $limit = $this->limit($filters['limit'] ?? 50);

        return [
            'branch_uuid' => $branchUuid,
            'range' => [
                'from' => $from,
                'to' => $to,
            ],
            'trust' => $this->trust($mode),
            'report_trusted' => $mode === 'live_apply',
            'sales' => $this->salesSummary($conn, $branchUuid, $from, $to),
            'by_cashier' => $this->groupedOrderTotals($conn, $branchUuid, $from, $to, 'cashier_user_id'),
            'by_waiter' => $this->groupedOrderTotals($conn, $branchUuid, $from, $to, 'waiter_id'),
            'by_order_type' => $this->groupedOrderTotals($conn, $branchUuid, $from, $to, 'order_type'),
            'by_source' => $this->sourceTotals($conn, $branchUuid, $from, $to),
            'payments' => $this->paymentBreakdown($conn, $branchUuid, $from, $to),
            'items' => $this->itemSales($conn, $branchUuid, $from, $to, $limit),
            'shifts' => $this->shiftSummary($conn, $branchUuid, $from, $to),
            'tables' => $this->tableSummary($conn, $branchUuid),
            'snapshot_counts' => $this->snapshotCounts($conn, $branchUuid),
        ];
    }

    private function trust(string $mode): array
    {
        if ($mode === 'live_apply') {
            return [
                'mode' => $mode,
                'report_trusted' => true,
                'label' => 'trusted',
                'warning' => null,
            ];
        }

        return [
            'mode' => $mode,
            'report_trusted' => false,
            'label' => $mode === 'receive_only' ? 'receive_only_untrusted' : 'shadow_untrusted',
            'warning' => $mode === 'receive_only'
                ? 'Cloud apply is disabled; reports are based only on previously applied snapshots.'
                : 'Shadow mode is enabled; compare against local reports before trusting cloud totals.',
        ];
    }

    private function salesSummary(mysqli $conn, string $branchUuid, ?string $from, ?string $to): array
    {
        [$where, $types, $params] = $this->where($branchUuid, 'branch_uuid', 'pro_date', $from, $to);
        $cancelled = $this->cancelledSql();
        $paid = $this->paidSql();

        $row = $this->queryOne($conn, "
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN {$cancelled} THEN 1 ELSE 0 END), 0) AS cancelled_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN 1 ELSE 0 END), 0) AS net_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) AND {$paid} THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) AND LOWER(COALESCE(payment_status, '')) = 'partial' THEN 1 ELSE 0 END), 0) AS partial_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN fat_total ELSE 0 END), 0) AS total_sales,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN fat_net ELSE 0 END), 0) AS net_sales,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN fat_disc ELSE 0 END), 0) AS discounts,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN paid_amount ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN remaining_amount ELSE 0 END), 0) AS remaining_amount,
                COALESCE(SUM(CASE WHEN {$cancelled} THEN fat_total ELSE 0 END), 0) AS cancelled_sales
            FROM cloud_orders
            WHERE {$where}
        ", $types, $params);

        return [
            'total_orders' => (int) ($row['total_orders'] ?? 0),
            'cancelled_orders' => (int) ($row['cancelled_orders'] ?? 0),
            'net_orders' => (int) ($row['net_orders'] ?? 0),
            'paid_orders' => (int) ($row['paid_orders'] ?? 0),
            'partial_orders' => (int) ($row['partial_orders'] ?? 0),
            'total_sales' => $this->decimal($row['total_sales'] ?? null),
            'net_sales' => $this->decimal($row['net_sales'] ?? null),
            'discounts' => $this->decimal($row['discounts'] ?? null),
            'paid_amount' => $this->decimal($row['paid_amount'] ?? null),
            'remaining_amount' => $this->decimal($row['remaining_amount'] ?? null),
            'cancelled_sales' => $this->decimal($row['cancelled_sales'] ?? null),
        ];
    }

    private function groupedOrderTotals(
        mysqli $conn,
        string $branchUuid,
        ?string $from,
        ?string $to,
        string $column
    ): array {
        $allowed = ['cashier_user_id', 'waiter_id', 'order_type'];
        if (!in_array($column, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported cloud report grouping.');
        }

        [$where, $types, $params] = $this->where($branchUuid, 'branch_uuid', 'pro_date', $from, $to);
        $cancelled = $this->cancelledSql();
        $rows = $this->queryAll($conn, "
            SELECT
                {$column} AS group_key,
                COUNT(*) AS order_count,
                COALESCE(SUM(fat_total), 0) AS total_sales
            FROM cloud_orders
            WHERE {$where}
              AND NOT ({$cancelled})
            GROUP BY {$column}
            ORDER BY total_sales DESC, order_count DESC
        ", $types, $params);

        return array_map(function (array $row): array {
            return [
                'key' => $row['group_key'],
                'order_count' => (int) ($row['order_count'] ?? 0),
                'total_sales' => $this->decimal($row['total_sales'] ?? null),
            ];
        }, $rows);
    }

    private function sourceTotals(mysqli $conn, string $branchUuid, ?string $from, ?string $to): array
    {
        [$where, $types, $params] = $this->where($branchUuid, 'branch_uuid', 'pro_date', $from, $to);
        $cancelled = $this->cancelledSql();
        $rows = $this->queryAll($conn, "
            SELECT
                CASE
                    WHEN LOWER(COALESCE(source_system, '')) = 'moova' THEN 'moova'
                    WHEN COALESCE(source_external_id, '') <> '' THEN 'external'
                    ELSE 'local'
                END AS source_bucket,
                COUNT(*) AS order_count,
                COALESCE(SUM(fat_total), 0) AS total_sales
            FROM cloud_orders
            WHERE {$where}
              AND NOT ({$cancelled})
            GROUP BY source_bucket
            ORDER BY total_sales DESC, source_bucket ASC
        ", $types, $params);

        return array_map(function (array $row): array {
            return [
                'source' => (string) ($row['source_bucket'] ?? 'local'),
                'order_count' => (int) ($row['order_count'] ?? 0),
                'total_sales' => $this->decimal($row['total_sales'] ?? null),
            ];
        }, $rows);
    }

    private function paymentBreakdown(mysqli $conn, string $branchUuid, ?string $from, ?string $to): array
    {
        [$where, $types, $params] = $this->where($branchUuid, 'p.branch_uuid', 'o.pro_date', $from, $to);
        $cancelled = $this->cancelledSql('o');
        $rows = $this->queryAll($conn, "
            SELECT
                COALESCE(NULLIF(p.payment_method, ''), 'unknown') AS payment_method,
                COUNT(*) AS payment_count,
                COALESCE(SUM(p.amount), 0) AS amount
            FROM cloud_order_payments p
            INNER JOIN cloud_orders o
                ON o.branch_uuid = p.branch_uuid
               AND o.order_uuid = p.order_uuid
            WHERE {$where}
              AND p.voided = 0
              AND NOT ({$cancelled})
            GROUP BY payment_method
            ORDER BY amount DESC, payment_method ASC
        ", $types, $params);

        return array_map(function (array $row): array {
            return [
                'payment_method' => (string) ($row['payment_method'] ?? 'unknown'),
                'payment_count' => (int) ($row['payment_count'] ?? 0),
                'amount' => $this->decimal($row['amount'] ?? null),
            ];
        }, $rows);
    }

    private function itemSales(mysqli $conn, string $branchUuid, ?string $from, ?string $to, int $limit): array
    {
        [$where, $types, $params] = $this->where($branchUuid, 'l.branch_uuid', 'o.pro_date', $from, $to);
        $cancelled = $this->cancelledSql('o');
        $rows = $this->queryAll($conn, "
            SELECT
                l.item_uuid,
                l.item_id,
                l.item_name,
                m.category_id,
                COALESCE(SUM(l.qty_out), 0) AS qty_out,
                COALESCE(SUM(l.det_value), 0) AS line_total
            FROM cloud_order_lines l
            INNER JOIN cloud_orders o
                ON o.branch_uuid = l.branch_uuid
               AND o.order_uuid = l.order_uuid
            LEFT JOIN cloud_menu_items m
                ON m.branch_uuid = l.branch_uuid
               AND m.item_uuid = l.item_uuid
            WHERE {$where}
              AND l.isdeleted = 0
              AND NOT ({$cancelled})
            GROUP BY l.item_uuid, l.item_id, l.item_name, m.category_id
            ORDER BY line_total DESC, l.item_name ASC
            LIMIT {$limit}
        ", $types, $params);

        return array_map(function (array $row): array {
            return [
                'item_uuid' => $row['item_uuid'],
                'item_id' => $row['item_id'] === null ? null : (int) $row['item_id'],
                'item_name' => $row['item_name'],
                'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
                'qty_out' => $this->decimal($row['qty_out'] ?? null),
                'line_total' => $this->decimal($row['line_total'] ?? null),
            ];
        }, $rows);
    }

    private function shiftSummary(mysqli $conn, string $branchUuid, ?string $from, ?string $to): array
    {
        [$where, $types, $params] = $this->where($branchUuid, 'branch_uuid', 'closed_at', $from, $to);
        $row = $this->queryOne($conn, "
            SELECT
                COUNT(*) AS shift_count,
                COALESCE(SUM(total_sales), 0) AS total_sales,
                COALESCE(SUM(total_cash), 0) AS expected_cash,
                COALESCE(SUM(total_card), 0) AS expected_card,
                COALESCE(SUM(actual_cash), 0) AS actual_cash,
                COALESCE(SUM(actual_card), 0) AS actual_card,
                COALESCE(SUM(cash_deficit), 0) AS cash_deficit,
                COALESCE(SUM(card_deficit), 0) AS card_deficit
            FROM cloud_shifts
            WHERE {$where}
        ", $types, $params);

        return [
            'shift_count' => (int) ($row['shift_count'] ?? 0),
            'total_sales' => $this->decimal($row['total_sales'] ?? null),
            'expected_cash' => $this->decimal($row['expected_cash'] ?? null),
            'expected_card' => $this->decimal($row['expected_card'] ?? null),
            'actual_cash' => $this->decimal($row['actual_cash'] ?? null),
            'actual_card' => $this->decimal($row['actual_card'] ?? null),
            'cash_deficit' => $this->decimal($row['cash_deficit'] ?? null),
            'card_deficit' => $this->decimal($row['card_deficit'] ?? null),
        ];
    }

    private function tableSummary(mysqli $conn, string $branchUuid): array
    {
        $row = $this->queryOne($conn, "
            SELECT
                COUNT(*) AS table_count,
                COALESCE(SUM(CASE WHEN active_order_uuid IS NOT NULL THEN 1 ELSE 0 END), 0) AS active_tables,
                COALESCE(SUM(CASE WHEN table_case <> 0 THEN 1 ELSE 0 END), 0) AS occupied_tables,
                COALESCE(SUM(CASE WHEN isdeleted = 1 THEN 1 ELSE 0 END), 0) AS deleted_tables
            FROM cloud_tables
            WHERE branch_uuid = ?
        ", 's', [$branchUuid]);

        return [
            'table_count' => (int) ($row['table_count'] ?? 0),
            'active_tables' => (int) ($row['active_tables'] ?? 0),
            'occupied_tables' => (int) ($row['occupied_tables'] ?? 0),
            'deleted_tables' => (int) ($row['deleted_tables'] ?? 0),
        ];
    }

    private function snapshotCounts(mysqli $conn, string $branchUuid): array
    {
        $tables = [
            'orders' => 'cloud_orders',
            'order_lines' => 'cloud_order_lines',
            'payments' => 'cloud_order_payments',
            'payment_receipts' => 'cloud_payment_receipts',
            'tables' => 'cloud_tables',
            'shifts' => 'cloud_shifts',
            'menu_items' => 'cloud_menu_items',
        ];

        $counts = [];
        foreach ($tables as $key => $table) {
            $row = $this->queryOne($conn, "SELECT COUNT(*) AS c FROM {$table} WHERE branch_uuid = ?", 's', [$branchUuid]);
            $counts[$key] = (int) ($row['c'] ?? 0);
        }

        return $counts;
    }

    private function where(string $branchUuid, string $branchColumn, string $dateColumn, ?string $from, ?string $to): array
    {
        $where = "{$branchColumn} = ?";
        $types = 's';
        $params = [$branchUuid];

        if ($from !== null) {
            $where .= " AND {$dateColumn} >= ?";
            $types .= 's';
            $params[] = $from;
        }

        if ($to !== null) {
            $where .= " AND {$dateColumn} < ?";
            $types .= 's';
            $params[] = $to;
        }

        return [$where, $types, $params];
    }

    private function cancelledSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        return "({$prefix}isdeleted = 1 OR LOWER(COALESCE({$prefix}order_status, '')) IN ('cancelled','canceled','deleted','voided'))";
    }

    private function paidSql(): string
    {
        return "LOWER(COALESCE(payment_status, '')) IN ('paid','completed','complete')";
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('Invalid report date filter.');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function limit($value): int
    {
        $limit = (int) ($value ?: 50);
        if ($limit < 1) {
            return 1;
        }

        if ($limit > 500) {
            return 500;
        }

        return $limit;
    }

    private function decimal($value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '0.0000';
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function queryOne(mysqli $conn, string $sql, string $types, array $params): array
    {
        $rows = $this->queryAll($conn, $sql, $types, $params);
        return $rows[0] ?? [];
    }

    private function queryAll(mysqli $conn, string $sql, string $types, array $params): array
    {
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        $stmt->bind_param($types, ...$refs);
    }
}
