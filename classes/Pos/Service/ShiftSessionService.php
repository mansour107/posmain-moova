<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/DrawerLedgerPostingService.php';
require_once __DIR__ . '/../../ShiftReport.php';

// Drawer session lookups depend on posmain_drawer_sessions_table_exists().
if (!function_exists('posmain_drawer_sessions_table_exists')) {
    require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';
}

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

        $registerId = $context['register_id'] ?? ($_SESSION['pos_register_id'] ?? null);
        $session = $this->drawerSessions->openSession($conn, [
            'user_id' => $userId,
            'opened_by' => (int) ($context['opened_by'] ?? $userId),
            'tenant' => $scope['tenant'],
            'branch' => $scope['branch'],
            'fund_account_id' => $fundAccountId,
            'opening_cash' => $context['opening_cash'] ?? '0',
            'opened_at' => $context['opened_at'] ?? null,
            'notes' => $context['notes'] ?? null,
            'register_id' => $registerId,
            'preceding_session_id' => $context['preceding_session_id'] ?? null,
            'takeover_authorized_by' => $context['takeover_authorized_by'] ?? null,
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

    public function recordShiftExpense(mysqli $conn, int $userId, array $payload, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

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

        $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));

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

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        $summary = $this->shiftExpenseSummary($conn, $userId, $scope);

        return [
            'movement' => $movement,
            'summary' => $summary,
        ];
    }

    public function recordShiftPayIn(mysqli $conn, int $userId, array $payload, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

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

        $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));

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

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        $summary = $this->shiftPayInSummary($conn, $userId, $scope);

        return [
            'movement' => $movement,
            'summary' => $summary,
        ];
    }

    public function recordShiftSafeDrop(mysqli $conn, int $userId, array $payload, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

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

        $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));

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

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
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

    public function closeSimpleShift(mysqli $conn, int $userId, array $payload, array $context = []): array
    {
        require_once __DIR__ . '/ShiftCloseService.php';
        require_once __DIR__ . '/ShiftCountService.php';
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        $countService = new ShiftCountService();
        if ($countService->handoverEnabled($conn)) {
            if (!empty($payload['close_token'])) {
                $result = $countService->closeWithValidatedCount($conn, $userId, $payload, $context);
            } elseif (!empty($_SESSION['pos_shift_close_count']) && empty($payload['bypass_count_token'])) {
                throw new RuntimeException('CLOSE_TOKEN_REQUIRED');
            } else {
                $result = (new ShiftCloseService())->closeShift($conn, $userId, $payload, $context);
            }
        } else {
            $result = (new ShiftCloseService())->closeShift($conn, $userId, $payload, $context);
        }

        return $result;
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
        $stmt = $conn->prepare('SELECT uname, display_name FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return (string) ($_SESSION['login'] ?? 'Unknown');
        }
        $display = trim((string) ($row['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }

        return trim((string) ($row['uname'] ?? '')) ?: (string) ($_SESSION['login'] ?? 'Unknown');
    }

    /**
     * Identity payload for POS chrome: current cashier + optional takeover lineage.
     *
     * @return array{
     *   cashier_name:string,
     *   cashier_user_id:int,
     *   terminal_name:?string,
     *   terminal_user_id:int,
     *   is_takeover:bool,
     *   preceding_cashier_name:?string,
     *   preceding_user_id:?int,
     *   authorized_by_name:?string,
     *   authorized_by_user_id:?int,
     *   drawer_session_id:?int
     * }
     */
    public function resolvePosIdentity(?mysqli $conn, ?array $session = null): array
    {
        $session = $session ?? $_SESSION;
        $actingId = function_exists('pos_acting_user_id')
            ? pos_acting_user_id()
            : (int) ($session['pos_acting_user_id'] ?? $session['pos_user_id'] ?? 0);
        $terminalId = function_exists('pos_terminal_user_id')
            ? pos_terminal_user_id()
            : (int) ($session['userid'] ?? 0);

        $cashierName = trim((string) ($session['pos_acting_user_name'] ?? $session['pos_user_name'] ?? ''));
        if ($cashierName === '' && $conn instanceof mysqli && $actingId > 0) {
            $cashierName = $this->cashierUsername($conn, $actingId);
        }
        if ($cashierName === '') {
            $cashierName = (string) ($session['login'] ?? 'الموظف');
        }

        $terminalName = null;
        if ($terminalId > 0 && $terminalId !== $actingId) {
            $terminalName = $conn instanceof mysqli
                ? $this->cashierUsername($conn, $terminalId)
                : (string) ($session['login'] ?? null);
        }

        $identity = [
            'cashier_name' => $cashierName,
            'cashier_user_id' => $actingId,
            'terminal_name' => $terminalName,
            'terminal_user_id' => $terminalId,
            'is_takeover' => false,
            'preceding_cashier_name' => null,
            'preceding_user_id' => null,
            'authorized_by_name' => null,
            'authorized_by_user_id' => null,
            'drawer_session_id' => null,
        ];

        if (!$conn instanceof mysqli || $actingId < 1) {
            return $identity;
        }

        $scope = $this->resolveScope([]);
        $drawerSession = $this->currentDrawerSession($conn, $actingId, $scope);
        if (!$drawerSession) {
            return $identity;
        }

        $identity['drawer_session_id'] = (int) ($drawerSession['id'] ?? 0);
        $ownerId = (int) ($drawerSession['user_id'] ?? 0);
        if ($ownerId > 0) {
            $identity['cashier_user_id'] = $ownerId;
            $identity['cashier_name'] = $this->cashierUsername($conn, $ownerId);
        }

        $precedingId = (int) ($drawerSession['preceding_session_id'] ?? 0);
        if ($precedingId < 1) {
            return $identity;
        }

        try {
            $preceding = $this->drawerSessions->sessionById($conn, $precedingId);
        } catch (Throwable $ignored) {
            return $identity;
        }

        $precedingUserId = (int) ($preceding['user_id'] ?? 0);
        if ($precedingUserId < 1) {
            return $identity;
        }

        $identity['is_takeover'] = true;
        $identity['preceding_user_id'] = $precedingUserId;
        $identity['preceding_cashier_name'] = $this->cashierUsername($conn, $precedingUserId);

        $authorizedBy = (int) ($drawerSession['takeover_authorized_by'] ?? 0);
        if ($authorizedBy < 1) {
            $authorizedBy = (int) ($preceding['closed_by'] ?? 0);
            // Prefer not labeling the incoming cashier as the authorizer.
            if ($authorizedBy === $actingId || $authorizedBy === $ownerId) {
                $authorizedBy = 0;
            }
        }
        if ($authorizedBy > 0) {
            $identity['authorized_by_user_id'] = $authorizedBy;
            $identity['authorized_by_name'] = $this->cashierUsername($conn, $authorizedBy);
        }

        return $identity;
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
        require_once dirname(__DIR__, 2) . '/Security/SecurityAuditLogger.php';

        $session = $this->drawerSessions->sessionById($conn, $sessionId);
        if (($session['status'] ?? '') !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }

        $scope = $this->resolveScope($payload);
        $sessionTenant = (int) ($session['tenant'] ?? 0);
        $sessionBranch = (int) ($session['branch'] ?? 0);
        if ($sessionTenant !== $scope['tenant'] || $sessionBranch !== $scope['branch']) {
            throw new RuntimeException('DRAWER_SESSION_SCOPE_MISMATCH');
        }

        $ownerUserId = (int) ($session['user_id'] ?? 0);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($ownerUserId !== $actingUserId && $reason === '') {
            throw new RuntimeException('FORCE_CLOSE_REASON_REQUIRED');
        }
        if ($reason === '') {
            $reason = 'force_close';
        }

        $approvalId = null;
        $approvalService = new ManagerApprovalService();
        $isTakeover = !empty($payload['takeover']);

        if ($isTakeover) {
            // POS drawer takeover always requires a consumed manager PIN approval —
            // never trust the unlocked POS session alone, even with force_close.
            $approvalId = (int) ($payload['manager_approval_id'] ?? $payload['approval_id'] ?? 0);
            if ($approvalId < 1) {
                throw new ManagerApprovalRequiredException('pos.shift.force_close');
            }
            $approvalService->validateApprovedPermissionOverride(
                $conn,
                $approvalId,
                'pos.shift.force_close',
                $actingUserId
            );
            $approvalService->consumeApproval($conn, $approvalId, $actingUserId);
        } elseif (!auth_guard_has_permission('pos.shift.force_close', $conn)) {
            $approval = $approvalService->requireApprovedIfNeeded(
                $conn,
                'pos.shift.force_close',
                'drawer_session',
                $sessionId,
                1.0,
                $payload,
                [
                    'user_id' => $actingUserId,
                    'require_manager_approval' => true,
                ]
            );
            if ($approval) {
                $approvalId = (int) $approval['id'];
                $approvalService->consumeApproval($conn, $approvalId, $actingUserId);
            }
        } else {
            $approvalId = (int) ($payload['manager_approval_id'] ?? 0) ?: null;
        }

        $expectedBefore = (float) $this->drawerSessions->expectedCash($conn, $sessionId);
        $countedCash = trim((string) ($payload['counted_cash'] ?? ''));
        if ($countedCash === '') {
            $countedCash = number_format($expectedBefore, 3, '.', '');
        }
        if (!is_numeric($countedCash) || (float) $countedCash < 0) {
            throw new RuntimeException('COUNTED_AMOUNT_INVALID');
        }

        $closed = $this->drawerSessions->forceCloseSession($conn, $sessionId, [
            'closed_by' => $actingUserId,
            'counted_cash' => number_format((float) $countedCash, 3, '.', ''),
            'notes' => $reason,
        ]);

        $difference = (float) ($closed['difference'] ?? 0);
        $openingUnresolved = (($session['variance_status'] ?? '') === 'unresolved')
            && in_array((string) ($session['variance_type'] ?? ''), ['opening', 'both'], true);

        $varianceStatus = (abs($difference) > 0.0001 || $openingUnresolved) ? 'unresolved' : 'none';
        $varianceType = 'none';
        if ($openingUnresolved && abs($difference) > 0.0001) {
            $varianceType = 'both';
        } elseif ($openingUnresolved) {
            $varianceType = 'opening';
        } elseif (abs($difference) > 0.0001) {
            $varianceType = 'closing';
        }

        if ($varianceStatus === 'unresolved' && $this->drawerSessionsColumnExists($conn, 'variance_status')) {
            $type = $varianceType;
            $status = $varianceStatus;
            $snapshot = number_format($expectedBefore, 3, '.', '');
            if ($this->drawerSessionsColumnExists($conn, 'close_expected_snapshot')) {
                $stmt = $conn->prepare("
                    UPDATE drawer_sessions
                    SET variance_status = ?, variance_type = ?, close_expected_snapshot = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('sssi', $status, $type, $snapshot, $sessionId);
            } else {
                $stmt = $conn->prepare("
                    UPDATE drawer_sessions
                    SET variance_status = ?, variance_type = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('ssi', $status, $type, $sessionId);
            }
            $stmt->execute();
            $stmt->close();
        }

        $result = $this->drawerSessions->sessionById($conn, $sessionId);

        try {
            $eventType = !empty($payload['takeover'])
                ? 'drawer_takeover_force_close'
                : 'drawer_force_close';
            (new SecurityAuditLogger())->record($conn, $eventType, [
                'user_id' => $actingUserId,
                'tenant' => $sessionTenant,
                'branch' => $sessionBranch,
                'target_type' => 'drawer_session',
                'target_id' => $sessionId,
                'metadata' => [
                    'owner_user_id' => $ownerUserId,
                    'incoming_user_id' => (int) ($payload['incoming_user_id'] ?? $actingUserId),
                    'counted_cash' => (float) $countedCash,
                    'expected_before' => $expectedBefore,
                    'difference' => $difference,
                    'variance_status' => $varianceStatus,
                    'variance_type' => $varianceType,
                    'reason' => $reason,
                    'manager_approval_id' => $approvalId,
                    'takeover' => !empty($payload['takeover']),
                ],
            ]);
        } catch (Throwable $ignored) {
            // Domain close already succeeded; audit must not roll it back.
        }

        return $result;
    }

    private function drawerSessionsColumnExists(mysqli $conn, string $column): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM drawer_sessions LIKE '" . $conn->real_escape_string($column) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
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
