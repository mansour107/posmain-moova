<?php

require_once __DIR__ . '/../../classes/Pos/Http/PosOrderController.php';
require_once __DIR__ . '/table_order_test_schema.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_table_pay_without_save_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-table-pay-without-save-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

putenv('POSMAIN_INVENTORY_LEDGER_MODE=shadow');
putenv('POSMAIN_INVENTORY_STRICT_STOCK=0');

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    tableOrderTestCreateSchema($conn);

    $conn->query("INSERT INTO settings (id, def_pos_client, def_pos_store, def_pos_employee, def_pos_fund, isdeleted) VALUES (1, 501, 3, 4, 51, 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted) VALUES
            (3, '123001', 'Main store', 0, 0, 1, 0, 0),
            (4, '213001', 'Employee 1', 35, 0, 0, 0, 0),
            (35, '213', 'Employees', 0, 1, 0, 0, 0),
            (51, '121001', 'Default fund', 0, 0, 0, 1, 0),
            (501, '122001', 'Default client', 0, 0, 0, 0, 0),
            (91, '3111', 'Sales', 0, 0, 0, 0, 0)
    ");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, edit_sales, add_sales, add_payment, show_payment, isdeleted) VALUES (1, 'Admin', 1, 1, 1, 1, 0)");
    $conn->query("INSERT INTO users (id, uname, userrole, isdeleted) VALUES (7, 'cashier', 1, 0)");
    $conn->query("INSERT INTO myitems (id, iname, item_type, track_stock, price1, isdeleted) VALUES (10, 'Table item 10', 'sellable', 1, 15, 0)");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 0, 0)");

    $controller = new PosOrderController();
    $payload = [
        'table_id' => 1,
        'selected_table_id' => 1,
        'order_id' => 0,
        'paid' => 30,
        'paid_cash' => 30,
        'net' => 30,
        'headnet' => 30,
        'total' => 30,
        'headtotal' => 30,
        'discount' => 0,
        'headdisc' => 0,
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'payment_fund_id' => 51,
        'items' => [
            ['id' => 10, 'qty' => 2, 'price' => 15, 'discount' => 0],
        ],
        'idempotency_key' => 'table-pay-without-save-' . getmypid(),
    ];

    $result = $controller->payTable($conn, $payload, ['HTTP_X_IDEMPOTENCY_KEY' => $payload['idempotency_key']], 7);
    $body = is_array($result['payload'] ?? null) ? $result['payload'] : [];

    posTablePayWithoutSaveAssert(($result['http_status'] ?? 0) === 200, 'table pay without prior save should succeed');
    posTablePayWithoutSaveAssert(($body['success'] ?? false) === true, 'table pay without prior save should return success');
    posTablePayWithoutSaveAssert((int) ($body['order_id'] ?? 0) > 0, 'table pay without prior save should return order id');

    $orderId = (int) $body['order_id'];
    $order = $conn->query("SELECT payment_status, table_id FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
    posTablePayWithoutSaveAssert((string) ($order['payment_status'] ?? '') === 'paid', 'auto-saved table order should be paid');
    posTablePayWithoutSaveAssert((int) ($order['table_id'] ?? 0) === 1, 'paid order should stay linked to table');
    posTablePayWithoutSaveAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'] === 1, 'auto-saved table order should have line items');

    try {
        $controller->payTable($conn, [
            'table_id' => 1,
            'order_id' => 0,
            'paid' => 10,
            'items' => [],
            'idempotency_key' => 'table-pay-empty-' . getmypid(),
        ], [], 7);
        throw new RuntimeException('empty table pay should fail');
    } catch (InvalidArgumentException $exception) {
        posTablePayWithoutSaveAssert(strpos($exception->getMessage(), 'لا يوجد طلب نشط لهذه الطاولة') !== false, 'empty cart pay should keep no-active-order error');
    }
} finally {
  $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}

echo "pos-table-pay-without-save-ok\n";

function posTablePayWithoutSaveAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
