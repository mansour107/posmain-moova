<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/SideEffectPolicy.php';

if (PHP_SAPI === 'cli' && empty($_GET) && getenv('QUERY_STRING')) {
    parse_str((string) getenv('QUERY_STRING'), $_GET);
}

$detailRequested = isset($_GET['detail']) || isset($_GET['details']);
$tokenOk = posmainHealthTokenIsValid();
if ($detailRequested && !$tokenOk) {
    posmainHealthJson(403, [
        'ok' => false,
        'healthy' => false,
        'error' => 'forbidden',
        'message' => 'Detailed health requires POSMAIN_STATUS_TOKEN.',
    ]);
}

$configLoadError = null;
try {
    $config = posmain_app_config();
} catch (Throwable $configException) {
    $configLoadError = $configException->getMessage();
    $config = posmainHealthFallbackConfig();
}

$scope = strtolower(trim((string) ($_GET['scope'] ?? '')));
$updateScope = $scope === 'update';
$checks = [];
$healthy = true;

$checks['main_auth'] = posmainHealthMainAuthCheck($config, $configLoadError);
$healthy = $healthy && !empty($checks['main_auth']['ok']);

$checks['database'] = posmainHealthDatabaseCheck();
$healthy = $healthy && !empty($checks['database']['ok']);

$checks['migrations'] = posmainHealthMigrationCheck();
$healthy = $healthy && !empty($checks['migrations']['ok']);

$checks['writable_paths'] = posmainHealthWritablePaths();
foreach ($checks['writable_paths'] as $pathCheck) {
    $healthy = $healthy && !empty($pathCheck['ok']);
}

if (!$updateScope) {
    $isProduction = !empty($config['production_mode']);
    $role = strtolower(trim((string) ($config['role'] ?? 'branch')));
    $branchConfigured = trim((string) ($config['branch']['uuid'] ?? '')) !== '';
    $branchIdentityRequired = $isProduction && $role !== 'cloud';
    $checks['branch_identity'] = [
        'ok' => !$branchIdentityRequired || $branchConfigured,
        'configured' => $branchConfigured,
        'required' => $branchIdentityRequired,
        'role' => $role,
    ];
    $healthy = $healthy && !empty($checks['branch_identity']['ok']);

    if (!empty($config['sync']['worker_enabled']) || !empty($config['features']['cloud_sync'])) {
        $checks['worker'] = posmainHealthWorkerCheck();
    }

    if ($detailRequested && $tokenOk) {
        try {
            $orderCreationConn = posmain_db_connect();
            $checks['order_creation'] = posmainHealthOrderCreationCheck($orderCreationConn);
            $orderCreationConn->close();
            $healthy = $healthy && !empty($checks['order_creation']['ok']);
        } catch (Throwable $orderCreationError) {
            $checks['order_creation'] = [
                'ok' => false,
                'error' => $orderCreationError->getMessage(),
            ];
            $healthy = false;
        }
    }
}

if ($scope === 'router' && $detailRequested && $tokenOk) {
    $checks['router_shops'] = posmainHealthRouterShopsCheck($config);
    foreach ($checks['router_shops']['shops'] ?? [] as $shopCheck) {
        if (empty($shopCheck['ok'])) {
            $healthy = false;
        }
    }
    if (!empty($checks['router_shops']['error'])) {
        $healthy = false;
    }
}

$payload = [
    'ok' => $healthy,
    'healthy' => $healthy,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'main_auth_mode' => $checks['main_auth']['main_auth_mode'] ?? null,
    'deployment_role' => $checks['main_auth']['deployment_role'] ?? null,
    'pin_secret_ready' => !empty($checks['main_auth']['pin_secret_ready']),
];

if ($detailRequested) {
    $payload['env'] = (string) ($config['env'] ?? '');
    $payload['role'] = (string) ($config['role'] ?? '');
    $payload['production_mode'] = !empty($config['production_mode']);
    $payload['scope'] = $updateScope ? 'update' : 'full';
    $payload['app_version'] = posmainHealthAppVersion();
    $payload['checks'] = $checks;
}

posmainHealthJson($healthy ? 200 : 503, $payload);

function posmainHealthTokenIsValid(): bool
{
    $expected = '';
    try {
        $config = posmain_app_config();
        $expected = trim((string) ($config['status_token'] ?? ''));
    } catch (Throwable $exception) {
        $expected = trim((string) (getenv('POSMAIN_STATUS_TOKEN') ?: ($_ENV['POSMAIN_STATUS_TOKEN'] ?? '')));
    }
    if ($expected === '') {
        return false;
    }

    $provided = (string) ($_SERVER['HTTP_X_POSMAIN_STATUS_TOKEN'] ?? '');
    if ($provided === '') {
        $provided = (string) (getenv('HTTP_X_POSMAIN_STATUS_TOKEN') ?: '');
    }
    if ($provided === '') {
        $provided = (string) ($_GET['token'] ?? '');
    }
    $provided = trim($provided);
    if ($provided === '') {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            $provided = trim($matches[1]);
        }
    }

    return $provided !== '' && hash_equals($expected, $provided);
}

