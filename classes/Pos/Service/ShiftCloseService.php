<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/ShiftSessionService.php';
require_once __DIR__ . '/../../ShiftReport.php';

class ShiftCloseService
{
    private DrawerSessionService $drawerSessions;
    private ShiftSessionService $shiftSessions;

    public function __construct(
        ?DrawerSessionService $drawerSessions = null,
        ?ShiftSessionService $shiftSessions = null
    ) {
        $this->drawerSessions = $drawerSessions ?: new DrawerSessionService();
        $this->shiftSessions = $shiftSessions ?: new ShiftSessionService();
    }

    public function closeShift(mysqli $conn, int $userId, array $payload, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        if (!empty($_SESSION['pos_shift_closed_for_session'])) {
            throw new RuntimeException('SHIFT_ALREADY_CLOSED');
        }

        $scope = $this->shiftSessions->resolveScope($payload);
        $drawerSession = $this->shiftSessions->currentDrawerSession($conn, $userId, $scope);
        $drawerSessionId = $drawerSession ? (int) $drawerSession['id'] : 0;

        $countedCash = (float) ($payload['fund_after'] ?? $payload['counted_cash'] ?? 0);
        $cash = (float) ($payload['cash'] ?? $countedCash);
        $notes = trim((string) ($payload['notes'] ?? ''));
        $closePath = (string) ($payload['close_path'] ?? 'close_shift.php');
        $varianceStatus = (string) ($payload['variance_status'] ?? 'none');
        $varianceType = (string) ($payload['variance_type'] ?? 'none');
        $closeExpectedSnapshot = isset($payload['close_expected_snapshot'])
            ? (float) $payload['close_expected_snapshot']
            : null;
        $openingVarianceUnresolved = !empty($payload['opening_variance_unresolved']);

        if ($openingVarianceUnresolved && $varianceType === 'none' && $varianceStatus === 'unresolved') {
            $varianceType = 'opening';
        } elseif ($varianceStatus === 'unresolved' && $varianceType === 'none') {
            $varianceType = 'closing';
        } elseif ($openingVarianceUnresolved && $varianceStatus === 'unresolved' && $varianceType === 'closing') {
            $varianceType = 'both';
        }

        $shiftDate = date('Y-m-d');
        $shiftTime = date('H:i:s');
        $reportScope = $scope;
        if ($drawerSession) {
            $reportScope['shift_opened_at'] = $drawerSession['opened_at'];
            $reportScope['drawer_session_id'] = $drawerSessionId;
        }

        $report = new ShiftReport($conn, $userId, $shiftDate, $reportScope);
        $totals = $report->getTotals();
        $totalOrders = (int) ($totals['total_orders'] ?? 0);
        $totalSales = (float) ($totals['total_net'] ?? 0);

        $username = $this->cashierUsername($conn, $userId);
        $resolvedExpenses = $this->resolveCloseExpenses($conn, $userId, $scope, $payload);
        $expenses = (float) $resolvedExpenses['expenses'];
        $expNotes = (string) $resolvedExpenses['exp_notes'];
        $expenseSummary = $resolvedExpenses['expense_summary'];
        $payInSummary = $drawerSession
            ? $this->shiftSessions->shiftPayInSummary($conn, $userId, $scope)
            : ['total' => 0.0, 'count' => 0];

        // Legacy closed_orders.info is VARCHAR(50). Keep cashier notes bounded there;
        // put session token + full notes in json_details so audit data is not lost.
        $shiftSessionToken = trim((string) ($_SESSION['pos_shift_session_token'] ?? ''));
        $infoNotes = $this->truncateClosedOrderInfo($notes);
        $drawerNotes = $this->truncateDrawerSessionNotes($notes);

        $zDetails = $payload['z_details'] ?? null;
        if (is_array($zDetails)) {
            $details = $zDetails;
        } else {
            $details = [
                'drawer_session_id' => $drawerSessionId,
                'shift_opened_at' => $drawerSession['opened_at'] ?? null,
                'total_orders' => $totalOrders,
                'close_path' => $closePath,
                'expense_source' => $expenseSummary['source'] ?? null,
                'expense_count' => (int) ($expenseSummary['count'] ?? 0),
                'payin_total' => (float) ($payInSummary['total'] ?? 0),
                'payin_count' => (int) ($payInSummary['count'] ?? 0),
                'drawer_expected_cash' => $expenseSummary['expected_cash'] ?? null,
                'counted_cash' => $countedCash,
                'variance_status' => $varianceStatus,
                'variance_type' => $varianceType,
            ];
        }
        if ($shiftSessionToken !== '') {
            $details['shift_session_token'] = $shiftSessionToken;
        }
        if ($notes !== '') {
            $details['cashier_notes'] = $notes;
        }
        $jsonDetails = json_encode($details, JSON_UNESCAPED_UNICODE);

        $zRow = is_array($payload['z_row'] ?? null) ? $payload['z_row'] : null;
        if ($zRow !== null) {
            $zRow['info'] = $infoNotes;
            $zJson = [];
            if (!empty($zRow['json_details'])) {
                $decoded = json_decode((string) $zRow['json_details'], true);
                if (is_array($decoded)) {
                    $zJson = $decoded;
                }
            }
            if ($shiftSessionToken !== '') {
                $zJson['shift_session_token'] = $shiftSessionToken;
            }
            if ($notes !== '') {
                $zJson['cashier_notes'] = $notes;
            }
            $zRow['json_details'] = json_encode($zJson, JSON_UNESCAPED_UNICODE);
        }

        $shiftNumber = date('Ymd') . '_' . $userId;
        $closedSession = null;
        $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));
        $childContext = ['in_transaction' => true];

        try {
            $closedOrderId = $this->insertClosedOrder($conn, [
                'shift' => $shiftNumber,
                'date' => $shiftDate,
                'user' => $username,
                'endtime' => $shiftTime,
                'total_sales' => $totalSales,
                'expenses' => $expenses,
                'exp_notes' => $expNotes,
                'cash' => $cash,
                'fund_after' => $countedCash,
                'info' => $infoNotes,
                'json_details' => $jsonDetails,
                'drawer_session_id' => $drawerSessionId,
                'z_row' => $zRow,
            ]);

            if ($drawerSessionId > 0) {
                $closedSession = $this->drawerSessions->closeSession($conn, $drawerSessionId, [
                    'closed_by' => $userId,
                    'counted_cash' => (string) $countedCash,
                    'notes' => $drawerNotes,
                    'skip_close_expected_snapshot' => $closeExpectedSnapshot !== null,
                ], $childContext);

                $this->updateSessionVarianceMetadata($conn, $drawerSessionId, [
                    'variance_status' => $varianceStatus,
                    'variance_type' => $this->mergeVarianceType(
                        (string) ($drawerSession['variance_type'] ?? 'none'),
                        $varianceType
                    ),
                    'close_expected_snapshot' => $closeExpectedSnapshot !== null
                        ? $closeExpectedSnapshot
                        : (float) ($closedSession['close_expected_snapshot'] ?? 0),
                ]);

                $this->linkCountAttemptsToSession($conn, $drawerSessionId, 'close');
            }

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        // Only clear PHP session after a committed owned TX. When joining an outer
        // idempotency TX, the caller clears after the outer commit succeeds.
        if ($ownsTransaction) {
            $this->clearPosShiftSessionAfterClose();
        }

        // Prefer true pre-close over/short from finishSession (difference).
        $closedDifference = null;
        $closedExpectedSnapshot = $closeExpectedSnapshot;
        if (is_array($closedSession)) {
            if (array_key_exists('difference', $closedSession) && $closedSession['difference'] !== null) {
                $closedDifference = (float) $closedSession['difference'];
            }
            if ($closedExpectedSnapshot === null && isset($closedSession['close_expected_snapshot'])) {
                $closedExpectedSnapshot = (float) $closedSession['close_expected_snapshot'];
            }
        }

        $expectedCash = $closedExpectedSnapshot !== null
            ? (float) $closedExpectedSnapshot
            : ($drawerSessionId > 0 ? (float) ($expenseSummary['expected_cash'] ?? 0) : 0.0);
        $variance = $closedDifference !== null
            ? round($closedDifference, 3)
            : round($countedCash - $expectedCash, 3);

        return [
            'closed_order_id' => $closedOrderId,
            'drawer_session_id' => $drawerSessionId,
            'total_orders' => $totalOrders,
            'total_sales' => $totalSales,
            'username' => $username,
            'counted_cash' => $countedCash,
            'expected_cash' => $expectedCash,
            'variance' => $variance,
            'variance_status' => $varianceStatus,
            'variance_type' => $varianceType,
            'matched' => abs($variance) <= 0.010,
            'clear_pos_shift_session' => !$ownsTransaction,
        ];
    }

    public function clearPosShiftSessionAfterClose(): void
    {
        if (function_exists('posmain_clear_pos_shift_session')) {
            posmain_clear_pos_shift_session(true);
        }
        unset($_SESSION['pos_drawer_session_id'], $_SESSION['pos_shift_session_token'], $_SESSION['pos_shift_close_count']);
        if (function_exists('posmain_session_regenerate')) {
            posmain_session_regenerate();
        }
    }

    private function resolveCloseExpenses(mysqli $conn, int $userId, array $scope, array $payload): array
    {
        $summary = $this->shiftSessions->shiftExpenseSummary($conn, $userId, $scope);
        $expNotes = trim((string) ($payload['exp_notes'] ?? ''));

        if ($summary['drawer_active']) {
            return [
                'expenses' => (float) $summary['total'],
                'exp_notes' => $expNotes !== '' ? $this->truncateExpenseNotes($expNotes) : $summary['notes'],
                'expense_summary' => $summary,
            ];
        }

        return [
            'expenses' => (float) ($payload['expenses'] ?? $summary['total']),
            'exp_notes' => $this->truncateExpenseNotes($expNotes),
            'expense_summary' => $summary,
        ];
    }

    private function truncateExpenseNotes(string $notes): string
    {
        return $this->truncateUtf8($notes, 30);
    }

    /**
     * Legacy closed_orders.info column is VARCHAR(50).
     */
    private function truncateClosedOrderInfo(string $notes): string
    {
        return $this->truncateUtf8($notes, 50);
    }

    /**
     * drawer_sessions.notes accepts longer text; keep a safe upper bound.
     */
    private function truncateDrawerSessionNotes(string $notes): string
    {
        return $this->truncateUtf8($notes, 500);
    }

    private function truncateUtf8(string $value, int $maxChars): string
    {
        if ($value === '' || $maxChars < 1) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxChars);
        }

        return substr($value, 0, $maxChars);
    }

    private function cashierUsername(mysqli $conn, int $userId): string
    {
        $stmt = $conn->prepare('SELECT uname FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row['uname'] ?? ($_SESSION['login'] ?? 'Unknown');
    }

    private function insertClosedOrder(mysqli $conn, array $row): int
    {
        $zRow = is_array($row['z_row'] ?? null) ? $row['z_row'] : null;

        if ($zRow !== null) {
            return $this->insertClosedOrderZ($conn, $zRow);
        }

        if ($this->closedOrdersHasJsonDetails($conn)) {
            $stmt = $conn->prepare(
                'INSERT INTO closed_orders
                    (shift, date, user, endtime, total_sales, expenses, exp_notes, cash, fund_after, info, json_details)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssssddsddss',
                $row['shift'],
                $row['date'],
                $row['user'],
                $row['endtime'],
                $row['total_sales'],
                $row['expenses'],
                $row['exp_notes'],
                $row['cash'],
                $row['fund_after'],
                $row['info'],
                $row['json_details']
            );
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO closed_orders
                    (shift, date, user, endtime, total_sales, expenses, exp_notes, cash, fund_after, info)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssssddsdds',
                $row['shift'],
                $row['date'],
                $row['user'],
                $row['endtime'],
                $row['total_sales'],
                $row['expenses'],
                $row['exp_notes'],
                $row['cash'],
                $row['fund_after'],
                $row['info']
            );
        }

        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        if ($this->closedOrdersHasDrawerSessionId($conn) && (int) ($row['drawer_session_id'] ?? 0) > 0) {
            $sessionId = (int) $row['drawer_session_id'];
            $update = $conn->prepare('UPDATE closed_orders SET drawer_session_id = ? WHERE id = ?');
            $update->bind_param('ii', $sessionId, $id);
            $update->execute();
            $update->close();
        }

        return $id;
    }

    private function insertClosedOrderZ(mysqli $conn, array $zRow): int
    {
        $hasDrawerSessionColumn = $this->closedOrdersHasDrawerSessionId($conn);

        if ($hasDrawerSessionColumn) {
            $insertQuery = "INSERT INTO closed_orders
                     (shift, date, user, endtime,
                      total_sales, expenses, cash, fund_after, info,
                      total_cash, total_visa, total_discount,
                      actual_cash, actual_visa, deficit, status, json_details, drawer_session_id)
                     VALUES
                     (?, ?, ?, ?,
                      ?, ?, ?, ?, ?,
                      ?, ?, 0,
                      ?, ?, ?, 1, ?, ?)";
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->bind_param(
                'ssssddddsdddddsi',
                $zRow['shift'],
                $zRow['date'],
                $zRow['user'],
                $zRow['endtime'],
                $zRow['total_sales'],
                $zRow['expenses'],
                $zRow['cash'],
                $zRow['fund_after'],
                $zRow['info'],
                $zRow['total_cash'],
                $zRow['total_visa'],
                $zRow['actual_cash'],
                $zRow['actual_visa'],
                $zRow['deficit'],
                $zRow['json_details'],
                $zRow['drawer_session_id']
            );
        } else {
            $insertQuery = "INSERT INTO closed_orders
                     (shift, date, user, endtime,
                      total_sales, expenses, cash, fund_after, info,
                      total_cash, total_visa, total_discount,
                      actual_cash, actual_visa, deficit, status, json_details)
                     VALUES
                     (?, ?, ?, ?,
                      ?, ?, ?, ?, ?,
                      ?, ?, 0,
                      ?, ?, ?, 1, ?)";
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->bind_param(
                'ssssddddsddddds',
                $zRow['shift'],
                $zRow['date'],
                $zRow['user'],
                $zRow['endtime'],
                $zRow['total_sales'],
                $zRow['expenses'],
                $zRow['cash'],
                $zRow['fund_after'],
                $zRow['info'],
                $zRow['total_cash'],
                $zRow['total_visa'],
                $zRow['actual_cash'],
                $zRow['actual_visa'],
                $zRow['deficit'],
                $zRow['json_details']
            );
        }

        $insertStmt->execute();
        $id = (int) $conn->insert_id;
        $insertStmt->close();

        return $id;
    }

    private function closedOrdersHasJsonDetails(mysqli $conn): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM closed_orders LIKE 'json_details'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function closedOrdersHasDrawerSessionId(mysqli $conn): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM closed_orders LIKE 'drawer_session_id'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function updateSessionVarianceMetadata(mysqli $conn, int $sessionId, array $data): void
    {
        if (!$this->columnExists($conn, 'drawer_sessions', 'variance_status')) {
            return;
        }

        $fields = [];
        $params = [];
        $types = '';

        foreach (['variance_status', 'variance_type'] as $key) {
            if (isset($data[$key])) {
                $fields[] = "{$key} = ?";
                $params[] = (string) $data[$key];
                $types .= 's';
            }
        }

        if (isset($data['close_expected_snapshot']) && $this->columnExists($conn, 'drawer_sessions', 'close_expected_snapshot')) {
            $fields[] = 'close_expected_snapshot = ?';
            $params[] = number_format((float) $data['close_expected_snapshot'], 3, '.', '');
            $types .= 's';
        }

        if (!$fields) {
            return;
        }

        $params[] = $sessionId;
        $types .= 'i';
        $sql = 'UPDATE drawer_sessions SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    private function linkCountAttemptsToSession(mysqli $conn, int $sessionId, string $phase): void
    {
        if (!$this->tableExists($conn, 'drawer_count_attempts') || !isset($_SESSION['pos_shift_close_count'])) {
            return;
        }

        $attemptIds = array_map('intval', (array) ($_SESSION['pos_shift_close_count']['attempt_ids'] ?? []));
        if (!$attemptIds) {
            return;
        }

        foreach ($attemptIds as $attemptId) {
            if ($attemptId < 1) {
                continue;
            }
            $stmt = $conn->prepare('
                UPDATE drawer_count_attempts
                SET drawer_session_id = ?
                WHERE id = ? AND count_phase = ? AND drawer_session_id IS NULL
            ');
            $stmt->bind_param('iis', $sessionId, $attemptId, $phase);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function mergeVarianceType(string $existing, string $incoming): string
    {
        if ($existing === 'both' || ($existing === 'opening' && $incoming === 'closing') || ($existing === 'closing' && $incoming === 'opening')) {
            return 'both';
        }
        if ($incoming === 'none') {
            return $existing !== '' ? $existing : 'none';
        }
        if ($existing === 'none' || $existing === '') {
            return $incoming;
        }
        if ($existing === $incoming) {
            return $existing;
        }

        return 'both';
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
