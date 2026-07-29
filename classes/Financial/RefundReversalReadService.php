<?php

require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/Decimal.php';

/**
 * Canonical read projection for immutable posted refund reversals.
 *
 * Revenue reversal is owned by posted credit notes. Payment-refund settlement
 * is deliberately not used here because it represents tender custody, not the
 * moment the sale revenue is reversed.
 */
final class RefundReversalReadService
{
    /** @var array<string, bool> */
    private array $tableCache = [];
    /** @var array<string, bool> */
    private array $columnCache = [];

    /**
     * Original posted sales remain gross evidence even if an older paid-void
     * path hid the order header. Posted credit notes are the authority that
     * proves such a row is a financial reversal rather than an unpaid cancel.
     *
     * @param list<string> $normalStatuses
     */
    public function originalSaleEvidencePredicate(
        mysqli $conn,
        string $alias = 'oh',
        array $normalStatuses = ['paid', 'refunded']
    ): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('INVALID_SQL_ALIAS');
        }
        $statuses = [];
        foreach ($normalStatuses as $status) {
            $statuses[] = "'" . $conn->real_escape_string((string) $status) . "'";
        }
        if ($statuses === []) {
            $statuses[] = "''";
        }
        $normalParts = [];
        if ($this->columnExists($conn, 'ot_head', 'isdeleted')) {
            $normalParts[] = "COALESCE({$alias}.isdeleted, 0) = 0";
        }
        if ($this->columnExists($conn, 'ot_head', 'payment_status')) {
            $normalParts[] = "COALESCE({$alias}.payment_status, '') IN (" . implode(', ', $statuses) . ')';
        }
        // Upgrade/diagnostic fixtures may expose only the immutable legacy
        // header fields.  Do not generate invalid SQL while schema readiness
        // is being assessed.
        $normal = '(' . ($normalParts !== [] ? implode(' AND ', $normalParts) : '1 = 1') . ')';
        if (!$this->tableExists($conn, 'credit_notes')) {
            return $normal;
        }

        return '(' . $normal
            . " OR EXISTS (SELECT 1 FROM credit_notes reversal_cn"
            . " WHERE reversal_cn.original_order_id = {$alias}.id"
            . " AND reversal_cn.status = 'posted'))";
    }

    /**
     * @return array{total_amount:string,count:int,rows:array<int,array<string,mixed>>}
     */
    public function periodSummary(mysqli $conn, array $filters, bool $includeRows = false): array
    {
        if (!$this->tableExists($conn, 'credit_notes')) {
            return ['total_amount' => '0.00', 'count' => 0, 'rows' => []];
        }

        $from = $this->date((string) ($filters['date_from'] ?? date('Y-m-d')));
        $to = $this->date((string) ($filters['date_to'] ?? $from));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $where = ["cn.status = 'posted'"];
        $params = [];
        $dateExpression = $this->columnExists($conn, 'credit_notes', 'business_day')
            ? 'COALESCE(cn.business_day, DATE(cn.created_at))'
            : 'DATE(cn.created_at)';
        $where[] = "{$dateExpression} BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;

        $joinOrder = $this->tableExists($conn, 'ot_head');
        foreach (['tenant', 'branch'] as $column) {
            $value = max(0, (int) ($filters[$column] ?? 0));
            if ($value < 1) {
                continue;
            }
            if ($this->columnExists($conn, 'credit_notes', $column)) {
                $where[] = "cn.{$column} = ?";
                $params[] = $value;
            } elseif ($joinOrder && $this->columnExists($conn, 'ot_head', $column)) {
                $where[] = "oh.{$column} = ?";
                $params[] = $value;
            }
        }

        $drawerSessionId = max(0, (int) ($filters['drawer_session_id'] ?? 0));
        $scopedByDrawerSession = $drawerSessionId > 0
            && $this->columnExists($conn, 'credit_notes', 'drawer_session_id');
        $cashierId = max(0, (int) ($filters['cashier_id'] ?? 0));
        if (!$scopedByDrawerSession
            && $cashierId > 0
            && $this->columnExists($conn, 'credit_notes', 'created_by')) {
            $where[] = 'cn.created_by = ?';
            $params[] = $cashierId;
        }
        if ($scopedByDrawerSession) {
            // The drawer is the custody/reporting boundary. A manager may
            // authorize and perform a refund against another cashier's active
            // drawer; actor filtering must not hide that financial reversal.
            $where[] = 'cn.drawer_session_id = ?';
            $params[] = $drawerSessionId;
        }
        $orderId = max(0, (int) ($filters['order_id'] ?? 0));
        if ($orderId > 0) {
            $where[] = 'cn.original_order_id = ?';
            $params[] = $orderId;
        }

        $joins = $joinOrder ? ' LEFT JOIN ot_head oh ON oh.id = cn.original_order_id' : '';
        $summary = $this->queryOne(
            $conn,
            'SELECT COUNT(*) AS c, COALESCE(SUM(cn.total_amount), 0) AS total_amount'
                . ' FROM credit_notes cn' . $joins
                . ' WHERE ' . implode(' AND ', $where),
            $params
        ) ?: [];

        $rows = [];
        if ($includeRows) {
            $select = [
                'cn.id AS credit_note_id',
                'cn.original_order_id',
                'cn.total_amount',
                'cn.created_at',
                "{$dateExpression} AS business_day",
                $this->selectColumn($conn, 'credit_notes', 'created_by', 'cn'),
                $this->selectColumn($conn, 'credit_notes', 'tenant', 'cn'),
                $this->selectColumn($conn, 'credit_notes', 'branch', 'cn'),
                $this->selectColumn($conn, 'credit_notes', 'drawer_session_id', 'cn'),
                $this->selectColumn($conn, 'credit_notes', 'manager_approval_id', 'cn'),
                $this->selectColumn($conn, 'credit_notes', 'reason', 'cn'),
            ];
            if ($joinOrder) {
                $select[] = $this->selectColumn($conn, 'ot_head', 'pro_id', 'oh', 'public_order_number');
                $select[] = $this->selectColumn($conn, 'ot_head', 'receipt_number', 'oh');
                $select[] = $this->selectColumn($conn, 'ot_head', 'fat_net', 'oh', 'original_amount');
            } else {
                $select[] = 'NULL AS public_order_number';
                $select[] = 'NULL AS receipt_number';
                $select[] = 'NULL AS original_amount';
            }
            $rows = $this->queryAll(
                $conn,
                'SELECT ' . implode(', ', $select)
                    . ' FROM credit_notes cn' . $joins
                    . ' WHERE ' . implode(' AND ', $where)
                    . ' ORDER BY cn.created_at DESC, cn.id DESC',
                $params
            );
            foreach ($rows as &$row) {
                $row['credit_note_id'] = (int) $row['credit_note_id'];
                $row['original_order_id'] = (int) $row['original_order_id'];
                $row['total_amount'] = Money::from((string) $row['total_amount'])->toString();
                $row['original_amount'] = $row['original_amount'] !== null
                    ? Money::from((string) $row['original_amount'])->toString()
                    : null;
                foreach (['created_by', 'tenant', 'branch', 'drawer_session_id', 'manager_approval_id'] as $key) {
                    $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
                }
            }
            unset($row);
        }

        return [
            'total_amount' => Money::from((string) ($summary['total_amount'] ?? '0'))->toString(),
            'count' => (int) ($summary['c'] ?? 0),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{original_amount:string,cumulative_refunded_amount:string,remaining_refundable_amount:string,reversal_status:string,refund_count:int}
     */
    public function stateForOrder(mysqli $conn, int $orderId): array
    {
        if ($orderId < 1 || !$this->tableExists($conn, 'ot_head')) {
            return $this->emptyState();
        }
        $order = $this->queryOne(
            $conn,
            'SELECT fat_net FROM ot_head WHERE id = ? LIMIT 1',
            [$orderId]
        );
        if (!$order) {
            return $this->emptyState();
        }

        $original = Money::from((string) ($order['fat_net'] ?? '0'));
        $refunded = Money::zero();
        $count = 0;
        if ($this->tableExists($conn, 'credit_notes')) {
            $row = $this->queryOne(
                $conn,
                "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS refunded
                   FROM credit_notes
                  WHERE original_order_id = ? AND status = 'posted'",
                [$orderId]
            ) ?: [];
            $refunded = Money::from((string) ($row['refunded'] ?? '0'));
            $count = (int) ($row['c'] ?? 0);
        }
        $remaining = $original->subtract($refunded);
        if ($remaining->isNegative()) {
            $remaining = Money::zero();
        }
        $status = $refunded->compare(Money::zero()) <= 0
            ? 'none'
            : ($refunded->compare($original) >= 0 ? 'full' : 'partial');

        return [
            'original_amount' => $original->toString(),
            'cumulative_refunded_amount' => $refunded->toString(),
            'remaining_refundable_amount' => $remaining->toString(),
            'reversal_status' => $status,
            'refund_count' => $count,
        ];
    }

    /**
     * Profit reversed by posted credit-note lines for one original sale.
     *
     * The original detail profit is apportioned by the credited line value,
     * matching the refund-aware product profitability reports. Tender
     * settlement is intentionally irrelevant here.
     */
    public function refundedProfitForOrder(mysqli $conn, int $orderId): string
    {
        if (
            $orderId < 1
            || !$this->tableExists($conn, 'credit_notes')
            || !$this->tableExists($conn, 'credit_note_lines')
            || !$this->tableExists($conn, 'fat_details')
        ) {
            return '0.000000';
        }

        $row = $this->queryOne(
            $conn,
            "SELECT COALESCE(SUM(
                        CASE
                            WHEN ABS(fd.det_value) >= 0.000001
                            THEN COALESCE(fd.profit, 0) * (cnl.line_amount / fd.det_value)
                            ELSE 0
                        END
                    ), 0) AS refunded_profit
               FROM credit_notes cn
               INNER JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
               INNER JOIN fat_details fd ON fd.id = cnl.original_detail_id
              WHERE cn.original_order_id = ? AND cn.status = 'posted'",
            [$orderId]
        ) ?: [];

        return FinancialDecimal::normalize(
            (string) ($row['refunded_profit'] ?? '0'),
            6,
            true
        );
    }

    /** @return array{original_amount:string,cumulative_refunded_amount:string,remaining_refundable_amount:string,reversal_status:string,refund_count:int} */
    private function emptyState(): array
    {
        return [
            'original_amount' => '0.00',
            'cumulative_refunded_amount' => '0.00',
            'remaining_refundable_amount' => '0.00',
            'reversal_status' => 'none',
            'refund_count' => 0,
        ];
    }

    private function selectColumn(mysqli $conn, string $table, string $column, string $alias, ?string $as = null): string
    {
        $as ??= $column;
        return $this->columnExists($conn, $table, $column)
            ? "{$alias}.{$column} AS {$as}"
            : "NULL AS {$as}";
    }

    private function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        return $this->tableCache[$table] = $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->columnCache[$key])) {
            return $this->columnCache[$key];
        }
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $escaped = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$escaped}'");
        return $this->columnCache[$key] = $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /** @return array<string,mixed>|null */
    private function queryOne(mysqli $conn, string $sql, array $params): ?array
    {
        $rows = $this->queryAll($conn, $sql, $params);
        return $rows[0] ?? null;
    }

    /** @return array<int,array<string,mixed>> */
    private function queryAll(mysqli $conn, string $sql, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if ($params !== []) {
            $types = '';
            foreach ($params as $param) {
                $types .= is_int($param) ? 'i' : 's';
            }
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