/**
 * Resolve main auth readiness without exposing POSMAIN_PIN_SECRET.
 *
 * @return array{
 *   ok: bool,
 *   main_auth_mode: ?string,
 *   deployment_role: string,
 *   router_enabled: bool,
 *   pin_secret_ready: bool,
 *   error?: string
 * }
 */
function posmainHealthMainAuthCheck(array $config, ?string $configLoadError = null): array
{
    $role = strtolower(trim((string) ($config['role'] ?? 'branch')));
    $routerEnabled = !empty($config['router']['enabled']);
    $check = [
        'ok' => true,
        'main_auth_mode' => null,
        'deployment_role' => $role,
        'router_enabled' => $routerEnabled,
        'pin_secret_ready' => false,
    ];

    if ($configLoadError !== null && $configLoadError !== '') {
        $check['ok'] = false;
        $check['error'] = $configLoadError;
        if ($configLoadError === 'MAIN_AUTH_MODE_UNSAFE') {
            $check['error'] = 'MAIN_AUTH_MODE_UNSAFE';
        }
        // Still report whether the secret is present without revealing it.
        $check['pin_secret_ready'] = posmainHealthPinSecretReady();
        return $check;
    }

    try {
        $mode = function_exists('posmain_main_auth_mode')
            ? posmain_main_auth_mode($config)
            : (string) ($config['auth']['main_login_mode'] ?? '');
        $check['main_auth_mode'] = $mode !== '' ? $mode : null;
    } catch (Throwable $exception) {
        $check['ok'] = false;
        $check['error'] = $exception->getMessage();
        $check['pin_secret_ready'] = posmainHealthPinSecretReady();
        return $check;
    }

    $isHosted = in_array($role, ['cloud', 'fake_cloud'], true) || $routerEnabled;
    $configured = strtolower(trim((string) posmain_env('POSMAIN_MAIN_AUTH_MODE', '', true)));
    if ($configured === 'pin' && $isHosted) {
        $check['ok'] = false;
        $check['error'] = 'MAIN_AUTH_MODE_UNSAFE';
        $check['pin_secret_ready'] = posmainHealthPinSecretReady();
        return $check;
    }

    $pinSecretReady = posmainHealthPinSecretReady();
    $check['pin_secret_ready'] = $pinSecretReady;
    if (($check['main_auth_mode'] ?? '') === 'pin' && !$pinSecretReady) {
        $check['ok'] = false;
        $check['error'] = 'PIN_SECRET_MISSING';
    }

    return $check;
}

function posmainHealthPinSecretReady(): bool
{
    try {
        if (!function_exists('posmain_pin_secret')) {
            return false;
        }
        $secret = posmain_pin_secret();
        return is_string($secret) && trim($secret) !== '';
    } catch (Throwable $exception) {
        return false;
    }
}

function posmainHealthFallbackConfig(): array
{
    $role = strtolower(trim((string) (getenv('POSMAIN_ROLE') ?: ($_ENV['POSMAIN_ROLE'] ?? 'branch'))));
    $env = (string) (getenv('POSMAIN_ENV') ?: ($_ENV['POSMAIN_ENV'] ?? 'local'));
    $routerEnabled = in_array(
        strtolower(trim((string) (getenv('POSMAIN_ROUTER_ENABLED') ?: ($_ENV['POSMAIN_ROUTER_ENABLED'] ?? '0')))),
        ['1', 'true', 'yes', 'on'],
        true
    );

    return [
        'env' => $env,
        'role' => $role !== '' ? $role : 'branch',
        'production_mode' => false,
        'auth' => ['main_login_mode' => null],
        'router' => ['enabled' => $routerEnabled],
        'branch' => ['uuid' => ''],
        'sync' => ['worker_enabled' => false],
        'features' => ['cloud_sync' => false],
        'status_token' => (string) (getenv('POSMAIN_STATUS_TOKEN') ?: ($_ENV['POSMAIN_STATUS_TOKEN'] ?? '')),
    ];
}

function posmainHealthDatabaseCheck(): array
{
    try {
        $conn = posmain_db_connect();
        $row = $conn->query('SELECT DATABASE() AS db_name, VERSION() AS version')->fetch_assoc();
        $conn->close();

        return [
            'ok' => true,
            'database' => (string) ($row['db_name'] ?? ''),
            'version' => (string) ($row['version'] ?? ''),
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'db_unreachable',
            'message' => $e->getMessage(),
        ];
    }
}

