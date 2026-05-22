<?php

require_once __DIR__ . '/../classes/Sync/SyncRuntimeDbConfigFile.php';
require_once __DIR__ . '/../classes/Sync/SyncRuntimeSettings.php';

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

if (!function_exists('posmain_read_env_file_values')) {
    function posmain_read_env_file_values(?string $path): array
    {
        static $cache = [];

        if ($path === null || $path === '') {
            return [];
        }

        $realPath = realpath($path) ?: $path;
        if (isset($cache[$realPath])) {
            return $cache[$realPath];
        }

        if (!is_file($path) || !is_readable($path)) {
            $cache[$realPath] = [];
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $cache[$realPath] = [];
            return [];
        }

        $values = [];
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
            if ($name === '') {
                continue;
            }

            $value = trim($parts[1]);
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $values[$name] = $value;
        }

        $cache[$realPath] = $values;
        return $values;
    }
}

if (!function_exists('posmain_sync_local_env_files')) {
    function posmain_sync_local_env_files(): array
    {
        $paths = [];
        $customPath = posmain_env('POSMAIN_BRANCH_WORKER_ENV_FILE', '', true);
        if (is_string($customPath) && trim($customPath) !== '') {
            $paths[] = trim($customPath);
        }

        $paths[] = __DIR__ . '/../.env.branch-worker';
        $paths[] = '/etc/posmain/branch-worker.env';

        return array_values(array_unique($paths));
    }
}

