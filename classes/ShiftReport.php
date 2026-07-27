<?php

require_once __DIR__ . '/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/Pos/Service/BusinessDayService.php';
require_once __DIR__ . '/Financial/RefundReversalReadService.php';

class ShiftReport
{
    private $conn;
    private $userId;
    private $username;
    private $date;
    private $shiftOpenedAt = null;
    private $drawerReconciliationService;
    private $refundReversalReadService;
    private $tenant = 0;
    private $branch = 0;
    private $drawerSessionId = 0;
    /** @var array<string, string> */
    private $shiftWindowTimestampCache = [];

    public function __construct($conn, $userId, $date = null, array $scope = [])
    {
        $this->conn = $conn;
        $this->userId = (int) $userId;
        $this->drawerReconciliationService = new ShiftDrawerReconciliationService();
        $this->refundReversalReadService = new RefundReversalReadService();
        $this->tenant = (int) ($scope['tenant'] ?? $scope['pos_tenant'] ?? $_SESSION['pos_tenant'] ?? 0);
        $this->branch = (int) ($scope['branch'] ?? $scope['pos_branch'] ?? $_SESSION['pos_branch'] ?? 0);
        $this->drawerSessionId = (int) ($scope['drawer_session_id'] ?? $_SESSION['pos_drawer_session_id'] ?? 0);
        $this->username = $this->getCashierUsernameById($this->userId);
        $this->resolveShiftWindow($scope);
        $this->date = $date ? $date : $this->resolveBusinessDay($scope);
    }

