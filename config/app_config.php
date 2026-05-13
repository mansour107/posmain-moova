<?php

if (!function_exists('posmain_load_env_file')) {
    function posmain_load_env_file(?string $path = null): void
    {
        static $loaded = [];

        $path = $path ?: __DIR__ . '/../.env';
        $realPath = realpath($path) ?: $path;
        if (isset($loaded[$realPath])) {
            return;
        }
        $loaded[$realPath] = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $value = trim($parts[1]);
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

posmain_load_env_file();

if (!function_exists('posmain_env')) {
    function posmain_env(string $name, $default = null, bool $allowEmpty = false)
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        if (!$allowEmpty && $value === '') {
            return $default;
        }

        return $value;
    }
}

if (!function_exists('posmain_first_env')) {
    function posmain_first_env(array $names, $default = null, bool $allowEmpty = false)
    {
        foreach ($names as $name) {
            $value = posmain_env((string) $name, null, $allowEmpty);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('posmain_bool')) {
    function posmain_bool($value, bool $default = false): bool
    {
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

if (!function_exists('posmain_int')) {
    function posmain_int($value, ?int $default = 0): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return $default;
        }

        return (int) $value;
    }
}

if (!function_exists('posmain_map')) {
    function posmain_map($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === false || trim((string) $value) === '') {
            return [];
        }

        $text = trim((string) $value);
        if ($text[0] === '{') {
            $decoded = json_decode($text, true);
            return is_array($decoded) ? $decoded : [];
        }

        $map = [];
        foreach (explode(',', $text) as $part) {
            $pair = array_map('trim', explode('=', $part, 2));
            if (count($pair) === 2 && $pair[0] !== '') {
                $map[$pair[0]] = $pair[1];
            }
        }

        return $map;
    }
}

if (!function_exists('posmain_merge_config')) {
    function posmain_merge_config(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = posmain_merge_config($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}

if (!function_exists('posmain_app_config')) {
    function posmain_app_config(array $overrides = []): array
    {
        $env = (string) posmain_env('POSMAIN_ENV', 'local');
        $productionMode = posmain_bool(posmain_env('POSMAIN_PRODUCTION_MODE', null), strtolower($env) === 'production');

        $config = [
            'env' => $env,
            'role' => (string) posmain_env('POSMAIN_ROLE', 'branch'),
            'timezone' => (string) posmain_env('POSMAIN_TIMEZONE', 'Africa/Cairo'),
            'production_mode' => $productionMode,
            'public_base_url' => (string) posmain_env('POSMAIN_PUBLIC_BASE_URL', ''),
            'status_token' => (string) posmain_first_env(['POSMAIN_STATUS_TOKEN', 'POSMAIN_SYNC_STATUS_TOKEN'], '', true),
            'database' => [
                'host' => (string) posmain_first_env(
                    ['POSMAIN_SYNC_DB_HOST', 'POSMAIN_DB_HOST', 'POSMAIN_TEST_MYSQL_HOST', 'POSMAIN_API_DB_HOST'],
                    '127.0.0.1'
                ),
                'port' => posmain_int(posmain_first_env(
                    ['POSMAIN_SYNC_DB_PORT', 'POSMAIN_DB_PORT', 'POSMAIN_TEST_MYSQL_PORT', 'POSMAIN_API_DB_PORT'],
                    3306
                ), 3306),
                'user' => (string) posmain_first_env(
                    ['POSMAIN_SYNC_DB_USER', 'POSMAIN_DB_USER', 'POSMAIN_TEST_MYSQL_USER', 'POSMAIN_API_DB_USER'],
                    'root'
                ),
                'pass' => (string) posmain_first_env(
                    ['POSMAIN_SYNC_DB_PASS', 'POSMAIN_DB_PASS', 'POSMAIN_TEST_MYSQL_PASS', 'POSMAIN_API_DB_PASS'],
                    '',
                    true
                ),
                'name' => (string) posmain_first_env(
                    ['POSMAIN_SYNC_DB_NAME', 'POSMAIN_DB_NAME', 'POSMAIN_TEST_MYSQL_DB', 'POSMAIN_API_DB_NAME'],
                    'kody2'
                ),
                'charset' => (string) posmain_env('POSMAIN_DB_CHARSET', 'utf8mb4'),
            ],
            'branch' => [
                'uuid' => (string) posmain_env('POSMAIN_BRANCH_UUID', ''),
                'name' => (string) posmain_env('POSMAIN_BRANCH_NAME', ''),
                'pos_tenant' => posmain_int(posmain_env('POSMAIN_POS_TENANT', null), null),
                'pos_branch' => posmain_int(posmain_env('POSMAIN_POS_BRANCH', null), null),
                'cloud_base_url' => (string) posmain_env('POSMAIN_CLOUD_BASE_URL', ''),
            ],
            'features' => [
                'legacy_debug_routes' => posmain_bool(posmain_env('POSMAIN_ENABLE_LEGACY_DEBUG_ROUTES', '0'), false),
                'legacy_offline_prototype' => posmain_bool(posmain_env('POSMAIN_ENABLE_LEGACY_OFFLINE_PROTOTYPE', '0'), false),
                'moova_direct_apply' => posmain_bool(posmain_first_env(['POSMAIN_ENABLE_MOOVA_DIRECT_APPLY', 'POSMAIN_MOOVA_DIRECT_APPLY_ENABLED'], '0'), false),
                'moova_queued_apply' => posmain_bool(posmain_first_env(['POSMAIN_ENABLE_MOOVA_QUEUED_APPLY', 'POSMAIN_MOOVA_QUEUED_APPLY_ENABLED'], '0'), false),
                'sync_outbox' => posmain_bool(posmain_first_env(['POSMAIN_ENABLE_SYNC_OUTBOX', 'POSMAIN_SYNC_OUTBOX_ENABLED'], '1'), true),
                'cloud_sync' => posmain_bool(posmain_first_env(['POSMAIN_ENABLE_CLOUD_SYNC', 'POSMAIN_BRANCH_SYNC_ENABLED'], '0'), false),
                'kds' => posmain_bool(posmain_env('POSMAIN_ENABLE_KDS', '0'), false),
                'modifiers' => posmain_bool(posmain_env('POSMAIN_ENABLE_MODIFIERS', '0'), false),
                'nutrition' => posmain_bool(posmain_env('POSMAIN_ENABLE_NUTRITION', '0'), false),
                'ai_analytics' => posmain_bool(posmain_env('POSMAIN_ENABLE_AI_ANALYTICS', '0'), false),
                'eta_ereceipt' => posmain_bool(posmain_first_env(['POSMAIN_ENABLE_ETA_ERECEIPT', 'POSMAIN_ETA_ERECEIPT_ENABLED'], '0'), false),
            ],
            'sync' => [
                'branch_secret' => (string) posmain_env('POSMAIN_BRANCH_SYNC_SECRET', '', true),
                'cloud_branch_secrets' => posmain_map(posmain_env('POSMAIN_CLOUD_BRANCH_SECRETS', '', true)),
                'outbox_enabled' => posmain_bool(posmain_first_env(['POSMAIN_SYNC_OUTBOX_ENABLED', 'POSMAIN_ENABLE_SYNC_OUTBOX'], '1'), true),
                'branch_sync_enabled' => posmain_bool(posmain_first_env(['POSMAIN_BRANCH_SYNC_ENABLED', 'POSMAIN_ENABLE_CLOUD_SYNC'], '0'), false),
                'worker_enabled' => posmain_bool(posmain_env('POSMAIN_SYNC_WORKER_ENABLED', '1'), true),
                'cloud_apply_enabled' => posmain_bool(posmain_env('POSMAIN_CLOUD_APPLY_ENABLED', '1'), true),
                'shadow_mode' => posmain_bool(posmain_env('POSMAIN_SYNC_SHADOW_MODE', '0'), false),
                'moova_poller_enabled' => posmain_bool(posmain_env('POSMAIN_MOOVA_POLLER_ENABLED', '1'), true),
                'moova_apply_enabled' => posmain_bool(posmain_first_env(['POSMAIN_MOOVA_APPLY_ENABLED', 'POSMAIN_ENABLE_MOOVA_QUEUED_APPLY'], '0'), false),
                'moova_apply_user_id' => posmain_int(posmain_env('POSMAIN_MOOVA_APPLY_USER_ID', null), 1),
                'menu_sync_enabled' => posmain_bool(posmain_env('POSMAIN_MENU_SYNC_ENABLED', '0'), false),
                'http_connect_timeout_ms' => posmain_int(posmain_env('POSMAIN_SYNC_HTTP_CONNECT_TIMEOUT_MS', null), 1000),
                'http_timeout_ms' => posmain_int(posmain_env('POSMAIN_SYNC_HTTP_TIMEOUT_MS', null), 5000),
            ],
        ];

        return posmain_merge_config($config, $overrides);
    }
}
