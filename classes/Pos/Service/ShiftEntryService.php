<?php

require_once __DIR__ . '/BusinessDayService.php';
require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/ShiftSessionService.php';
require_once __DIR__ . '/ShiftCountService.php';
require_once __DIR__ . '/PosRegisterService.php';
require_once __DIR__ . '/DrawerBranchBlockedException.php';
require_once __DIR__ . '/DrawerOverrideService.php';

/**
 * Deterministic cashier post-login / POS entry state machine.
 */
class ShiftEntryService
{
    public const STATE_SELLING_READY = 'selling_ready';
    public const STATE_OPEN_COUNT_PENDING = 'open_count_pending';
    public const STATE_BRANCH_BLOCKED = 'branch_blocked';
    public const STATE_REGISTER_TRANSFER_REQUIRED = 'register_transfer_required';
    public const STATE_STALE_SHIFT = 'stale_shift';
    public const STATE_BASELINE_REQUIRED = 'baseline_required';
    public const STATE_PERMISSION_DENIED = 'permission_denied';
    public const STATE_REGISTER_UNPAIRED = 'register_unpaired';

    public function __construct(
        private ?ShiftSessionService $shifts = null,
        private ?ShiftCountService $counts = null,
        private ?DrawerSessionService $drawers = null,
        private ?BusinessDayService $businessDays = null,
        private ?PosRegisterService $registers = null,
        private ?DrawerOverrideService $overrides = null
    ) {
        $this->shifts = $this->shifts ?: new ShiftSessionService();
        $this->counts = $this->counts ?: new ShiftCountService();
        $this->drawers = $this->drawers ?: new DrawerSessionService();
        $this->businessDays = $this->businessDays ?: new BusinessDayService();
        $this->registers = $this->registers ?: new PosRegisterService();
        $this->overrides = $this->overrides ?: new DrawerOverrideService();
    }