if (!function_exists('posmain_first_env_or_file')) {
    function posmain_first_env_or_file(array $names, $default = null, bool $allowEmpty = false, array $paths = [])
    {
        $value = posmain_first_env($names, null, $allowEmpty);
        if ($value !== null) {
            return $value;
        }

        foreach ($paths as $path) {
            $values = posmain_read_env_file_values((string) $path);
            foreach ($names as $name) {
                $name = (string) $name;
                if (!array_key_exists($name, $values)) {
                    continue;
                }

                $value = $values[$name];
                if (!$allowEmpty && $value === '') {
                    continue;
                }

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

if (!function_exists('posmain_runtime_file_database_overrides')) {
    function posmain_runtime_file_database_overrides(): array
    {
        if (SyncRuntimeDbConfigFile::disabled()) {
            return [];
        }

        try {
            $loaded = (new SyncRuntimeDbConfigFile())->load();
            return isset($loaded['database']) && is_array($loaded['database'])
                ? ['database' => $loaded['database']]
                : [];
        } catch (Throwable $e) {
            error_log('POSMAIN runtime DB config ignored: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('posmain_runtime_db_settings_overrides')) {
    function posmain_runtime_db_settings_overrides(array $config): array
    {
        if (SyncRuntimeDbConfigFile::disabled()) {
            return [];
        }

        $db = $config['database'] ?? [];
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli(
            (string) ($db['host'] ?? '127.0.0.1'),
            (string) ($db['user'] ?? 'root'),
            (string) ($db['pass'] ?? ''),
            (string) ($db['name'] ?? 'kody2'),
            (int) ($db['port'] ?? 3306)
        );
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        if ($conn->connect_error) {
            return [];
        }

        try {
            $conn->set_charset((string) (($db['charset'] ?? '') ?: 'utf8mb4'));
            $overrides = (new SyncRuntimeSettings())->fetchConfigOverrides($conn);
            $conn->close();
            return $overrides;
        } catch (Throwable $e) {
            $conn->close();
            error_log('POSMAIN sync runtime settings ignored: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('posmain_app_config')) {
    function posmain_app_config(array $overrides = []): array
    {
        $env = (string) posmain_env('POSMAIN_ENV', 'local');
        $productionMode = posmain_bool(posmain_env('POSMAIN_PRODUCTION_MODE', null), strtolower($env) === 'production');
        $moovaMode = strtolower(trim((string) posmain_env('POSMAIN_MOOVA_MODE', '')));
        $moovaMode = str_replace(['-', ' '], '_', $moovaMode);
        if ($moovaMode === 'direct') {
            $moovaMode = 'direct_widget';
        } elseif ($moovaMode === 'queued') {
            $moovaMode = 'queued_worker';
        }

        $rawMoovaDirectApply = posmain_bool(
            posmain_first_env(['POSMAIN_ENABLE_MOOVA_DIRECT_APPLY', 'POSMAIN_MOOVA_DIRECT_APPLY_ENABLED'], null),
            false
        );
        $rawMoovaQueuedApply = posmain_bool(
            posmain_first_env(['POSMAIN_ENABLE_MOOVA_QUEUED_APPLY', 'POSMAIN_MOOVA_QUEUED_APPLY_ENABLED'], null),
            false
        );
        $rawMoovaWorkerApply = posmain_bool(
            posmain_first_env(['POSMAIN_MOOVA_APPLY_ENABLED', 'POSMAIN_ENABLE_MOOVA_QUEUED_APPLY'], null),
            false
        );

        if (!in_array($moovaMode, ['disabled', 'direct_widget', 'queued_worker', 'hybrid'], true)) {
            if ($rawMoovaDirectApply && ($rawMoovaQueuedApply || $rawMoovaWorkerApply)) {
                $moovaMode = 'hybrid';
            } elseif ($rawMoovaDirectApply) {
                $moovaMode = 'direct_widget';
            } elseif ($rawMoovaQueuedApply || $rawMoovaWorkerApply) {
                $moovaMode = 'queued_worker';
            } else {
                $moovaMode = 'disabled';
            }
        }

        $moovaModeAllowsDirect = in_array($moovaMode, ['direct_widget', 'hybrid'], true);
        $moovaModeAllowsQueued = in_array($moovaMode, ['queued_worker', 'hybrid'], true);
        $moovaDirectApply = $moovaModeAllowsDirect && ($rawMoovaDirectApply || posmain_env('POSMAIN_MOOVA_MODE', '') !== '');
        $moovaQueuedApply = $moovaModeAllowsQueued && $rawMoovaQueuedApply;
        $moovaWorkerApply = $moovaModeAllowsQueued && $rawMoovaWorkerApply;

        $config = [
            'env' => $env,
            'role' => (string) posmain_env('POSMAIN_ROLE', 'branch'),
            'timezone' => (string) posmain_env('POSMAIN_TIMEZONE', 'Africa/Cairo'),
            'production_mode' => $productionMode,
            'moova' => [
                'mode' => $moovaMode,
                'direct_apply_enabled' => $moovaDirectApply,
                'queued_apply_enabled' => $moovaQueuedApply,
                'worker_apply_enabled' => $moovaWorkerApply,
                'queued_worker_requires_acceptance' => true,
            ],
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
                'moova_direct_apply' => $moovaDirectApply,
                'moova_queued_apply' => $moovaQueuedApply,
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
                'legacy_pos_mirror_enabled' => posmain_bool(posmain_env('POSMAIN_CLOUD_LEGACY_POS_MIRROR_ENABLED', '0'), false),
                'cloud_to_branch_publish_enabled' => posmain_bool(posmain_env('POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED', '0'), false),
                'cloud_pull_enabled' => posmain_bool(posmain_env('POSMAIN_CLOUD_PULL_ENABLED', '1'), true),
                'shadow_mode' => posmain_bool(posmain_env('POSMAIN_SYNC_SHADOW_MODE', '0'), false),
                'moova_poller_enabled' => posmain_bool(posmain_env('POSMAIN_MOOVA_POLLER_ENABLED', '1'), true),
                'moova_apply_enabled' => $moovaWorkerApply,
                'moova_apply_user_id' => posmain_int(posmain_env('POSMAIN_MOOVA_APPLY_USER_ID', null), 1),
                'menu_sync_enabled' => posmain_bool(posmain_env('POSMAIN_MENU_SYNC_ENABLED', '0'), false),
                'http_connect_timeout_ms' => posmain_int(posmain_env('POSMAIN_SYNC_HTTP_CONNECT_TIMEOUT_MS', null), 1000),
                'http_timeout_ms' => posmain_int(posmain_env('POSMAIN_SYNC_HTTP_TIMEOUT_MS', null), 5000),
            ],
        ];

        $config = posmain_merge_config($config, posmain_runtime_file_database_overrides());
        $config = posmain_merge_config($config, posmain_runtime_db_settings_overrides($config));

        return posmain_merge_config($config, $overrides);
    }
}
