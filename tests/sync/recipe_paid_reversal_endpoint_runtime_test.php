<?php

if (($argv[1] ?? '') === '--child') {
    recipePaidReversalEndpointRuntimeChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_paid_reversal_endpoint_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipePaidReversalEndpointRuntimeCreateSchema($conn);
    recipePaidReversalEndpointRuntimeSeedCommonRows($conn);

    recipePaidReversalEndpointRuntimeSeedOrder($conn, 701, 'takeaway');
    $refund = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 701,
        'action' => 'refund',
        'refund_stock_policy' => 'return_to_stock',
        'reason' => 'endpoint smoke refund',
        'idempotency_key' => 'recipe-endpoint-refund-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(($refund['success'] ?? false) === true, 'refund endpoint should succeed');
    recipePaidReversalEndpointRuntimeAssert(($refund['data']['payment_status'] ?? '') === 'refunded', 'refund endpoint should return refunded status');
    recipePaidReversalEndpointRuntimeAssert(($refund['data']['recipe']['noop'] ?? null) === true, 'recipe lifecycle should stay no-op while recipe flags are off');

    $refundedOrder = $conn->query('SELECT * FROM ot_head WHERE id = 701')->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert($refundedOrder['payment_status'] === 'refunded', 'refund endpoint should mutate only the temp order');
    recipePaidReversalEndpointRuntimeAssert((int) $refundedOrder['isdeleted'] === 0, 'refund should keep the temp order visible for audit');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 701 AND event_type = 'order.refunded'")->fetch_assoc()['c'] === 1,
        'refund endpoint should write one order event'
    );

    $refundReplay = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 701,
        'action' => 'refund',
        'refund_stock_policy' => 'return_to_stock',
        'reason' => 'endpoint smoke refund',
        'idempotency_key' => 'recipe-endpoint-refund-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(($refundReplay['success'] ?? false) === true, 'refund idempotency replay should return completed response');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 701 AND event_type = 'order.refunded'")->fetch_assoc()['c'] === 1,
        'refund idempotency replay should not write a second order event'
    );

    recipePaidReversalEndpointRuntimeSeedOrder($conn, 702, 'table');
    $void = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 702,
        'action' => 'void',
        'refund_stock_policy' => 'waste',
        'reason' => 'endpoint smoke void',
        'idempotency_key' => 'recipe-endpoint-void-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(($void['success'] ?? false) === true, 'void endpoint should succeed');
    recipePaidReversalEndpointRuntimeAssert(($void['data']['payment_status'] ?? '') === 'voided', 'void endpoint should return voided status');

    $voidedOrder = $conn->query('SELECT * FROM ot_head WHERE id = 702')->fetch_assoc();
    $table = $conn->query('SELECT * FROM tables WHERE id = 12')->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert($voidedOrder['payment_status'] === 'voided', 'void endpoint should mark the temp order voided');
    recipePaidReversalEndpointRuntimeAssert((int) $voidedOrder['isdeleted'] === 1, 'void endpoint should hide the temp order from active lists');
    recipePaidReversalEndpointRuntimeAssert((int) $table['table_case'] === 0, 'void endpoint should free the temp table');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 702 AND event_type = 'order.voided'")->fetch_assoc()['c'] === 1,
        'void endpoint should write one order event'
    );

    echo "recipe-paid-reversal-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipePaidReversalEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'recipe-endpoint-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'ajax/refund_order.php';
    $_SERVER['SCRIPT_NAME'] = 'ajax/refund_order.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    $_POST = [
        'order_id' => (int) ($payload['order_id'] ?? 0),
        'action' => (string) ($payload['action'] ?? ''),
        'refund_stock_policy' => (string) ($payload['refund_stock_policy'] ?? ''),
        'reason' => (string) ($payload['reason'] ?? ''),
        'idempotency_key' => (string) ($payload['idempotency_key'] ?? ''),
        'csrf_token' => $csrf,
    ];

    session_id('recipeendpoint' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'endpoint_smoke';
    $_SESSION['userid'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['posmain_csrf_tokens'] = [
        'pos_browser' => $csrf,
    ];

    chdir(dirname(__DIR__, 2) . '/ajax');
    require dirname(__DIR__, 2) . '/ajax/refund_order.php';
    exit(0);
}

function recipePaidReversalEndpointRuntimeRunChild(string $db, array $payload): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_ENABLE_RECIPES' => '0',
        'POSMAIN_RECIPE_MODE' => 'off',
        'POSMAIN_ROUTER_ENABLED' => '0',
        'POSMAIN_REQUIRE_MANAGER_APPROVAL' => '0',
    ]);
    $command = [
        PHP_BINARY,
        __FILE__,
        '--child',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start paid reversal endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Paid reversal endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Paid reversal endpoint child did not return JSON: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function recipePaidReversalEndpointRuntimeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (lang, edit_pass) VALUES ('ar', '')");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(191) NOT NULL,
            password VARCHAR(255) NULL,
            userrole INT NULL,
            usertype INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            edit_payment TINYINT(1) NOT NULL DEFAULT 0,
            delete_payment TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_id VARCHAR(80) NULL,
            table_id INT NULL,
            pro_tybe INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            fat_net DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            remaining_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL,
            updated_by INT NULL,
            crtime DATETIME NULL,
            mdtime DATETIME NULL,
            pro_date DATE NULL,
            completed_at DATETIME NULL,
            acc1 INT NULL,
            info TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            item_id INT NOT NULL,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            det_store INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(191) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE pos_request_keys (
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
        CREATE TABLE order_events (
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
}

function recipePaidReversalEndpointRuntimeSeedCommonRows(mysqli $conn): void
{
    $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (1, 'endpoint_smoke', '', 1, 2, 0)");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, edit_payment, delete_payment, isdeleted) VALUES (1, 'admin', 1, 1, 0)");
}

function recipePaidReversalEndpointRuntimeSeedOrder(mysqli $conn, int $orderId, string $orderType): void
{
    $tableId = $orderType === 'table' ? 12 : 0;
    if ($tableId > 0) {
        $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES ({$tableId}, 'Endpoint Smoke Table', 1, 0) ON DUPLICATE KEY UPDATE table_case = 1");
    }

    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, pro_tybe, order_type, payment_status, invoice_status,
            order_status, fat_net, paid_amount, remaining_amount, isdeleted, crtime, mdtime, pro_date
        ) VALUES (
            {$orderId}, 'EP-{$orderId}', {$tableId}, 9, '{$orderType}', 'paid', 'completed',
            'completed', 20.00, 20.00, 0.00, 0, NOW(), NOW(), CURDATE()
        )
    ");
    $detailId = $orderId + 1000;
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, u_val, det_store, isdeleted)
        VALUES ({$detailId}, {$orderId}, 3001, 0.000000, 2.000000, 1.000000, 0, 0)
    ");
}

function recipePaidReversalEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