    /**
     * @return array{
     *   state: string,
     *   redirect: string,
     *   drawer_session?: ?array,
     *   register?: ?array,
     *   blocking_session?: ?array,
     *   override_period?: ?array,
     *   message?: string
     * }
     */
    public function resolveForUser(mysqli $conn, int $userId, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/auth_guard.php';

        $scope = $this->shifts->resolveScope($context);
        $tenant = (int) $scope['tenant'];
        $branch = (int) $scope['branch'];

        require_once dirname(__DIR__, 2) . '/Security/PermissionService.php';
        $permissions = PermissionService::forConnection($conn);
        if (!$permissions->check($userId, 'pos.open')) {
            return [
                'state' => self::STATE_PERMISSION_DENIED,
                'redirect' => 'no_access.php',
                'message' => 'ليس لديك صلاحية فتح نقطة البيع',
            ];
        }

        $register = null;
        try {
            if ($this->registers->tableExists($conn)) {
                $register = $this->registers->requirePairedRegister($conn, $tenant, $branch);
                $_SESSION['pos_register_id'] = (int) $register['id'];
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'REGISTER_UNPAIRED') {
                return [
                    'state' => self::STATE_REGISTER_UNPAIRED,
                    'redirect' => 'register_pair.php',
                    'message' => 'يجب ربط هذا الجهاز بصندوق أولاً',
                ];
            }
            throw $exception;
        }

        // Resume an active temporary override for this operator first.
        $activeOverride = $this->overrides->findActiveForOperator($conn, $userId, true);
        if ($activeOverride) {
            try {
                $overrideDrawer = $this->drawers->sessionById(
                    $conn,
                    (int) $activeOverride['drawer_session_id']
                );
                if (($overrideDrawer['status'] ?? '') !== 'open') {
                    throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
                }
                $this->overrides->bindSession($activeOverride, $overrideDrawer);
                posmain_begin_pos_shift_session($userId);
                unset($_SESSION['pos_unlocked_pending_open'], $_SESSION['posmain_shift_blocking']);

                return [
                    'state' => self::STATE_SELLING_READY,
                    'redirect' => 'pos_barcode.php',
                    'drawer_session' => $overrideDrawer,
                    'register' => $register,
                    'override_period' => $activeOverride,
                    'message' => 'تجاوز مؤقت نشط على وردية موظف آخر',
                ];
            } catch (Throwable $ignored) {
                $this->overrides->endOverride(
                    $conn,
                    (int) $activeOverride['id'],
                    DrawerOverrideService::END_REASON_FORCE_CLOSE,
                    $userId,
                    true
                );
            }
        }

        $ownOpen = $this->drawers->findOpenSession($conn, $userId, $tenant, $branch);
        $currentBusinessDay = $this->businessDays->currentBusinessDayForBranch($conn, $tenant, $branch);

        if ($ownOpen) {
            $sessionBusinessDay = trim((string) ($ownOpen['business_day'] ?? ''));
            if ($sessionBusinessDay === '') {
                $sessionBusinessDay = $this->businessDays->businessDayForTimestamp(
                    (string) $ownOpen['opened_at'],
                    $this->businessDays->cutoffHourForBranch($conn, $tenant, $branch)
                );
            }

            if ($sessionBusinessDay !== $currentBusinessDay) {
                $_SESSION['pos_drawer_session_id'] = (int) $ownOpen['id'];
                posmain_begin_pos_shift_session($userId);
                return [
                    'state' => self::STATE_STALE_SHIFT,
                    'redirect' => 'pos_barcode.php?shift=stale',
                    'drawer_session' => $ownOpen,
                    'register' => $register,
                    'message' => 'يوجد وردية مفتوحة من يوم عمل سابق ويجب إغلاقها أولاً',
                ];
            }

            $sessionRegisterId = (int) ($ownOpen['register_id'] ?? 0);
            $currentRegisterId = (int) ($register['id'] ?? 0);
            if ($currentRegisterId > 0 && $sessionRegisterId > 0 && $sessionRegisterId !== $currentRegisterId) {
                $_SESSION['pos_drawer_session_id'] = (int) $ownOpen['id'];
                return [
                    'state' => self::STATE_REGISTER_TRANSFER_REQUIRED,
                    'redirect' => 'pos_barcode.php?shift=transfer',
                    'drawer_session' => $ownOpen,
                    'register' => $register,
                    'message' => 'الوردية مفتوحة على صندوق آخر وتحتاج موافقة مدير للنقل',
                ];
            }

            // Resume own current-day shift on this register.
            $_SESSION['pos_drawer_session_id'] = (int) $ownOpen['id'];
            posmain_begin_pos_shift_session($userId);
            unset($_SESSION['pos_unlocked_pending_open'], $_SESSION['posmain_shift_blocking']);

            return [
                'state' => self::STATE_SELLING_READY,
                'redirect' => 'pos_barcode.php',
                'drawer_session' => $ownOpen,
                'register' => $register,
            ];
        }

        $canOpen = $permissions->check($userId, 'pos.shift.open');
        $openingCountEnabled = $this->openingCountEnabled($conn);
        $currentRegisterId = (int) ($register['id'] ?? 0);

        $blocking = $this->resolveBlockingSession($conn, $tenant, $branch, $currentRegisterId, $userId, $context);
        if ($blocking && (int) ($blocking['user_id'] ?? 0) !== $userId) {
            $enriched = $this->enrichBlockingSession(
                $conn,
                $blocking,
                $userId,
                $permissions,
                $currentBusinessDay,
                $register
            );
            $_SESSION['posmain_shift_blocking'] = $enriched;
            unset($_SESSION['pos_unlocked_pending_open'], $_SESSION['pos_shift_closed_for_session']);

            $ownerLabel = (string) ($enriched['owner_name'] ?? 'موظف آخر');
            $message = !empty($enriched['is_stale_business_day'])
                ? "يوجد درج مفتوح لـ {$ownerLabel} من يوم عمل سابق على هذا الصندوق"
                : "يوجد درج مفتوح لـ {$ownerLabel} على هذا الصندوق";

            return [
                'state' => self::STATE_BRANCH_BLOCKED,
                'redirect' => 'pos_barcode.php?shift=blocked',
                'blocking_session' => $enriched,
                'register' => $register,
                'message' => $message,
            ];
        }

        if (!$canOpen) {
            return [
                'state' => self::STATE_PERMISSION_DENIED,
                'redirect' => 'pos_barcode.php?shift=denied',
                'register' => $register,
                'message' => 'ليس لديك صلاحية فتح وردية',
            ];
        }

        if ($openingCountEnabled && $this->counts->handoverEnabled($conn)) {
            $_SESSION['pos_unlocked_pending_open'] = true;
            pos_set_acting_user($userId);
            // Mark authenticated for overlay, drawer opens after count.
            $_SESSION['pos_authenticated'] = true;
            $_SESSION['pos_user_id'] = $userId;
            unset($_SESSION['posmain_shift_blocking'], $_SESSION['pos_shift_closed_for_session']);

            return [
                'state' => self::STATE_OPEN_COUNT_PENDING,
                'redirect' => 'pos_barcode.php?shift=open_count',
                'register' => $register,
            ];
        }

        // Immediate open (handover/count disabled).
        $session = $this->shifts->openForCashier($conn, $userId, [
            'opened_by' => $userId,
            'opening_cash' => $context['opening_cash'] ?? '0',
            'tenant' => $tenant,
            'branch' => $branch,
            'register_id' => (int) ($register['id'] ?? 0) ?: null,
        ]);
        unset($_SESSION['posmain_shift_blocking']);

        return [
            'state' => self::STATE_SELLING_READY,
            'redirect' => 'pos_barcode.php',
            'drawer_session' => $session,
            'register' => $register,
        ];
    }

