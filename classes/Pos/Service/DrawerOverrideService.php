<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/ManagerApprovalService.php';
require_once dirname(__DIR__, 2) . '/Security/SecurityAuditLogger.php';
require_once dirname(__DIR__, 2) . '/Security/PermissionService.php';

/**
 * Temporary manager/owner override of an open cashier-owned drawer shift.
 *
 * Ownership of drawer_sessions.user_id stays with the original cashier.
 * The operator (manager/owner) becomes the acting POS user for attribution.
 */
class DrawerOverrideService
{
    public const END_REASON_EXPLICIT = 'explicit_end';
    public const END_REASON_LOCK = 'lock';
    public const END_REASON_LOGOUT = 'logout';
    public const END_REASON_SHIFT_CLOSE = 'shift_close';
    public const END_REASON_INACTIVITY = 'inactivity';
    public const END_REASON_FORCE_CLOSE = 'force_close';
    public const END_REASON_SUPERSEDED = 'superseded';

    /** @var int Inactivity TTL in seconds before an override auto-expires. */
    private const DEFAULT_INACTIVITY_SECONDS = 1800;

    public function __construct(
        private ?DrawerSessionService $drawers = null,
        private ?ManagerApprovalService $approvals = null,
        private ?SecurityAuditLogger $audit = null
    ) {
        $this->drawers = $this->drawers ?: new DrawerSessionService();
        $this->approvals = $this->approvals ?: new ManagerApprovalService();
        $this->audit = $this->audit ?: new SecurityAuditLogger();
    }

