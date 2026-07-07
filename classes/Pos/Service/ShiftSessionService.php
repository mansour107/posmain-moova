<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/DrawerLedgerPostingService.php';
require_once __DIR__ . '/../../ShiftReport.php';

class ShiftSessionService
{
    private DrawerSessionService $drawerSessions;
    private DrawerLedgerPostingService $ledgerPosting;

    public function __construct(
        ?DrawerSessionService $drawerSessions = null,
        ?DrawerLedgerPostingService $ledgerPosting = null
    ) {
        $this->drawerSessions = $drawerSessions ?: new DrawerSessionService();
        $this->ledgerPosting = $ledgerPosting ?: new DrawerLedgerPostingService();
    }

    public function resolveScope(array $context = []): array
    {
        return [
            'tenant' => (int) ($context['tenant'] ?? $_SESSION['pos_tenant'] ?? 0),
            'branch' => (int) ($context['branch'] ?? $_SESSION['pos_branch'] ?? 0),
        ];
    }

    public function drawerSubsystemActive(mysqli $conn, int $tenant = 0, int $branch = 0): bool
    {
        if (!function_exists('posmain_drawer_sessions_table_exists')
            || !posmain_drawer_sessions_table_exists($conn)) {
            return false;
        }

        return $this->drawerSessions->branchHasSessions($conn, $tenant, $branch);
    }

    public function openForCashier(mysqli $conn, int $userId, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';

        if (!posmain_drawer_sessions_table_exists($conn)) {
            posmain_begin_pos_shift_session($userId);

            return [
                'id' => 0,
                'status' => 'open',
                'opened_at' => date('Y-m-d H:i:s'),
                'user_id' => $userId,
            ];
        }

        $scope = $this->resolveScope($context);
        $existing = $this->drawerSessions->findOpenSession(
            $conn,
            $userId,
            $scope['tenant'],
            $scope['branch']
        );

        if ($existing) {
            $_SESSION['pos_drawer_session_id'] = (int) $existing['id'];
            posmain_begin_pos_shift_session($userId);

            return $existing;
        }

        $fundAccountId = $context['fund_account_id'] ?? null;
        if ($fundAccountId === null || (int) $fundAccountId < 1) {
            $fundAccountId = $this->ledgerPosting->resolveFundAccountId($conn, [
                'fund_account_id' => null,
            ]);
            if ((int) $fundAccountId < 1) {
                $fundAccountId = null;
            }
        }

        $session = $this->drawerSessions->openSession($conn, [
            'user_id' => $userId,
            'opened_by' => (int) ($context['opened_by'] ?? $userId),
            'tenant' => $scope['tenant'],
            'branch' => $scope['branch'],
            'fund_account_id' => $fundAccountId,
            'opening_cash' => $context['opening_cash'] ?? '0',
            'opened_at' => $context['opened_at'] ?? null,
            'notes' => $context['notes'] ?? null,
        ]);

        $_SESSION['pos_drawer_session_id'] = (int) $session['id'];
        posmain_begin_pos_shift_session($userId);

        return $session;
    }

    public function currentDrawerSession(mysqli $conn, int $userId, array $context = []): ?array
    {
        if (!function_exists('posmain_drawer_sessions_table_exists')
            || !posmain_drawer_sessions_table_exists($conn)) {
            return null;
        }

        $scope = $this->resolveScope($context);
        $sessionId = (int) ($context['drawer_session_id'] ?? $_SESSION['pos_drawer_session_id'] ?? 0);

        if ($sessionId > 0) {
            try {
                $session = $this->drawerSessions->sessionById($conn, $sessionId);
                if ($session['status'] === 'open' && (int) $session['user_id'] === $userId) {
                    return $session;
                }
            } catch (Throwable $exception) {
                // fall through to lookup
            }
        }

        return $this->drawerSessions->findOpenSession(
            $conn,
            $userId,
            $scope['tenant'],
            $scope['branch']
        );
    }

    public function recordShiftExpense(mysqli $conn, int $userId, array $payload): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';

        if (posmain_pos_shift_write_blocked(null, $conn)) {
            throw new RuntimeException('SHIFT_WRITE_BLOCKED');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 3);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($amount <= 0) {
            throw new RuntimeException('EXPENSE_AMOUNT_REQUIRED');
        }
        if ($reason === '') {
            throw new RuntimeException('EXPENSE_REASON_REQUIRED');
        }

