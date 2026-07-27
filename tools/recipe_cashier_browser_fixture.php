<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../tests/sync/pos_takeaway_invoice_handler_test.php';

$options = getopt('', [
    'json',
    'smoke',
    'help',
]);

if (isset($options['help'])) {
    recipeCashierBrowserFixtureUsage();
    exit(0);
}

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cashier_browser_' . getmypid();
$root = dirname(__DIR__);
$sessionDir = sys_get_temp_dir() . '/posmain-cashier-browser-session-' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);
$server = null;
$cleaned = false;

register_shutdown_function(function () use (&$server, &$conn, &$db, &$sessionDir, &$cleaned): void {
    recipeCashierBrowserFixtureCleanup($server, $conn, $db, $sessionDir, $cleaned);
});
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function (): void {
        exit(0);
    });
    pcntl_signal(SIGINT, static function (): void {
        exit(0);
    });
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayInvoiceCreateSchema($conn);
    posTakeawayInvoiceSeedFixtures($conn);
    recipeCashierBrowserFixtureExtendSchema($conn);
    posTakeawayInvoiceSeedRecipe($conn);

    $server = posTakeawayInvoiceStartServer($root, $db, $sessionDir, $host, $port, $user, $pass);
    $baseUrl = preg_replace('#/do/doadd_invoice\.php$#', '', (string) $server['url']);
    $result = [
        'ok' => true,
        'db' => $db,
        'base_url' => $baseUrl,
        'pos_url' => $baseUrl . '/pos_barcode.php',
        'cookie' => 'PHPSESSID=' . $server['session_id'],
        'csrf_token' => 'takeaway-http-csrf-fixed',
        'pilot_item_id' => 10,
        'ingredient_item_id' => 12,
        'ingredient_start_qty' => '10.000000',
        'read_only' => false,
        'local_temp_db_only' => true,
    ];

    if (isset($options['smoke'])) {
        $page = recipeCashierBrowserFixtureFetch($result['pos_url'], $result['cookie']);
        $result['smoke'] = recipeCashierBrowserFixtureAssessPosPage($page);
        $result['paid_reversal_smoke'] = recipeCashierBrowserFixtureAssessPaidReversalSurface($conn, $baseUrl, $result['cookie'], $result['csrf_token']);
        $result['ok'] = !empty($result['smoke']['ok']);
        if (empty($result['paid_reversal_smoke']['ok'])) {
            $result['ok'] = false;
        }
        recipeCashierBrowserFixtureOutput($result, isset($options['json']));
        exit($result['ok'] ? 0 : 2);
    }

    recipeCashierBrowserFixtureOutput($result + [
        'message' => 'Fixture is running. Press Ctrl-C or close stdin to stop and drop the temp DB.',
    ], isset($options['json']));

    while (!feof(STDIN)) {
        sleep(1);
    }
} finally {
    recipeCashierBrowserFixtureCleanup($server, $conn, $db, $sessionDir, $cleaned);
}

function recipeCashierBrowserFixtureUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_cashier_browser_fixture.php [--json] [--smoke]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Starts an isolated temp POS runtime for browser cashier recipe QA.\n");
    fwrite(STDOUT, "The fixture creates a temp database, seeds a logged-in cashier session, enables recipe consume_pilot only for item 10, and drops the database on exit.\n");
}

