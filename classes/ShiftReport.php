<?php

require_once __DIR__ . '/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/Pos/Service/BusinessDayService.php';

class ShiftReport
{
    private $conn;
    private $userId;
    private $username;
    private $date;
    private $shiftOpenedAt = null;
    private $drawerReconciliationService;
    /** @var array<string, string> */
    private $shiftWindowTimestampCache = [];

    public function __construct($conn, $userId, $date = null, array $scope = [])
    {
        $this->conn = $conn;
        $this->userId = (int) $userId;
        $this->drawerReconciliationService = new ShiftDrawerReconciliationService();
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

        $this->setLastClosingTimeFromClosedOrders();
    }

    private function setLastClosingTimeFromClosedOrders(): void
    {
        if ($this->username === '') {
            return;
        }

        $checkCol = $this->conn->query("SHOW COLUMNS FROM closed_orders LIKE 'created_at'");
        if ($checkCol && $checkCol->num_rows > 0) {
            $query = 'SELECT MAX(created_at) AS last_time
                      FROM closed_orders
                      WHERE user = ? AND date = ?';
        } else {
            $checkCrtime = $this->conn->query("SHOW COLUMNS FROM closed_orders LIKE 'crtime'");
            if ($checkCrtime && $checkCrtime->num_rows > 0) {
                $query = 'SELECT MAX(crtime) AS last_time
                          FROM closed_orders
                          WHERE user = ? AND date = ?';
            } else {
                $query = "SELECT MAX(CONCAT(date, ' ', endtime)) AS last_time
                          FROM closed_orders
                          WHERE user = ? AND date = ?";
            }
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $this->username, $this->date);
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
        $query = 'SELECT
                    COUNT(*) as total_orders,
                    COALESCE(SUM(fat_total), 0) as total_gross,
                    COALESCE(SUM(fat_disc), 0) as total_discount,
                    COALESCE(SUM(fat_net), 0) as total_net
                  FROM ot_head
                  WHERE DATE(pro_date) = ?
                  AND user = ?
                  AND pro_tybe = 9
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
        $query = 'SELECT
                    mi.iname,
                    mi.barcode,
                    SUM(fd.qty_out) as qty,
                    SUM(fd.det_value) as value
                   FROM fat_details fd
                   JOIN ot_head oh ON fd.fatid = oh.id
                   JOIN myitems mi ON fd.item_id = mi.id
                   WHERE DATE(oh.pro_date) = ?
                   AND oh.user = ?
                   AND oh.pro_tybe = 9
                   AND oh.isdeleted = 0
                   AND fd.isdeleted = 0';
        $query = $this->appendShiftWindow($query, $params, 'oh.crtime');
        $query .= ' GROUP BY fd.item_id ORDER BY value DESC';

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
