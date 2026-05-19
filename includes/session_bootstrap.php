<?php

require_once __DIR__ . '/../config/app_config.php';

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

if (!function_exists('posmain_session_touch')) {
    function posmain_session_touch(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $now = time();
        $sessionLifetime = posmain_session_lifetime_seconds();
        $idleSeconds = (int) posmain_env('POSMAIN_SESSION_IDLE_SECONDS', $sessionLifetime);
        $absoluteSeconds = (int) posmain_env('POSMAIN_SESSION_ABSOLUTE_SECONDS', $sessionLifetime);

        if (empty($_SESSION['posmain_session_started_at'])) {
            $_SESSION['posmain_session_started_at'] = $now;
        }

        if (
            $idleSeconds > 0
            && !empty($_SESSION['posmain_session_last_seen_at'])
            && ($now - (int) $_SESSION['posmain_session_last_seen_at']) > $idleSeconds
        ) {
            posmain_session_expire();
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
    }
}

if (!function_exists('posmain_session_expire')) {
    function posmain_session_expire(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, [
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