        $scope = $this->resolveScope($payload);
        $drawerSession = $this->currentDrawerSession($conn, $userId, $scope);
        if (!$drawerSession) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        $managerApprovalId = $this->requirePayoutApprovalIfNeeded(
            $conn,
            $userId,
            $amount,
            (int) $drawerSession['id'],
            $payload
        );

        $conn->begin_transaction();

        try {
            $fundAccountId = $this->ledgerPosting->resolveFundAccountId($conn, $drawerSession);
            $refOtHeadId = null;
            if ($this->ledgerPosting->canPost($conn)) {
                $refOtHeadId = $this->ledgerPosting->postPayOut(
                    $conn,
                    $amount,
                    $reason,
                    $userId,
                    $fundAccountId,
                    (int) $drawerSession['id']
                );
            }

            $movement = $this->drawerSessions->recordMovement($conn, (int) $drawerSession['id'], [
                'movement_type' => 'paid_out',
                'amount' => number_format($amount, 3, '.', ''),
                'reason' => $reason,
                'created_by' => $userId,
                'manager_approval_id' => $managerApprovalId,
                'ref_ot_head_id' => $refOtHeadId,
            ]);

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $summary = $this->shiftExpenseSummary($conn, $userId, $scope);

        return [
            'movement' => $movement,
            'summary' => $summary,
        ];
    }

    public function recordShiftPayIn(mysqli $conn, int $userId, array $payload): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';

        if (posmain_pos_shift_write_blocked(null, $conn)) {
            throw new RuntimeException('SHIFT_WRITE_BLOCKED');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 3);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($amount <= 0) {
            throw new RuntimeException('PAYIN_AMOUNT_REQUIRED');
        }
        if ($reason === '') {
            throw new RuntimeException('PAYIN_REASON_REQUIRED');
        }

        $scope = $this->resolveScope($payload);
        $drawerSession = $this->currentDrawerSession($conn, $userId, $scope);
        if (!$drawerSession) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        $managerApprovalId = (int) ($payload['manager_approval_id'] ?? 0) ?: null;

        $conn->begin_transaction();

        try {
            $fundAccountId = $this->ledgerPosting->resolveFundAccountId($conn, $drawerSession);
            $refOtHeadId = null;
            if ($this->ledgerPosting->canPost($conn)) {
                $refOtHeadId = $this->ledgerPosting->postPayIn(
                    $conn,
                    $amount,
                    $reason,
                    $userId,
                    $fundAccountId,
                    (int) $drawerSession['id']
                );
            }

            $movement = $this->drawerSessions->recordMovement($conn, (int) $drawerSession['id'], [
                'movement_type' => 'paid_in',
                'amount' => number_format($amount, 3, '.', ''),
                'reason' => $reason,
                'created_by' => $userId,
                'manager_approval_id' => $managerApprovalId,
                'ref_ot_head_id' => $refOtHeadId,
            ]);

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $summary = $this->shiftPayInSummary($conn, $userId, $scope);

        return [
            'movement' => $movement,
            'summary' => $summary,
        ];
    }

    public function recordShiftSafeDrop(mysqli $conn, int $userId, array $payload): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';

        if (posmain_pos_shift_write_blocked(null, $conn)) {
            throw new RuntimeException('SHIFT_WRITE_BLOCKED');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 3);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($amount <= 0) {
            throw new RuntimeException('SAFE_DROP_AMOUNT_REQUIRED');
        }
        if ($reason === '') {
            throw new RuntimeException('SAFE_DROP_REASON_REQUIRED');
        }

        $scope = $this->resolveScope($payload);
        $drawerSession = $this->currentDrawerSession($conn, $userId, $scope);
        if (!$drawerSession) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        $managerApprovalId = (int) ($payload['manager_approval_id'] ?? 0) ?: null;

        $conn->begin_transaction();

        try {
            $fundAccountId = $this->ledgerPosting->resolveFundAccountId($conn, $drawerSession);
            $refOtHeadId = null;
            if ($this->ledgerPosting->canPost($conn)) {
                $refOtHeadId = $this->ledgerPosting->postSafeDrop(
                    $conn,
                    $amount,
                    $reason,
                    $userId,
                    $fundAccountId,
                    (int) $drawerSession['id']
                );
            }

            $movement = $this->drawerSessions->recordMovement($conn, (int) $drawerSession['id'], [
                'movement_type' => 'safe_drop',
                'amount' => number_format($amount, 3, '.', ''),
                'reason' => $reason,
                'created_by' => $userId,
                'manager_approval_id' => $managerApprovalId,
                'ref_ot_head_id' => $refOtHeadId,
            ]);

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $summary = $this->shiftSafeDropSummary($conn, $userId, $scope);

        return [
            'movement' => $movement,
            'summary' => $summary,
        ];
    }

