<?php

require_once __DIR__ . '/DrawerFloatExpectationService.php';
require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/ShiftSessionService.php';
require_once __DIR__ . '/ShiftCloseService.php';
require_once __DIR__ . '/DrawerBranchBlockedException.php';
require_once __DIR__ . '/PosRegisterService.php';

if (!function_exists('posmain_drawer_sessions_table_exists')) {
    require_once dirname(__DIR__, 3) . '/includes/pos_shift_guard.php';
}

class ShiftCountService
{
    private const MAX_ATTEMPTS = 2;
    private const TOKEN_TTL_SECONDS = 900;

    private DrawerFloatExpectationService $floatExpectation;
    private DrawerSessionService $drawerSessions;
    private ShiftSessionService $shiftSessions;
    private ShiftCloseService $shiftClose;
    private PosRegisterService $registers;

    public function __construct(
        ?DrawerFloatExpectationService $floatExpectation = null,
        ?DrawerSessionService $drawerSessions = null,
        ?ShiftSessionService $shiftSessions = null,
        ?ShiftCloseService $shiftClose = null,
        ?PosRegisterService $registers = null
    ) {
        $this->floatExpectation = $floatExpectation ?: new DrawerFloatExpectationService();
        $this->drawerSessions = $drawerSessions ?: new DrawerSessionService();
        $this->shiftSessions = $shiftSessions ?: new ShiftSessionService();
        $this->shiftClose = $shiftClose ?: new ShiftCloseService();
        $this->registers = $registers ?: new PosRegisterService();
    }

    public function handoverEnabled(mysqli $conn): bool
    {
        return $this->tableExists($conn, 'drawer_count_attempts')
            && $this->columnExists($conn, 'drawer_sessions', 'variance_status');
    }

    public function needsOpeningCount(mysqli $conn, int $userId, array $context = []): bool
    {
        if (!$this->handoverEnabled($conn)) {
            return false;
        }

        // When handover is enabled, every unlock without an open drawer must go
        // through blind opening count — including the first session on a branch.
        // Do NOT gate on branchHasSessions(); that flag is only for legacy
        // order-write fail-open behavior in pos_shift_guard.

        $scope = $this->shiftSessions->resolveScope($context);
        $existing = $this->drawerSessions->findOpenSession(
            $conn,
            $userId,
            $scope['tenant'],
            $scope['branch']
        );

        return $existing === null;
    }

    public function beginOpenCount(mysqli $conn, int $userId, array $context = []): array
    {
        if (!$this->handoverEnabled($conn)) {
            throw new RuntimeException('HANDOVER_NOT_ENABLED');
        }

        $scope = $this->shiftSessions->resolveScope($context);
        $scope['register_id'] = $this->resolvePairedRegisterId($conn, $scope, $context);
        $this->assertNoBlockingOpenSession($conn, $userId, $scope);

        $breakdown = $this->floatExpectation->expectedOpeningFloat($conn, $scope['tenant'], $scope['branch']);
        if (!empty($breakdown['baseline_required'])) {
            throw new RuntimeException('OPENING_BASELINE_REQUIRED');
        }

        $tolerance = $this->floatExpectation->toleranceForBranch($conn, $scope['tenant'], $scope['branch']);

        $_SESSION['pos_shift_open_count'] = [
            'user_id' => $userId,
            'tenant' => $scope['tenant'],
            'branch' => $scope['branch'],
            'register_id' => $scope['register_id'],
            'expected' => (float) $breakdown['expected'],
            'breakdown' => $breakdown,
            'tolerance' => $tolerance,
            'attempt_number' => 0,
            'attempt_ids' => [],
            'started_at' => time(),
        ];

        return [
            'phase' => 'open',
            'attempt_number' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'unassigned_net' => $breakdown['unassigned_net'],
            'has_unassigned' => abs((float) $breakdown['unassigned_net']) > 0.0001,
        ];
    }

    public function submitOpenCount(mysqli $conn, int $userId, string $countedAmount, array $context = []): array
    {
        if (!$this->handoverEnabled($conn)) {
            throw new RuntimeException('HANDOVER_NOT_ENABLED');
        }

        $state = $_SESSION['pos_shift_open_count'] ?? null;
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('OPEN_COUNT_NOT_STARTED');
        }

        if (!empty($state['breakdown']['baseline_required'])) {
            throw new RuntimeException('OPENING_BASELINE_REQUIRED');
        }

        $scope = $this->shiftSessions->resolveScope($context);
        $scope['register_id'] = $this->resolvePairedRegisterId($conn, $scope, $context);
        $startedRegisterId = (int) ($state['register_id'] ?? 0);
        if ($startedRegisterId > 0 && $startedRegisterId !== (int) $scope['register_id']) {
            unset($_SESSION['pos_shift_open_count']);
            throw new RuntimeException('REGISTER_CHANGED');
        }
        $this->assertNoBlockingOpenSession($conn, $userId, $scope);

        $attemptNumber = (int) ($state['attempt_number'] ?? 0) + 1;
        if ($attemptNumber > self::MAX_ATTEMPTS) {
            throw new RuntimeException('OPEN_COUNT_MAX_ATTEMPTS');
        }

        $counted = round((float) $countedAmount, 3);
        if ($counted < 0) {
            throw new RuntimeException('COUNTED_AMOUNT_INVALID');
        }

        $expected = (float) ($state['expected'] ?? 0);
        $tolerance = (float) ($state['tolerance'] ?? 0.010);
        $variance = round($counted - $expected, 3);
        $matched = $this->floatExpectation->amountsMatch($counted, $expected, $tolerance);

        $attemptId = $this->recordCountAttempt($conn, [
            'drawer_session_id' => null,
            'count_phase' => 'open',
            'attempt_number' => $attemptNumber,
            'counted_amount' => $counted,
            'expected_amount' => $expected,
            'variance' => $variance,
            'matched' => $matched,
            'expected_snapshot_json' => $state['breakdown'] ?? [],
            'tenant' => $scope['tenant'],
            'branch' => $scope['branch'],
            'created_by' => $userId,
        ]);

        $state['attempt_number'] = $attemptNumber;
        $state['attempt_ids'][] = $attemptId;
        $_SESSION['pos_shift_open_count'] = $state;

        if ($matched || $attemptNumber >= self::MAX_ATTEMPTS) {
            return $this->finalizeOpen(
                $conn,
                $userId,
                $counted,
                $expected,
                $variance,
                $matched,
                $scope,
                $state,
                $context
            );
        }

