<?php

require_once __DIR__ . '/PinService.php';
require_once __DIR__ . '/LoginThrottleService.php';
require_once __DIR__ . '/SecurityAuditLogger.php';
require_once __DIR__ . '/LocalSecurityBootstrapService.php';
require_once __DIR__ . '/../PasswordService.php';
require_once __DIR__ . '/../../config/app_config.php';

class MainAuthenticationService
{
    private const MAIN_PIN_REGISTER_MAX_ATTEMPTS = 5;
    private const MAIN_PIN_REGISTER_WINDOW_SECONDS = 3600;
    private const MAIN_PIN_REGISTER_LOCK_SECONDS = 60;
    private const MAIN_PIN_REGISTER_MAX_LOCK_SECONDS = 1800;
    private const MAIN_PIN_IP_MAX_ATTEMPTS = 10;
    private const MAIN_PIN_IP_WINDOW_SECONDS = 3600;
    private const MAIN_PIN_IP_LOCK_SECONDS = 300;
    private const MAIN_PIN_IP_MAX_LOCK_SECONDS = 3600;
    private const MAIN_PIN_GLOBAL_MAX_ATTEMPTS = 100;
    private const MAIN_PIN_GLOBAL_WINDOW_SECONDS = 900;
    private const MAIN_PIN_GLOBAL_LOCK_SECONDS = 900;

    public function __construct(
        private ?PinService $pinService = null,
        private ?LoginThrottleService $throttle = null,
        private ?SecurityAuditLogger $audit = null
    ) {
        $this->pinService = $this->pinService ?: new PinService();
        $this->throttle = $this->throttle ?: new LoginThrottleService();
        $this->audit = $this->audit ?: new SecurityAuditLogger();
    }

