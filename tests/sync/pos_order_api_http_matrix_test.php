<?php

require_once __DIR__ . '/pos_takeaway_invoice_handler_test.php';

if (($argv[1] ?? '') === '--child') {
    posOrderApiHttpMatrixChild($argv[2] ?? '');
    exit(0);
}

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_order_api_matrix_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-order-api-http-matrix-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayInvoiceCreateSchema($conn);
    posTakeawayInvoiceSeedFixtures($conn);
    posOrderApiHttpMatrixCreateSupportTables($conn);

    $savePayload = posOrderApiHttpMatrixSavePayload();
    posOrderApiHttpMatrixAssert(strpos(file_get_contents(dirname(__DIR__, 2) . '/includes/pos_api_dispatch.php'), 'require_csrf') !== false, 'dispatch should require CSRF for browser routes');

    $missingKey = posOrderApiHttpMatrixRunChild($db, 'orders.takeaway', posOrderApiHttpMatrixSavePayload(['idempotency_key' => '']), true);
    posOrderApiHttpMatrixAssert(($missingKey['body']['code'] ?? '') === 'IDEMPOTENCY_REQUIRED', 'missing idempotency key should be rejected');

    $csrfBlocked = posOrderApiHttpMatrixRunChild($db, 'orders.takeaway', $savePayload, false);
    posOrderApiHttpMatrixAssert(($csrfBlocked['http_status'] ?? 0) === 403, 'missing CSRF should return 403');
    posOrderApiHttpMatrixAssert(($csrfBlocked['body']['code'] ?? '') === 'CSRF_INVALID', 'missing CSRF should be flagged');

    $created = posOrderApiHttpMatrixRunChild($db, 'orders.takeaway', $savePayload, true);
    posOrderApiHttpMatrixAssert(($created['http_status'] ?? 0) === 200, 'takeaway save should return 200: ' . json_encode($created['body'] ?? [], JSON_UNESCAPED_UNICODE));
    posOrderApiHttpMatrixAssert(($created['body']['success'] ?? false) === true, 'takeaway save should succeed');
    posOrderApiHttpMatrixAssert((int) ($created['body']['order_id'] ?? 0) > 0, 'takeaway save should return order id');

    $replay = posOrderApiHttpMatrixRunChild($db, 'orders.takeaway', $savePayload, true);
    posOrderApiHttpMatrixAssert(($replay['http_status'] ?? 0) === 200, 'idempotency replay should return 200');
    posOrderApiHttpMatrixAssert(!empty($replay['body']['idempotency_replayed']), 'idempotency replay should be flagged');
    posOrderApiHttpMatrixAssert((int) $conn->query("SELECT COUNT(*) AS c FROM ot_head WHERE pro_tybe = 9 AND op2 IS NULL")->fetch_assoc()['c'] === 1, 'replay should not create duplicate order');

    $conflictPayload = $savePayload;
    $conflictPayload['pro_serial'] = 'API-MATRIX-SAVE-CONFLICT';
    $conflict = posOrderApiHttpMatrixRunChild($db, 'orders.takeaway', $conflictPayload, true);
    posOrderApiHttpMatrixAssert(($conflict['http_status'] ?? 0) === 409, 'conflicting payload should return 409');
    posOrderApiHttpMatrixAssert(($conflict['body']['code'] ?? '') === 'IDEMPOTENCY_CONFLICT', 'conflict code should be set');

    echo "pos-order-api-http-matrix-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posOrderApiHttpMatrixCreateSupportTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS pos_request_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(80) NOT NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            user_id BIGINT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            status ENUM('processing','completed','failed','voided') NOT NULL DEFAULT 'processing',
            response_json JSON NULL,
            error_code VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_scope_key (scope, idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS order_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            event_source VARCHAR(80) NOT NULL,
            actor_user_id BIGINT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            before_state_json JSON NULL,
            after_state_json JSON NULL,
            metadata_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order_created (order_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS sync_outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            aggregate_type VARCHAR(80) NOT NULL,
            aggregate_id BIGINT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            payload_json JSON NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function posOrderApiHttpMatrixSavePayload(array $overrides = []): array
{
    return array_merge([
        'submit' => 'save',
        'submit_action' => 'save',
        'age' => '1',
        'store_id' => '3',
        'pro_serial' => 'API-MATRIX-SAVE-1',
        'pro_date' => '2026-06-28',
        'accural_date' => '2026-06-28',
        'acc2_id' => '501',
        'emp_id' => '4',
        'fund_id' => '51',
        'headtotal' => '20',
        'headdisc' => '0',
        'headplus' => '0',
        'headnet' => '20',
        'paid_cash' => '0',
        'paid_bank' => '0',
        'itmname' => ['10'],
        'itmqty' => ['2'],
        'itmprice' => ['10'],
        'itmdisc' => ['0'],
        'itmnote' => [''],
        'idempotency_key' => 'api-matrix-save-' . getmypid(),
    ], $overrides);
}

function posOrderApiHttpMatrixRunChild(string $db, string $route, array $payload, bool $withCsrf): array
{
    $encoded = base64_encode(json_encode([
        'db' => $db,
        'route' => $route,
        'payload' => $payload,
        'with_csrf' => $withCsrf,
    ], JSON_UNESCAPED_UNICODE));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($encoded) . ' 2>&1';
    $lines = [];
    exec($command, $lines, $exitCode);
    posOrderApiHttpMatrixAssert($exitCode === 0, "child failed:\n" . implode("\n", $lines));
    $raw = implode("\n", $lines);
    $jsonStart = strrpos($raw, '{"http_status"');
    if ($jsonStart === false) {
        $jsonStart = strrpos($raw, '{"success"');
    }
    posOrderApiHttpMatrixAssert($jsonStart !== false, 'child should return JSON: ' . $raw);
    $decoded = json_decode(substr($raw, $jsonStart), true);
    posOrderApiHttpMatrixAssert(is_array($decoded), 'child should return JSON');

    if (!isset($decoded['http_status']) && ($decoded['code'] ?? '') === 'CSRF_INVALID') {
        return [
            'http_status' => 403,
            'body' => $decoded,
        ];
    }

    return $decoded;
}

function posOrderApiHttpMatrixChild(string $encoded): void
{
    $request = json_decode(base64_decode($encoded, true) ?: '', true);
    if (!is_array($request)) {
        fwrite(STDERR, "invalid child request\n");
        exit(9);
    }

    putenv('POSMAIN_ENV=test');
    putenv('POSMAIN_PRODUCTION_MODE=0');
    putenv('POSMAIN_REQUIRE_OPEN_SHIFT=0');
    putenv('POSMAIN_RECIPE_MODE=off');
    putenv('POSMAIN_DB_HOST=' . (getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1'));
    putenv('POSMAIN_DB_PORT=' . (getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307'));
    putenv('POSMAIN_DB_USER=' . (getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root'));
    putenv('POSMAIN_DB_PASS=' . (getenv('POSMAIN_TEST_MYSQL_PASS') ?: ''));
    putenv('POSMAIN_DB_NAME=' . (string) ($request['db'] ?? ''));
    putenv('POSMAIN_SESSION_DRIVER=file');

    $csrf = 'api-matrix-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET['route'] = (string) ($request['route'] ?? 'orders.takeaway');
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    if (!empty($request['with_csrf'])) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
        $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    }

    $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id('apimatrix' . getmypid());
    session_start();
    $_SESSION['login'] = 'api_matrix_smoke';
    $_SESSION['userid'] = 7;
    $_SESSION['user_id'] = 7;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['pos_authenticated'] = true;
    $_SESSION['pos_user_id'] = 7;
    $_SESSION['posmain_csrf_tokens'] = ['pos_browser' => $csrf];

    require_once dirname(__DIR__, 2) . '/includes/db_bootstrap.php';
    require_once dirname(__DIR__, 2) . '/includes/connect.php';
    require_once dirname(__DIR__, 2) . '/includes/auth_guard.php';
    require_once dirname(__DIR__, 2) . '/includes/csrf.php';
    require_once dirname(__DIR__, 2) . '/includes/pos_api_dispatch.php';
    require_once dirname(__DIR__, 2) . '/classes/Pos/Http/PosRequest.php';

    $conn = posmain_db_connect();
    $route = (string) ($_GET['route'] ?? '');

    try {
        $result = pos_api_dispatch($conn, $route, [
            'request' => new PosRequest($payload, $_SERVER),
        ]);
        echo json_encode([
            'http_status' => (int) ($result['http_status'] ?? 200),
            'body' => $result['payload'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $mapped = pos_api_dispatch_exception_payload($e, $route);
        echo json_encode([
            'http_status' => (int) ($mapped['http_status'] ?? 500),
            'body' => $mapped['payload'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
    }
    exit(0);
}

function posOrderApiHttpMatrixAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
