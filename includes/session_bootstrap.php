<?php

require_once __DIR__ . '/../config/app_config.php';

if (!function_exists('posmain_session_driver')) {
    function posmain_session_driver(): string
    {
        $configured = strtolower(trim((string) posmain_env('POSMAIN_SESSION_DRIVER', '')));
        if (in_array($configured, ['database', 'db', 'mysql'], true)) {
            return 'database';
        }

        if (in_array($configured, ['file', 'files'], true)) {
            return 'file';
        }

        $config = posmain_app_config();
        return !empty($config['production_mode']) ? 'database' : 'file';
    }
}

if (!function_exists('posmain_is_https_request')) {
    function posmain_is_https_request(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        return false;
    }
}

if (!function_exists('posmain_session_lifetime_seconds')) {
    function posmain_session_lifetime_seconds(): int
    {
        $seconds = (int) posmain_env('POSMAIN_SESSION_LIFETIME_SECONDS', 86400);
        return $seconds > 0 ? $seconds : 86400;
    }
}

if (!function_exists('posmain_session_configure_runtime')) {
    function posmain_session_configure_runtime(): void
    {
        $lifetime = posmain_session_lifetime_seconds();
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.cookie_lifetime', (string) $lifetime);

        if (posmain_session_driver() === 'database' && posmain_session_register_database_handler()) {
            return;
        }

        posmain_session_configure_file_runtime();
    }
}

if (!function_exists('posmain_session_configure_file_runtime')) {
    function posmain_session_configure_file_runtime(): void
    {
        $savePath = posmain_session_save_path();
        if ($savePath !== null) {
            session_save_path($savePath);
        }
    }
}