    public function shiftExpenseSummary(mysqli $conn, int $userId, array $context = []): array
    {
        $scope = $this->resolveScope($context);
        if (!empty($context['drawer_session_id'])) {
            $scope['drawer_session_id'] = (int) $context['drawer_session_id'];
        }
        $drawerSession = $this->currentDrawerSession($conn, $userId, $scope);
        if ($drawerSession) {
            return $this->drawerExpenseSummary($conn, $drawerSession);
        }

        $reportScope = $scope;
        $report = new ShiftReport($conn, $userId, $context['date'] ?? date('Y-m-d'), $reportScope);
        $voucherTotal = (float) ($report->getExpenses()['total'] ?? 0);

        return [
            'source' => 'legacy',
            'drawer_active' => false,
            'mid_shift_enabled' => false,
            'total' => $voucherTotal,
            'total_formatted' => number_format($voucherTotal, 2),
            'count' => 0,
            'notes' => '',
            'movements' => [],
            'expected_cash' => null,
        ];
    }

    public function shiftPayInSummary(mysqli $conn, int $userId, array $context = []): array
    {
        $cashSummary = $this->drawerCashMovementSummary($conn, $userId, $context);
        $payins = $cashSummary['payins'];
        $payins['source'] = $cashSummary['source'];
        $payins['drawer_active'] = $cashSummary['drawer_active'];
        $payins['mid_shift_enabled'] = $cashSummary['mid_shift_enabled'];
        $payins['drawer_session_id'] = $cashSummary['drawer_session_id'] ?? null;
        $payins['expected_cash'] = $cashSummary['expected_cash'];

        return $payins;
    }

    public function shiftSafeDropSummary(mysqli $conn, int $userId, array $context = []): array
    {
        $cashSummary = $this->drawerCashMovementSummary($conn, $userId, $context);
        $safeDrops = $cashSummary['safe_drops'];
        $safeDrops['source'] = $cashSummary['source'];
        $safeDrops['drawer_active'] = $cashSummary['drawer_active'];
        $safeDrops['mid_shift_enabled'] = $cashSummary['mid_shift_enabled'];
        $safeDrops['drawer_session_id'] = $cashSummary['drawer_session_id'] ?? null;
        $safeDrops['expected_cash'] = $cashSummary['expected_cash'];

        return $safeDrops;
    }

    public function drawerCashMovementSummary(mysqli $conn, int $userId, array $context = []): array
    {
        $scope = $this->resolveScope($context);
        if (!empty($context['drawer_session_id'])) {
            $scope['drawer_session_id'] = (int) $context['drawer_session_id'];
        }
        $drawerSession = $this->currentDrawerSession($conn, $userId, $scope);
        if (!$drawerSession) {
            return [
                'source' => 'legacy',
                'drawer_active' => false,
                'mid_shift_enabled' => false,
                'expected_cash' => null,
                'payins' => [
                    'total' => 0.0,
                    'total_formatted' => '0.00',
                    'count' => 0,
                    'notes' => '',
                    'movements' => [],
                ],
                'payouts' => [
                    'total' => 0.0,
                    'total_formatted' => '0.00',
                    'count' => 0,
                    'notes' => '',
                    'movements' => [],
                ],
                'safe_drops' => [
                    'total' => 0.0,
                    'total_formatted' => '0.00',
                    'count' => 0,
                    'notes' => '',
                    'movements' => [],
                ],
            ];
        }

        $payins = $this->summarizeMovements($conn, $drawerSession, 'paid_in', true);
        $payouts = $this->summarizeMovements($conn, $drawerSession, 'paid_out', false);
        $safeDrops = $this->summarizeMovements($conn, $drawerSession, 'safe_drop', false);

        return [
            'source' => 'drawer',
            'drawer_active' => true,
            'mid_shift_enabled' => true,
            'drawer_session_id' => (int) $drawerSession['id'],
            'expected_cash' => $this->drawerSessions->expectedCash($conn, (int) $drawerSession['id']),
            'payins' => $payins,
            'payouts' => $payouts,
            'safe_drops' => $safeDrops,
        ];
    }