    private function resolveBusinessDay(array $scope): string
    {
        $businessDays = new BusinessDayService();
        $tenant = (int) ($scope['tenant'] ?? $scope['pos_tenant'] ?? $_SESSION['pos_tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? $scope['pos_branch'] ?? $_SESSION['pos_branch'] ?? 0);

        if ($this->shiftOpenedAt !== null && trim((string) $this->shiftOpenedAt) !== '') {
            $cutoffHour = $businessDays->cutoffHourForBranch($this->conn, $tenant, $branch);

            return $businessDays->businessDayForTimestamp((string) $this->shiftOpenedAt, $cutoffHour);
        }

        if (!empty($scope['drawer_session_id'])) {
            try {
                $session = (new DrawerSessionService())->sessionById($this->conn, (int) $scope['drawer_session_id']);
                $sessionDay = trim((string) ($session['business_day'] ?? ''));
                if ($sessionDay !== '') {
                    return $sessionDay;
                }
                if (!empty($session['opened_at'])) {
                    $cutoffHour = $businessDays->cutoffHourForBranch(
                        $this->conn,
                        (int) ($session['tenant'] ?? $tenant),
                        (int) ($session['branch'] ?? $branch)
                    );

                    return $businessDays->businessDayForTimestamp((string) $session['opened_at'], $cutoffHour);
                }
            } catch (Throwable $exception) {
                // Fall through to current business day.
            }
        }

        return $businessDays->currentBusinessDayForBranch($this->conn, $tenant, $branch);
    }

    private function getCashierUsernameById($id): string
    {
        $stmt = $this->conn->prepare('SELECT uname FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ? (string) $row['uname'] : '';
    }

    private function resolveShiftWindow(array $scope): void
    {
        $explicitOpenedAt = trim((string) ($scope['shift_opened_at'] ?? ''));
        if ($explicitOpenedAt !== '') {
            $this->shiftOpenedAt = $explicitOpenedAt;

            return;
        }

        if (function_exists('posmain_drawer_sessions_table_exists')
            && posmain_drawer_sessions_table_exists($this->conn)) {
            $drawer = new DrawerSessionService();
            $tenant = (int) ($scope['tenant'] ?? 0);
            $branch = (int) ($scope['branch'] ?? 0);
            $drawerSessionId = (int) ($scope['drawer_session_id'] ?? $_SESSION['pos_drawer_session_id'] ?? 0);

            if ($drawerSessionId > 0) {
                try {
                    $session = $drawer->sessionById($this->conn, $drawerSessionId);
                    if (!empty($session['opened_at'])) {
                        $this->shiftOpenedAt = (string) $session['opened_at'];

                        return;
                    }
                } catch (Throwable $exception) {
                    // continue to lookup
                }
            }

            $openSession = $drawer->findOpenSession($this->conn, $this->userId, $tenant, $branch);
            if ($openSession && !empty($openSession['opened_at'])) {
                $this->shiftOpenedAt = (string) $openSession['opened_at'];

                return;
            }
        }

        $this->setLastClosingTimeFromDrawerSessions($scope);
    }

    private function setLastClosingTimeFromDrawerSessions(array $scope): void
    {
        if (!function_exists('posmain_drawer_sessions_table_exists')
            || !posmain_drawer_sessions_table_exists($this->conn)) {
            return;
        }

        $tenant = (int) ($scope['tenant'] ?? $scope['pos_tenant'] ?? $_SESSION['pos_tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? $scope['pos_branch'] ?? $_SESSION['pos_branch'] ?? 0);
        $stmt = $this->conn->prepare(
            "SELECT MAX(closed_at) AS last_time
             FROM drawer_sessions
             WHERE user_id = ? AND tenant = ? AND branch = ?
               AND status IN ('closed', 'forced_closed')"
        );
        $stmt->bind_param('iii', $this->userId, $tenant, $branch);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $lastTime = trim((string) ($row['last_time'] ?? ''));
            if ($lastTime !== '') {
                $this->shiftOpenedAt = $lastTime;
            }
        }
        $stmt->close();
    }

    public function getShiftOpenedAt(): ?string
    {
        return $this->shiftOpenedAt;
    }

    private function appendShiftWindow(string $sql, array &$params, string $tableAlias = ''): string
    {
        if ($this->shiftOpenedAt !== null && $this->shiftOpenedAt !== '') {
            $sql .= ' AND ' . $this->shiftWindowTimestampExpression($tableAlias) . ' >= ?';
            $params[] = $this->shiftOpenedAt;
        }

        return $sql;
    }

    private function shiftWindowTimestampExpression(string $tableAlias = ''): string
    {
        $cacheKey = $tableAlias === '' ? '_root' : $tableAlias;
        if (isset($this->shiftWindowTimestampCache[$cacheKey])) {
            return $this->shiftWindowTimestampCache[$cacheKey];
        }

        $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
        $columns = [];
        foreach (['crtime', 'payment_date', 'completed_at'] as $column) {
            if ($this->columnExists('ot_head', $column)) {
                $columns[] = $prefix . $column;
            }
        }
        $columns[] = 'TIMESTAMP(' . $prefix . 'pro_date)';
        $expression = 'COALESCE(' . implode(', ', $columns) . ')';
        $this->shiftWindowTimestampCache[$cacheKey] = $expression;

        return $expression;
    }

    private function columnExists(string $table, string $column): bool
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: $table;
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?: $column;
        $result = $this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function getTotals()
    {
        $params = [$this->date, (string) $this->userId];
        $saleEvidence = $this->refundReversalReadService->originalSaleEvidencePredicate($this->conn, 'oh');
        $query = 'SELECT
                    COUNT(*) as total_orders,
                    COALESCE(SUM(fat_total), 0) as total_gross,
                    COALESCE(SUM(fat_disc), 0) as total_discount,
                    COALESCE(SUM(fat_net), 0) as total_net
                  FROM ot_head oh
                  WHERE DATE(oh.pro_date) = ?
                  AND oh.user = ?
                  AND oh.pro_tybe = 9
                  AND ' . $saleEvidence;
        $query = $this->appendShiftWindow($query, $params, 'oh');

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $originalNet = (float) ($row['total_net'] ?? 0);
        $returns = $this->canonicalReturns();
        $row['total_sales_after_discount'] = $originalNet;
        $row['total_refunds'] = (float) $returns['total'];
        $row['total_net'] = $originalNet - (float) $returns['total'];

        return $row;
    }

    public function getOrderTypeCounts(): array
    {
        $params = [$this->date, (string) $this->userId];
        $saleEvidence = $this->refundReversalReadService->originalSaleEvidencePredicate($this->conn, 'oh');
        $query = "SELECT
                    SUM(CASE WHEN oh.order_type = 'table' THEN 1 ELSE 0 END) as table_count,
                    SUM(CASE WHEN oh.order_type = 'delivery' THEN 1 ELSE 0 END) as delivery_count,
                    SUM(CASE WHEN oh.order_type = 'takeaway' THEN 1 ELSE 0 END) as takeaway_count
                  FROM ot_head oh
                  WHERE DATE(oh.pro_date) = ?
                  AND oh.user = ?
                  AND oh.pro_tybe = 9
                  AND {$saleEvidence}";
        $query = $this->appendShiftWindow($query, $params, 'oh');

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'table_count' => (int) ($row['table_count'] ?? 0),
            'delivery_count' => (int) ($row['delivery_count'] ?? 0),
            'takeaway_count' => (int) ($row['takeaway_count'] ?? 0),
        ];
    }

    public function getSaleTimeBounds(): array
    {
        $params = [$this->date, (string) $this->userId];
        $timestampExpr = $this->shiftWindowTimestampExpression();
        $saleEvidence = $this->refundReversalReadService->originalSaleEvidencePredicate($this->conn, 'oh');
        $query = "SELECT
                    MIN({$timestampExpr}) as first_sale_time,
                    MAX({$timestampExpr}) as last_sale_time
                  FROM ot_head oh
                  WHERE DATE(oh.pro_date) = ?
                  AND oh.user = ?
                  AND oh.pro_tybe = 9
                  AND {$saleEvidence}";
        $query = $this->appendShiftWindow($query, $params, 'oh');

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'first_sale_time' => $row['first_sale_time'] ?? null,
            'last_sale_time' => $row['last_sale_time'] ?? null,
        ];
    }

    public function getBusinessDay(): string
    {
        return (string) $this->date;
    }

    public function getCashierUsername(): string
    {
        return $this->username;
    }

    public function getPaymentBreakdown()
    {
        $params = [$this->date, (string) $this->userId];
        $query = 'SELECT
                    ah.aname as fund_name,
                    oh.acc1 as fund_id,
                    COUNT(*) as count,
                    COALESCE(SUM(oh.pro_value), 0) as total
                  FROM ot_head oh
                  LEFT JOIN acc_head ah ON oh.acc1 = ah.id
                  WHERE DATE(oh.pro_date) = ?
                  AND oh.user = ?
                  AND oh.pro_tybe = 1
                  AND oh.isdeleted = 0';
        $query = $this->appendShiftWindow($query, $params, 'oh');
        $query .= ' GROUP BY oh.acc1';

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        return $result;
    }

    public function getReturns()
    {
        if ($this->tableExists('credit_notes')) {
            return $this->canonicalReturns();
        }

        $params = [$this->date, (string) $this->userId];
        $query = 'SELECT
                    COUNT(*) as count,
                    COALESCE(SUM(fat_net), 0) as total
                  FROM ot_head
                  WHERE DATE(pro_date) = ?
                  AND user = ?
                  AND pro_tybe = 11
                  AND isdeleted = 0';
        $query = $this->appendShiftWindow($query, $params);

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /** @return array{count:int,total:float} */
    private function canonicalReturns(): array
    {
        $summary = $this->refundReversalReadService->periodSummary($this->conn, [
            'date_from' => $this->date,
            'date_to' => $this->date,
            'tenant' => $this->tenant,
            'branch' => $this->branch,
            'cashier_id' => $this->userId,
            'drawer_session_id' => $this->drawerSessionId,
        ]);

        return [
            'count' => (int) $summary['count'],
            'total' => (float) $summary['total_amount'],
        ];
    }

    private function tableExists(string $table): bool
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: $table;
        $escaped = $this->conn->real_escape_string($table);
        $result = $this->conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function getExpenses()
    {
        $params = [$this->date, (string) $this->userId];
        $query = 'SELECT
                   COALESCE(SUM(pro_value), 0) as total
                  FROM ot_head
                  WHERE DATE(pro_date) = ?
                  AND user = ?
                  AND pro_tybe = 2
                  AND isdeleted = 0';
        $query = $this->appendShiftWindow($query, $params);

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }

    public function getItemsBreakdown()
    {
        $params = [$this->date, (string) $this->userId];
        $saleEvidence = $this->refundReversalReadService->originalSaleEvidencePredicate($this->conn, 'oh');
        $saleWhere = 'DATE(oh.pro_date) = ?
                   AND oh.user = ?
                   AND oh.pro_tybe = 9
                   AND ' . $saleEvidence . '
                   AND fd.isdeleted = 0';
        $saleWhere = $this->appendShiftWindow($saleWhere, $params, 'oh');
        $query = 'SELECT item_id, iname, barcode, price,
                         SUM(qty_delta) AS total_qty,
                         SUM(value_delta) AS total_value,
                         COUNT(DISTINCT order_id) AS order_count
                    FROM (
                        SELECT fd.item_id, mi.iname, mi.barcode, fd.price,
                               (fd.qty_out - fd.qty_in) AS qty_delta,
                               fd.det_value AS value_delta,
                               oh.id AS order_id
                          FROM fat_details fd
                          JOIN ot_head oh ON fd.fatid = oh.id
                          JOIN myitems mi ON fd.item_id = mi.id
                         WHERE ' . $saleWhere;

        if ($this->tableExists('credit_notes') && $this->tableExists('credit_note_lines')) {
            $query .= ' UNION ALL
                        SELECT fd.item_id, mi.iname, mi.barcode, fd.price,
                               -cnl.quantity AS qty_delta,
                               -cnl.line_amount AS value_delta,
                               cn.original_order_id AS order_id
                          FROM credit_notes cn
                          JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
                          JOIN fat_details fd ON fd.id = cnl.original_detail_id
                          JOIN myitems mi ON mi.id = fd.item_id
                         WHERE cn.status = \'posted\'
                           AND COALESCE(cn.business_day, DATE(cn.created_at)) = ?
                           AND cn.created_by = ?';
            $params[] = $this->date;
            $params[] = (string) $this->userId;
            if ($this->drawerSessionId > 0 && $this->columnExists('credit_notes', 'drawer_session_id')) {
                $query .= ' AND cn.drawer_session_id = ?';
                $params[] = (string) $this->drawerSessionId;
            } elseif ($this->shiftOpenedAt !== null && $this->shiftOpenedAt !== '') {
                $query .= ' AND cn.created_at >= ?';
                $params[] = $this->shiftOpenedAt;
            }
        }
        $query .= ') item_activity
                  GROUP BY item_id, iname, barcode, price
                  HAVING ABS(total_qty) >= 0.000001 OR ABS(total_value) >= 0.01
                  ORDER BY total_value DESC';

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        return $result;
    }

    public function getInvoices()
    {
        $params = [$this->date, (string) $this->userId];
        $query = "SELECT
                    oh.id,
                    oh.crtime,
                    oh.payment_date,
                    oh.fat_net,
                    oh.info,
                    CASE
                        WHEN oh.order_type = 'table' THEN 'طاولة'
                        WHEN oh.order_type = 'delivery' THEN 'دليفري'
                        WHEN oh.order_type = 'takeaway' THEN 'تيك أواي'
                        ELSE 'غير محدد'
                    END as order_type
                  FROM ot_head oh
                  WHERE DATE(oh.pro_date) = ?
                  AND oh.user = ?
                  AND oh.pro_tybe = 9
                  AND oh.isdeleted = 0";
        $query = $this->appendShiftWindow($query, $params, 'oh');
        $query .= ' ORDER BY COALESCE(oh.crtime, oh.payment_date, TIMESTAMP(oh.pro_date)) DESC';

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        return $result;
    }

    public function getDrawerReconciliation(array $scope = [])
    {
        $scope = array_merge($scope, [
            'user_id' => $this->userId,
            'date' => $this->date,
        ]);

        return $this->drawerReconciliationService->buildForUser($this->conn, $scope);
    }
}