if (!function_exists('posmain_session_register_database_handler')) {
    function posmain_session_register_database_handler(): bool
    {
        static $registered = false;

        if ($registered) {
            return true;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return false;
        }

        try {
            require_once __DIR__ . '/db_bootstrap.php';
            require_once __DIR__ . '/../classes/Infrastructure/DatabaseSessionHandler.php';

            $tableName = trim((string) posmain_env('POSMAIN_SESSION_TABLE', 'app_sessions'));
            $handler = new DatabaseSessionHandler(
                static function (): mysqli {
                    return function_exists('posmain_session_db_connect')
                        ? posmain_session_db_connect()
                        : posmain_db_connect();
                },
                $tableName !== '' ? $tableName : 'app_sessions',
                posmain_session_lifetime_seconds()
            );
            $handler->ensureSchema();
            session_set_save_handler($handler, true);
            $registered = true;

            return true;
        } catch (Throwable $e) {
            error_log('POSMAIN database session handler unavailable, using file sessions: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('posmain_session_save_path')) {
    function posmain_session_save_path(): ?string
    {
        $configuredPath = trim((string) posmain_env('POSMAIN_SESSION_SAVE_PATH', ''));
        $path = $configuredPath !== '' ? $configuredPath : __DIR__ . '/../var/sessions';

        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            error_log('POSMAIN session save path is not writable: ' . $path);
            return null;
        }

        if (!is_writable($path)) {
            error_log('POSMAIN session save path is not writable: ' . $path);
            return null;
        }

        @chmod($path, 0700);
        return $path;
    }
}

if (!function_exists('posmain_session_cookie_options')) {
    function posmain_session_cookie_options(): array
    {
        $config = posmain_app_config();
        $secure = !empty($config['production_mode']) || posmain_is_https_request();
        $sameSite = (string) posmain_env('POSMAIN_SESSION_SAMESITE', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        return [
            'lifetime' => posmain_session_lifetime_seconds(),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }
}

if (!function_exists('posmain_session_start')) {
    function posmain_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            posmain_session_touch();
            return;
        }

        posmain_session_configure_runtime();
        session_set_cookie_params(posmain_session_cookie_options());

        session_start();
        posmain_session_touch();
    }
}

if (!function_exists('posmain_is_background_session_request')) {
    /**
     * Heartbeat-gap soft-lock is for abandoned tabs (XHR/fetch still firing, or polls).
     * Full document navigations are user presence — enforce idle/absolute only.
     */
    function posmain_is_background_session_request(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');

        return strpos($accept, 'application/json') !== false
            || strpos($contentType, 'application/json') !== false
            || $requestedWith === 'xmlhttprequest'
            || strpos($script, '/ajax/') !== false
            || strpos($script, 'ajax/') === 0;
    }
}

if (!function_exists('posmain_session_touch')) {
    function posmain_session_touch(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $now = time();
        $sessionLifetime = posmain_session_lifetime_seconds();
        $defaultIdleSeconds = $sessionLifetime;
        $isPinMainAuth = false;
        try {
            if (function_exists('posmain_is_pin_main_auth') && posmain_is_pin_main_auth()) {
                $isPinMainAuth = true;
                $defaultIdleSeconds = (int) posmain_env('POSMAIN_INACTIVITY_LOCK_SECONDS', 300);
            }
        } catch (Throwable $ignored) {
            // Unsafe auth configuration is handled by the entry point/health check.
        }
        $idleSeconds = (int) posmain_env('POSMAIN_SESSION_IDLE_SECONDS', $defaultIdleSeconds);
        $absoluteSeconds = (int) posmain_env('POSMAIN_SESSION_ABSOLUTE_SECONDS', $sessionLifetime);
        $heartbeatGraceSeconds = max(30, min(600, (int) posmain_env('POSMAIN_HEARTBEAT_GRACE_SECONDS', 60)));

        if (empty($_SESSION['posmain_session_started_at'])) {
            $_SESSION['posmain_session_started_at'] = $now;
        }

        // Chrome throttles background timers to ~60s+; a document click after that
        // must not look like "logout". Soft-lock stale heartbeats on XHR/polls only.
        if (
            $isPinMainAuth
            && posmain_is_background_session_request()
            && !empty($_SESSION['posmain_heartbeat_last_at'])
            && ($now - (int) $_SESSION['posmain_heartbeat_last_at']) > $heartbeatGraceSeconds
        ) {
            posmain_session_soft_lock();
            return;
        }

        if (
            $idleSeconds > 0
            && !empty($_SESSION['posmain_session_last_seen_at'])
            && ($now - (int) $_SESSION['posmain_session_last_seen_at']) > $idleSeconds
        ) {
            if ($isPinMainAuth) {
                posmain_session_soft_lock();
            } else {
                posmain_session_expire();
            }
            return;
        }

        if (
            $absoluteSeconds > 0
            && !empty($_SESSION['posmain_session_started_at'])
            && ($now - (int) $_SESSION['posmain_session_started_at']) > $absoluteSeconds
        ) {
            posmain_session_expire();
            return;
        }

        $_SESSION['posmain_session_last_seen_at'] = $now;
        // Document navigation is live presence — refresh heartbeat so the next
        // poll does not soft-lock immediately after a throttled background tab.
        if ($isPinMainAuth && !posmain_is_background_session_request()) {
            $_SESSION['posmain_heartbeat_last_at'] = $now;
        }
    }
}

if (!function_exists('posmain_session_soft_lock')) {
    function posmain_session_soft_lock(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $authPath = __DIR__ . '/../classes/Security/MainAuthenticationService.php';
        if (is_file($authPath)) {
            require_once $authPath;
        }

        if (class_exists('MainAuthenticationService', false)) {
            (new MainAuthenticationService())->lockToLoginScreen();
            return;
        }

        // Fallback if the auth service cannot be loaded: clear identity keys only.
        foreach ([
            'login', 'userid', 'usrole', 'usty', 'userrole',
            'posmain_auth_version', 'posmain_auth_method',
            'posmain_bootstrap_pending', 'posmain_pin_must_change',
            'posmain_capabilities_cache', 'posmain_capabilities_version',
            'posmain_shop_id', 'posmain_shop_slug', 'posmain_shop_user_id',
            'posmain_heartbeat_last_at',
            'pos_acting_user_id', 'pos_acting_user_name',
            'pos_authenticated', 'pos_user_id', 'pos_user_name',
            'pos_drawer_session_id',
            'pos_shift_session_token', 'pos_last_activity_at',
            'pos_unlocked_pending_open', 'pos_pending_takeover',
            'pos_cart_park_required', 'pos_previous_acting_user_id',
        ] as $key) {
            unset($_SESSION[$key]);
        }

        if (function_exists('posmain_session_regenerate')) {
            posmain_session_regenerate();
        }
    }
}

if (!function_exists('posmain_session_expire')) {
    function posmain_session_expire(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}

if (!function_exists('posmain_session_regenerate')) {
    function posmain_session_regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            posmain_session_start();
        }

        session_regenerate_id(true);
        $_SESSION['posmain_session_started_at'] = time();
        $_SESSION['posmain_session_last_seen_at'] = time();
    }
}

posmain_session_start();

require_once __DIR__ . '/entry_classification_guard.php';
posmain_enforce_entry_classification();