    public function drawerExpenseSummary(mysqli $conn, array $drawerSession): array
    {
        $cashSummary = $this->drawerCashMovementSummary($conn, (int) $drawerSession['user_id'], [
            'drawer_session_id' => (int) $drawerSession['id'],
            'tenant' => (int) ($drawerSession['tenant'] ?? 0),
            'branch' => (int) ($drawerSession['branch'] ?? 0),
        ]);
        $payouts = $cashSummary['payouts'];

        return [
            'source' => 'drawer',
            'drawer_active' => true,
            'mid_shift_enabled' => true,
            'drawer_session_id' => (int) $drawerSession['id'],
            'total' => $payouts['total'],
            'total_formatted' => $payouts['total_formatted'],
            'count' => $payouts['count'],
            'notes' => $payouts['notes'],
            'movements' => $payouts['movements'],
            'expected_cash' => $cashSummary['expected_cash'],
        ];
    }

    private function summarizeMovements(
        mysqli $conn,
        array $drawerSession,
        string $movementType,
        bool $excludeZeroAmountPaidIn
    ): array {
        $movements = [];
        $total = 0.0;
        $notes = [];

        foreach ($this->drawerSessions->movementsForSession($conn, (int) $drawerSession['id']) as $movement) {
            if ((string) ($movement['movement_type'] ?? '') !== $movementType) {
                continue;
            }

            $amount = (float) ($movement['amount'] ?? 0);
            if ($excludeZeroAmountPaidIn && $amount <= 0) {
                continue;
            }

            $total += $amount;
            $reason = trim((string) ($movement['reason'] ?? ''));
            if ($reason !== '') {
                $notes[] = $reason;
            }

            $movements[] = [
                'id' => (int) ($movement['id'] ?? 0),
                'amount' => number_format($amount, 2, '.', ''),
                'reason' => $reason,
                'created_at' => (string) ($movement['created_at'] ?? ''),
            ];
        }

        return [
            'total' => $total,
            'total_formatted' => number_format($total, 2),
            'count' => count($movements),
            'notes' => $this->truncateExpenseNotes(implode(' | ', $notes)),
            'movements' => $movements,
        ];
    }

    private function resolveCloseExpenses(mysqli $conn, int $userId, array $scope, array $payload): array
    {
        $summary = $this->shiftExpenseSummary($conn, $userId, $scope);
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
        if ($notes === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($notes, 0, 30);
        }

        return substr($notes, 0, 30);
    }

    public function closeSimpleShift(mysqli $conn, int $userId, array $payload): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';

        if (!empty($_SESSION['pos_shift_closed_for_session'])) {
            throw new RuntimeException('SHIFT_ALREADY_CLOSED');
        }

        $scope = $this->resolveScope($payload);
        $drawerSession = $this->currentDrawerSession($conn, $userId, $scope);
        $drawerSessionId = $drawerSession ? (int) $drawerSession['id'] : 0;

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
            ? $this->shiftPayInSummary($conn, $userId, $scope)
            : ['total' => 0.0, 'count' => 0];
        $cash = (float) ($payload['cash'] ?? 0);
        $fundAfter = (float) ($payload['fund_after'] ?? 0);
        $notes = trim((string) ($payload['notes'] ?? ''));
        $shiftSessionToken = trim((string) ($_SESSION['pos_shift_session_token'] ?? ''));
        if ($shiftSessionToken !== '') {
            $tokenSuffix = 'shift_token:' . $shiftSessionToken;
            $notes = $notes === '' ? $tokenSuffix : $notes . ' | ' . $tokenSuffix;
        }

        $jsonDetails = json_encode([
            'drawer_session_id' => $drawerSessionId,
            'shift_opened_at' => $drawerSession['opened_at'] ?? null,
            'total_orders' => $totalOrders,
            'close_path' => 'close_shift.php',
            'expense_source' => $expenseSummary['source'] ?? null,
            'expense_count' => (int) ($expenseSummary['count'] ?? 0),
            'payin_total' => (float) ($payInSummary['total'] ?? 0),
            'payin_count' => (int) ($payInSummary['count'] ?? 0),
            'drawer_expected_cash' => $expenseSummary['expected_cash'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $shiftNumber = date('Ymd') . '_' . $userId;
        $conn->begin_transaction();

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
                'fund_after' => $fundAfter,
                'info' => $notes,
                'json_details' => $jsonDetails,
            ]);

            if ($drawerSessionId > 0) {
                $this->drawerSessions->closeSession($conn, $drawerSessionId, [
                    'closed_by' => $userId,
                    'counted_cash' => (string) $fundAfter,
                    'notes' => $notes,
                ]);
            }

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        posmain_clear_pos_shift_session(true);
        unset($_SESSION['pos_drawer_session_id'], $_SESSION['pos_shift_session_token']);
        if (function_exists('posmain_session_regenerate')) {
            posmain_session_regenerate();
        }

        return [
            'closed_order_id' => $closedOrderId,
            'drawer_session_id' => $drawerSessionId,
            'total_orders' => $totalOrders,
            'total_sales' => $totalSales,
            'username' => $username,
        ];
    }

