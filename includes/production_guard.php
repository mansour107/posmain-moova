<?php

if (!function_exists('production_guard_env_bool')) {
    function production_guard_env_bool(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            if (function_exists('posmain_env')) {
                $value = posmain_env($name, null, true);
            }
        }

        if ($value === null || $value === false || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }
}

if (!function_exists('production_guard_is_production')) {
    function production_guard_is_production(): bool
    {
        if (!function_exists('posmain_env')) {
            $configPath = __DIR__ . '/../config/app_config.php';
            if (is_file($configPath)) {
                require_once $configPath;
            }
        }

        return production_guard_env_bool('POSMAIN_PRODUCTION_MODE', false)
            || strtolower(trim((string) getenv('POSMAIN_ENV'))) === 'production';
    }
}

if (!function_exists('production_guard_current_user_id')) {
    function production_guard_current_user_id(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            foreach (['userid', 'user_id', 'waiter_id'] as $key) {
                if (isset($_SESSION[$key])) {
                    return (string) $_SESSION[$key];
                }
            }
        }

        return 'anonymous';
    }
}

if (!function_exists('production_guard_log_denial')) {
    function production_guard_log_denial(string $route): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $method = $_SERVER['REQUEST_METHOD'] ?? PHP_SAPI;
        $uri = $_SERVER['REQUEST_URI'] ?? $route;
        $message = sprintf(
            '[production_guard] denied route=%s method=%s user=%s ip=%s uri=%s at=%s',
            $route,
            $method,
            production_guard_current_user_id(),
            $ip,
            $uri,
            date('c')
        );

        error_log($message);

        $logFile = __DIR__ . '/../logs/production_guard.log';
        $logDir = dirname($logFile);
        if (is_dir($logDir) && is_writable($logDir)) {
            @file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

if (!function_exists('production_guard_deny_route')) {
    function production_guard_deny_route(string $route, array $options = []): void
    {
        $allowCli = $options['allow_cli'] ?? true;
        if (PHP_SAPI === 'cli' && $allowCli) {
            return;
        }

        if (!production_guard_is_production()) {
            return;
        }

        production_guard_log_denial($route);

        $status = (int) ($options['status'] ?? 403);
        if ($status < 400 || $status > 499) {
            $status = 403;
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        echo $status === 404 ? 'Not Found' : 'Forbidden';
        exit;
    }
}

if (!function_exists('production_guard_deny_debug_request')) {
    function production_guard_deny_debug_request(string $route, array $keys = ['debug']): void
    {
        if (!production_guard_is_production()) {
            return;
        }

        foreach ($keys as $key) {
            if (isset($_GET[$key])) {
                production_guard_deny_route($route . '?' . $key, ['status' => 404]);
            }
        }
    }
}