    public function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'drawer_override_periods'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function inactivitySeconds(): int
    {
        $configured = 0;
        if (function_exists('posmain_env')) {
            $configured = (int) posmain_env('POSMAIN_OVERRIDE_INACTIVITY_SECONDS', self::DEFAULT_INACTIVITY_SECONDS);
        }

        return max(60, $configured > 0 ? $configured : self::DEFAULT_INACTIVITY_SECONDS);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function startOverride(
        mysqli $conn,
        int $operatorUserId,
        int $drawerSessionId,
        string $reason,
        int $managerApprovalId,
        array $request = []
    ): array {
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';

        if (!$this->tableExists($conn)) {
            throw new RuntimeException('OVERRIDE_TABLE_MISSING');
        }
        if ($operatorUserId < 1) {
            throw new RuntimeException('AUTH_REQUIRED');
        }
        if ($drawerSessionId < 1) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('OVERRIDE_REASON_REQUIRED');
        }
        if ($managerApprovalId < 1) {
            throw new ManagerApprovalRequiredException('pos.shift.override');
        }

        $permissions = PermissionService::forConnection($conn);
        if (!$permissions->check($operatorUserId, 'pos.shift.override')) {
            $this->auditDenied($conn, $operatorUserId, $drawerSessionId, 'PERMISSION_DENIED', $request);
            throw new RuntimeException('OVERRIDE_PERMISSION_DENIED');
        }

        $approval = $this->approvals->validateApprovedPermissionOverride(
            $conn,
            $managerApprovalId,
            'pos.shift.override',
            $operatorUserId
        );
        $approvedBy = (int) ($approval['approved_by'] ?? 0);
        if ($approvedBy < 1) {
            throw new RuntimeException('MANAGER_APPROVAL_NOT_APPROVED');
        }

        $ownsTransaction = posmain_tx_begin_if_needed(
            $conn,
            posmain_tx_context_in_transaction($request)
        );

        try {
            $session = $this->drawers->sessionById($conn, $drawerSessionId);
            if (($session['status'] ?? '') !== 'open') {
                throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
            }
            $ownerId = (int) ($session['user_id'] ?? 0);
            if ($ownerId < 1) {
                throw new RuntimeException('DRAWER_OWNER_REQUIRED');
            }
            if ($ownerId === $operatorUserId) {
                throw new RuntimeException('CANNOT_OVERRIDE_OWN_SESSION');
            }

            $existing = $this->findActiveForDrawer($conn, $drawerSessionId, false);
            if ($existing) {
                if ((int) ($existing['operator_user_id'] ?? 0) === $operatorUserId) {
                    $this->approvals->consumeApproval($conn, $managerApprovalId, $operatorUserId);
                    $touched = $this->touch($conn, (int) $existing['id']);
                    $this->bindSession($touched, $session);
                    posmain_tx_commit_if_owned($conn, $ownsTransaction);

                    return $touched;
                }
                throw new RuntimeException('OVERRIDE_ALREADY_ACTIVE');
            }

            $this->approvals->consumeApproval($conn, $managerApprovalId, $operatorUserId);

            $tenant = (int) ($session['tenant'] ?? ($request['tenant'] ?? 0));
            $branch = (int) ($session['branch'] ?? ($request['branch'] ?? 0));
            $registerId = isset($session['register_id']) && $session['register_id'] !== null
                ? (int) $session['register_id']
                : null;
            $lock = (string) $drawerSessionId;
            $now = date('Y-m-d H:i:s');

            $stmt = $conn->prepare("
                INSERT INTO drawer_override_periods (
                    drawer_session_id, original_owner_user_id, operator_user_id, approved_by_user_id,
                    manager_approval_id, reason, started_at, last_activity_at, active_drawer_lock,
                    tenant, branch, register_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $registerIdParam = $registerId; // may be null
            $stmt->bind_param(
                'iiiiissssiii',
                $drawerSessionId,
                $ownerId,
                $operatorUserId,
                $approvedBy,
                $managerApprovalId,
                $reason,
                $now,
                $now,
                $lock,
                $tenant,
                $branch,
                $registerIdParam
            );
            try {
                $stmt->execute();
            } catch (Throwable $exception) {
                $stmt->close();
                if ($this->isDuplicateKey($exception)) {
                    throw new RuntimeException('OVERRIDE_ALREADY_ACTIVE', 0, $exception);
                }
                throw $exception;
            }
            $id = (int) $conn->insert_id;
            $stmt->close();
            if ($id < 1) {
                throw new RuntimeException('OVERRIDE_START_FAILED');
            }

            $period = $this->periodById($conn, $id);
            $this->bindSession($period, $session);

            try {
                $this->audit->record($conn, 'drawer_override_started', [
                    'user_id' => $operatorUserId,
                    'tenant' => $tenant,
                    'branch' => $branch,
                    'target_type' => 'drawer_override_period',
                    'target_id' => $id,
                    'metadata' => [
                        'override_period_id' => $id,
                        'drawer_session_id' => $drawerSessionId,
                        'original_owner_user_id' => $ownerId,
                        'operator_user_id' => $operatorUserId,
                        'approved_by_user_id' => $approvedBy,
                        'manager_approval_id' => $managerApprovalId,
                        'reason' => $reason,
                        'register_id' => $registerId,
                    ],
                ]);
            } catch (Throwable $ignored) {
            }

            posmain_tx_commit_if_owned($conn, $ownsTransaction);

            return $period;
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            if (
                $exception instanceof RuntimeException
                && in_array($exception->getMessage(), [
                    'OVERRIDE_PERMISSION_DENIED',
                    'OVERRIDE_ALREADY_ACTIVE',
                    'CANNOT_OVERRIDE_OWN_SESSION',
                ], true)
            ) {
                // already audited where needed
            } elseif ($exception instanceof RuntimeException) {
                $this->auditDenied($conn, $operatorUserId, $drawerSessionId, $exception->getMessage(), $request);
            }
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function endOverride(
        mysqli $conn,
        int $periodId,
        string $endReason,
        ?int $actorUserId = null,
        bool $clearSession = true
    ): array {
        if (!$this->tableExists($conn) || $periodId < 1) {
            throw new RuntimeException('OVERRIDE_NOT_FOUND');
        }

        $period = $this->periodById($conn, $periodId);
        if (!empty($period['ended_at'])) {
            if ($clearSession) {
                $this->clearSessionBinding($period);
            }

            return $period;
        }

        $endReason = trim($endReason) !== '' ? trim($endReason) : self::END_REASON_EXPLICIT;
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            UPDATE drawer_override_periods
               SET ended_at = ?,
                   end_reason = ?,
                   active_drawer_lock = NULL,
                   last_activity_at = ?
             WHERE id = ?
               AND ended_at IS NULL
        ");
        $stmt->bind_param('sssi', $now, $endReason, $now, $periodId);
        $stmt->execute();
        $stmt->close();

        $period = $this->periodById($conn, $periodId);
        $eventType = $endReason === self::END_REASON_INACTIVITY
            ? 'drawer_override_expired'
            : 'drawer_override_ended';

        try {
            $this->audit->record($conn, $eventType, [
                'user_id' => $actorUserId ?? (int) ($period['operator_user_id'] ?? 0),
                'tenant' => (int) ($period['tenant'] ?? 0),
                'branch' => (int) ($period['branch'] ?? 0),
                'target_type' => 'drawer_override_period',
                'target_id' => $periodId,
                'metadata' => [
                    'override_period_id' => $periodId,
                    'drawer_session_id' => (int) ($period['drawer_session_id'] ?? 0),
                    'original_owner_user_id' => (int) ($period['original_owner_user_id'] ?? 0),
                    'operator_user_id' => (int) ($period['operator_user_id'] ?? 0),
                    'end_reason' => $endReason,
                ],
            ]);
        } catch (Throwable $ignored) {
        }

        if ($clearSession) {
            $this->clearSessionBinding($period);
        }

        return $period;
    }

    public function endActiveForOperator(
        mysqli $conn,
        int $operatorUserId,
        string $endReason,
        bool $clearSession = true
    ): ?array {
        $active = $this->findActiveForOperator($conn, $operatorUserId, true);
        if (!$active) {
            if ($clearSession) {
                $this->clearSessionBinding();
            }

            return null;
        }

        return $this->endOverride($conn, (int) $active['id'], $endReason, $operatorUserId, $clearSession);
    }

    public function endActiveForDrawer(
        mysqli $conn,
        int $drawerSessionId,
        string $endReason,
        ?int $actorUserId = null
    ): ?array {
        $active = $this->findActiveForDrawer($conn, $drawerSessionId, false);
        if (!$active) {
            return null;
        }

        return $this->endOverride($conn, (int) $active['id'], $endReason, $actorUserId, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveForOperator(mysqli $conn, int $operatorUserId, bool $expireStale = true): ?array
    {
        if (!$this->tableExists($conn) || $operatorUserId < 1) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT *
              FROM drawer_override_periods
             WHERE operator_user_id = ?
               AND ended_at IS NULL
             ORDER BY started_at DESC, id DESC
             LIMIT 1
        ");
        $stmt->bind_param('i', $operatorUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $period = $this->formatPeriod($row);
        if ($expireStale) {
            $period = $this->expireIfStale($conn, $period) ?? $period;
            if (!empty($period['ended_at'])) {
                return null;
            }
        }

        return $period;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveForDrawer(mysqli $conn, int $drawerSessionId, bool $expireStale = true): ?array
    {
        if (!$this->tableExists($conn) || $drawerSessionId < 1) {
            return null;
        }

        $lock = (string) $drawerSessionId;
        $stmt = $conn->prepare("
            SELECT *
              FROM drawer_override_periods
             WHERE drawer_session_id = ?
               AND ended_at IS NULL
               AND active_drawer_lock = ?
             ORDER BY started_at DESC, id DESC
             LIMIT 1
        ");
        $stmt->bind_param('is', $drawerSessionId, $lock);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $period = $this->formatPeriod($row);
        if ($expireStale) {
            $period = $this->expireIfStale($conn, $period) ?? $period;
            if (!empty($period['ended_at'])) {
                return null;
            }
        }

        return $period;
    }

    /**
     * Validate that the operator may write against a non-owned open drawer.
     *
     * @return array{period: array<string, mixed>, drawer_session: array<string, mixed>}
     */
    public function requireActiveOverrideForWrite(
        mysqli $conn,
        int $operatorUserId,
        int $drawerSessionId
    ): array {
        $period = $this->findActiveForDrawer($conn, $drawerSessionId, true);
        if (!$period || (int) ($period['operator_user_id'] ?? 0) !== $operatorUserId) {
            throw new RuntimeException('OVERRIDE_REQUIRED');
        }

        $session = $this->drawers->sessionById($conn, $drawerSessionId);
        if (($session['status'] ?? '') !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }
        $this->touch($conn, (int) $period['id']);
        $this->bindSession($period, $session);

        return [
            'period' => $period,
            'drawer_session' => $session,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function touch(mysqli $conn, int $periodId): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            UPDATE drawer_override_periods
               SET last_activity_at = ?
             WHERE id = ?
               AND ended_at IS NULL
        ");
        $stmt->bind_param('si', $now, $periodId);
        $stmt->execute();
        $stmt->close();

        return $this->periodById($conn, $periodId);
    }

    /**
     * @return array<string, mixed>
     */
    public function periodById(mysqli $conn, int $periodId): array
    {
        $stmt = $conn->prepare('SELECT * FROM drawer_override_periods WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $periodId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('OVERRIDE_NOT_FOUND');
        }

        return $this->formatPeriod($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listPeriods(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn)) {
            return [];
        }

        $where = ['1=1'];
        $types = '';
        $params = [];

        if (!empty($filters['drawer_session_id'])) {
            $where[] = 'p.drawer_session_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['drawer_session_id'];
        }
        if (!empty($filters['operator_user_id'])) {
            $where[] = 'p.operator_user_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['operator_user_id'];
        }
        if (!empty($filters['original_owner_user_id'])) {
            $where[] = 'p.original_owner_user_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['original_owner_user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(p.started_at) >= ?';
            $types .= 's';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(p.started_at) <= ?';
            $types .= 's';
            $params[] = (string) $filters['date_to'];
        }
        if (isset($filters['tenant'])) {
            $where[] = 'p.tenant = ?';
            $types .= 'i';
            $params[] = (int) $filters['tenant'];
        }
        if (isset($filters['branch'])) {
            $where[] = 'p.branch = ?';
            $types .= 'i';
            $params[] = (int) $filters['branch'];
        }

        $sql = '
            SELECT p.*
              FROM drawer_override_periods p
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.started_at DESC, p.id DESC
             LIMIT 500
        ';
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(fn ($row) => $this->formatPeriod($row), $rows ?: []);
    }

    /**
     * @param array<string, mixed> $period
     * @param array<string, mixed>|null $drawerSession
     */
    public function bindSession(array $period, ?array $drawerSession = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION['pos_override_period_id'] = (int) ($period['id'] ?? 0);
        $_SESSION['pos_override_drawer_session_id'] = (int) ($period['drawer_session_id'] ?? 0);
        $_SESSION['pos_override_owner_user_id'] = (int) ($period['original_owner_user_id'] ?? 0);
        $_SESSION['pos_override_operator_user_id'] = (int) ($period['operator_user_id'] ?? 0);
        $_SESSION['pos_override_started_at'] = (string) ($period['started_at'] ?? '');
        if ($drawerSession && !empty($drawerSession['id'])) {
            $_SESSION['pos_drawer_session_id'] = (int) $drawerSession['id'];
        } elseif (!empty($period['drawer_session_id'])) {
            $_SESSION['pos_drawer_session_id'] = (int) $period['drawer_session_id'];
        }
        unset($_SESSION['pos_unlocked_pending_open'], $_SESSION['posmain_shift_blocking']);
        $_SESSION['posmain_shift_entry_state'] = 'selling_ready';
        unset($_SESSION['posmain_shift_entry_message']);
    }

    public function clearSessionBinding(?array $period = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionPeriodId = (int) ($_SESSION['pos_override_period_id'] ?? 0);
        $periodId = (int) ($period['id'] ?? 0);
        if ($periodId > 0 && $sessionPeriodId > 0 && $sessionPeriodId !== $periodId) {
            return;
        }

        unset(
            $_SESSION['pos_override_period_id'],
            $_SESSION['pos_override_drawer_session_id'],
            $_SESSION['pos_override_owner_user_id'],
            $_SESSION['pos_override_operator_user_id'],
            $_SESSION['pos_override_started_at']
        );
    }

    public function sessionOverridePeriodId(): int
    {
        return (int) ($_SESSION['pos_override_period_id'] ?? 0);
    }

    /**
     * Central POS-write audit for active override periods.
     */
    public function auditPosWrite(mysqli $conn, string $route, bool $success, array $extra = []): void
    {
        $periodId = $this->sessionOverridePeriodId();
        if ($periodId < 1 || !$this->tableExists($conn)) {
            return;
        }

        try {
            $period = $this->periodById($conn, $periodId);
            if (!empty($period['ended_at'])) {
                return;
            }
            $this->touch($conn, $periodId);
            $this->audit->record($conn, 'drawer_override_operation', [
                'user_id' => (int) ($period['operator_user_id'] ?? 0),
                'tenant' => (int) ($period['tenant'] ?? 0),
                'branch' => (int) ($period['branch'] ?? 0),
                'target_type' => 'drawer_override_period',
                'target_id' => $periodId,
                'metadata' => array_merge([
                    'override_period_id' => $periodId,
                    'drawer_session_id' => (int) ($period['drawer_session_id'] ?? 0),
                    'original_owner_user_id' => (int) ($period['original_owner_user_id'] ?? 0),
                    'operator_user_id' => (int) ($period['operator_user_id'] ?? 0),
                    'route' => $route,
                    'success' => $success,
                    'http_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                ], $extra),
            ]);
        } catch (Throwable $ignored) {
        }
    }

    /**
     * @param array<string, mixed> $period
     * @return array<string, mixed>|null
     */
    private function expireIfStale(mysqli $conn, array $period): ?array
    {
        if (!empty($period['ended_at'])) {
            return $period;
        }

        $last = strtotime((string) ($period['last_activity_at'] ?? $period['started_at'] ?? ''));
        if ($last === false) {
            return $period;
        }
        if ((time() - $last) < $this->inactivitySeconds()) {
            return $period;
        }

        return $this->endOverride(
            $conn,
            (int) $period['id'],
            self::END_REASON_INACTIVITY,
            (int) ($period['operator_user_id'] ?? 0),
            true
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPeriod(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'drawer_session_id' => (int) $row['drawer_session_id'],
            'original_owner_user_id' => (int) $row['original_owner_user_id'],
            'operator_user_id' => (int) $row['operator_user_id'],
            'approved_by_user_id' => (int) $row['approved_by_user_id'],
            'manager_approval_id' => $row['manager_approval_id'] !== null ? (int) $row['manager_approval_id'] : null,
            'reason' => (string) $row['reason'],
            'started_at' => (string) $row['started_at'],
            'last_activity_at' => (string) $row['last_activity_at'],
            'ended_at' => $row['ended_at'] !== null ? (string) $row['ended_at'] : null,
            'end_reason' => $row['end_reason'] !== null ? (string) $row['end_reason'] : null,
            'active_drawer_lock' => $row['active_drawer_lock'] !== null ? (string) $row['active_drawer_lock'] : null,
            'tenant' => (int) ($row['tenant'] ?? 0),
            'branch' => (int) ($row['branch'] ?? 0),
            'register_id' => array_key_exists('register_id', $row) && $row['register_id'] !== null
                ? (int) $row['register_id']
                : null,
            'is_active' => $row['ended_at'] === null,
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function auditDenied(
        mysqli $conn,
        int $operatorUserId,
        int $drawerSessionId,
        string $code,
        array $request = []
    ): void {
        try {
            $this->audit->record($conn, 'drawer_override_denied', [
                'user_id' => $operatorUserId,
                'tenant' => (int) ($request['tenant'] ?? $_SESSION['pos_tenant'] ?? 0),
                'branch' => (int) ($request['branch'] ?? $_SESSION['pos_branch'] ?? 0),
                'target_type' => 'drawer_session',
                'target_id' => $drawerSessionId > 0 ? $drawerSessionId : null,
                'metadata' => [
                    'code' => $code,
                    'drawer_session_id' => $drawerSessionId,
                    'operator_user_id' => $operatorUserId,
                ],
            ]);
        } catch (Throwable $ignored) {
        }
    }

    private function isDuplicateKey(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate')
            || str_contains($message, '1062')
            || (method_exists($exception, 'getCode') && (int) $exception->getCode() === 1062);
    }
}