function recipeCashierBrowserFixtureExtendSchema(mysqli $conn): void
{
    $conn->query("ALTER TABLE acc_head ADD COLUMN parent_id INT NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE acc_head ADD COLUMN is_basic TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE acc_head ADD COLUMN is_stock TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE acc_head ADD COLUMN is_fund TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE usr_pwrs ADD COLUMN isdeleted TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE usr_pwrs ADD COLUMN edit_payment TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE usr_pwrs ADD COLUMN delete_payment TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE myitems ADD COLUMN group1 INT NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE myitems ADD COLUMN info TEXT NULL");
    $conn->query("ALTER TABLE myitems ADD COLUMN salesqty DECIMAL(15,4) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE settings ADD COLUMN def_pos_store INT NULL");
    $conn->query("ALTER TABLE settings ADD COLUMN def_pos_employee INT NULL");
    $conn->query("ALTER TABLE settings ADD COLUMN def_pos_fund INT NULL");
    $conn->query("ALTER TABLE ot_head ADD COLUMN cancelled_at DATETIME NULL");
    $conn->query("ALTER TABLE ot_head ADD COLUMN cancelled_by INT NULL");
    $conn->query("ALTER TABLE ot_head ADD COLUMN cancellation_reason VARCHAR(255) NULL");
    $conn->query("ALTER TABLE ot_head ADD COLUMN updated_by INT NULL");
    $conn->query("
        CREATE TABLE item_group (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            gname VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE imgs (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            itemid INT NOT NULL,
            iname VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_imgs_itemid (itemid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(255) NULL,
            password VARCHAR(255) NULL,
            userrole INT NOT NULL DEFAULT 1,
            usertype INT NOT NULL DEFAULT 2,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE session_time (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("UPDATE acc_head SET is_fund = 1 WHERE id IN (51, 61)");
    $conn->query("UPDATE acc_head SET is_stock = 1 WHERE id = 3");
    $conn->query("UPDATE acc_head SET parent_id = 35 WHERE id = 4");
    $conn->query("UPDATE settings SET def_pos_store = 3, def_pos_employee = 4, def_pos_fund = 51, def_pos_client = 501 WHERE id = 1");
    $conn->query("UPDATE myitems SET group1 = 1, salesqty = CASE id WHEN 10 THEN 20 WHEN 11 THEN 10 ELSE 0 END");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, isdeleted, parent_id, is_basic, is_stock, is_fund) VALUES
            (3, '130001', 'Main Store', 0, 0, 0, 1, 0),
            (4, '350001', 'Cashier Employee', 0, 35, 0, 0, 0)
    ");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'QA Table 1', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id,
            pro_id,
            pro_tybe,
            pro_serial,
            pro_date,
            acc1,
            acc2,
            store_id,
            emp_id,
            table_id,
            order_type,
            payment_status,
            invoice_status,
            order_status,
            fat_total,
            fat_net,
            paid_amount,
            remaining_amount,
            isdeleted,
            crtime,
            mdtime,
            completed_at,
            info
        ) VALUES (
            9001,
            9001,
            9,
            'REVERSAL-FIXTURE-9001',
            CURRENT_DATE,
            501,
            501,
            3,
            4,
            0,
            'takeaway',
            'paid',
            'completed',
            'completed',
            10.0000,
            10.0000,
            10.0000,
            0.0000,
            0,
            NOW(),
            NOW(),
            NOW(),
            'paid reversal fixture order'
        )
    ");
    $conn->query("
        INSERT INTO ot_head (
            id,
            pro_id,
            pro_tybe,
            pro_serial,
            pro_date,
            acc1,
            acc2,
            store_id,
            emp_id,
            table_id,
            order_type,
            payment_status,
            invoice_status,
            order_status,
            fat_total,
            fat_net,
            paid_amount,
            remaining_amount,
            isdeleted,
            crtime,
            mdtime,
            completed_at,
            info
        ) VALUES (
            9002,
            9002,
            9,
            'VOID-FIXTURE-9002',
            CURRENT_DATE,
            501,
            501,
            3,
            4,
            1,
            'table',
            'paid',
            'completed',
            'completed',
            10.0000,
            10.0000,
            10.0000,
            0.0000,
            0,
            NOW(),
            NOW(),
            NOW(),
            'paid void fixture order'
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id,
            pro_tybe,
            pro_id,
            item_id,
            u_val,
            qty_in,
            qty_out,
            price,
            discount,
            det_value,
            fatid,
            fat_tybe,
            det_store,
            cost_price,
            profit,
            tenant,
            branch,
            isdeleted
        ) VALUES (
            9001,
            9,
            9001,
            10,
            1.0000,
            0.0000,
            1.0000,
            10.0000,
            0.0000,
            10.0000,
            9001,
            9,
            3,
            4.0000,
            6.0000,
            0,
            0,
            0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id,
            pro_tybe,
            pro_id,
            item_id,
            u_val,
            qty_in,
            qty_out,
            price,
            discount,
            det_value,
            fatid,
            fat_tybe,
            det_store,
            cost_price,
            profit,
            tenant,
            branch,
            isdeleted
        ) VALUES (
            9002,
            9,
            9002,
            10,
            1.0000,
            0.0000,
            1.0000,
            10.0000,
            0.0000,
            10.0000,
            9002,
            9,
            3,
            4.0000,
            6.0000,
            0,
            0,
            0
        )
    ");
    $conn->query("INSERT INTO item_group (id, gname, isdeleted) VALUES (1, 'QA Menu', 0)");
    $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (7, 'fixture-cashier', '" . md5('1234') . "', 1, 2, 0)");
}

function recipeCashierBrowserFixtureCleanup(?array &$server, mysqli &$conn, string $db, string $sessionDir, bool &$cleaned): void
{
    if ($cleaned) {
        return;
    }
    $cleaned = true;

    if (is_array($server)) {
        posTakeawayInvoiceStopServer($server);
        $server = null;
    }
    try {
        $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    } catch (Throwable $exception) {
    }
    try {
        $conn->close();
    } catch (Throwable $exception) {
    }
    posTakeawayInvoiceRemoveDir($sessionDir);
}

function recipeCashierBrowserFixtureFetch(string $url, string $cookie): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'Accept: text/html,application/xhtml+xml',
                'Cookie: ' . $cookie,
            ]) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
}

function recipeCashierBrowserFixturePost(string $url, string $cookie, array $post, string $csrf): array
{
    $post['csrf_token'] = $csrf;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Cookie: ' . $cookie,
                'X-CSRF-Token: ' . $csrf,
                'X-POSMAIN-CSRF-Token: ' . $csrf,
                'X-Requested-With: XMLHttpRequest',
            ]) . "\r\n",
            'content' => http_build_query($post),
            'ignore_errors' => true,
            'timeout' => 10,
            'follow_location' => 0,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
}

