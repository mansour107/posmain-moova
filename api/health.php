<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';

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

$config = posmain_app_config();
$checks = [];
$healthy = true;

$checks['database'] = posmainHealthDatabaseCheck();
$healthy = $healthy && !empty($checks['database']['ok']);

$checks['migrations'] = posmainHealthMigrationCheck();
$healthy = $healthy && !empty($checks['migrations']['ok']);

$checks['writable_paths'] = posmainHealthWritablePaths();
foreach ($checks['writable_paths'] as $pathCheck) {
    $healthy = $healthy && !empty($pathCheck['ok']);
}

$isProduction = !empty($config['production_mode']);
$branchConfigured = trim((string) ($config['branch']['uuid'] ?? '')) !== '';
$checks['branch_identity'] = [
    'ok' => !$isProduction || $branchConfigured,
    'configured' => $branchConfigured,
];
$healthy = $healthy && !empty($checks['branch_identity']['ok']);

if (!empty($config['sync']['worker_enabled']) || !empty($config['features']['cloud_sync'])) {
    $checks['worker'] = posmainHealthWorkerCheck();
}

$payload = [
    'ok' => $healthy,
    'healthy' => $healthy,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
];

if ($detailRequested) {
    $payload['env'] = (string) ($config['env'] ?? '');
    $payload['role'] = (string) ($config['role'] ?? '');
    $payload['production_mode'] = !empty($config['production_mode']);
    $payload['app_version'] = posmainHealthAppVersion();
    $payload['checks'] = $checks;
}

posmainHealthJson($healthy ? 200 : 503, $payload);

function posmainHealthTokenIsValid(): bool
{
    $config = posmain_app_config();
    $expected = trim((string) ($config['status_token'] ?? ''));
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

function posmainHealthJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
