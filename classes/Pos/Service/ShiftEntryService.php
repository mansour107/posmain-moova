<?php

require_once __DIR__ . '/BusinessDayService.php';
require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/ShiftSessionService.php';
require_once __DIR__ . '/ShiftCountService.php';
require_once __DIR__ . '/PosRegisterService.php';
require_once __DIR__ . '/DrawerBranchBlockedException.php';

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
        private ?PosRegisterService $registers = null
    ) {
        $this->shifts = $this->shifts ?: new ShiftSessionService();
        $this->counts = $this->counts ?: new ShiftCountService();
        $this->drawers = $this->drawers ?: new DrawerSessionService();
        $this->businessDays = $this->businessDays ?: new BusinessDayService();
        $this->registers = $this->registers ?: new PosRegisterService();
    }

    /**
     * @return array{
     *   state: string,
     *   redirect: string,
     *   drawer_session?: ?array,
     *   register?: ?array,
     *   blocking_session?: ?array,
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
            unset($_SESSION['pos_unlocked_pending_open']);

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

        // Prefer register-scoped conflict detection when registers are in use.
        $blocking = null;
        if ($currentRegisterId > 0 && method_exists($this->drawers, 'findOpenSessionForRegister')) {
            $blocking = $this->drawers->findOpenSessionForRegister($conn, $currentRegisterId);
        } elseif (method_exists($this->drawers, 'findOpenSessionForBranch')) {
            $blocking = $this->drawers->findOpenSessionForBranch($conn, $tenant, $branch);
        }
        if ($blocking && (int) ($blocking['user_id'] ?? 0) !== $userId) {
            return [
                'state' => self::STATE_BRANCH_BLOCKED,
                'redirect' => 'pos_barcode.php?shift=blocked',
                'blocking_session' => $blocking,
                'register' => $register,
                'message' => 'يوجد صندوق مفتوح لموظف آخر على هذا الجهاز',
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
}
