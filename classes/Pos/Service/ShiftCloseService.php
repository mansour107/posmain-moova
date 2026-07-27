<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/DrawerSessionCloseSummaryService.php';
require_once __DIR__ . '/ShiftSessionService.php';
require_once dirname(__DIR__) . '/Value/CashAmount.php';
require_once __DIR__ . '/../../ShiftReport.php';
require_once dirname(__DIR__, 2) . '/Sync/OperationalSyncEventService.php';
require_once dirname(__DIR__, 2) . '/Financial/Money.php';

class ShiftCloseService
{
    private DrawerSessionService $drawerSessions;
    private DrawerSessionCloseSummaryService $closeSummaries;
    private ShiftSessionService $shiftSessions;

    public function __construct(
        ?DrawerSessionService $drawerSessions = null,
        ?ShiftSessionService $shiftSessions = null,
        ?DrawerSessionCloseSummaryService $closeSummaries = null
    ) {
        $this->drawerSessions = $drawerSessions ?: new DrawerSessionService();
        $this->shiftSessions = $shiftSessions ?: new ShiftSessionService();
        $this->closeSummaries = $closeSummaries ?: new DrawerSessionCloseSummaryService();
    }

    public function closeShift(mysqli $conn, int $userId, array $payload, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        if (!empty($_SESSION['pos_shift_closed_for_session'])) {
            throw new RuntimeException('SHIFT_ALREADY_CLOSED');
        }

        $joiningTransaction = posmain_tx_context_in_transaction($context);
        if (!$joiningTransaction) {
            $this->closeSummaries->ensureSchema($conn);
        }
        $ownsTransaction = posmain_tx_begin_if_needed($conn, $joiningTransaction);

        try {
        $scope = $this->shiftSessions->resolveScope($payload);
        $drawerSession = $this->shiftSessions->currentDrawerSession($conn, $userId, $scope);
        $drawerSessionId = $drawerSession ? (int) $drawerSession['id'] : 0;
        $recoveredDrawerSession = false;
        if ($drawerSessionId < 1) {
            // Compatibility for the still-supported legacy close_shift.php
            // caller. Materialize its implicit shift window as a real drawer
            // session, then close it in this same transaction.
            $preliminaryReport = new ShiftReport($conn, $userId, date('Y-m-d'), $scope);
            $openedAt = $preliminaryReport->getShiftOpenedAt();
            if ($openedAt === null || trim($openedAt) === '') {
                $bounds = $preliminaryReport->getSaleTimeBounds();
                $openedAt = trim((string) ($bounds['first_sale_time'] ?? ''));
            }
            $drawerSession = $this->recoverLegacyDrawerSession(
                $conn,
                $userId,
                $scope,
                $payload,
                $openedAt
            );
            $drawerSessionId = (int) ($drawerSession['id'] ?? 0);
            $recoveredDrawerSession = true;
        }

        $countedCash = CashAmount::normalize($payload['fund_after'] ?? $payload['counted_cash'] ?? '0.00');
        $cash = CashAmount::normalize($payload['cash'] ?? $countedCash);
        $notes = trim((string) ($payload['notes'] ?? ''));
        $closePath = (string) ($payload['close_path'] ?? 'close_shift.php');
        $varianceStatus = (string) ($payload['variance_status'] ?? 'none');
        $varianceType = (string) ($payload['variance_type'] ?? 'none');
        $closeExpectedSnapshot = isset($payload['close_expected_snapshot'])
            ? CashAmount::normalize($payload['close_expected_snapshot'])
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
        // ShiftReport is a legacy read model which still returns PHP floats.
        // Convert that internal value exactly at this one adapter boundary;
        // client mutation payloads remain strict decimal strings.
        $totalSales = $this->legacyReportAmount($totals['total_net'] ?? '0.00', true);

        $username = $this->cashierUsername($conn, $userId);
        $resolvedExpenses = $this->resolveCloseExpenses($conn, $userId, $scope, $payload);
        $expenses = CashAmount::normalize($resolvedExpenses['expenses'] ?? '0.00');
        $expNotes = (string) $resolvedExpenses['exp_notes'];
        $expenseSummary = $resolvedExpenses['expense_summary'];
        $payInSummary = $drawerSession
            ? $this->shiftSessions->shiftPayInSummary($conn, $userId, $scope)
            : ['total' => '0.00', 'count' => 0];

        $shiftSessionToken = trim((string) ($_SESSION['pos_shift_session_token'] ?? ''));
        $drawerNotes = $this->truncateDrawerSessionNotes($notes);
        if ($recoveredDrawerSession && $drawerNotes === '') {
            $drawerNotes = 'legacy_close_shift_recovery';
        }

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
                'payin_total' => CashAmount::normalize($payInSummary['total'] ?? '0.00'),
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
        if ($recoveredDrawerSession) {
            $details['legacy_drawer_session_recovered'] = true;
        }
        $jsonDetails = json_encode($details, JSON_UNESCAPED_UNICODE);

        $zRow = is_array($payload['z_row'] ?? null) ? $payload['z_row'] : null;
        if ($zRow !== null) {
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
        $closeSummary = null;
        $childContext = ['in_transaction' => true];
        $syncConfig = is_array($context['sync_config'] ?? null)
            ? $context['sync_config']
            : (is_array($payload['sync_config'] ?? null) ? $payload['sync_config'] : null);
        if ($syncConfig !== null) {
            $childContext['sync_config'] = $syncConfig;
        }

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
                    : CashAmount::normalize($closedSession['close_expected_snapshot'] ?? '0.00'),
            ]);
            $closedSession = $this->drawerSessions->captureExternalSessionMutation(
                $conn,
                $drawerSessionId,
                $childContext
            );

            $this->linkCountAttemptsToSession($conn, $drawerSessionId, 'close');

            $cashSales = CashAmount::normalize($zRow['total_cash'] ?? $details['sys_cash'] ?? $cash);
            if (isset($zRow['total_visa']) || isset($details['sys_visa'])) {
                $nonCashSales = CashAmount::normalize($zRow['total_visa'] ?? $details['sys_visa']);
            } else {
                $derivedNonCash = CashAmount::subtract($totalSales, $cashSales);
                $nonCashSales = CashAmount::compare($derivedNonCash, '0.00') < 0 ? '0.00' : $derivedNonCash;
            }
            $countedNonCash = array_key_exists('actual_visa', (array) $zRow)
                ? CashAmount::normalize($zRow['actual_visa'])
                : null;
            $closeSummary = $this->closeSummaries->createForSession($conn, $drawerSessionId, [
                'shift_number' => (string) ($zRow['shift'] ?? $shiftNumber),
                'total_orders' => $totalOrders,
                'total_sales' => CashAmount::normalize($zRow['total_sales'] ?? $totalSales, true),
                'cash_sales' => $cashSales,
                'non_cash_sales' => $nonCashSales,
                'discount_total' => isset($zRow['total_discount'])
                    ? CashAmount::normalize($zRow['total_discount'])
                    : $this->legacyReportAmount($totals['total_discount'] ?? '0.00'),
                'return_total' => CashAmount::normalize($zRow['total_returns'] ?? $details['total_returns'] ?? '0.00'),
                'expense_total' => $expenses,
                'expense_notes' => $expNotes,
                'expected_non_cash' => $nonCashSales,
                'counted_non_cash' => $countedNonCash,
                'non_cash_difference' => $countedNonCash === null
                    ? null
                    : CashAmount::subtract($countedNonCash, $nonCashSales),
                'close_path' => $closePath,
                'report_snapshot' => json_decode((string) $jsonDetails, true) ?: $details,
                'payment_summary' => [
                    'cash' => $cashSales,
                    'non_cash' => $nonCashSales,
                    'counted_cash' => $countedCash,
                    'counted_non_cash' => $countedNonCash,
                ],
            ]);

            $shiftCloseOptions = [];
            if ($syncConfig !== null) {
                $shiftCloseOptions['config'] = $syncConfig;
            }
            (new OperationalSyncEventService())->recordShiftCloseSnapshot(
                $conn,
                (int) ($closeSummary['id'] ?? 0),
                $shiftCloseOptions
            );

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
                $closedDifference = CashAmount::normalize($closedSession['difference'], true);
            }
            if ($closedExpectedSnapshot === null && isset($closedSession['close_expected_snapshot'])) {
                $closedExpectedSnapshot = CashAmount::normalize($closedSession['close_expected_snapshot']);
            }
        }

        $expectedCash = $closedExpectedSnapshot !== null
            ? CashAmount::normalize($closedExpectedSnapshot)
            : ($drawerSessionId > 0
                ? CashAmount::normalize($expenseSummary['expected_cash'] ?? '0.00')
                : '0.00');
        $variance = $closedDifference !== null
            ? CashAmount::normalize($closedDifference, true)
            : CashAmount::subtract($countedCash, $expectedCash);

        return [
            'drawer_session_id' => $drawerSessionId,
            'close_summary_id' => (int) ($closeSummary['id'] ?? 0),
            'total_orders' => $totalOrders,
            'total_sales' => $totalSales,
            'username' => $username,
            'counted_cash' => $countedCash,
            'expected_cash' => $expectedCash,
            'variance' => $variance,
            'variance_status' => $varianceStatus,
            'variance_type' => $varianceType,
            'matched' => CashAmount::compare($this->absoluteAmount($variance), '0.01') <= 0,
            'legacy_drawer_session_recovered' => $recoveredDrawerSession,
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
                'expenses' => CashAmount::normalize($summary['total'] ?? '0.00'),
                'exp_notes' => $expNotes !== '' ? $this->truncateExpenseNotes($expNotes) : $summary['notes'],
                'expense_summary' => $summary,
            ];
        }

        return [
            'expenses' => CashAmount::normalize($payload['expenses'] ?? $summary['total'] ?? '0.00'),
            'exp_notes' => $this->truncateExpenseNotes($expNotes),
            'expense_summary' => $summary,
        ];
    }

    private function truncateExpenseNotes(string $notes): string
    {
        return $this->truncateUtf8($notes, 30);
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

    private function recoverLegacyDrawerSession(
        mysqli $conn,
        int $userId,
        array $scope,
        array $payload,
        ?string $openedAt
    ): array {
        $openedAt = trim((string) $openedAt);
        if ($openedAt === '' || strtotime($openedAt) === false || strtotime($openedAt) > time()) {
            $openedAt = date('Y-m-d 00:00:00');
        }

        $session = $this->drawerSessions->openSession($conn, [
            'user_id' => $userId,
            'opened_by' => $userId,
            'tenant' => (int) ($scope['tenant'] ?? 0),
            'branch' => (int) ($scope['branch'] ?? 0),
            'register_id' => (int) ($payload['register_id'] ?? $_SESSION['pos_register_id'] ?? 0) ?: null,
            'fund_account_id' => (int) ($payload['fund_account_id'] ?? 0) ?: null,
            'opening_cash' => $payload['opening_cash'] ?? '0',
            'opened_at' => $openedAt,
            'notes' => 'legacy_close_shift_recovery',
            'in_transaction' => true,
        ]);
        if ((int) ($session['id'] ?? 0) < 1) {
            throw new RuntimeException('DRAWER_SESSION_RECOVERY_FAILED');
        }
        $_SESSION['pos_drawer_session_id'] = (int) $session['id'];

        return $session;
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
            $params[] = CashAmount::normalize($data['close_expected_snapshot']);
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

    private function absoluteAmount($value): string
    {
        $normalized = CashAmount::normalize($value, true);

        return CashAmount::compare($normalized, '0.00') < 0
            ? CashAmount::negate($normalized)
            : $normalized;
    }

    private function legacyReportAmount($value, bool $allowNegative = false): string
    {
        return Money::fromLegacy($value, $allowNegative)->toString();
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
