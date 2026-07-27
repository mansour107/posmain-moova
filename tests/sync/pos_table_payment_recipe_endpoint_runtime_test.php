<?php

require_once __DIR__ . '/pos_takeaway_invoice_handler_test.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

if (($argv[1] ?? '') === '--child') {
    posTableRecipePaymentEndpointChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_OFF);

posTableRecipePaymentEndpointConfigureRecipeEnv();

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_table_recipe_payment_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-table-payment-recipe-endpoint-runtime-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayInvoiceCreateSchema($conn);
    $conn->query("ALTER TABLE ot_head ADD COLUMN payment_notes TEXT NULL");
    posTakeawayInvoiceSeedFixtures($conn);
    $conn->query("INSERT INTO payment_methods
        (code, name_ar, name_en, account_id, type, requires_reference, settlement_policy, is_active, sort_order)
        VALUES ('cash', 'نقدي', 'Cash', 51, 'cash', 0, 'cash_drawer', 1, 1)");
    (new DrawerSessionService())->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 0,
        'branch' => 0,
        'fund_account_id' => 51,
        'opening_cash' => '100.00',
    ]);
    posTakeawayInvoiceSeedRecipe($conn);
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'Recipe Table Runtime', 0, 0)");

    $service = new PosOrderMutationService();
    $saved = $service->saveTableOrder($conn, [
        'table_id' => 1,
        'order_date' => '2026-05-25',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'items' => [
            ['id' => 10, 'qty' => 2, 'price' => 10],
        ],
        'total' => 20,
        'discount' => 0,
        'net' => 20,
    ], ['user_id' => 7]);

    posTableRecipePaymentAssert(($saved['success'] ?? false) === true, 'table order save should succeed');
    $orderId = (int) ($saved['data']['order_id'] ?? 0);
    posTableRecipePaymentAssert($orderId > 0, 'table order save should return order id');
    posTableRecipePaymentAssert(($saved['data']['payment_status'] ?? '') === 'unpaid', 'saved table order should be unpaid before endpoint payment');
    posTableRecipePaymentAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'saved table order should occupy the table');
    posTableRecipePaymentAssertRecipeReserved($conn, $orderId);

    $payload = [
        'table_id' => 1,
        'order_id' => $orderId,
        'discount' => '0',
        'net' => '20',
        'paid' => '20',
        'payment_method' => 'cash',
        'notes' => 'recipe endpoint table payment',
        'idempotency_key' => 'recipe-table-payment-endpoint-' . getmypid(),
    ];
    $paid = posTableRecipePaymentEndpointRunChild($db, $payload);
    posTableRecipePaymentAssert(($paid['success'] ?? false) === true, 'table payment endpoint should succeed: ' . json_encode($paid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    posTableRecipePaymentAssert(($paid['code'] ?? '') === 'OK', 'table payment endpoint should return OK');
    posTableRecipePaymentAssert(($paid['payment_status'] ?? '') === 'paid', 'table payment endpoint should mark order paid');
    posTableRecipePaymentAssert((int) ($paid['order_id'] ?? 0) === $orderId, 'table payment endpoint should return the paid order id');

    $replay = posTableRecipePaymentEndpointRunChild($db, $payload);
    posTableRecipePaymentAssert(($replay['success'] ?? false) === true, 'table payment endpoint idempotency replay should succeed');
    posTableRecipePaymentAssert(($replay['request_id'] ?? '') === $payload['idempotency_key'], 'table payment replay should return original request id');
    posTableRecipePaymentAssert((int) ($replay['order_id'] ?? 0) === $orderId, 'table payment replay should return original order id');

    posTableRecipePaymentAssertRecipeConsumedOnce($conn, $orderId);
    posTableRecipePaymentAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'full table payment should release the table');
    posTableRecipePaymentAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_payments WHERE order_id = {$orderId}")->fetch_assoc()['c'] === 1, 'payment replay should not create a second payment row');
    posTableRecipePaymentAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = {$orderId} AND event_type = 'order.payment_recorded'")->fetch_assoc()['c'] === 1, 'payment replay should not duplicate order events');
    posTableRecipePaymentAssert((int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.payment.table' AND idempotency_key = '{$payload['idempotency_key']}' AND status = 'completed'")->fetch_assoc()['c'] === 1, 'payment replay should keep one completed idempotency row');

    echo "pos-table-payment-recipe-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posTableRecipePaymentEndpointConfigureRecipeEnv(): void
{
    putenv('POSMAIN_ENV=test');
    putenv('POSMAIN_PRODUCTION_MODE=0');
    putenv('POSMAIN_SYNC_OUTBOX_ENABLED=1');
    putenv('POSMAIN_SYNC_OUTBOX_ENABLED=1');
    putenv('POSMAIN_BRANCH_UUID=' . POS_TAKEAWAY_BRANCH_UUID);
    putenv('POSMAIN_BRANCH_NAME=Table Recipe Payment Endpoint Fixture');
    putenv('POSMAIN_POS_TENANT=0');
    putenv('POSMAIN_POS_BRANCH=0');
    putenv('POSMAIN_CLOUD_BASE_URL=http://127.0.0.1/cloud-fixture');
    putenv('POSMAIN_RECIPE_MODE=consume_pilot');
    putenv('POSMAIN_RECIPE_MODE=consume_pilot');
    putenv('POSMAIN_RECIPE_RESERVATIONS=1');
    putenv('POSMAIN_RECIPE_CONSUMPTION=1');
    putenv('POSMAIN_RECIPE_ACCOUNTING=0');
    putenv('POSMAIN_RECIPE_AVAILABILITY=0');
    putenv('POSMAIN_RECIPE_MOOVA_SYNC=0');
    putenv('POSMAIN_RECIPE_STRICT_STOCK=0');
    putenv('POSMAIN_RECIPE_PILOT_POS_BRANCH=0');
    putenv('POSMAIN_RECIPE_PILOT_ITEM_IDS=10');
    putenv('POSMAIN_REQUIRE_OPEN_SHIFT=0');
}

function posTableRecipePaymentEndpointChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'recipe-table-payment-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'ajax/process_table_payment.php';
    $_SERVER['SCRIPT_NAME'] = 'ajax/process_table_payment.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    $_POST = [
        'table_id' => (int) ($payload['table_id'] ?? 0),
        'order_id' => (int) ($payload['order_id'] ?? 0),
        'discount' => (string) ($payload['discount'] ?? '0'),
        'net' => (string) ($payload['net'] ?? ''),
        'paid' => (string) ($payload['paid'] ?? ''),
        'payment_method' => (string) ($payload['payment_method'] ?? 'cash'),
        'notes' => (string) ($payload['notes'] ?? ''),
        'idempotency_key' => (string) ($payload['idempotency_key'] ?? ''),
        'csrf_token' => $csrf,
    ];

    session_id('recipetablepay' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'table_payment_smoke';
    $_SESSION['userid'] = 7;
    $_SESSION['user_id'] = 7;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['pos_authenticated'] = true;
    $_SESSION['pos_user_id'] = 7;
    $_SESSION['pos_user_name'] = 'table_payment_smoke';
    $_SESSION['posmain_csrf_tokens'] = [
        'pos_browser' => $csrf,
    ];

    chdir(dirname(__DIR__, 2) . '/ajax');
    require dirname(__DIR__, 2) . '/ajax/process_table_payment.php';
    exit(0);
}

function posTableRecipePaymentEndpointRunChild(string $db, array $payload): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_ROUTER_ENABLED' => '0',
    ]);
    foreach (getenv() ?: [] as $key => $value) {
        if (strpos((string) $key, 'POSMAIN_RECIPE') === 0 || in_array($key, [
            'POSMAIN_ENV',
            'POSMAIN_PRODUCTION_MODE',
            'POSMAIN_RECIPE_MODE',
            'POSMAIN_SYNC_OUTBOX_ENABLED',
            'POSMAIN_BRANCH_UUID',
            'POSMAIN_BRANCH_NAME',
            'POSMAIN_POS_TENANT',
            'POSMAIN_POS_BRANCH',
            'POSMAIN_CLOUD_BASE_URL',
            'POSMAIN_REQUIRE_OPEN_SHIFT',
        ], true)) {
            $env[$key] = $value;
        }
    }
    $process = proc_open([
        PHP_BINARY,
        __FILE__,
        '--child',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start table payment endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Table payment endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Table payment endpoint child did not return JSON: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function posTableRecipePaymentAssertRecipeReserved(mysqli $conn, int $orderId): void
{
    $usages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTableRecipePaymentAssert(count($usages) === 1, 'saved table order should create one recipe usage row');
    posTableRecipePaymentAssert((string) $usages[0]['status'] === 'reserved', 'saved table order recipe usage should be reserved before payment');
    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 12);
    posTableRecipePaymentAssert(is_array($balance), 'ingredient balance should exist after table reservation');
    posTableRecipePaymentAssert((string) $balance['qty_on_hand'] === '10.000000', 'table reservation should not consume stock on hand');
    posTableRecipePaymentAssert((string) $balance['qty_reserved'] === '2.000000', 'table reservation should reserve two ingredient units');
}

function posTableRecipePaymentAssertRecipeConsumedOnce(mysqli $conn, int $orderId): void
{
    $usages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTableRecipePaymentAssert(count($usages) === 1, 'paid table order should keep one recipe usage row');
    posTableRecipePaymentAssert((string) $usages[0]['status'] === 'consumed', 'paid table order recipe usage should be consumed');
    posTableRecipePaymentAssert((int) $usages[0]['sellable_item_id'] === 10, 'table recipe usage should belong to the pilot sellable item');

    $movements = $conn->query("SELECT * FROM inventory_movements WHERE order_id = {$orderId} AND movement_type = 'recipe_consumption' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTableRecipePaymentAssert(count($movements) === 1, 'paid table endpoint should write one recipe consumption movement');
    posTableRecipePaymentAssert((int) $movements[0]['item_id'] === 12, 'table payment recipe movement should consume the ingredient item');
    posTableRecipePaymentAssert((string) $movements[0]['qty_out'] === '2.000000', 'table payment recipe movement should consume ingredient quantity once');
    posTableRecipePaymentAssert((int) $movements[0]['recipe_order_line_usage_id'] === (int) $usages[0]['id'], 'table payment recipe movement should link to usage row');

    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 12);
    posTableRecipePaymentAssert(is_array($balance), 'ingredient balance should exist after table payment');
    posTableRecipePaymentAssert((string) $balance['qty_on_hand'] === '8.000000', 'full table payment should deduct ingredient stock exactly once');
    posTableRecipePaymentAssert((string) $balance['qty_reserved'] === '0.000000', 'full table payment should clear reserved ingredient stock');
}

function posTableRecipePaymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