function recipeCashierBrowserFixtureAssessPaidReversalSurface(mysqli $conn, string $baseUrl, string $cookie, string $csrf): array
{
    $recent = recipeCashierBrowserFixtureFetch($baseUrl . '/ajax/get_recent_orders.php', $cookie);
    $recentBody = (string) ($recent['body'] ?? '');
    $recentPayload = json_decode($recentBody, true);
    $orders = is_array($recentPayload['orders'] ?? null) ? $recentPayload['orders'] : [];
    $paidReversibleOrderSeen = false;
    $missingCapabilityFields = [];
    foreach ($orders as $index => $order) {
        if (!is_array($order)) {
            continue;
        }
        foreach (['can_refund', 'can_void', 'payment_status', 'order_status'] as $field) {
            if (!array_key_exists($field, $order)) {
                $missingCapabilityFields[] = 'orders[' . $index . '].' . $field;
            }
        }
        if (!empty($order['can_refund']) || !empty($order['can_void'])) {
            $paidReversibleOrderSeen = true;
        }
    }

    $methodGuard = recipeCashierBrowserFixtureFetch($baseUrl . '/ajax/refund_order.php', $cookie);
    $methodGuardBody = (string) ($methodGuard['body'] ?? '');
    $methodGuardPayload = json_decode($methodGuardBody, true);
    $methodGuardOk = (int) ($methodGuard['status'] ?? 0) === 405
        || (is_array($methodGuardPayload) && (string) ($methodGuardPayload['code'] ?? '') === 'METHOD_NOT_ALLOWED');

    $refundPayload = [
        'order_id' => 9001,
        'action' => 'refund',
        'refund_stock_policy' => 'return_to_stock',
        'refund_payment_method' => 'cash',
        'reason' => 'fixture HTTP refund smoke',
        'idempotency_key' => 'fixture-http-refund-9001-fixed',
    ];
    $refund = recipeCashierBrowserFixturePost($baseUrl . '/ajax/refund_order.php', $cookie, $refundPayload, $csrf);
    $refundDecoded = json_decode((string) ($refund['body'] ?? ''), true);
    $refundReplay = recipeCashierBrowserFixturePost($baseUrl . '/ajax/refund_order.php', $cookie, $refundPayload, $csrf);
    $refundReplayDecoded = json_decode((string) ($refundReplay['body'] ?? ''), true);

    $refundedOrder = $conn->query("SELECT payment_status, invoice_status, order_status, isdeleted FROM ot_head WHERE id = 9001")->fetch_assoc();
    $refundEventCount = (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 9001 AND event_type = 'order.refunded'")->fetch_assoc()['c'];
    $idempotencyCount = (int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.order.refund' AND idempotency_key = 'fixture-http-refund-9001-fixed' AND status = 'completed'")->fetch_assoc()['c'];
    $refundMutationOk = (int) ($refund['status'] ?? 0) === 200
        && is_array($refundDecoded)
        && ($refundDecoded['success'] ?? false) === true
        && (string) ($refundDecoded['data']['payment_status'] ?? '') === 'refunded'
        && (int) ($refundReplay['status'] ?? 0) === 200
        && is_array($refundReplayDecoded)
        && ($refundReplayDecoded['success'] ?? false) === true
        && (string) ($refundReplayDecoded['request_id'] ?? '') === 'fixture-http-refund-9001-fixed'
        && is_array($refundedOrder)
        && (string) ($refundedOrder['payment_status'] ?? '') === 'refunded'
        && (string) ($refundedOrder['invoice_status'] ?? '') === 'cancelled'
        && (string) ($refundedOrder['order_status'] ?? '') === 'cancelled'
        && (int) ($refundedOrder['isdeleted'] ?? 1) === 0
        && $refundEventCount === 1
        && $idempotencyCount === 1;

    $voidPayload = [
        'order_id' => 9002,
        'action' => 'void',
        'refund_stock_policy' => 'waste',
        'reason' => 'fixture HTTP void smoke',
        'idempotency_key' => 'fixture-http-void-9002-fixed',
    ];
    $void = recipeCashierBrowserFixturePost($baseUrl . '/ajax/refund_order.php', $cookie, $voidPayload, $csrf);
    $voidDecoded = json_decode((string) ($void['body'] ?? ''), true);
    $voidReplay = recipeCashierBrowserFixturePost($baseUrl . '/ajax/refund_order.php', $cookie, $voidPayload, $csrf);
    $voidReplayDecoded = json_decode((string) ($voidReplay['body'] ?? ''), true);
    $voidedOrder = $conn->query("SELECT payment_status, invoice_status, order_status, isdeleted, table_id FROM ot_head WHERE id = 9002")->fetch_assoc();
    $voidTable = $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc();
    $voidEventCount = (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 9002 AND event_type = 'order.voided'")->fetch_assoc()['c'];
    $voidIdempotencyCount = (int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.order.void' AND idempotency_key = 'fixture-http-void-9002-fixed' AND status = 'completed'")->fetch_assoc()['c'];
    $voidMutationOk = (int) ($void['status'] ?? 0) === 200
        && is_array($voidDecoded)
        && ($voidDecoded['success'] ?? false) === true
        && (string) ($voidDecoded['data']['payment_status'] ?? '') === 'voided'
        && (int) ($voidReplay['status'] ?? 0) === 200
        && is_array($voidReplayDecoded)
        && ($voidReplayDecoded['success'] ?? false) === true
        && (string) ($voidReplayDecoded['request_id'] ?? '') === 'fixture-http-void-9002-fixed'
        && is_array($voidedOrder)
        && (string) ($voidedOrder['payment_status'] ?? '') === 'voided'
        && (string) ($voidedOrder['invoice_status'] ?? '') === 'cancelled'
        && (string) ($voidedOrder['order_status'] ?? '') === 'cancelled'
        && (int) ($voidedOrder['isdeleted'] ?? 0) === 1
        && (int) ($voidedOrder['table_id'] ?? 0) === 1
        && is_array($voidTable)
        && (int) ($voidTable['table_case'] ?? 1) === 0
        && $voidEventCount === 1
        && $voidIdempotencyCount === 1;

    return [
        'ok' => is_array($recentPayload)
            && ($recentPayload['success'] ?? false) === true
            && $paidReversibleOrderSeen
            && $missingCapabilityFields === []
            && $methodGuardOk
            && $refundMutationOk
            && $voidMutationOk,
        'recent_orders_status' => (int) ($recent['status'] ?? 0),
        'recent_orders_count' => count($orders),
        'paid_reversible_order_seen' => $paidReversibleOrderSeen,
        'missing_capability_fields' => $missingCapabilityFields,
        'method_guard_status' => (int) ($methodGuard['status'] ?? 0),
        'method_guard_code' => is_array($methodGuardPayload) ? (string) ($methodGuardPayload['code'] ?? '') : '',
        'method_guard_ok' => $methodGuardOk,
        'refund_post_status' => (int) ($refund['status'] ?? 0),
        'refund_post_success' => is_array($refundDecoded) && ($refundDecoded['success'] ?? false) === true,
        'refund_post_code' => is_array($refundDecoded) ? (string) ($refundDecoded['code'] ?? '') : '',
        'refund_post_message' => is_array($refundDecoded) ? (string) ($refundDecoded['message'] ?? '') : '',
        'refund_payment_status' => is_array($refundDecoded) ? (string) ($refundDecoded['data']['payment_status'] ?? '') : '',
        'refund_replay_status' => (int) ($refundReplay['status'] ?? 0),
        'refund_replay_success' => is_array($refundReplayDecoded) && ($refundReplayDecoded['success'] ?? false) === true,
        'refund_replay_code' => is_array($refundReplayDecoded) ? (string) ($refundReplayDecoded['code'] ?? '') : '',
        'refund_replay_message' => is_array($refundReplayDecoded) ? (string) ($refundReplayDecoded['message'] ?? '') : '',
        'refund_replay_request_id' => is_array($refundReplayDecoded) ? (string) ($refundReplayDecoded['request_id'] ?? '') : '',
        'refund_db_payment_status' => is_array($refundedOrder) ? (string) ($refundedOrder['payment_status'] ?? '') : '',
        'refund_db_isdeleted' => is_array($refundedOrder) ? (int) ($refundedOrder['isdeleted'] ?? 1) : null,
        'refund_order_event_count' => $refundEventCount,
        'refund_idempotency_completed_count' => $idempotencyCount,
        'refund_mutation_ok' => $refundMutationOk,
        'void_post_status' => (int) ($void['status'] ?? 0),
        'void_post_success' => is_array($voidDecoded) && ($voidDecoded['success'] ?? false) === true,
        'void_post_code' => is_array($voidDecoded) ? (string) ($voidDecoded['code'] ?? '') : '',
        'void_post_message' => is_array($voidDecoded) ? (string) ($voidDecoded['message'] ?? '') : '',
        'void_payment_status' => is_array($voidDecoded) ? (string) ($voidDecoded['data']['payment_status'] ?? '') : '',
        'void_replay_status' => (int) ($voidReplay['status'] ?? 0),
        'void_replay_success' => is_array($voidReplayDecoded) && ($voidReplayDecoded['success'] ?? false) === true,
        'void_replay_code' => is_array($voidReplayDecoded) ? (string) ($voidReplayDecoded['code'] ?? '') : '',
        'void_replay_message' => is_array($voidReplayDecoded) ? (string) ($voidReplayDecoded['message'] ?? '') : '',
        'void_replay_request_id' => is_array($voidReplayDecoded) ? (string) ($voidReplayDecoded['request_id'] ?? '') : '',
        'void_db_payment_status' => is_array($voidedOrder) ? (string) ($voidedOrder['payment_status'] ?? '') : '',
        'void_db_isdeleted' => is_array($voidedOrder) ? (int) ($voidedOrder['isdeleted'] ?? 0) : null,
        'void_table_case' => is_array($voidTable) ? (int) ($voidTable['table_case'] ?? 1) : null,
        'void_order_event_count' => $voidEventCount,
        'void_idempotency_completed_count' => $voidIdempotencyCount,
        'void_mutation_ok' => $voidMutationOk,
    ];
}

function recipeCashierBrowserFixtureAssessPosPage(array $page): array
{
    $body = (string) ($page['body'] ?? '');
    $missing = [];
    foreach (['id="posForm"', 'id="itemsGrid"', 'data-item-id="10"', 'data-item-name="Coffee"', 'pos-pay-order-btn'] as $snippet) {
        if (strpos($body, $snippet) === false) {
            $missing[] = $snippet;
        }
    }
    $fatal = preg_match('/Fatal error|mysqli_sql_exception|SQL syntax|Unknown column|Unknown table/i', $body) === 1;

    return [
        'ok' => (int) ($page['status'] ?? 0) === 200 && $missing === [] && !$fatal,
        'status' => (int) ($page['status'] ?? 0),
        'body_bytes' => strlen($body),
        'missing_snippets' => $missing,
        'fatal_or_sql_text' => $fatal,
    ];
}

function recipeCashierBrowserFixtureOutput(array $result, bool $json): void
{
    if ($json) {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        return;
    }

    foreach ($result as $key => $value) {
        if (is_scalar($value) || $value === null) {
            fwrite(STDOUT, $key . ': ' . (string) $value . PHP_EOL);
        }
    }
}