    public function openingCountEnabled(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'app_settings'");
        if ($result instanceof mysqli_result && $result->num_rows > 0) {
            $stmt = $conn->prepare(
                "SELECT setting_value FROM app_settings WHERE setting_key = 'shift_opening_count_enabled' LIMIT 1"
            );
            if ($stmt) {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    $value = strtolower(trim((string) ($row['setting_value'] ?? '1')));

                    return in_array($value, ['1', 'true', 'yes', 'on'], true);
                }
            }
        }

        return $this->counts->handoverEnabled($conn);
    }

    /**
     * Register-first conflict detection with safe legacy null-register fallback.
     *
     * @return array<string, mixed>|null
     */
    private function resolveBlockingSession(
        mysqli $conn,
        int $tenant,
        int $branch,
        int $currentRegisterId,
        int $userId,
        array $context = []
    ): ?array {
        if ($currentRegisterId > 0 && method_exists($this->drawers, 'findOpenSessionForRegister')) {
            $blocking = $this->drawers->findOpenSessionForRegister($conn, $currentRegisterId);
            if ($blocking) {
                return $blocking;
            }

            $legacy = $this->findLegacyNullRegisterOpenSession($conn, $tenant, $branch);
            if (!$legacy) {
                return null;
            }

            $activeRegisters = $this->registers->tableExists($conn)
                ? $this->registers->findActiveRegisters($conn, $tenant, $branch)
                : [];
            if (count($activeRegisters) === 1) {
                $onlyRegisterId = (int) ($activeRegisters[0]['id'] ?? 0);
                if ($onlyRegisterId === $currentRegisterId) {
                    $this->backfillLegacyRegisterId(
                        $conn,
                        (int) $legacy['id'],
                        $onlyRegisterId,
                        $tenant,
                        $branch,
                        $context
                    );

                    return $this->drawers->sessionById($conn, (int) $legacy['id']);
                }
            }

            // Multiple registers or mismatch: block at branch scope rather than allowing a second shift.
            return $legacy;
        }

        if (method_exists($this->drawers, 'findOpenSessionForBranch')) {
            return $this->drawers->findOpenSessionForBranch($conn, $tenant, $branch);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLegacyNullRegisterOpenSession(mysqli $conn, int $tenant, int $branch): ?array
    {
        $hasRegisterColumn = $this->columnExists($conn, 'drawer_sessions', 'register_id');
        if (!$hasRegisterColumn) {
            return $this->drawers->findOpenSessionForBranch($conn, $tenant, $branch);
        }

        $stmt = $conn->prepare("
            SELECT *
              FROM drawer_sessions
             WHERE tenant = ?
               AND branch = ?
               AND status = 'open'
               AND (register_id IS NULL OR register_id = 0)
             ORDER BY opened_at DESC, id DESC
             LIMIT 1
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->drawers->sessionById($conn, (int) $row['id']) : null;
    }

    private function backfillLegacyRegisterId(
        mysqli $conn,
        int $sessionId,
        int $registerId,
        int $tenant,
        int $branch,
        array $context = []
    ): void {
        if ($sessionId < 1 || $registerId < 1) {
            return;
        }

        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        $sets = ['register_id = ?'];
        $types = 'i';
        $params = [$registerId];

        if ($this->columnExists($conn, 'drawer_sessions', 'open_register_lock')) {
            $sets[] = 'open_register_lock = ?';
            $types .= 's';
            $params[] = $tenant . ':' . $branch . ':r' . $registerId;
        }

        $types .= 'i';
        $params[] = $sessionId;
        $sql = 'UPDATE drawer_sessions SET ' . implode(', ', $sets) . ' WHERE id = ? AND status = \'open\'';

        $ownsTransaction = posmain_tx_begin_if_needed($conn, $this->connectionInTransaction($conn));
        $stmt = null;
        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            try {
                $stmt->execute();
            } catch (Throwable $ignored) {
                // Unique lock race: caller still treats the legacy session as blocking.
                $stmt->close();
                $stmt = null;
                posmain_tx_rollback_if_owned($conn, $ownsTransaction);

                return;
            }

            $affected = $stmt->affected_rows;
            $stmt->close();
            $stmt = null;
            if ($affected !== 1) {
                posmain_tx_commit_if_owned($conn, $ownsTransaction);

                return;
            }

            $syncContext = ['in_transaction' => true];
            if (isset($context['sync_config']) && is_array($context['sync_config'])) {
                $syncContext['sync_config'] = $context['sync_config'];
            }
            $this->drawers->captureExternalSessionMutation($conn, $sessionId, $syncContext);
            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }
    }

    private function connectionInTransaction(mysqli $conn): bool
    {
        $result = $conn->query('SELECT @@session.in_transaction AS active_transaction');
        $row = $result->fetch_assoc() ?: [];

        return !empty($row['active_transaction']);
    }

    /**
     * @param array<string, mixed> $blocking
     * @param object $permissions
     * @param array<string, mixed>|null $register
     * @return array<string, mixed>
     */
    private function enrichBlockingSession(
        mysqli $conn,
        array $blocking,
        int $userId,
        $permissions,
        string $currentBusinessDay,
        ?array $register
    ): array {
        $ownerId = (int) ($blocking['user_id'] ?? 0);
        $ownerName = $this->userDisplayName($conn, $ownerId);
        $sessionBusinessDay = trim((string) ($blocking['business_day'] ?? ''));
        if ($sessionBusinessDay === '' && !empty($blocking['opened_at'])) {
            $tenant = (int) ($blocking['tenant'] ?? 0);
            $branch = (int) ($blocking['branch'] ?? 0);
            $sessionBusinessDay = $this->businessDays->businessDayForTimestamp(
                (string) $blocking['opened_at'],
                $this->businessDays->cutoffHourForBranch($conn, $tenant, $branch)
            );
        }
        $isStale = $sessionBusinessDay !== '' && $sessionBusinessDay !== $currentBusinessDay;
        $canOverride = $permissions->check($userId, 'pos.shift.override');
        $canForceClose = $permissions->check($userId, 'pos.shift.force_close');
        $existingOverride = $this->overrides->findActiveForDrawer($conn, (int) ($blocking['id'] ?? 0), true);

        $blocking['owner_user_id'] = $ownerId;
        $blocking['owner_name'] = $ownerName;
        $blocking['business_day'] = $sessionBusinessDay !== '' ? $sessionBusinessDay : null;
        $blocking['current_business_day'] = $currentBusinessDay;
        $blocking['is_stale_business_day'] = $isStale;
        $blocking['register_id'] = isset($blocking['register_id']) ? (int) $blocking['register_id'] : null;
        $blocking['register_name'] = $register['name'] ?? $register['code'] ?? null;
        $blocking['can_override'] = $canOverride;
        $blocking['can_force_close'] = $canForceClose;
        $blocking['active_override'] = $existingOverride;

        return $blocking;
    }

    private function userDisplayName(mysqli $conn, int $userId): string
    {
        if ($userId < 1) {
            return 'موظف آخر';
        }
        $stmt = $conn->prepare('SELECT uname, display_name FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return 'موظف آخر';
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return 'موظف آخر';
        }
        $display = trim((string) ($row['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }
        $uname = trim((string) ($row['uname'] ?? ''));

        return $uname !== '' ? $uname : 'موظف آخر';
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
        if ($table === '' || $column === '') {
            return false;
        }
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