    public function sessionStatus(?mysqli $conn, ?array $session = null): array
    {
        $session = $session ?? $_SESSION;
        $userId = auth_guard_user_id_from_session($session);
        $scope = $this->resolveScope([]);
        $drawerSession = ($userId > 0 && $conn instanceof mysqli)
            ? $this->currentDrawerSession($conn, $userId, $scope)
            : null;

        $drawerTableExists = $conn instanceof mysqli
            && function_exists('posmain_drawer_sessions_table_exists')
            && posmain_drawer_sessions_table_exists($conn);
        $drawerSubsystemActive = $drawerTableExists
            && $this->drawerSubsystemActive($conn, $scope['tenant'], $scope['branch']);

        $barcodeShiftActive = auth_guard_is_pos_barcode_unlocked($session);

        if (!$barcodeShiftActive) {
            $shiftOpen = false;
        } elseif ($drawerSubsystemActive) {
            $shiftOpen = $drawerSession !== null;
        } else {
            $shiftOpen = true;
        }

        return [
            'authenticated' => auth_guard_is_pos_write_authorized($session),
            'shift_open' => $shiftOpen,
            'drawer_session_id' => $drawerSession ? (int) $drawerSession['id'] : null,
            'shift_opened_at' => $drawerSession['opened_at'] ?? null,
        ];
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

        return $id;
    }

    private function closedOrdersHasJsonDetails(mysqli $conn): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM closed_orders LIKE 'json_details'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function forceCloseDrawerForUser(mysqli $conn, int $actingUserId, int $sessionId, array $payload = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once __DIR__ . '/ManagerApprovalService.php';

        $session = $this->drawerSessions->sessionById($conn, $sessionId);
        if (($session['status'] ?? '') !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }

        if (!auth_guard_has_permission('pos.shift.force_close', $conn)) {
            $approvalService = new ManagerApprovalService();
            $approval = $approvalService->requireApprovedIfNeeded(
                $conn,
                'pos.shift.force_close',
                'drawer_session',
                $sessionId,
                1.0,
                $payload,
                ['user_id' => $actingUserId]
            );
            if ($approval) {
                $approvalService->consumeApproval($conn, (int) $approval['id'], $actingUserId);
            }
        }

        return $this->drawerSessions->forceCloseSession($conn, $sessionId, [
            'closed_by' => $actingUserId,
            'reason' => trim((string) ($payload['reason'] ?? 'force_close')),
        ]);
    }

    private function requirePayoutApprovalIfNeeded(
        mysqli $conn,
        int $userId,
        float $amount,
        int $drawerSessionId,
        array $request
    ): ?int {
        if (!class_exists('PermissionService', false)) {
            require_once dirname(__DIR__, 2) . '/Security/PermissionService.php';
        }
        if (!class_exists('ManagerApprovalService', false)) {
            require_once __DIR__ . '/ManagerApprovalService.php';
        }

        if (!$this->payoutLimitInfrastructureAvailable($conn)) {
            return null;
        }

        $permissionService = PermissionService::forConnection($conn);
        $limit = $permissionService->limit($userId, 'pos.payout.over_limit');
        $withinLimit = true;
        if ($limit !== null && empty($limit['is_unlimited']) && $limit['limit_value'] !== null) {
            $withinLimit = $amount <= (float) $limit['limit_value'];
        }

        if ($withinLimit) {
            return null;
        }

        $approvalService = new ManagerApprovalService();
        $approval = $approvalService->requireApprovedIfNeeded(
            $conn,
            'pos.payout.over_limit',
            'drawer_session',
            $drawerSessionId,
            $amount,
            $request,
            [
                'user_id' => $userId,
                'limit_permission_key' => 'pos.payout.over_limit',
                'escalation_permission_key' => 'pos.payout.over_limit',
            ]
        );
        if ($approval) {
            $approvalService->consumeApproval($conn, (int) $approval['id'], $userId);

            return (int) $approval['id'];
        }

        return null;
    }

    private function payoutLimitInfrastructureAvailable(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'usr_pwrs'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