        return [
            'status' => 'recount',
            'phase' => 'open',
            'attempt_number' => $attemptNumber,
            'max_attempts' => self::MAX_ATTEMPTS,
            'message' => 'الرجاء إعادة العد بعناية',
        ];
    }

    public function beginCloseCount(mysqli $conn, int $userId, array $context = []): array
    {
        if (!$this->handoverEnabled($conn)) {
            throw new RuntimeException('HANDOVER_NOT_ENABLED');
        }

        $scope = $this->shiftSessions->resolveScope($context);
        if (!empty($context['drawer_session_id'])) {
            $scope['drawer_session_id'] = (int) $context['drawer_session_id'];
        }
        $drawerSession = $this->shiftSessions->currentDrawerSession($conn, $userId, $scope);
        if (!$drawerSession && !empty($scope['drawer_session_id'])) {
            try {
                $candidate = $this->drawerSessions->sessionById($conn, (int) $scope['drawer_session_id']);
                if (($candidate['status'] ?? '') === 'open' && (int) ($candidate['user_id'] ?? 0) === $userId) {
                    $drawerSession = $candidate;
                }
            } catch (Throwable $exception) {
                // fall through
            }
        }
        if (!$drawerSession) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        $sessionId = (int) $drawerSession['id'];
        $existing = $_SESSION['pos_shift_close_count'] ?? null;
        $canResume = is_array($existing)
            && (int) ($existing['user_id'] ?? 0) === $userId
            && (int) ($existing['drawer_session_id'] ?? 0) === $sessionId
            && (time() - (int) ($existing['started_at'] ?? 0)) <= self::TOKEN_TTL_SECONDS;

        if ($canResume) {
            $attemptNumber = (int) ($existing['attempt_number'] ?? 0);
            if ($attemptNumber >= self::MAX_ATTEMPTS) {
                throw new RuntimeException('CLOSE_COUNT_MAX_ATTEMPTS');
            }

            $token = (string) ($existing['token'] ?? '');
            if ($token === '') {
                $token = $this->issueCloseToken(
                    $sessionId,
                    (float) ($existing['expected'] ?? 0),
                    $userId
                );
                $existing['token'] = $token;
                $_SESSION['pos_shift_close_count'] = $existing;
            }

            return [
                'phase' => 'close',
                'attempt_number' => $attemptNumber,
                'max_attempts' => self::MAX_ATTEMPTS,
                'drawer_session_id' => $sessionId,
                'close_token' => $token,
            ];
        }

        $expected = (float) $this->drawerSessions->expectedCash($conn, $sessionId);
        $tolerance = $this->floatExpectation->toleranceForBranch(
            $conn,
            (int) ($drawerSession['tenant'] ?? 0),
            (int) ($drawerSession['branch'] ?? 0)
        );
        $token = $this->issueCloseToken($sessionId, $expected, $userId);

        $_SESSION['pos_shift_close_count'] = [
            'user_id' => $userId,
            'drawer_session_id' => $sessionId,
            'expected' => $expected,
            'tolerance' => $tolerance,
            'attempt_number' => 0,
            'attempt_ids' => [],
            'token' => $token,
            'started_at' => time(),
        ];

        return [
            'phase' => 'close',
            'attempt_number' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'drawer_session_id' => $sessionId,
            'close_token' => $token,
        ];
    }

    public function submitCloseCount(mysqli $conn, int $userId, string $countedAmount, array $context = []): array
    {
        if (!$this->handoverEnabled($conn)) {
            throw new RuntimeException('HANDOVER_NOT_ENABLED');
        }

        $state = $_SESSION['pos_shift_close_count'] ?? null;
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('CLOSE_COUNT_NOT_STARTED');
        }

        $scope = $this->shiftSessions->resolveScope($context);
        if (!empty($context['drawer_session_id'])) {
            $scope['drawer_session_id'] = (int) $context['drawer_session_id'];
        }

        $sessionId = (int) ($state['drawer_session_id'] ?? 0);
        if ($sessionId < 1 && !empty($scope['drawer_session_id'])) {
            $sessionId = (int) $scope['drawer_session_id'];
        }
        $drawerSession = $this->drawerSessions->sessionById($conn, $sessionId);
        if (($drawerSession['status'] ?? '') !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }

        $currentExpected = (float) $this->drawerSessions->expectedCash($conn, $sessionId);
        $snapshotExpected = (float) ($state['expected'] ?? 0);
        $tolerance = (float) ($state['tolerance'] ?? 0.010);

        if (!$this->floatExpectation->amountsMatch($currentExpected, $snapshotExpected, $tolerance)) {
            unset($_SESSION['pos_shift_close_count']);
            throw new RuntimeException('CLOSE_EXPECTED_DRIFTED');
        }

        $attemptNumber = (int) ($state['attempt_number'] ?? 0) + 1;
        if ($attemptNumber > self::MAX_ATTEMPTS) {
            throw new RuntimeException('CLOSE_COUNT_MAX_ATTEMPTS');
        }

        $counted = round((float) $countedAmount, 3);
        if ($counted < 0) {
            throw new RuntimeException('COUNTED_AMOUNT_INVALID');
        }

        $variance = round($counted - $snapshotExpected, 3);
        $matched = $this->floatExpectation->amountsMatch($counted, $snapshotExpected, $tolerance);

        $attemptId = $this->recordCountAttempt($conn, [
            'drawer_session_id' => $sessionId,
            'count_phase' => 'close',
            'attempt_number' => $attemptNumber,
            'counted_amount' => $counted,
            'expected_amount' => $snapshotExpected,
            'variance' => $variance,
            'matched' => $matched,
            'expected_snapshot_json' => ['expected_cash' => $snapshotExpected],
            'tenant' => (int) ($drawerSession['tenant'] ?? 0),
            'branch' => (int) ($drawerSession['branch'] ?? 0),
            'created_by' => $userId,
        ]);

        $state['attempt_number'] = $attemptNumber;
        $state['attempt_ids'][] = $attemptId;
        $_SESSION['pos_shift_close_count'] = $state;

        if ($matched || $attemptNumber >= self::MAX_ATTEMPTS) {
            $closeToken = $this->issueCloseToken($sessionId, $snapshotExpected, $userId, $counted, $matched, $conn);
            $canSeeExpected = $this->canSeeExpectedCash($conn);

            $response = [
                'status' => $matched ? 'ready_to_close' : 'close_with_variance',
                'phase' => 'close',
                'attempt_number' => $attemptNumber,
                'matched' => $matched,
                'counted_cash' => $counted,
                'close_token' => $closeToken,
                'message' => $matched
                    ? 'العد متطابق — جاري إغلاق الشيفت'
                    : 'تم تسجيل العد — جاري إغلاق الشيفت',
            ];

            // Blind cashiers must not learn expected or over/short before close.
            if ($canSeeExpected) {
                $response['variance'] = $variance;
                $response['variance_direction'] = $variance > 0 ? 'over' : ($variance < 0 ? 'under' : 'balanced');
                $response['expected_cash'] = $snapshotExpected;
                if (!$matched) {
                    $response['message'] = $variance > 0
                        ? 'زيادة في الدرج: ' . number_format(abs($variance), 2)
                        : 'عجز في الدرج: ' . number_format(abs($variance), 2);
                }
            }

            return $response;
        }

        return [
            'status' => 'recount',
            'phase' => 'close',
            'attempt_number' => $attemptNumber,
            'max_attempts' => self::MAX_ATTEMPTS,
            'message' => 'الرجاء إعادة العد بعناية',
        ];
    }

    /**
     * Blind recount before force-closing someone else's open drawer (takeover).
     *
     * @return array{phase:string,attempt_number:int,max_attempts:int,drawer_session_id:int}
     */
    public function beginTakeoverCloseCount(mysqli $conn, int $userId, int $blockingSessionId, array $context = []): array
    {
        if (!$this->handoverEnabled($conn)) {
            throw new RuntimeException('HANDOVER_NOT_ENABLED');
        }
        if ($blockingSessionId < 1) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        $scope = $this->shiftSessions->resolveScope($context);
        $drawerSession = $this->drawerSessions->sessionById($conn, $blockingSessionId);
        if (($drawerSession['status'] ?? '') !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }
        if ((int) ($drawerSession['user_id'] ?? 0) === $userId) {
            throw new RuntimeException('CANNOT_TAKEOVER_OWN_SESSION');
        }
        $sessionTenant = (int) ($drawerSession['tenant'] ?? 0);
        $sessionBranch = (int) ($drawerSession['branch'] ?? 0);
        if ($sessionTenant !== (int) $scope['tenant'] || $sessionBranch !== (int) $scope['branch']) {
            throw new RuntimeException('DRAWER_SESSION_SCOPE_MISMATCH');
        }

        $expected = (float) $this->drawerSessions->expectedCash($conn, $blockingSessionId);
        $tolerance = $this->floatExpectation->toleranceForBranch($conn, $scope['tenant'], $scope['branch']);

        $_SESSION['pos_shift_takeover_close_count'] = [
            'user_id' => $userId,
            'drawer_session_id' => $blockingSessionId,
            'expected' => $expected,
            'tolerance' => $tolerance,
            'attempt_number' => 0,
            'attempt_ids' => [],
            'finalized' => false,
            'started_at' => time(),
        ];

        return [
            'phase' => 'takeover_close',
            'attempt_number' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'drawer_session_id' => $blockingSessionId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitTakeoverCloseCount(mysqli $conn, int $userId, string $countedAmount, array $context = []): array
    {
        if (!$this->handoverEnabled($conn)) {
            throw new RuntimeException('HANDOVER_NOT_ENABLED');
        }

        $state = $_SESSION['pos_shift_takeover_close_count'] ?? null;
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('TAKEOVER_CLOSE_COUNT_NOT_STARTED');
        }

        $sessionId = (int) ($state['drawer_session_id'] ?? 0);
        $drawerSession = $this->drawerSessions->sessionById($conn, $sessionId);
        if (($drawerSession['status'] ?? '') !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }
        if ((int) ($drawerSession['user_id'] ?? 0) === $userId) {
            throw new RuntimeException('CANNOT_TAKEOVER_OWN_SESSION');
        }

        $currentExpected = (float) $this->drawerSessions->expectedCash($conn, $sessionId);
        $snapshotExpected = (float) ($state['expected'] ?? 0);
        $tolerance = (float) ($state['tolerance'] ?? 0.010);

        if (!$this->floatExpectation->amountsMatch($currentExpected, $snapshotExpected, $tolerance)) {
            unset($_SESSION['pos_shift_takeover_close_count']);
            throw new RuntimeException('CLOSE_EXPECTED_DRIFTED');
        }

        $attemptNumber = (int) ($state['attempt_number'] ?? 0) + 1;
        if ($attemptNumber > self::MAX_ATTEMPTS) {
            throw new RuntimeException('TAKEOVER_CLOSE_COUNT_MAX_ATTEMPTS');
        }

        $counted = round((float) $countedAmount, 3);
        if ($counted < 0) {
            throw new RuntimeException('COUNTED_AMOUNT_INVALID');
        }

        $variance = round($counted - $snapshotExpected, 3);
        $matched = $this->floatExpectation->amountsMatch($counted, $snapshotExpected, $tolerance);

        $attemptId = $this->recordCountAttempt($conn, [
            'drawer_session_id' => $sessionId,
            'count_phase' => 'close',
            'attempt_number' => $attemptNumber,
            'counted_amount' => $counted,
            'expected_amount' => $snapshotExpected,
            'variance' => $variance,
            'matched' => $matched,
            'expected_snapshot_json' => [
                'expected_cash' => $snapshotExpected,
                'takeover' => true,
            ],
            'tenant' => (int) ($drawerSession['tenant'] ?? 0),
            'branch' => (int) ($drawerSession['branch'] ?? 0),
            'created_by' => $userId,
        ]);

        $state['attempt_number'] = $attemptNumber;
        $state['attempt_ids'][] = $attemptId;
        $_SESSION['pos_shift_takeover_close_count'] = $state;

        if ($matched || $attemptNumber >= self::MAX_ATTEMPTS) {
            $direction = $variance > 0.0001 ? 'over' : ($variance < -0.0001 ? 'under' : 'balanced');
            $state['finalized'] = true;
            $state['counted_cash'] = $counted;
            $state['matched'] = $matched;
            $state['variance'] = $variance;
            $state['variance_direction'] = $direction;
            $_SESSION['pos_shift_takeover_close_count'] = $state;

            $message = $matched
                ? 'العد متطابق — يمكن متابعة إغلاق وردية الموظف'
                : ($direction === 'over'
                    ? 'زيادة في الدرج: ' . number_format(abs($variance), 2) . ' — سيتم التسجيل والمتابعة'
                    : 'عجز في الدرج: ' . number_format(abs($variance), 2) . ' — سيتم التسجيل والمتابعة');

            return [
                'status' => $matched ? 'ready_to_takeover' : 'takeover_with_variance',
                'phase' => 'takeover_close',
                'attempt_number' => $attemptNumber,
                'max_attempts' => self::MAX_ATTEMPTS,
                'matched' => $matched,
                'counted_cash' => $counted,
                'expected_cash' => $snapshotExpected,
                'variance' => $variance,
                'variance_direction' => $direction,
                'drawer_session_id' => $sessionId,
                'message' => $message,
            ];
        }

        return [
            'status' => 'recount',
            'phase' => 'takeover_close',
            'attempt_number' => $attemptNumber,
            'max_attempts' => self::MAX_ATTEMPTS,
            'message' => 'الرجاء إعادة العد بعناية',
        ];
    }

    /**
     * Final counted cash from a completed takeover close-count phase (if any).
     *
     * @return array{counted_cash:float,matched:bool,variance:float,attempt_ids:array}|null
     */
    public function peekTakeoverCloseCount(int $userId, int $sessionId): ?array
    {
        $state = $_SESSION['pos_shift_takeover_close_count'] ?? null;
        if (!is_array($state)
            || empty($state['finalized'])
            || (int) ($state['user_id'] ?? 0) !== $userId
            || (int) ($state['drawer_session_id'] ?? 0) !== $sessionId) {
            return null;
        }

        return [
            'counted_cash' => (float) ($state['counted_cash'] ?? 0),
            'matched' => !empty($state['matched']),
            'variance' => (float) ($state['variance'] ?? 0),
            'attempt_ids' => $state['attempt_ids'] ?? [],
        ];
    }

    public function clearTakeoverCloseCount(): void
    {
        unset($_SESSION['pos_shift_takeover_close_count']);
    }

    /**
     * @deprecated use peekTakeoverCloseCount + clearTakeoverCloseCount
     */
    public function consumeTakeoverCloseCount(int $userId, int $sessionId): ?array
    {
        $result = $this->peekTakeoverCloseCount($userId, $sessionId);
        if ($result !== null) {
            $this->clearTakeoverCloseCount();
        }

        return $result;
    }

    /**
     * Open the incoming manager shift using the cash already counted during
     * takeover close — avoids a second blind count of the same drawer.
     *
     * Requires $_SESSION['pos_pending_takeover'] so preceding_session lineage is linked.
     *
     * @return array<string, mixed>
     */
    public function openFromTakeoverCountedCash(
        mysqli $conn,
        int $userId,
        float $countedCash,
        array $context = []
    ): array {
        $counted = round($countedCash, 3);
        if ($counted < 0) {
            throw new RuntimeException('COUNTED_AMOUNT_INVALID');
        }

        $scope = $this->shiftSessions->resolveScope($context);
        $scope['register_id'] = $this->resolvePairedRegisterId($conn, $scope, $context);
        $this->assertNoBlockingOpenSession($conn, $userId, $scope);

        if (!$this->handoverEnabled($conn)) {
            require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';
            require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';

            $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));
            try {
                $openRequest = [
                    'user_id' => $userId,
                    'opened_by' => $userId,
                    'tenant' => $scope['tenant'],
                    'branch' => $scope['branch'],
                    'register_id' => (int) ($scope['register_id'] ?? 0) ?: null,
                    'opening_cash' => number_format($counted, 3, '.', ''),
                    'in_transaction' => true,
                ];
                $pendingTakeover = $_SESSION['pos_pending_takeover'] ?? null;
                if (is_array($pendingTakeover)
                    && (int) ($pendingTakeover['incoming_user_id'] ?? 0) === $userId
                    && (int) ($pendingTakeover['preceding_session_id'] ?? 0) > 0
                ) {
                    $openRequest['preceding_session_id'] = (int) $pendingTakeover['preceding_session_id'];
                    if (!empty($pendingTakeover['authorized_by'])) {
                        $openRequest['takeover_authorized_by'] = (int) $pendingTakeover['authorized_by'];
                    }
                }
                $session = $this->drawerSessions->openSession($conn, $openRequest);
                $_SESSION['pos_drawer_session_id'] = (int) $session['id'];
                posmain_begin_pos_shift_session($userId);
                unset(
                    $_SESSION['pos_shift_open_count'],
                    $_SESSION['pos_unlocked_pending_open'],
                    $_SESSION['pos_pending_takeover']
                );
                posmain_tx_commit_if_owned($conn, $ownsTransaction);

                return [
                    'status' => 'opened',
                    'phase' => 'open',
                    'matched' => true,
                    'variance' => 0.0,
                    'variance_direction' => 'balanced',
                    'counted_cash' => $counted,
                    'expected_cash' => $counted,
                    'attempt_number' => 1,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'drawer_session_id' => (int) ($session['id'] ?? 0),
                    'message' => 'تم فتح الشيفت بنجاح',
                    'variance_status' => 'none',
                ];
            } catch (Throwable $exception) {
                posmain_tx_rollback_if_owned($conn, $ownsTransaction);
                throw $exception;
            }
        }

        $breakdown = $this->floatExpectation->expectedOpeningFloat($conn, $scope['tenant'], $scope['branch']);
        if (!empty($breakdown['baseline_required'])) {
            throw new RuntimeException('OPENING_BASELINE_REQUIRED');
        }

        $expected = (float) ($breakdown['expected'] ?? 0);
        $tolerance = $this->floatExpectation->toleranceForBranch($conn, $scope['tenant'], $scope['branch']);
        $variance = round($counted - $expected, 3);
        $matched = $this->floatExpectation->amountsMatch($counted, $expected, $tolerance);

        $attemptId = $this->recordCountAttempt($conn, [
            'drawer_session_id' => null,
            'count_phase' => 'open',
            'attempt_number' => 1,
            'counted_amount' => $counted,
            'expected_amount' => $expected,
            'variance' => $variance,
            'matched' => $matched,
            'expected_snapshot_json' => [
                'expected_cash' => $expected,
                'takeover_auto_open' => true,
            ],
            'tenant' => (int) $scope['tenant'],
            'branch' => (int) $scope['branch'],
            'created_by' => $userId,
        ]);

        $state = [
            'user_id' => $userId,
            'tenant' => $scope['tenant'],
            'branch' => $scope['branch'],
            'register_id' => $scope['register_id'],
            'expected' => $expected,
            'tolerance' => $tolerance,
            'attempt_number' => 1,
            'attempt_ids' => [$attemptId],
        ];

        return $this->finalizeOpen(
            $conn,
            $userId,
            $counted,
            $expected,
            $variance,
            $matched,
            $scope,
            $state,
            $context
        );
    }

    public function validateCloseToken(string $token, int $sessionId, int $userId, float $countedCash, bool $matched, ?mysqli $conn = null): bool
    {
        $payload = $this->extractTokenPayload($token);
        $tokenHash = (string) ($payload['hash'] ?? '');
        if ($tokenHash === '') {
            return false;
        }

        if ((int) ($payload['sid'] ?? 0) !== $sessionId || (int) ($payload['uid'] ?? 0) !== $userId) {
            return false;
        }

        $countedFromToken = round((float) ($payload['cnt'] ?? 0), 3);
        $matchedFromToken = !empty($payload['m']);
        if (abs($countedFromToken - round($countedCash, 3)) > 0.0001 || $matchedFromToken !== $matched) {
            return false;
        }

        $state = $_SESSION['pos_shift_close_count'] ?? null;
        if (is_array($state)
            && (int) ($state['user_id'] ?? 0) === $userId
            && (int) ($state['drawer_session_id'] ?? 0) === $sessionId
            && ($state['token'] ?? '') === $token) {
            if ((time() - (int) ($state['started_at'] ?? 0)) > self::TOKEN_TTL_SECONDS) {
                return false;
            }

            $expected = (float) ($state['expected'] ?? $payload['exp'] ?? 0);
            $expectedHash = $this->buildCloseTokenHash($sessionId, $expected, $userId, $countedCash, $matched);

            return hash_equals($expectedHash, $tokenHash);
        }

        // Durable fallback: PHP session lost; trust hash stored on open drawer session.
        if ($conn instanceof mysqli && $this->columnExists($conn, 'drawer_sessions', 'close_token_hash')) {
            $stmt = $conn->prepare("
                SELECT close_token_hash
                FROM drawer_sessions
                WHERE id = ? AND status = 'open'
                LIMIT 1
            ");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stored = (string) ($row['close_token_hash'] ?? '');
            if ($stored !== '' && hash_equals($stored, $tokenHash)) {
                $issuedAt = (int) ($payload['ts'] ?? 0);
                if ($issuedAt > 0 && (time() - $issuedAt) > self::TOKEN_TTL_SECONDS) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    public function closeWithValidatedCount(mysqli $conn, int $userId, array $payload, array $context = []): array
    {
        $token = trim((string) ($payload['close_token'] ?? ''));
        $countedCash = round((float) ($payload['counted_cash'] ?? $payload['fund_after'] ?? 0), 3);
        $matched = !empty($payload['matched']);
        $sessionId = (int) ($payload['drawer_session_id'] ?? ($_SESSION['pos_shift_close_count']['drawer_session_id'] ?? 0));

        if (!$this->handoverEnabled($conn)) {
            return $this->shiftClose->closeShift($conn, $userId, $payload, $context);
        }

        if ($token === '' || !$this->validateCloseToken($token, $sessionId, $userId, $countedCash, $matched, $conn)) {
            throw new RuntimeException('CLOSE_TOKEN_INVALID');
        }

        $state = $_SESSION['pos_shift_close_count'] ?? [];
        $drawerSession = $this->drawerSessions->sessionById($conn, $sessionId);
        $openingUnresolved = ($drawerSession['variance_status'] ?? '') === 'unresolved'
            && in_array((string) ($drawerSession['variance_type'] ?? ''), ['opening', 'both'], true);

        $tokenPayload = $this->extractTokenPayload($token);
        if (isset($state['expected'])) {
            $closeExpectedSnapshot = (float) $state['expected'];
        } elseif (isset($tokenPayload['exp'])) {
            $closeExpectedSnapshot = (float) $tokenPayload['exp'];
        } elseif (isset($drawerSession['close_expected_snapshot'])) {
            $closeExpectedSnapshot = (float) $drawerSession['close_expected_snapshot'];
        } else {
            $closeExpectedSnapshot = (float) $this->drawerSessions->expectedCash($conn, $sessionId);
        }

        return $this->shiftClose->closeShift($conn, $userId, array_merge($payload, [
            'fund_after' => $countedCash,
            'cash' => $countedCash,
            'counted_cash' => $countedCash,
            'variance_status' => $matched && !$openingUnresolved ? 'none' : 'unresolved',
            'variance_type' => $matched ? ($openingUnresolved ? 'opening' : 'none') : ($openingUnresolved ? 'both' : 'closing'),
            'close_expected_snapshot' => $closeExpectedSnapshot,
            'opening_variance_unresolved' => $openingUnresolved,
        ]), $context);
    }

    /**
     * Manager-facing causes for accepting a drawer count variance.
     * Stored as codes so variance reports can aggregate by cause.
     *
     * @return array<string, string> code => Arabic label
     */
    public static function resolutionReasonCodes(): array
    {
        return [
            'recount_confirmed' => 'أُعيد العد والفرق مؤكد',
            'previous_shift' => 'خطأ من الوردية السابقة',
            'change_rounding' => 'فكة / فرق تقريب بسيط',
            'unrecorded_movement' => 'إيداع أو مصروف لم يُسجل وقته',
            'under_investigation' => 'الفرق قيد التحقيق',
            'other' => 'سبب آخر',
        ];
    }

    public function resolveSession(mysqli $conn, int $actingUserId, int $sessionId, array $payload, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        if (!auth_guard_has_permission('pos.shift.resolve_variance', $conn)) {
            throw new RuntimeException('PERMISSION_DENIED');
        }

        if (!$this->tableExists($conn, 'drawer_session_resolutions')) {
            throw new RuntimeException('RESOLUTION_NOT_ENABLED');
        }

        $session = $this->drawerSessions->sessionById($conn, $sessionId);
        $priorStatus = (string) ($session['variance_status'] ?? 'none');
        if ($priorStatus !== 'unresolved') {
            throw new RuntimeException('VARIANCE_NOT_UNRESOLVED');
        }

        $reasonCodes = self::resolutionReasonCodes();
        $reasonCode = trim((string) ($payload['resolution_reason_code'] ?? ''));
        if ($reasonCode === '' || !isset($reasonCodes[$reasonCode])) {
            throw new RuntimeException('RESOLUTION_REASON_CODE_REQUIRED');
        }

        $notes = trim((string) ($payload['resolution_notes'] ?? ''));
        if ($notes === '' && $reasonCode === 'other') {
            throw new RuntimeException('RESOLUTION_NOTES_REQUIRED');
        }

        $varianceType = (string) ($session['variance_type'] ?? 'closing');
        if (!in_array($varianceType, ['opening', 'closing', 'both', 'force_close'], true)) {
            $varianceType = 'closing';
        }

        // Always use server-side true over/short — never trust client-posted zero.
        if ($varianceType === 'opening') {
            $varianceAmount = (float) ($session['opening_variance'] ?? 0);
        } elseif ($varianceType === 'both') {
            $varianceAmount = round(
                (float) ($session['opening_variance'] ?? 0) + (float) ($session['difference'] ?? 0),
                3
            );
        } else {
            $varianceAmount = (float) ($session['difference'] ?? 0);
        }

        $snapshot = [
            'expected_cash' => $session['expected_cash'],
            'counted_cash' => $session['counted_cash'],
            'difference' => $session['difference'],
            'opening_variance' => $session['opening_variance'] ?? null,
            'expected_opening_cash' => $session['expected_opening_cash'] ?? null,
            'close_expected_snapshot' => $session['close_expected_snapshot'] ?? null,
        ];

        $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));

        try {
            $hasReasonCodeColumn = $this->columnExists($conn, 'drawer_session_resolutions', 'resolution_reason_code');
            // Legacy schema without the code column: keep the cause in the notes
            // text so no accepted variance is ever stored without a recorded reason.
            $storedNotes = $hasReasonCodeColumn
                ? $notes
                : trim($reasonCodes[$reasonCode] . ($notes !== '' ? ' — ' . $notes : ''));

            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
            $varianceFormatted = number_format($varianceAmount, 3, '.', '');

            if ($hasReasonCodeColumn) {
                $stmt = $conn->prepare('
                    INSERT INTO drawer_session_resolutions (
                        drawer_session_id, variance_type, variance_amount,
                        resolved_by, resolution_notes, resolution_reason_code, prior_status, snapshot_json
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->bind_param(
                    'isidssss',
                    $sessionId,
                    $varianceType,
                    $varianceFormatted,
                    $actingUserId,
                    $storedNotes,
                    $reasonCode,
                    $priorStatus,
                    $snapshotJson
                );
            } else {
                $stmt = $conn->prepare('
                    INSERT INTO drawer_session_resolutions (
                        drawer_session_id, variance_type, variance_amount,
                        resolved_by, resolution_notes, prior_status, snapshot_json
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->bind_param(
                    'isidsss',
                    $sessionId,
                    $varianceType,
                    $varianceFormatted,
                    $actingUserId,
                    $storedNotes,
                    $priorStatus,
                    $snapshotJson
                );
            }
            $stmt->execute();
            $resolutionId = (int) $conn->insert_id;
            $stmt->close();

            if ($this->columnExists($conn, 'drawer_sessions', 'variance_status')) {
                $update = $conn->prepare("UPDATE drawer_sessions SET variance_status = 'resolved' WHERE id = ? AND variance_status = 'unresolved'");
                $update->bind_param('i', $sessionId);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    $update->close();
                    throw new RuntimeException('VARIANCE_NOT_UNRESOLVED');
                }
                $update->close();
            }

            // Book the accepted over/short into the ledger so the fund account
            // matches the physically counted cash. Same transaction as the
            // resolution row: either both are recorded or neither is.
            // Skipped on installs without the accounting subsystem.
            $ledgerOtHeadId = null;
            if (round(abs($varianceAmount), 3) >= 0.001) {
                require_once __DIR__ . '/DrawerLedgerPostingService.php';
                $ledgerPosting = new DrawerLedgerPostingService();
                if ($ledgerPosting->canPost($conn)) {
                    $fundAccountId = $ledgerPosting->resolveFundAccountId($conn, $session);
                    $ledgerReason = $reasonCodes[$reasonCode] . ($notes !== '' ? ' — ' . $notes : '');
                    $ledgerOtHeadId = $ledgerPosting->postCashOverShort(
                        $conn,
                        $varianceAmount,
                        $ledgerReason,
                        $actingUserId,
                        $fundAccountId,
                        $sessionId
                    );
                    if ($ledgerOtHeadId > 0 && $this->columnExists($conn, 'drawer_session_resolutions', 'ledger_ot_head_id')) {
                        $link = $conn->prepare('UPDATE drawer_session_resolutions SET ledger_ot_head_id = ? WHERE id = ?');
                        $link->bind_param('ii', $ledgerOtHeadId, $resolutionId);
                        $link->execute();
                        $link->close();
                    }
                }
            }

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        return [
            'resolution_id' => $resolutionId,
            'drawer_session_id' => $sessionId,
            'variance_status' => 'resolved',
            'variance_amount' => $varianceFormatted,
            'variance_type' => $varianceType,
            'resolution_reason_code' => $reasonCode,
            'ledger_ot_head_id' => $ledgerOtHeadId,
        ];
    }

    /**
     * @param array{variance_type?:string,user_id?:int,override_operator_id?:int,has_override?:bool,offset?:int} $options
     * @return list<array<string, mixed>>
     */
    public function unresolvedSessions(
        mysqli $conn,
        int $tenant = 0,
        int $branch = 0,
        int $limit = 50,
        array $options = []
    ): array {
        if (!$this->columnExists($conn, 'drawer_sessions', 'variance_status')) {
            return [];
        }

        $sql = "
            SELECT ds.*, u.uname, u.display_name
            FROM drawer_sessions ds
            LEFT JOIN users u ON u.id = ds.user_id
            WHERE ds.variance_status = 'unresolved'
        ";
        [$sql, $params, $types] = $this->appendUnresolvedScope($conn, $sql, $tenant, $branch, $options);

        $limit = max(1, min(100, $limit));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $sql .= ' ORDER BY ds.closed_at DESC, ds.opened_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'user_name' => (string) (($row['display_name'] ?? '') ?: ($row['uname'] ?? '')),
                'opened_at' => (string) ($row['opened_at'] ?? ''),
                'closed_at' => $row['closed_at'],
                'status' => (string) ($row['status'] ?? ''),
                'variance_type' => (string) ($row['variance_type'] ?? ''),
                'expected_opening_cash' => $row['expected_opening_cash'] ?? null,
                'opening_variance' => $row['opening_variance'] ?? null,
                'opening_cash' => $row['opening_cash'] ?? null,
                'expected_cash' => $row['expected_cash'] ?? null,
                'close_expected_snapshot' => $row['close_expected_snapshot'] ?? null,
                'counted_cash' => $row['counted_cash'] ?? null,
                'difference' => $row['difference'] ?? null,
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @param array{variance_type?:string,user_id?:int,override_operator_id?:int,has_override?:bool} $options
     */
    public function countUnresolvedSessions(
        mysqli $conn,
        int $tenant = 0,
        int $branch = 0,
        array $options = []
    ): int {
        if (!$this->columnExists($conn, 'drawer_sessions', 'variance_status')) {
            return 0;
        }

        $sql = "
            SELECT COUNT(*) AS cnt
            FROM drawer_sessions ds
            WHERE ds.variance_status = 'unresolved'
        ";
        [$sql, $params, $types] = $this->appendUnresolvedScope($conn, $sql, $tenant, $branch, $options);

        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * @param array{variance_type?:string,user_id?:int,override_operator_id?:int,has_override?:bool} $options
     * @return array{0:string,1:list<mixed>,2:string}
     */
    private function appendUnresolvedScope(mysqli $conn, string $sql, int $tenant, int $branch, array $options): array
    {
        $params = [];
        $types = '';

        if ($tenant > 0) {
            $sql .= ' AND ds.tenant = ?';
            $params[] = $tenant;
            $types .= 'i';
        }
        if ($branch > 0) {
            $sql .= ' AND ds.branch = ?';
            $params[] = $branch;
            $types .= 'i';
        }

        $varianceType = strtolower(trim((string) ($options['variance_type'] ?? '')));
        $allowedTypes = ['opening', 'closing', 'both', 'force_close'];
        if (in_array($varianceType, $allowedTypes, true)) {
            $sql .= ' AND ds.variance_type = ?';
            $params[] = $varianceType;
            $types .= 's';
        }

        $userId = max(0, (int) ($options['user_id'] ?? 0));
        if ($userId > 0) {
            $sql .= ' AND ds.user_id = ?';
            $params[] = $userId;
            $types .= 'i';
        }

        $overrideOperatorId = max(0, (int) ($options['override_operator_id'] ?? 0));
        $needsOverrideJoin = !empty($options['has_override']) || $overrideOperatorId > 0;
        if ($needsOverrideJoin) {
            if (!$this->tableExists($conn, 'drawer_override_periods')) {
                // A requested override filter cannot match on a pre-feature
                // database. Use a false predicate rather than returning rows
                // that contradict the selected filter.
                $sql .= ' AND 1 = 0';
            } else {
                $sql .= ' AND EXISTS (
                    SELECT 1
                    FROM drawer_override_periods dop
                    WHERE dop.drawer_session_id = ds.id';
                if ($overrideOperatorId > 0) {
                    $sql .= ' AND dop.operator_user_id = ?';
                    $params[] = $overrideOperatorId;
                    $types .= 'i';
                }
                $sql .= ')';
            }
        }

        return [$sql, $params, $types];
    }

    /** @return list<array<string, mixed>> */
    public function countAttemptsForSession(mysqli $conn, int $sessionId): array
    {
        if (!$this->tableExists($conn, 'drawer_count_attempts')) {
            return [];
        }

        $stmt = $conn->prepare('
            SELECT *
            FROM drawer_count_attempts
            WHERE drawer_session_id = ?
            ORDER BY count_phase, attempt_number, id
        ');
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function resolutionsForSession(mysqli $conn, int $sessionId): array
    {
        if (!$this->tableExists($conn, 'drawer_session_resolutions')) {
            return [];
        }

        $stmt = $conn->prepare('
            SELECT r.*, u.uname, u.display_name
            FROM drawer_session_resolutions r
            LEFT JOIN users u ON u.id = r.resolved_by
            WHERE r.drawer_session_id = ?
            ORDER BY r.resolved_at DESC, r.id DESC
        ');
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function finalizeOpen(
        mysqli $conn,
        int $userId,
        float $counted,
        float $expected,
        float $variance,
        bool $matched,
        array $scope,
        array $state,
        array $context = []
    ): array {
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';

        $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));

        try {
            $openRequest = [
                'user_id' => $userId,
                'opened_by' => $userId,
                'tenant' => $scope['tenant'],
                'branch' => $scope['branch'],
                'register_id' => (int) ($state['register_id'] ?? $scope['register_id'] ?? 0) ?: null,
                'opening_cash' => number_format($counted, 3, '.', ''),
                'in_transaction' => true,
            ];

            $pendingTakeover = $_SESSION['pos_pending_takeover'] ?? null;
            if (is_array($pendingTakeover)
                && (int) ($pendingTakeover['incoming_user_id'] ?? 0) === $userId
                && (int) ($pendingTakeover['preceding_session_id'] ?? 0) > 0
            ) {
                $openRequest['preceding_session_id'] = (int) $pendingTakeover['preceding_session_id'];
                if (!empty($pendingTakeover['authorized_by'])) {
                    $openRequest['takeover_authorized_by'] = (int) $pendingTakeover['authorized_by'];
                }
            }

            $session = $this->drawerSessions->openSession($conn, $openRequest);

            $_SESSION['pos_drawer_session_id'] = (int) $session['id'];
            posmain_begin_pos_shift_session($userId);

            $sessionId = (int) ($session['id'] ?? 0);
            if ($sessionId > 0) {
                $this->updateOpeningVariance($conn, $sessionId, $expected, $variance, $matched);
                $this->linkOpenAttempts($conn, $sessionId, $state['attempt_ids'] ?? []);
            }

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        unset(
            $_SESSION['pos_shift_open_count'],
            $_SESSION['pos_unlocked_pending_open'],
            $_SESSION['pos_pending_takeover']
        );

        return [
            'status' => $matched ? 'opened' : 'opened_with_variance',
            'phase' => 'open',
            'matched' => $matched,
            'variance' => $variance,
            'variance_direction' => $variance > 0 ? 'over' : ($variance < 0 ? 'under' : 'balanced'),
            'counted_cash' => $counted,
            'expected_cash' => $expected,
            'attempt_number' => (int) ($state['attempt_number'] ?? self::MAX_ATTEMPTS),
            'max_attempts' => self::MAX_ATTEMPTS,
            'drawer_session_id' => (int) ($session['id'] ?? 0),
            'message' => $matched
                ? 'تم فتح الشيفت بنجاح'
                : ($variance > 0
                    ? 'تم فتح الشيفت — زيادة: ' . number_format(abs($variance), 2)
                    : 'تم فتح الشيفت — عجز: ' . number_format(abs($variance), 2)),
            'variance_status' => $matched ? 'none' : 'unresolved',
        ];
    }

    private function updateOpeningVariance(mysqli $conn, int $sessionId, float $expected, float $variance, bool $matched): void
    {
        if (!$this->columnExists($conn, 'drawer_sessions', 'expected_opening_cash')) {
            return;
        }

        $varianceStatus = $matched ? 'none' : 'unresolved';
        $varianceType = $matched ? 'none' : 'opening';

        $stmt = $conn->prepare('
            UPDATE drawer_sessions
            SET expected_opening_cash = ?,
                opening_variance = ?,
                variance_status = ?,
                variance_type = ?
            WHERE id = ?
        ');
        $expectedFormatted = number_format($expected, 3, '.', '');
        $varianceFormatted = number_format($variance, 3, '.', '');
        $stmt->bind_param('ssssi', $expectedFormatted, $varianceFormatted, $varianceStatus, $varianceType, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    private function linkOpenAttempts(mysqli $conn, int $sessionId, array $attemptIds): void
    {
        foreach ($attemptIds as $attemptId) {
            $attemptId = (int) $attemptId;
            if ($attemptId < 1) {
                continue;
            }
            $stmt = $conn->prepare('
                UPDATE drawer_count_attempts
                SET drawer_session_id = ?
                WHERE id = ? AND count_phase = \'open\' AND drawer_session_id IS NULL
            ');
            $stmt->bind_param('ii', $sessionId, $attemptId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function recordCountAttempt(mysqli $conn, array $data): int
    {
        if (!$this->tableExists($conn, 'drawer_count_attempts')) {
            return 0;
        }

        $snapshotJson = json_encode($data['expected_snapshot_json'] ?? [], JSON_UNESCAPED_UNICODE);
        $sessionId = $data['drawer_session_id'] ?? null;
        $matched = !empty($data['matched']) ? 1 : 0;
        $counted = number_format((float) $data['counted_amount'], 3, '.', '');
        $expected = number_format((float) $data['expected_amount'], 3, '.', '');
        $variance = number_format((float) $data['variance'], 3, '.', '');
        $phase = (string) $data['count_phase'];
        $attempt = (int) $data['attempt_number'];
        $tenant = (int) $data['tenant'];
        $branch = (int) $data['branch'];
        $createdBy = (int) $data['created_by'];

        if ($sessionId === null) {
            $stmt = $conn->prepare('
                INSERT INTO drawer_count_attempts (
                    drawer_session_id, count_phase, attempt_number,
                    counted_amount, expected_amount, variance, matched,
                    expected_snapshot_json, tenant, branch, created_by
                ) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param('sisssssiii', $phase, $attempt, $counted, $expected, $variance, $matched, $snapshotJson, $tenant, $branch, $createdBy);
        } else {
            $sessionIdInt = (int) $sessionId;
            $stmt = $conn->prepare('
                INSERT INTO drawer_count_attempts (
                    drawer_session_id, count_phase, attempt_number,
                    counted_amount, expected_amount, variance, matched,
                    expected_snapshot_json, tenant, branch, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param('isisssssiii', $sessionIdInt, $phase, $attempt, $counted, $expected, $variance, $matched, $snapshotJson, $tenant, $branch, $createdBy);
        }
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function issueCloseToken(
        int $sessionId,
        float $expected,
        int $userId,
        ?float $counted = null,
        ?bool $matched = null,
        ?mysqli $conn = null
    ): string {
        $countedValue = $counted ?? 0.0;
        $matchedValue = $matched ?? false;
        $hash = $this->buildCloseTokenHash($sessionId, $expected, $userId, $countedValue, $matchedValue);
        // Client token must not embed expected cash (blind-count integrity).
        // Expected stays in PHP session / close_token_hash HMAC only.
        $payload = base64_encode(json_encode([
            'sid' => $sessionId,
            'uid' => $userId,
            'cnt' => $countedValue,
            'm' => $matchedValue,
            'hash' => $hash,
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE));

        if (!isset($_SESSION['pos_shift_close_count']) || !is_array($_SESSION['pos_shift_close_count'])) {
            $_SESSION['pos_shift_close_count'] = [];
        }
        $_SESSION['pos_shift_close_count']['token'] = $payload;
        $_SESSION['pos_shift_close_count']['expected'] = $expected;

        if ($conn instanceof mysqli
            && $counted !== null
            && $this->columnExists($conn, 'drawer_sessions', 'close_token_hash')) {
            $stmt = $conn->prepare('UPDATE drawer_sessions SET close_token_hash = ? WHERE id = ? AND status = \'open\'');
            $stmt->bind_param('si', $hash, $sessionId);
            $stmt->execute();
            $stmt->close();
        }

        return $payload;
    }

    private function canSeeExpectedCash(mysqli $conn): bool
    {
        try {
            if (!function_exists('auth_guard_has_permission')) {
                require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';
            }

            return auth_guard_has_permission('reports.cash_flow', $conn);
        } catch (Throwable $ignored) {
            // Fail closed: never leak expected/variance when permission lookup fails.
            return false;
        }
    }

    private function buildCloseTokenHash(int $sessionId, float $expected, int $userId, float $counted, bool $matched): string
    {
        $secret = $this->tokenSecret();

        return hash_hmac(
            'sha256',
            implode('|', [
                $sessionId,
                number_format($expected, 3, '.', ''),
                $userId,
                number_format($counted, 3, '.', ''),
                $matched ? '1' : '0',
            ]),
            $secret
        );
    }

    /** @return array<string, mixed> */
    private function extractTokenPayload(string $token): array
    {
        $decoded = json_decode(base64_decode($token, true) ?: '', true);

        return is_array($decoded) ? $decoded : [];
    }

    private function tokenSecret(): string
    {
        if (defined('POSMAIN_APP_KEY') && POSMAIN_APP_KEY !== '') {
            return (string) POSMAIN_APP_KEY;
        }

        return hash('sha256', __DIR__ . '|shift-count-token');
    }

    private function assertNoBlockingOpenSession(mysqli $conn, int $userId, array $scope): void
    {
        $registerId = (int) ($scope['register_id'] ?? 0);
        $branchOpen = $registerId > 0
            ? $this->drawerSessions->findOpenSessionForRegister($conn, $registerId)
            : $this->drawerSessions->findOpenSessionForBranch(
                $conn,
                $scope['tenant'],
                $scope['branch']
            );
        if ($branchOpen && (int) ($branchOpen['user_id'] ?? 0) !== $userId) {
            throw new DrawerBranchBlockedException(
                $this->buildBlockingSessionPayload($conn, $branchOpen)
            );
        }
    }

    private function resolvePairedRegisterId(mysqli $conn, array $scope, array $context): int
    {
        $contextRegisterId = (int) (
            $context['register_id']
            ?? $_SESSION['pos_register_id']
            ?? 0
        );
        if (!$this->registers->tableExists($conn)) {
            return $contextRegisterId;
        }

        $register = $this->registers->requirePairedRegister(
            $conn,
            (int) $scope['tenant'],
            (int) $scope['branch']
        );
        $resolvedRegisterId = (int) ($register['id'] ?? 0);
        if ($resolvedRegisterId < 1) {
            throw new RuntimeException('REGISTER_UNPAIRED');
        }
        if ($contextRegisterId > 0 && $contextRegisterId !== $resolvedRegisterId) {
            throw new RuntimeException('REGISTER_CHANGED');
        }
        $_SESSION['pos_register_id'] = $resolvedRegisterId;

        return $resolvedRegisterId;
    }

    /**
     * @param array<string, mixed> $branchOpen
     * @return array{drawer_session_id:int,user_id:int,cashier_name:string,opened_at:string}
     */
    public function buildBlockingSessionPayload(mysqli $conn, array $branchOpen): array
    {
        $holderId = (int) ($branchOpen['user_id'] ?? 0);

        return [
            'drawer_session_id' => (int) ($branchOpen['id'] ?? 0),
            'user_id' => $holderId,
            'cashier_name' => $this->resolveCashierDisplayName($conn, $holderId),
            'opened_at' => (string) ($branchOpen['opened_at'] ?? ''),
        ];
    }

    private function resolveCashierDisplayName(mysqli $conn, int $userId): string
    {
        if ($userId < 1) {
            return 'غير معروف';
        }

        $stmt = $conn->prepare('SELECT uname, display_name FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return 'مستخدم #' . $userId;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return 'مستخدم #' . $userId;
        }

        $display = trim((string) ($row['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }
        $uname = trim((string) ($row['uname'] ?? ''));

        return $uname !== '' ? $uname : ('مستخدم #' . $userId);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
