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
        [$where, $types, $params] = $this->where($branchUuid, 'o.branch_uuid', 'o.pro_date', $from, $to);
        $cancelled = $this->cancelledSql('o');
        $paid = $this->paidSql('o');

        $row = $this->queryOne($conn, "
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN {$cancelled} THEN 1 ELSE 0 END), 0) AS cancelled_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN 1 ELSE 0 END), 0) AS net_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) AND {$paid} THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) AND LOWER(COALESCE(o.payment_status, '')) = 'partial' THEN 1 ELSE 0 END), 0) AS partial_orders,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN o.fat_total ELSE 0 END), 0) AS total_sales,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN o.fat_net ELSE 0 END), 0) AS net_sales,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN o.fat_disc ELSE 0 END), 0) AS discounts,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN o.paid_amount ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN NOT ({$cancelled}) THEN o.remaining_amount ELSE 0 END), 0) AS remaining_amount,
                COALESCE(SUM(CASE WHEN {$cancelled} THEN o.fat_total ELSE 0 END), 0) AS cancelled_sales
            FROM cloud_orders o
            WHERE {$where}
        ", $types, $params);
        $refunds = $this->refundSummary($conn, $branchUuid, $from, $to);
        $salesAfterDiscount = (float) ($row['net_sales'] ?? 0);
        $refundTotal = (float) $refunds['total'];

        return [
            'total_orders' => (int) ($row['total_orders'] ?? 0),
            'cancelled_orders' => (int) ($row['cancelled_orders'] ?? 0),
            'net_orders' => (int) ($row['net_orders'] ?? 0),
            'paid_orders' => (int) ($row['paid_orders'] ?? 0),
            'partial_orders' => (int) ($row['partial_orders'] ?? 0),
            'total_sales' => $this->decimal($row['total_sales'] ?? null),
            'sales_after_discount' => $this->decimal($salesAfterDiscount),
            'refunds' => $this->decimal($refundTotal),
            'refund_count' => $refunds['count'],
            'net_sales' => $this->decimal($salesAfterDiscount - $refundTotal),
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

        [$where, $types, $params] = $this->where($branchUuid, 'o.branch_uuid', 'o.pro_date', $from, $to);
        $cancelled = $this->cancelledSql('o');
        $rows = $this->queryAll($conn, "
            SELECT
                o.{$column} AS group_key,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.fat_net), 0) AS total_sales
            FROM cloud_orders o
            WHERE {$where}
              AND NOT ({$cancelled})
            GROUP BY o.{$column}
            ORDER BY total_sales DESC, order_count DESC
        ", $types, $params);

        $refundGroups = $this->refundGroups($conn, $branchUuid, $from, $to, $column);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) ($row['group_key'] ?? '')] = $row;
        }
        foreach ($refundGroups as $refund) {
            $key = (string) ($refund['group_key'] ?? '');
            if (!isset($indexed[$key])) {
                $indexed[$key] = ['group_key' => $refund['group_key'], 'order_count' => 0, 'total_sales' => 0];
            }
            $indexed[$key]['total_sales'] = (float) $indexed[$key]['total_sales'] - (float) $refund['refund_total'];
        }
        $rows = array_values($indexed);
        usort($rows, static fn (array $a, array $b): int => (float) $b['total_sales'] <=> (float) $a['total_sales']);

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
        [$where, $types, $params] = $this->where($branchUuid, 'o.branch_uuid', 'o.pro_date', $from, $to);
        $cancelled = $this->cancelledSql('o');
        $rows = $this->queryAll($conn, "
            SELECT
                CASE
                    WHEN LOWER(COALESCE(o.source_system, '')) = 'moova' THEN 'moova'
                    WHEN COALESCE(o.source_external_id, '') <> '' THEN 'external'
                    ELSE 'local'
                END AS source_bucket,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.fat_net), 0) AS total_sales
            FROM cloud_orders o
            WHERE {$where}
              AND NOT ({$cancelled})
            GROUP BY source_bucket
            ORDER BY total_sales DESC, source_bucket ASC
        ", $types, $params);

        foreach ($this->refundGroups($conn, $branchUuid, $from, $to, 'source_bucket') as $refund) {
            $key = (string) ($refund['group_key'] ?? 'local');
            $found = false;
            foreach ($rows as &$row) {
                if ((string) ($row['source_bucket'] ?? '') === $key) {
                    $row['total_sales'] = (float) $row['total_sales'] - (float) $refund['refund_total'];
                    $found = true;
                    break;
                }
            }
            unset($row);
            if (!$found) {
                $rows[] = ['source_bucket' => $key, 'order_count' => 0, 'total_sales' => -(float) $refund['refund_total']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => (float) $b['total_sales'] <=> (float) $a['total_sales']);

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

        $indexed = [];
        foreach ($rows as $row) {
            $method = (string) ($row['payment_method'] ?? 'unknown');
            $indexed[$method] = $row + [
                'refund_count' => 0,
                'refunded_amount' => 0,
                'settled_refunded_amount' => 0,
                'pending_refund_amount' => 0,
            ];
        }

        [$refundJoins, $refundWhere, $refundTypes, $refundParams] = $this->refundScope($branchUuid, $from, $to);
        $refundRows = $this->queryAll($conn, "
            SELECT COALESCE(NULLIF(p.payment_method, ''), 'unknown') AS payment_method,
                   COUNT(pr.id) AS refund_count,
                   COALESCE(SUM(pr.amount), 0) AS refunded_amount,
                   COALESCE(SUM(CASE
                       WHEN pr.status = 'settled' OR (pr.status = 'posted' AND LOWER(COALESCE(p.payment_method, '')) = 'cash')
                       THEN pr.amount ELSE 0 END), 0) AS settled_refunded_amount,
                   COALESCE(SUM(CASE WHEN pr.status = 'pending_external' THEN pr.amount ELSE 0 END), 0) AS pending_refund_amount
            FROM credit_notes cn {$refundJoins}
            INNER JOIN payment_refunds pr ON pr.credit_note_id = cn.id
            LEFT JOIN cloud_order_payments p
                   ON p.branch_uuid = refund_order.branch_uuid
                  AND p.order_uuid = refund_order.order_uuid
                  AND p.local_payment_id = pr.original_payment_id
            WHERE {$refundWhere}
            GROUP BY payment_method
        ", $refundTypes, $refundParams);
        foreach ($refundRows as $refund) {
            $method = (string) ($refund['payment_method'] ?? 'unknown');
            if (!isset($indexed[$method])) {
                $indexed[$method] = [
                    'payment_method' => $method,
                    'payment_count' => 0,
                    'amount' => 0,
                    'refund_count' => 0,
                    'refunded_amount' => 0,
                    'settled_refunded_amount' => 0,
                    'pending_refund_amount' => 0,
                ];
            }
            foreach (['refund_count', 'refunded_amount', 'settled_refunded_amount', 'pending_refund_amount'] as $field) {
                $indexed[$method][$field] = $refund[$field] ?? 0;
            }
        }
        $rows = array_values($indexed);
        usort($rows, static fn (array $a, array $b): int => (float) $b['amount'] <=> (float) $a['amount']);

        return array_map(function (array $row): array {
            $collected = (float) ($row['amount'] ?? 0);
            $refunded = (float) ($row['refunded_amount'] ?? 0);
            $settledRefunded = (float) ($row['settled_refunded_amount'] ?? 0);
            return [
                'payment_method' => (string) ($row['payment_method'] ?? 'unknown'),
                'payment_count' => (int) ($row['payment_count'] ?? 0),
                'amount' => $this->decimal($collected),
                'refund_count' => (int) ($row['refund_count'] ?? 0),
                'refunded_amount' => $this->decimal($refunded),
                'settled_refunded_amount' => $this->decimal($settledRefunded),
                'pending_refund_amount' => $this->decimal($row['pending_refund_amount'] ?? 0),
                'net_after_refunds' => $this->decimal($collected - $refunded),
                'net_custody' => $this->decimal($collected - $settledRefunded),
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

        $refundRows = $this->refundedItems($conn, $branchUuid, $from, $to);
        $indexed = [];
        foreach ($rows as $row) {
            $key = (string) ($row['item_uuid'] ?: 'id:' . $row['item_id']);
            $row['qty_refunded'] = 0;
            $row['refund_total'] = 0;
            $indexed[$key] = $row;
        }
        foreach ($refundRows as $refund) {
            $key = (string) ($refund['item_uuid'] ?: 'id:' . $refund['item_id']);
            if (!isset($indexed[$key])) {
                $indexed[$key] = [
                    'item_uuid' => $refund['item_uuid'],
                    'item_id' => $refund['item_id'],
                    'item_name' => $refund['item_name'],
                    'category_id' => $refund['category_id'],
                    'qty_out' => 0,
                    'line_total' => 0,
                    'qty_refunded' => 0,
                    'refund_total' => 0,
                ];
            }
            $indexed[$key]['qty_refunded'] += (float) $refund['qty_refunded'];
            $indexed[$key]['refund_total'] += (float) $refund['refund_total'];
        }
        $rows = array_values($indexed);
        usort($rows, static fn (array $a, array $b): int =>
            ((float) $b['line_total'] - (float) $b['refund_total']) <=> ((float) $a['line_total'] - (float) $a['refund_total'])
        );
        $rows = array_slice($rows, 0, $limit);

        return array_map(function (array $row): array {
            $soldQty = (float) ($row['qty_out'] ?? 0);
            $refundedQty = (float) ($row['qty_refunded'] ?? 0);
            $soldTotal = (float) ($row['line_total'] ?? 0);
            $refundTotal = (float) ($row['refund_total'] ?? 0);
            return [
                'item_uuid' => $row['item_uuid'],
                'item_id' => $row['item_id'] === null ? null : (int) $row['item_id'],
                'item_name' => $row['item_name'],
                'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
                'qty_out' => $this->decimal($soldQty),
                'qty_refunded' => $this->decimal($refundedQty),
                'net_qty' => $this->decimal($soldQty - $refundedQty),
                'line_total' => $this->decimal($soldTotal),
                'refund_total' => $this->decimal($refundTotal),
                'net_total' => $this->decimal($soldTotal - $refundTotal),
            ];
        }, $rows);
    }

    /** @return array{total:float,count:int} */
    private function refundSummary(mysqli $conn, string $branchUuid, ?string $from, ?string $to): array
    {
        [$joins, $where, $types, $params] = $this->refundScope($branchUuid, $from, $to);
        $row = $this->queryOne($conn, "
            SELECT COUNT(*) AS refund_count, COALESCE(SUM(cn.total_amount), 0) AS refund_total
            FROM credit_notes cn {$joins}
            WHERE {$where}
        ", $types, $params);
        return ['total' => (float) ($row['refund_total'] ?? 0), 'count' => (int) ($row['refund_count'] ?? 0)];
    }

    /** @return list<array<string,mixed>> */
    private function refundGroups(
        mysqli $conn,
        string $branchUuid,
        ?string $from,
        ?string $to,
        string $column
    ): array {
        [$joins, $where, $types, $params] = $this->refundScope($branchUuid, $from, $to);
        $groupExpr = match ($column) {
            'cashier_user_id' => 'cn.created_by',
            'waiter_id' => 'refund_order.waiter_id',
            'order_type' => 'refund_order.order_type',
            'source_bucket' => "CASE WHEN LOWER(COALESCE(refund_order.source_system, '')) = 'moova' THEN 'moova' WHEN COALESCE(refund_order.source_external_id, '') <> '' THEN 'external' ELSE 'local' END",
            default => throw new InvalidArgumentException('Unsupported cloud refund grouping.'),
        };
        return $this->queryAll($conn, "
            SELECT {$groupExpr} AS group_key, COALESCE(SUM(cn.total_amount), 0) AS refund_total
            FROM credit_notes cn {$joins}
            WHERE {$where}
            GROUP BY {$groupExpr}
        ", $types, $params);
    }

    /** @return list<array<string,mixed>> */
    private function refundedItems(mysqli $conn, string $branchUuid, ?string $from, ?string $to): array
    {
        [$joins, $where, $types, $params] = $this->refundScope($branchUuid, $from, $to);
        return $this->queryAll($conn, "
            SELECT l.item_uuid, l.item_id, l.item_name, m.category_id,
                   COALESCE(SUM(cnl.quantity), 0) AS qty_refunded,
                   COALESCE(SUM(cnl.line_amount), 0) AS refund_total
            FROM credit_notes cn {$joins}
            INNER JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
            INNER JOIN cloud_order_lines l
                    ON l.branch_uuid = refund_order.branch_uuid
                   AND l.order_uuid = refund_order.order_uuid
                   AND l.local_line_id = cnl.original_detail_id
            LEFT JOIN cloud_menu_items m
                   ON m.branch_uuid = l.branch_uuid AND m.item_uuid = l.item_uuid
            WHERE {$where}
            GROUP BY l.item_uuid, l.item_id, l.item_name, m.category_id
        ", $types, $params);
    }

    /** @return array{0:string,1:string,2:string,3:array<int,mixed>} */
    private function refundScope(string $branchUuid, ?string $from, ?string $to): array
    {
        $joins = ' INNER JOIN cloud_orders refund_order ON refund_order.local_order_id = cn.original_order_id'
            . ' INNER JOIN cloud_branches refund_branch ON refund_branch.branch_uuid = refund_order.branch_uuid';
        $where = "refund_order.branch_uuid = ? AND cn.status = 'posted'"
            . ' AND (refund_branch.pos_tenant IS NULL OR cn.tenant = refund_branch.pos_tenant)'
            . ' AND (refund_branch.pos_branch IS NULL OR cn.branch = refund_branch.pos_branch)';
        $types = 's';
        $params = [$branchUuid];
        if ($from !== null) {
            $where .= ' AND COALESCE(cn.business_day, DATE(cn.created_at)) >= DATE(?)';
            $types .= 's';
            $params[] = $from;
        }
        if ($to !== null) {
            $where .= ' AND COALESCE(cn.business_day, DATE(cn.created_at)) < DATE(?)';
            $types .= 's';
            $params[] = $to;
        }
        return [$joins, $where, $types, $params];
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
        $cancelled = "({$prefix}isdeleted = 1 OR LOWER(COALESCE({$prefix}order_status, '')) IN ('cancelled','canceled','deleted','voided'))";
        $postedReversal = "EXISTS (
            SELECT 1
            FROM credit_notes reversal_cn
            INNER JOIN cloud_branches reversal_branch
                    ON reversal_branch.branch_uuid = {$prefix}branch_uuid
            WHERE reversal_cn.original_order_id = {$prefix}local_order_id
              AND reversal_cn.status = 'posted'
              AND (reversal_branch.pos_tenant IS NULL OR reversal_cn.tenant = reversal_branch.pos_tenant)
              AND (reversal_branch.pos_branch IS NULL OR reversal_cn.branch = reversal_branch.pos_branch)
        )";

        return "({$cancelled} AND NOT ({$postedReversal}))";
    }

    private function paidSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        return "LOWER(COALESCE({$prefix}payment_status, '')) IN ('paid','completed','complete')";
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
