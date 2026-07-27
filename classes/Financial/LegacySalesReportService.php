<?php

require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/RefundReversalReadService.php';

/**
 * Refund-aware adapter for the historical standalone sales report pages.
 *
 * New operational pages should use OperationsReportService. This adapter keeps
 * older time/category/product reports compatible while applying the same core
 * rule: original posted sales remain gross evidence and posted credit notes are
 * negative revenue on the credit-note business day.
 */
final class LegacySalesReportService
{
    /** @var array<string,bool> */
    private array $columns = [];

    /** @return list<array<string,mixed>> */
    public function timeBuckets(
        mysqli $conn,
        string $from,
        string $to,
        string $grain,
        array $scope = []
    ): array {
        $from = $this->date($from);
        $to = $this->date($to);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (!in_array($grain, ['day', 'week', 'month', 'hour'], true)) {
            throw new InvalidArgumentException('Unsupported legacy sales grain.');
        }

        $cutoff = max(0, min(23, (int) ($scope['cutoff_hour'] ?? 0)));
        [$saleKey, $saleExtras, $saleDateWhere] = match ($grain) {
            'day' => ['DATE(oh.pro_date)', 'DATE(oh.pro_date) AS bucket_date', 'DATE(oh.pro_date) BETWEEN ? AND ?'],
            'week' => ['YEARWEEK(oh.pro_date, 1)', 'MIN(DATE(oh.pro_date)) AS week_start, MAX(DATE(oh.pro_date)) AS week_end', 'DATE(oh.pro_date) BETWEEN ? AND ?'],
            'month' => ["DATE_FORMAT(oh.pro_date, '%Y-%m')", "DATE_FORMAT(oh.pro_date, '%Y-%m') AS bucket_month", 'DATE(oh.pro_date) BETWEEN ? AND ?'],
            'hour' => ['HOUR(oh.crtime)', 'HOUR(oh.crtime) AS bucket_hour', "DATE(DATE_SUB(oh.crtime, INTERVAL {$cutoff} HOUR)) BETWEEN ? AND ?"],
        };
        $posSaleEvidence = (new RefundReversalReadService())->originalSaleEvidencePredicate($conn, 'oh');
        $saleWhere = [
            'oh.pro_tybe IN (3, 9)',
            "((oh.pro_tybe <> 9 AND COALESCE(oh.isdeleted, 0) = 0) OR (oh.pro_tybe = 9 AND {$posSaleEvidence}))",
            $saleDateWhere,
        ];
        $saleParams = [$from, $to];
        $this->appendScope($conn, 'ot_head', 'oh', $scope, $saleWhere, $saleParams);
        $saleValue = 'CASE WHEN oh.pro_tybe = 9 THEN COALESCE(oh.fat_net, oh.pro_value, 0)'
            . ' ELSE COALESCE(oh.pro_value, oh.fat_net, 0) END';
        $sales = $this->queryAll(
            $conn,
            "SELECT {$saleKey} AS bucket_key, {$saleExtras}, COALESCE(SUM({$saleValue}), 0) AS sales_after_discount
               FROM ot_head oh
              WHERE " . implode(' AND ', $saleWhere) . "
              GROUP BY {$saleKey}",
            $saleParams
        );

        $refundDate = 'COALESCE(cn.business_day, DATE(cn.created_at))';
        [$refundKey, $refundExtras] = match ($grain) {
            'day' => [$refundDate, "{$refundDate} AS bucket_date"],
            'week' => ["YEARWEEK({$refundDate}, 1)", "MIN({$refundDate}) AS week_start, MAX({$refundDate}) AS week_end"],
            'month' => ["DATE_FORMAT({$refundDate}, '%Y-%m')", "DATE_FORMAT({$refundDate}, '%Y-%m') AS bucket_month"],
            'hour' => ['HOUR(cn.created_at)', 'HOUR(cn.created_at) AS bucket_hour'],
        };
        $refundWhere = ["cn.status = 'posted'", "{$refundDate} BETWEEN ? AND ?"];
        $refundParams = [$from, $to];
        $this->appendScope($conn, 'credit_notes', 'cn', $scope, $refundWhere, $refundParams);
        $refunds = $this->tableExists($conn, 'credit_notes')
            ? $this->queryAll(
                $conn,
                "SELECT {$refundKey} AS bucket_key, {$refundExtras}, COALESCE(SUM(cn.total_amount), 0) AS refunds
                   FROM credit_notes cn
                  WHERE " . implode(' AND ', $refundWhere) . "
                  GROUP BY {$refundKey}",
                $refundParams
            )
            : [];

        $buckets = [];
        foreach ($sales as $row) {
            $key = (string) $row['bucket_key'];
            $row['refunds'] = Money::zero()->toString();
            $buckets[$key] = $row;
        }
        foreach ($refunds as $row) {
            $key = (string) $row['bucket_key'];
            if (!isset($buckets[$key])) {
                $buckets[$key] = $row + ['sales_after_discount' => Money::zero()->toString()];
            }
            $buckets[$key]['refunds'] = Money::from((string) $row['refunds'])->toString();
            foreach (['bucket_date', 'bucket_month', 'bucket_hour', 'week_start', 'week_end'] as $field) {
                if (!isset($buckets[$key][$field]) && isset($row[$field])) {
                    $buckets[$key][$field] = $row[$field];
                }
            }
        }
        foreach ($buckets as &$bucket) {
            $salesAmount = Money::from((string) ($bucket['sales_after_discount'] ?? '0'));
            $refundAmount = Money::from((string) ($bucket['refunds'] ?? '0'));
            $bucket['sales_after_discount'] = $salesAmount->toString();
            $bucket['refunds'] = $refundAmount->toString();
            $bucket['total_sales'] = $salesAmount->subtract($refundAmount)->toString();
            if ($grain === 'day') {
                $bucket['pro_date'] = (string) ($bucket['bucket_date'] ?? $bucket['bucket_key']);
            } elseif ($grain === 'week') {
                $bucket['sales_week'] = (string) $bucket['bucket_key'];
                $monday = date('Y-m-d', strtotime(substr((string) $bucket['bucket_key'], 0, 4) . 'W' . substr((string) $bucket['bucket_key'], 4, 2)));
                $bucket['week_start'] = $monday;
                $bucket['week_end'] = date('Y-m-d', strtotime($monday . ' +6 days'));
            } elseif ($grain === 'month') {
                $bucket['sales_month'] = (string) ($bucket['bucket_month'] ?? $bucket['bucket_key']);
            } else {
                $bucket['sales_hour'] = (int) ($bucket['bucket_hour'] ?? $bucket['bucket_key']);
            }
        }
        unset($bucket);
        ksort($buckets, SORT_NATURAL);
        return array_values($buckets);
    }

