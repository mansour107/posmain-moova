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

if (!function_exists('posmain_update_channel_file')) {
    function posmain_update_channel_file(): array
    {
        static $channel = null;
        if ($channel !== null) {
            return $channel;
        }

        $path = __DIR__ . '/update_channel.php';
        if (!is_file($path)) {
            $channel = [];

            return $channel;
        }

        $loaded = require $path;
        $channel = is_array($loaded) ? $loaded : [];

        return $channel;
    }
}

if (!function_exists('posmain_update_channel_base_url')) {
    function posmain_update_channel_base_url(): string
    {
        $override = trim((string) posmain_env('POSMAIN_UPDATE_CHANNEL_URL', '', true));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        $channel = posmain_update_channel_file();

        return rtrim((string) ($channel['remote_base_url'] ?? ''), '/');
    }
}

if (!function_exists('posmain_update_version_manifest_url')) {
    function posmain_update_version_manifest_url(): string
    {
        $legacy = trim((string) posmain_env('POSMAIN_UPDATE_VERSION_URL', '', true));
        if ($legacy !== '') {
            return $legacy;
        }

        $base = posmain_update_channel_base_url();

        return $base !== '' ? $base . '/version.json' : '';
    }
}

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

if (!function_exists('posmain_branch_env_file_fallbacks')) {
    function posmain_branch_env_file_fallbacks(): array
    {
        if (posmain_bool(posmain_env('POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK', '0'), false)) {
            return [];
        }

        return posmain_sync_local_env_files();
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

if (!function_exists('posmain_csv_int_list')) {
    function posmain_csv_int_list($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif ($value === null || $value === false || trim((string) $value) === '') {
            return [];
        } else {
            $parts = explode(',', (string) $value);
        }

        $ints = [];
        foreach ($parts as $part) {
            $int = (int) trim((string) $part);
            if ($int > 0) {
                $ints[$int] = $int;
            }
        }

        return array_values($ints);
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
        $branchEnvFiles = posmain_branch_env_file_fallbacks();
        $branchEnv = static function (array $names, $default = null, bool $allowEmpty = false) use ($branchEnvFiles) {
            return posmain_first_env_or_file($names, $default, $allowEmpty, $branchEnvFiles);
        };

        $env = (string) posmain_env('POSMAIN_ENV', 'local');
        $productionMode = posmain_bool(posmain_env('POSMAIN_PRODUCTION_MODE', null), strtolower($env) === 'production');
        $moovaMode = strtolower(trim((string) $branchEnv(['POSMAIN_MOOVA_MODE'], 'direct_widget')));
        $moovaMode = str_replace(['-', ' '], '_', $moovaMode);
        if ($moovaMode === 'direct') {
            $moovaMode = 'direct_widget';
        } elseif ($moovaMode === 'queued') {
            $moovaMode = 'queued_worker';
        }

        if (!in_array($moovaMode, ['disabled', 'direct_widget', 'queued_worker', 'hybrid'], true)) {
            $moovaMode = 'direct_widget';
        }

        $moovaModeAllowsDirect = in_array($moovaMode, ['direct_widget', 'hybrid'], true);
        $moovaModeAllowsQueued = in_array($moovaMode, ['queued_worker', 'hybrid'], true);
        $moovaDirectApply = $moovaModeAllowsDirect;
        $moovaQueuedApply = $moovaModeAllowsQueued;
        $moovaWorkerApply = $moovaModeAllowsQueued
            && posmain_bool($branchEnv(['POSMAIN_MOOVA_APPLY_ENABLED'], null), true);
        $recipeMode = strtolower(trim((string) $branchEnv(['POSMAIN_RECIPE_MODE'], 'off')));
        $recipeMode = str_replace(['-', ' '], '_', $recipeMode);
        $recipeModes = ['off', 'schema_only', 'read_only', 'shadow', 'reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'];
        if (!in_array($recipeMode, $recipeModes, true)) {
            $recipeMode = 'off';
        }
        $inventoryLedgerMode = strtolower(trim((string) $branchEnv(['POSMAIN_INVENTORY_LEDGER_MODE'], 'off')));
        $inventoryLedgerMode = str_replace(['-', ' '], '_', $inventoryLedgerMode);
        if (!in_array($inventoryLedgerMode, ['off', 'shadow', 'bridge', 'live'], true)) {
            $inventoryLedgerMode = 'off';
        }

        $config = [
            'env' => $env,
            'role' => (string) $branchEnv(['POSMAIN_ROLE'], 'branch'),
            'timezone' => (string) $branchEnv(['POSMAIN_TIMEZONE'], 'Africa/Cairo'),
            'production_mode' => $productionMode,
            'moova' => [
                'mode' => $moovaMode,
                'direct_apply_enabled' => $moovaDirectApply,
                'queued_apply_enabled' => $moovaQueuedApply,
                'worker_apply_enabled' => $moovaWorkerApply,
                'queued_worker_requires_acceptance' => true,
            ],
            'router' => [
                'enabled' => posmain_bool(posmain_env('POSMAIN_ROUTER_ENABLED', '0'), false),
                'require_encryption' => true,
                'database' => [
                    'host' => (string) posmain_first_env(['POSMAIN_ROUTER_DB_HOST'], ''),
                    'port' => posmain_int(posmain_first_env(['POSMAIN_ROUTER_DB_PORT'], 3306), 3306),
                    'name' => (string) posmain_first_env(['POSMAIN_ROUTER_DB_NAME'], ''),
                    'user' => (string) posmain_first_env(['POSMAIN_ROUTER_DB_USER'], ''),
                    'pass' => (string) posmain_first_env(['POSMAIN_ROUTER_DB_PASS'], '', true),
                    'charset' => 'utf8mb4',
                ],
            ],
            'recipe' => [
                'enabled' => $recipeMode !== 'off',
                'mode' => $recipeMode,
                'shadow_ledger' => posmain_bool($branchEnv(['POSMAIN_RECIPE_SHADOW_LEDGER'], '0'), false),
                'reservations' => posmain_bool($branchEnv(['POSMAIN_RECIPE_RESERVATIONS', 'POSMAIN_INVENTORY_RESERVATIONS'], '0'), false),
                'consumption' => posmain_bool($branchEnv(['POSMAIN_RECIPE_CONSUMPTION'], '0'), false),
                'accounting' => posmain_bool($branchEnv(['POSMAIN_RECIPE_ACCOUNTING'], '0'), false),
                'availability' => posmain_bool($branchEnv(['POSMAIN_RECIPE_AVAILABILITY', 'POSMAIN_INVENTORY_AVAILABILITY'], '0'), false),
                'moova_sync' => posmain_bool($branchEnv(['POSMAIN_RECIPE_MOOVA_SYNC'], '0'), false),
                'strict_stock' => posmain_bool($branchEnv(['POSMAIN_RECIPE_STRICT_STOCK', 'POSMAIN_INVENTORY_STRICT_STOCK'], '0'), false),
                'cost_public_payloads' => posmain_bool($branchEnv(['POSMAIN_RECIPE_COST_PUBLIC_PAYLOADS'], '0'), false),
                'default_reservation_minutes' => posmain_int($branchEnv(['POSMAIN_RECIPE_DEFAULT_RESERVATION_MINUTES'], 90), 90),
                'default_safety_stock_qty' => (string) $branchEnv(['POSMAIN_RECIPE_DEFAULT_SAFETY_STOCK_QTY'], '0', true),
                'refund_stock_policy' => (string) $branchEnv(['POSMAIN_RECIPE_REFUND_STOCK_POLICY'], 'waste'),
                'allow_negative_stock_with_approval' => posmain_bool($branchEnv(['POSMAIN_RECIPE_ALLOW_NEGATIVE_STOCK_WITH_APPROVAL'], '0'), false),
                'production_variance_policy' => (string) $branchEnv(['POSMAIN_RECIPE_PRODUCTION_VARIANCE_POLICY'], 'adjust_unit_cost'),
                'accounts' => [
                    'cogs_account_id' => posmain_int($branchEnv(['POSMAIN_RECIPE_DEFAULT_COGS_ACCOUNT_ID'], 0), 0),
                    'raw_inventory_account_id' => posmain_int($branchEnv(['POSMAIN_RECIPE_RAW_INVENTORY_ACCOUNT_ID'], 0), 0),
                    'prepared_inventory_account_id' => posmain_int($branchEnv(['POSMAIN_RECIPE_PREPARED_INVENTORY_ACCOUNT_ID'], 0), 0),
                    'packaging_inventory_account_id' => posmain_int($branchEnv(['POSMAIN_RECIPE_PACKAGING_INVENTORY_ACCOUNT_ID'], 0), 0),
                    'waste_expense_account_id' => posmain_int($branchEnv(['POSMAIN_RECIPE_WASTE_EXPENSE_ACCOUNT_ID'], 0), 0),
                    'production_variance_account_id' => posmain_int($branchEnv(['POSMAIN_RECIPE_PRODUCTION_VARIANCE_ACCOUNT_ID'], 0), 0),
                ],
                'pilot' => [
                    'pos_branch' => (string) $branchEnv(['POSMAIN_RECIPE_PILOT_POS_BRANCH'], '', true),
                    'item_ids' => posmain_csv_int_list($branchEnv(['POSMAIN_RECIPE_PILOT_ITEM_IDS'], '', true)),
                    'category_ids' => posmain_csv_int_list($branchEnv(['POSMAIN_RECIPE_PILOT_CATEGORY_IDS'], '', true)),
                ],
            ],
            'inventory' => [
                'ledger_mode' => $inventoryLedgerMode,
                'legacy_mirror' => posmain_bool($branchEnv(['POSMAIN_INVENTORY_LEGACY_MIRROR'], '0'), false),
                'strict_stock' => posmain_bool($branchEnv(['POSMAIN_INVENTORY_STRICT_STOCK'], '0'), false),
                'reservations' => posmain_bool($branchEnv(['POSMAIN_INVENTORY_RESERVATIONS'], '0'), false),
                'accounting' => posmain_bool($branchEnv(['POSMAIN_INVENTORY_ACCOUNTING'], '0'), false),
                'availability' => posmain_bool($branchEnv(['POSMAIN_INVENTORY_AVAILABILITY'], '0'), false),
                'sync' => posmain_bool($branchEnv(['POSMAIN_INVENTORY_SYNC'], '0'), false),
                'cost_public_payloads' => posmain_bool($branchEnv(['POSMAIN_INVENTORY_COST_PUBLIC_PAYLOADS'], '0'), false),
                'accounts' => [
                    'inventory_asset_account_id' => posmain_int($branchEnv(['POSMAIN_INVENTORY_ASSET_ACCOUNT_ID'], 0), 0),
                    'inventory_account_id' => posmain_int($branchEnv(['POSMAIN_INVENTORY_ACCOUNT_ID'], 0), 0),
                    'purchase_clearing_account_id' => posmain_int($branchEnv(['POSMAIN_INVENTORY_PURCHASE_CLEARING_ACCOUNT_ID'], 0), 0),
                    'cogs_account_id' => posmain_int($branchEnv(['POSMAIN_INVENTORY_COGS_ACCOUNT_ID'], 0), 0),
                    'waste_expense_account_id' => posmain_int($branchEnv(['POSMAIN_INVENTORY_WASTE_EXPENSE_ACCOUNT_ID'], 0), 0),
                    'adjustment_gain_loss_account_id' => posmain_int($branchEnv(['POSMAIN_INVENTORY_ADJUSTMENT_GAIN_LOSS_ACCOUNT_ID'], 0), 0),
                ],
            ],
            'public_base_url' => (string) posmain_env('POSMAIN_PUBLIC_BASE_URL', ''),
            'update_channel_url' => posmain_update_channel_base_url(),
            'update_version_url' => posmain_update_version_manifest_url(),
            'status_token' => (string) $branchEnv(['POSMAIN_STATUS_TOKEN'], '', true),
            'database' => [
                'host' => (string) posmain_first_env(
                    ['POSMAIN_DB_HOST', 'POSMAIN_TEST_MYSQL_HOST', 'POSMAIN_API_DB_HOST'],
                    '127.0.0.1'
                ),
                'port' => posmain_int(posmain_first_env(
                    ['POSMAIN_DB_PORT', 'POSMAIN_TEST_MYSQL_PORT', 'POSMAIN_API_DB_PORT'],
                    3306
                ), 3306),
                'user' => (string) posmain_first_env(
                    ['POSMAIN_DB_USER', 'POSMAIN_TEST_MYSQL_USER', 'POSMAIN_API_DB_USER'],
                    'root'
                ),
                'pass' => (string) posmain_first_env(
                    ['POSMAIN_DB_PASS', 'POSMAIN_TEST_MYSQL_PASS', 'POSMAIN_API_DB_PASS'],
                    '',
                    true
                ),
                'name' => (string) posmain_first_env(
                    ['POSMAIN_DB_NAME', 'POSMAIN_TEST_MYSQL_DB', 'POSMAIN_API_DB_NAME'],
                    'kody2'
                ),
                'charset' => 'utf8mb4',
            ],
            'branch' => [
                'uuid' => (string) $branchEnv(['POSMAIN_BRANCH_UUID'], ''),
                'name' => (string) $branchEnv(['POSMAIN_BRANCH_NAME'], ''),
                'pos_tenant' => posmain_int($branchEnv(['POSMAIN_POS_TENANT'], null), null),
                'pos_branch' => posmain_int($branchEnv(['POSMAIN_POS_BRANCH'], null), null),
                'cloud_base_url' => (string) $branchEnv(['POSMAIN_CLOUD_BASE_URL'], ''),
            ],
            'features' => [
                'legacy_debug_routes' => posmain_bool($branchEnv(['POSMAIN_ENABLE_LEGACY_DEBUG_ROUTES'], '0'), false),
                'legacy_offline_prototype' => posmain_bool($branchEnv(['POSMAIN_ENABLE_LEGACY_OFFLINE_PROTOTYPE'], '0'), false),
                'moova_direct_apply' => $moovaDirectApply,
                'moova_queued_apply' => $moovaQueuedApply,
                'sync_outbox' => posmain_bool($branchEnv(['POSMAIN_SYNC_OUTBOX_ENABLED'], '1'), true),
                'cloud_sync' => posmain_bool($branchEnv(['POSMAIN_BRANCH_SYNC_ENABLED'], '0'), false),
                'kds' => posmain_bool($branchEnv(['POSMAIN_ENABLE_KDS'], '0'), false),
                'modifiers' => posmain_bool($branchEnv(['POSMAIN_ENABLE_MODIFIERS'], '0'), false),
                'nutrition' => posmain_bool($branchEnv(['POSMAIN_ENABLE_NUTRITION'], '0'), false),
                'ai_analytics' => posmain_bool($branchEnv(['POSMAIN_ENABLE_AI_ANALYTICS'], '0'), false),
                'eta_ereceipt' => posmain_bool($branchEnv(['POSMAIN_ETA_ERECEIPT_ENABLED'], '0'), false),
                'recipes' => $recipeMode !== 'off',
            ],
            'sync' => [
                'branch_secret' => (string) $branchEnv(['POSMAIN_BRANCH_SYNC_SECRET'], '', true),
                'cloud_branch_secrets' => posmain_map($branchEnv(['POSMAIN_CLOUD_BRANCH_SECRETS'], '', true)),
                'outbox_enabled' => posmain_bool($branchEnv(['POSMAIN_SYNC_OUTBOX_ENABLED'], '1'), true),
                'branch_sync_enabled' => posmain_bool($branchEnv(['POSMAIN_BRANCH_SYNC_ENABLED'], '0'), false),
                'worker_enabled' => posmain_bool($branchEnv(['POSMAIN_SYNC_WORKER_ENABLED'], '1'), true),
                'cloud_apply_enabled' => posmain_bool($branchEnv(['POSMAIN_CLOUD_APPLY_ENABLED'], '1'), true),
                'legacy_pos_mirror_enabled' => posmain_bool($branchEnv(['POSMAIN_CLOUD_LEGACY_POS_MIRROR_ENABLED'], '0'), false),
                'cloud_to_branch_publish_enabled' => posmain_bool($branchEnv(['POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED'], '0'), false),
                'cloud_pull_enabled' => posmain_bool($branchEnv(['POSMAIN_CLOUD_PULL_ENABLED'], '1'), true),
                'shadow_mode' => posmain_bool($branchEnv(['POSMAIN_SYNC_SHADOW_MODE'], '0'), false),
                'moova_poller_enabled' => posmain_bool($branchEnv(['POSMAIN_MOOVA_POLLER_ENABLED'], '1'), true),
                'moova_apply_enabled' => $moovaWorkerApply,
                'moova_apply_user_id' => posmain_int($branchEnv(['POSMAIN_MOOVA_APPLY_USER_ID'], null), 1),
                'menu_sync_enabled' => posmain_bool($branchEnv(['POSMAIN_MENU_SYNC_ENABLED'], '0'), false),
                'operational_sync_enabled' => posmain_bool($branchEnv(['POSMAIN_OPERATIONAL_SYNC_ENABLED'], '1'), true),
                'http_connect_timeout_ms' => posmain_int($branchEnv(['POSMAIN_SYNC_HTTP_CONNECT_TIMEOUT_MS'], null), 1000),
                'http_timeout_ms' => posmain_int($branchEnv(['POSMAIN_SYNC_HTTP_TIMEOUT_MS'], null), 5000),
            ],
        ];

        $config = posmain_merge_config($config, posmain_runtime_file_database_overrides());
        $config = posmain_merge_config($config, posmain_runtime_db_settings_overrides($config));

        return posmain_merge_config($config, $overrides);
    }
}