function posmainHealthMigrationCheck(): array
{
    try {
        $conn = posmain_db_connect();
        $dbName = (string) ($conn->query('SELECT DATABASE() AS db_name')->fetch_assoc()['db_name'] ?? '');
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'schema_migrations'
        ");
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();

        return [
            'ok' => true,
            'schema_migrations_exists' => ((int) ($row['table_count'] ?? 0)) > 0,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'migration_check_failed',
            'message' => $e->getMessage(),
        ];
    }
}

function posmainHealthWritablePaths(): array
{
    $paths = [
        'logs' => __DIR__ . '/../logs',
        'uploads' => __DIR__ . '/../uploads',
    ];
    $checks = [];

    foreach ($paths as $name => $path) {
        $checks[$name] = [
            'ok' => is_dir($path) && is_writable($path),
            'path' => $path,
        ];
    }

    return $checks;
}

function posmainHealthWorkerCheck(): array
{
    try {
        $conn = posmain_db_connect();
        $exists = $conn->query("SHOW TABLES LIKE 'sync_worker_logs'")->num_rows > 0;
        if (!$exists) {
            $conn->close();
            return ['ok' => true, 'configured' => false, 'message' => 'sync_worker_logs table not present'];
        }

        $result = $conn->query("SELECT worker_name, status, created_at FROM sync_worker_logs ORDER BY id DESC LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        $conn->close();

        return [
            'ok' => true,
            'configured' => true,
            'last_worker_log' => $row ?: null,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'worker_check_failed',
            'message' => $e->getMessage(),
        ];
    }
}

function posmainHealthAppVersion(): array
{
    $headFile = __DIR__ . '/../.git/HEAD';
    if (!is_file($headFile)) {
        return ['git' => null];
    }

    $head = trim((string) file_get_contents($headFile));
    if (strpos($head, 'ref: ') === 0) {
        $ref = trim(substr($head, 5));
        $refFile = __DIR__ . '/../.git/' . $ref;
        return [
            'ref' => $ref,
            'head' => is_file($refFile) ? trim((string) file_get_contents($refFile)) : null,
        ];
    }

    return ['head' => $head];
}

function posmainHealthRouterShopsCheck(array $config): array
{
    if (empty($config['router']['enabled'])) {
        return ['ok' => true, 'enabled' => false, 'shops' => []];
    }

    require_once __DIR__ . '/../classes/Router/ShopRouter.php';
    $routerDb = (array) ($config['router']['database'] ?? []);
    if (trim((string) ($routerDb['name'] ?? '')) === '') {
        return ['ok' => false, 'enabled' => true, 'error' => 'router_database_not_configured', 'shops' => []];
    }

    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $routerConn = new mysqli(
            (string) ($routerDb['host'] ?? '127.0.0.1'),
            (string) ($routerDb['user'] ?? ''),
            (string) ($routerDb['pass'] ?? ''),
            (string) ($routerDb['name'] ?? ''),
            (int) ($routerDb['port'] ?? 3306)
        );
        $router = new ShopRouter();
        $shops = [];
        foreach ($router->listActiveShops($routerConn) as $shop) {
            $dbName = trim((string) ($shop['db_name'] ?? ''));
            if ($dbName === '') {
                continue;
            }
            $shopOk = true;
            $detail = ['slug' => (string) ($shop['slug'] ?? ''), 'db_name' => $dbName];
            try {
                $validation = $router->validateShopConnection($routerConn, (int) ($shop['id'] ?? 0));
                $detail['database'] = (string) ($validation['database'] ?? '');
                $shopOk = !empty($validation['ok']);
            } catch (Throwable $exception) {
                $shopOk = false;
                $detail['error'] = $exception->getMessage();
            }
            $detail['ok'] = $shopOk;
            $shops[] = $detail;
        }
        $routerConn->close();

        return [
            'ok' => $shops !== [] && !in_array(false, array_column($shops, 'ok'), true),
            'enabled' => true,
            'shop_count' => count($shops),
            'shops' => $shops,
        ];
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'enabled' => true,
            'error' => $exception->getMessage(),
            'shops' => [],
        ];
    }
}

function posmainHealthOrderCreationCheck(mysqli $conn): array
{
    $requiredTables = ['ot_head', 'fat_details', 'pos_request_keys', 'sync_outbox'];
    $optionalTables = ['order_events', 'order_line_notes', 'order_line_modifiers'];
    $missing = [];
    $optionalMissing = [];

    foreach ($requiredTables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if (!$result || $result->num_rows === 0) {
            $missing[] = $table;
        }
    }
    foreach ($optionalTables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if (!$result || $result->num_rows === 0) {
            $optionalMissing[] = $table;
        }
    }

    return [
        'ok' => $missing === [],
        'required_tables' => $requiredTables,
        'missing_required' => $missing,
        'missing_optional' => $optionalMissing,
        'order_side_effect_mode' => class_exists('SideEffectPolicy') ? SideEffectPolicy::mode() : 'shadow',
    ];
}

function posmainHealthJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