    /** @return list<array<string,mixed>> */
    public function itemTotals(mysqli $conn, ?string $from, ?string $to, array $scope = []): array
    {
        $dateWhere = [];
        $params = [];
        $this->appendOptionalDate('DATE(oh.pro_date)', $from, $to, $dateWhere, $params);
        $posSaleEvidence = (new RefundReversalReadService())->originalSaleEvidencePredicate($conn, 'oh');
        $where = array_values(array_merge([
            'COALESCE(i.isdeleted, 0) = 0',
            'COALESCE(fd.isdeleted, 0) = 0',
            'oh.pro_tybe IN (3, 9)',
            "((oh.pro_tybe <> 9 AND COALESCE(oh.isdeleted, 0) = 0) OR (oh.pro_tybe = 9 AND {$posSaleEvidence}))",
        ], $dateWhere));
        $this->appendScope($conn, 'ot_head', 'oh', $scope, $where, $params);
        $sold = $this->queryAll($conn, "
            SELECT i.id, i.iname, i.barcode, i.price1, i.cost_price, i.group1,
                   COALESCE(g.gname, '') AS group_name,
                   COALESCE(SUM(fd.qty_out - COALESCE(fd.qty_in, 0)), 0) AS sold_qty,
                   COALESCE(SUM(fd.det_value), 0) AS sold_value,
                   COALESCE(SUM(fd.profit), 0) AS sold_profit
              FROM fat_details fd
              INNER JOIN ot_head oh ON oh.id = fd.fatid
              INNER JOIN myitems i ON i.id = fd.item_id
              LEFT JOIN item_group g ON g.id = i.group1
             WHERE " . implode(' AND ', $where) . '
             GROUP BY i.id, i.iname, i.barcode, i.price1, i.cost_price, i.group1, g.gname', $params);

        $refunds = [];
        if ($this->tableExists($conn, 'credit_notes') && $this->tableExists($conn, 'credit_note_lines')) {
            $refundDate = 'COALESCE(cn.business_day, DATE(cn.created_at))';
            $refundWhere = ["cn.status = 'posted'"];
            $refundParams = [];
            $this->appendOptionalDate($refundDate, $from, $to, $refundWhere, $refundParams);
            $this->appendScope($conn, 'credit_notes', 'cn', $scope, $refundWhere, $refundParams);
            $refunds = $this->queryAll($conn, "
                SELECT i.id, i.iname, i.barcode, i.price1, i.cost_price, i.group1,
                       COALESCE(g.gname, '') AS group_name,
                       COALESCE(SUM(cnl.quantity), 0) AS returned_qty,
                       COALESCE(SUM(cnl.line_amount), 0) AS refund_value,
                       CAST(COALESCE(SUM(CASE WHEN ABS(fd.det_value) >= 0.000001
                           THEN COALESCE(fd.profit, 0) * (cnl.line_amount / fd.det_value) ELSE 0 END), 0) AS DECIMAL(19,6)) AS refund_profit
                  FROM credit_notes cn
                  INNER JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
                  INNER JOIN fat_details fd ON fd.id = cnl.original_detail_id
                  INNER JOIN myitems i ON i.id = fd.item_id
                  LEFT JOIN item_group g ON g.id = i.group1
                 WHERE " . implode(' AND ', $refundWhere) . '
                 GROUP BY i.id, i.iname, i.barcode, i.price1, i.cost_price, i.group1, g.gname', $refundParams);
        }
        $byItem = [];
        foreach ($sold as $row) {
            $byItem[(int) $row['id']] = $row + ['returned_qty' => '0', 'refund_value' => '0', 'refund_profit' => '0'];
        }
        foreach ($refunds as $refund) {
            $id = (int) $refund['id'];
            if (!isset($byItem[$id])) {
                $byItem[$id] = $refund + [
                    'sold_qty' => '0',
                    'sold_value' => '0',
                    'sold_profit' => '0',
                ];
            }
            $byItem[$id]['returned_qty'] = $this->decimal((string) $refund['returned_qty'], 6);
            $byItem[$id]['refund_value'] = Money::from((string) $refund['refund_value'])->toString();
            $byItem[$id]['refund_profit'] = $this->decimal((string) $refund['refund_profit'], 6, true);
        }
        foreach ($byItem as &$row) {
            $row['total_qty'] = FinancialDecimal::subtract(
                $this->decimal((string) $row['sold_qty'], 6),
                $this->decimal((string) $row['returned_qty'], 6),
                6
            );
            $row['total_value'] = Money::from((string) $row['sold_value'])
                ->subtract(Money::from((string) $row['refund_value']))
                ->toString();
            $row['total_profit'] = FinancialDecimal::subtract(
                $this->decimal((string) $row['sold_profit'], 6, true),
                $this->decimal((string) $row['refund_profit'], 6, true),
                6
            );
        }
        unset($row);
        return array_values($byItem);
    }

    /** @return list<array<string,mixed>> */
    public function categoryTotals(mysqli $conn, ?string $from, ?string $to, array $scope = []): array
    {
        $groups = [];
        foreach ($this->itemTotals($conn, $from, $to, $scope) as $item) {
            $id = (int) ($item['group1'] ?? 0);
            $groups[$id] ??= [
                'group_id' => $id,
                'group_name' => (string) (($item['group_name'] ?? '') ?: 'بدون تصنيف'),
                'total_qty' => '0.000000',
                'total_sales' => Money::zero()->toString(),
            ];
            $groups[$id]['total_qty'] = FinancialDecimal::add(
                $this->decimal((string) $groups[$id]['total_qty'], 6, true),
                $this->decimal((string) $item['total_qty'], 6, true),
                6
            );
            $groups[$id]['total_sales'] = Money::from((string) $groups[$id]['total_sales'], true)
                ->add(Money::from((string) $item['total_value'], true))
                ->toString();
        }
        usort($groups, static fn (array $a, array $b): int => FinancialDecimal::compare(
            (string) $b['total_sales'],
            (string) $a['total_sales'],
            Money::SCALE
        ));
        return array_values($groups);
    }

    private function decimal(string $value, int $scale, bool $allowNegative = false): string
    {
        return FinancialDecimal::normalize($value, $scale, $allowNegative);
    }

    private function appendOptionalDate(string $expression, ?string $from, ?string $to, array &$where, array &$params): void
    {
        if ($from !== null && trim($from) !== '') {
            $where[] = "{$expression} >= ?";
            $params[] = $this->date($from);
        }
        if ($to !== null && trim($to) !== '') {
            $where[] = "{$expression} <= ?";
            $params[] = $this->date($to);
        }
    }

    private function appendScope(mysqli $conn, string $table, string $alias, array $scope, array &$where, array &$params): void
    {
        foreach (['tenant', 'branch'] as $column) {
            $value = max(0, (int) ($scope[$column] ?? 0));
            if ($value > 0 && $this->columnExists($conn, $table, $column)) {
                $where[] = "{$alias}.{$column} = ?";
                $params[] = $value;
            }
        }
    }

    private function date(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->columns[$key])) {
            return $this->columns[$key];
        }
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $this->columns[$key] = $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /** @return list<array<string,mixed>> */
    private function queryAll(mysqli $conn, string $sql, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if ($params !== []) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
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
}