    /**
     * Authenticate via PIN for local main login.
     *
     * @return array{user: array, bootstrap_pending: bool, redirect: string}
     */
    public function authenticateWithPin(mysqli $conn, string $pin, array $context = []): array
    {
        if (!posmain_is_pin_main_auth()) {
            throw new RuntimeException('MAIN_AUTH_MODE_PASSWORD');
        }

        $ip = (string) ($context['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $registerKey = trim((string) ($context['register_key'] ?? ''));
        $throttleBuckets = $this->mainPinThrottleBuckets($registerKey, $ip);

        if ($this->mainPinThrottleBlocked($conn, $throttleBuckets)) {
            $this->auditSafe($conn, 'main_pin_login_throttled', [
                'ip' => $ip,
                'metadata' => ['reason' => 'attempt_budget_exhausted'],
            ]);
            throw new RuntimeException('PIN_TERMINAL_FROZEN');
        }

        try {
            $this->pinService->validatePinFormat($pin, [
                'allow_bootstrap' => true,
            ]);
        } catch (Throwable $exception) {
            $this->consumeDummyVerification($pin);
            $this->recordMainPinFailure($conn, $throttleBuckets);
            throw new RuntimeException('PIN_INVALID', 0, $exception);
        }

        $bootstrap = new LocalSecurityBootstrapService();
        $bootstrapPending = $bootstrap->isPending($conn);

        // Bootstrap PIN path: only while pending, and only for the designated owner.
        if ($bootstrapPending && $pin === LocalSecurityBootstrapService::BOOTSTRAP_PIN) {
            $ownerId = (int) (($bootstrap->currentState($conn)['owner_user_id'] ?? 0) ?: $bootstrap->resolveOwnerUserId($conn));
            $user = $this->loadActiveUser($conn, $ownerId);
            if (!$user) {
                $this->consumeDummyVerification($pin);
                $this->recordMainPinFailure($conn, $throttleBuckets);
                throw new RuntimeException('PIN_INVALID');
            }
            if (!$this->pinService->verifyPin($pin, (string) ($user['pin_hash'] ?? ''))) {
                // Allow bootstrap verify even if blacklist would block set.
                if (!$this->verifyBootstrapPinHash($pin, (string) ($user['pin_hash'] ?? ''))) {
                    $this->recordMainPinFailure($conn, $throttleBuckets);
                    $this->pinService->recordUserFailure($conn, $ownerId);
                    throw new RuntimeException('PIN_INVALID');
                }
            }

            $this->recordMainPinSuccess($conn, $throttleBuckets);
            $this->pinService->clearUserFailures($conn, $ownerId);
            $this->establishSession($conn, $user, [
                'bootstrap_pending' => true,
                'auth_method' => 'main_pin_bootstrap',
            ]);

            $this->auditSafe($conn, 'main_pin_login_success', [
                'user_id' => $ownerId,
                'target_type' => 'user',
                'target_id' => $ownerId,
                'ip' => $ip,
                'metadata' => ['bootstrap' => true],
            ]);

            return [
                'user' => $user,
                'bootstrap_pending' => true,
                'redirect' => 'change_pin.php?bootstrap=1',
            ];
        }

        // Normal PIN path — never accept 0000 after bootstrap.
        try {
            $user = $this->pinService->findUserByPin($conn, $pin);
        } catch (InvalidArgumentException $exception) {
            $this->consumeDummyVerification($pin);
            $this->recordMainPinFailure($conn, $throttleBuckets);
            throw new RuntimeException('PIN_INVALID', 0, $exception);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'PIN_SECRET_MISSING') {
                throw $exception;
            }
            $this->consumeDummyVerification($pin);
            $this->recordMainPinFailure($conn, $throttleBuckets);
            throw new RuntimeException('PIN_INVALID', 0, $exception);
        }

        if (!$user) {
            $this->consumeDummyVerification($pin);
            $this->recordMainPinFailure($conn, $throttleBuckets);
            $this->auditSafe($conn, 'main_pin_login_failure', [
                'ip' => $ip,
                'metadata' => ['reason' => 'invalid_credentials'],
            ]);
            throw new RuntimeException('PIN_INVALID');
        }

        if ($this->pinService->isUserLocked($user)) {
            $this->consumeDummyVerification($pin);
            $this->recordMainPinFailure($conn, $throttleBuckets);
            $this->auditSafe($conn, 'main_pin_login_failure', [
                'ip' => $ip,
                'metadata' => ['reason' => 'invalid_credentials'],
            ]);
            throw new RuntimeException('PIN_INVALID');
        }

        if (!$this->pinService->verifyPin($pin, (string) ($user['pin_hash'] ?? ''))) {
            $this->pinService->recordUserFailure($conn, (int) $user['id']);
            $this->recordMainPinFailure($conn, $throttleBuckets);
            $this->auditSafe($conn, 'main_pin_login_failure', [
                'ip' => $ip,
                'target_type' => 'user',
                'target_id' => (int) $user['id'],
                'metadata' => ['reason' => 'invalid_credentials'],
            ]);
            throw new RuntimeException('PIN_INVALID');
        }

        $this->recordMainPinSuccess($conn, $throttleBuckets);
        $this->pinService->clearUserFailures($conn, (int) $user['id']);

        $mustChange = !empty($user['pin_must_change']) || $bootstrapPending;
        $this->establishSession($conn, $user, [
            'bootstrap_pending' => $bootstrapPending && (int) $user['id'] === (int) ($bootstrap->currentState($conn)['owner_user_id'] ?? 0),
            'auth_method' => 'main_pin',
            'must_change_pin' => $mustChange,
        ]);

        $this->auditSafe($conn, 'main_pin_login_success', [
            'user_id' => (int) $user['id'],
            'target_type' => 'user',
            'target_id' => (int) $user['id'],
            'ip' => $ip,
            'metadata' => ['must_change_pin' => $mustChange],
        ]);

        require_once __DIR__ . '/PostLoginRouteService.php';
        $router = new PostLoginRouteService();
        if ($mustChange) {
            $redirect = !empty($_SESSION['posmain_bootstrap_pending'])
                ? 'change_pin.php?bootstrap=1'
                : 'change_pin.php';
        } else {
            $route = $router->resolve($conn, (int) $user['id']);
            $redirect = (string) ($route['url'] ?? 'dashboard.php');
            if (($route['workspace'] ?? '') === PostLoginRouteService::WORKSPACE_POS) {
                require_once __DIR__ . '/../Pos/Service/ShiftEntryService.php';
                try {
                    $entry = (new ShiftEntryService())->resolveForUser($conn, (int) $user['id']);
                    $redirect = (string) ($entry['redirect'] ?? $redirect);
                } catch (Throwable $ignored) {
                    // Keep role-based POS URL if shift entry cannot resolve yet.
                }
            }
        }

        return [
            'user' => $user,
            'bootstrap_pending' => !empty($_SESSION['posmain_bootstrap_pending']),
            'redirect' => $redirect,
        ];
    }

    /**
     * Establish ERP session from a verified user row.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $options
     */
    public function establishSession(mysqli $conn, array $user, array $options = []): void
    {
        if (function_exists('posmain_session_regenerate')) {
            posmain_session_regenerate();
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $userId = (int) ($user['id'] ?? 0);
        $_SESSION['userid'] = $userId;
        $_SESSION['usrole'] = $user['userrole'] ?? ($user['usertype'] ?? 0);
        $_SESSION['usty'] = $user['usertype'] ?? ($user['userrole'] ?? 0);
        $_SESSION['login'] = (string) ($user['uname'] ?? '');
        $_SESSION['posmain_auth_version'] = (int) ($user['auth_version'] ?? 1);
        $_SESSION['posmain_auth_method'] = (string) ($options['auth_method'] ?? 'password');
        $_SESSION['posmain_bootstrap_pending'] = !empty($options['bootstrap_pending']);
        $_SESSION['posmain_pin_must_change'] = !empty($options['must_change_pin'])
            || !empty($user['pin_must_change'])
            || !empty($options['bootstrap_pending']);

        // Local PIN main auth: acting user is the same as the session user.
        if ($_SESSION['posmain_auth_method'] === 'main_pin'
            || $_SESSION['posmain_auth_method'] === 'main_pin_bootstrap'
        ) {
            if (function_exists('pos_set_acting_user')) {
                $display = trim((string) ($user['display_name'] ?? $user['uname'] ?? ''));
                pos_set_acting_user($userId, $display !== '' ? $display : null);
            }
            if (function_exists('posmain_begin_pos_shift_session')
                && empty($options['bootstrap_pending'])
                && empty($_SESSION['posmain_pin_must_change'])
            ) {
                // Defer shift open to ShiftEntryService; only mark acting identity.
                $_SESSION['pos_authenticated'] = false;
            }
        }

        if (function_exists('auth_guard_invalidate_capabilities_cache')) {
            auth_guard_invalidate_capabilities_cache();
        }

        $this->insertSessionTime($conn, $userId);
    }

    public function clearSessionIdentity(bool $preserveDrawer = true): void
    {
        $drawerId = $preserveDrawer ? ($_SESSION['pos_drawer_session_id'] ?? null) : null;

        if (function_exists('posmain_clear_pos_shift_session')) {
            posmain_clear_pos_shift_session(false);
        }

        foreach ([
            'login', 'userid', 'usrole', 'usty', 'userrole',
            'posmain_auth_version', 'posmain_auth_method',
            'posmain_bootstrap_pending', 'posmain_pin_must_change',
            'posmain_capabilities_cache', 'posmain_capabilities_version',
            'posmain_shop_id', 'posmain_shop_slug', 'posmain_shop_user_id',
            'pos_acting_user_id', 'pos_acting_user_name',
            'pos_authenticated', 'pos_user_id', 'pos_user_name',
            'pos_shift_session_token', 'pos_last_activity_at',
            'pos_unlocked_pending_open', 'pos_pending_takeover',
            'pos_cart_park_required', 'pos_previous_acting_user_id',
        ] as $key) {
            unset($_SESSION[$key]);
        }

        if ($preserveDrawer && $drawerId) {
            // Intentionally do not restore drawer into unlocked session.
            // Durable drawer remains in DB; next login reattaches via ShiftEntryService.
        }
    }

    public function lockToLoginScreen(): void
    {
        $this->clearSessionIdentity(true);
        if (function_exists('posmain_session_regenerate')) {
            posmain_session_regenerate();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadActiveUser(mysqli $conn, int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $cols = 'id, uname, display_name, password, userrole, usertype, pin_hash, pin_locked_until, failed_pin_attempts';
        if ($this->columnExists($conn, 'users', 'pin_must_change')) {
            $cols .= ', pin_must_change';
        }
        if ($this->columnExists($conn, 'users', 'auth_version')) {
            $cols .= ', auth_version';
        }

        $stmt = $conn->prepare(
            "SELECT {$cols} FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function sessionAuthVersionValid(mysqli $conn): bool
    {
        if (empty($_SESSION['userid'])) {
            return true;
        }
        if (!$this->columnExists($conn, 'users', 'auth_version')) {
            return true;
        }

        $userId = (int) $_SESSION['userid'];
        $sessionVersion = (int) ($_SESSION['posmain_auth_version'] ?? 0);
        $stmt = $conn->prepare(
            'SELECT auth_version FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }

        return (int) ($row['auth_version'] ?? 0) === $sessionVersion;
    }

    public function isBootstrapRestrictedSession(): bool
    {
        return !empty($_SESSION['posmain_bootstrap_pending'])
            || !empty($_SESSION['posmain_pin_must_change']);
    }

    /**
     * @return list<array{identity:string, ip:string, options:array<string,int|bool>, clear_on_success:bool}>
     */
    private function mainPinThrottleBuckets(string $registerKey, string $ip): array
    {
        $buckets = [];
        if ($registerKey !== '') {
            $buckets[] = [
                'identity' => 'main_pin_register:' . $registerKey,
                'ip' => 'register',
                'options' => [
                    'max_attempts' => self::MAIN_PIN_REGISTER_MAX_ATTEMPTS,
                    'window_seconds' => self::MAIN_PIN_REGISTER_WINDOW_SECONDS,
                    'lock_seconds' => self::MAIN_PIN_REGISTER_LOCK_SECONDS,
                    'max_lock_seconds' => self::MAIN_PIN_REGISTER_MAX_LOCK_SECONDS,
                    'escalate' => true,
                ],
                'clear_on_success' => true,
            ];
        }
        $buckets[] = [
            'identity' => 'main_pin_ip',
            'ip' => $ip,
            'options' => [
                'max_attempts' => self::MAIN_PIN_IP_MAX_ATTEMPTS,
                'window_seconds' => self::MAIN_PIN_IP_WINDOW_SECONDS,
                'lock_seconds' => self::MAIN_PIN_IP_LOCK_SECONDS,
                'max_lock_seconds' => self::MAIN_PIN_IP_MAX_LOCK_SECONDS,
                'escalate' => true,
            ],
            'clear_on_success' => true,
        ];
        $buckets[] = [
            'identity' => 'main_pin_global',
            'ip' => 'global',
            'options' => [
                'max_attempts' => self::MAIN_PIN_GLOBAL_MAX_ATTEMPTS,
                'window_seconds' => self::MAIN_PIN_GLOBAL_WINDOW_SECONDS,
                'lock_seconds' => self::MAIN_PIN_GLOBAL_LOCK_SECONDS,
            ],
            'clear_on_success' => false,
        ];

        return $buckets;
    }

    /**
     * @param list<array{identity:string, ip:string, options:array<string,int|bool>, clear_on_success:bool}> $buckets
     */
    private function mainPinThrottleBlocked(mysqli $conn, array $buckets): bool
    {
        foreach ($buckets as $bucket) {
            if ($this->throttle->isBlocked(
                $conn,
                $bucket['identity'],
                $bucket['ip'],
                $bucket['options']
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{identity:string, ip:string, options:array<string,int|bool>, clear_on_success:bool}> $buckets
     */
    private function recordMainPinFailure(mysqli $conn, array $buckets): void
    {
        foreach ($buckets as $bucket) {
            $this->throttle->recordFailure(
                $conn,
                $bucket['identity'],
                $bucket['ip'],
                $bucket['options']
            );
        }
    }

    /**
     * @param list<array{identity:string, ip:string, options:array<string,int|bool>, clear_on_success:bool}> $buckets
     */
    private function recordMainPinSuccess(mysqli $conn, array $buckets): void
    {
        foreach ($buckets as $bucket) {
            if ($bucket['clear_on_success']) {
                $this->throttle->recordSuccess($conn, $bucket['identity'], $bucket['ip']);
            }
        }
    }

    private function consumeDummyVerification(string $pin): void
    {
        // Standard public bcrypt test hash. It contains no application credential.
        PasswordService::verifyPassword(
            $pin,
            '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.'
        );
    }

    private function verifyBootstrapPinHash(string $pin, string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }

        return PasswordService::verifyPassword($pin, $storedHash);
    }

    private function insertSessionTime(mysqli $conn, int $userId): void
    {
        try {
            $result = $conn->query("SHOW TABLES LIKE 'session_time'");
            if (!$result || $result->num_rows < 1) {
                return;
            }
            $stmt = $conn->prepare('INSERT INTO session_time(user) VALUES (?)');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $ignored) {
        }
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function auditSafe(mysqli $conn, string $event, array $options = []): void
    {
        try {
            $this->audit->record($conn, $event, $options);
        } catch (Throwable $ignored) {
        }
    }
}
