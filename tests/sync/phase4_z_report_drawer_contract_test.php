<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/ShiftReport.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$source = file_get_contents(__DIR__ . '/../../z_report.php');
if ($source === false) {
    throw new RuntimeException('Unable to read z_report.php');
}

phase4ZReportAssert(strpos($source, '$report->getDrawerReconciliation()') !== false, 'z_report should read drawer reconciliation from ShiftReport');
phase4ZReportAssert(strpos($source, 'name="sys_total_sales"') !== false, 'sys_total_sales hidden field should remain');
phase4ZReportAssert(strpos($source, 'name="sys_total_cash"') !== false, 'sys_total_cash hidden field should remain');
phase4ZReportAssert(strpos($source, 'name="sys_total_visa"') !== false, 'sys_total_visa hidden field should remain');
phase4ZReportAssert(strpos($source, 'name="sys_expenses"') !== false, 'sys_expenses hidden field should remain');
phase4ZReportAssert(strpos($source, 'name="expected_cash"') !== false, 'expected_cash hidden field should remain');
phase4ZReportAssert(strpos($source, 'name="actual_cash"') !== false, 'actual_cash field should remain');
phase4ZReportAssert(strpos($source, 'name="actual_visa"') !== false, 'actual_visa hidden field should remain');
phase4ZReportAssert(strpos($source, 'name="drawer_session_id"') !== false, 'drawer_session_id hidden field expected');
phase4ZReportAssert(strpos($source, 'name="drawer_expected_cash"') !== false, 'drawer_expected_cash hidden field expected');
phase4ZReportAssert(strpos($source, 'name="drawer_cash_difference"') !== false, 'drawer_cash_difference hidden field expected');
phase4ZReportAssert(strpos($source, "csrf_input('shift_close_z')") !== false, 'shift close CSRF field should remain');
phase4ZReportAssert(strpos($source, 'action="do_close_shift_z.php"') !== false, 'Z close form action should remain');

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_z_report_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4ZReportCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $methods = new PaymentMethodService();
    $methods->saveMethod($conn, [
        'code' => 'cash_drawer',
        'name_ar' => 'Cash drawer',
        'type' => 'cash',
    ]);

    $drawer = new DrawerSessionService();
    $session = $drawer->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 2,
        'branch' => 3,
        'opening_cash' => '10.000',
        'opened_at' => '2026-05-13 08:00:00',
    ]);
    $drawer->recordMovement($conn, $session['id'], [
        'movement_type' => 'sale_cash',
        'amount' => '25.000',
        'order_id' => 100,
        'created_by' => 7,
    ]);
    $conn->query("
        INSERT INTO ot_head (id, table_id, pro_date, payment_date, user, pro_tybe, payment_status, isdeleted)
        VALUES (100, 1, '2026-05-13', '2026-05-13 09:00:00', 7, 9, 'paid', 0)
    ");
    $conn->query("
        INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
        VALUES (100, 25, 'cash_drawer', 7, '2026-05-13 09:00:00')
    ");

    $report = new ShiftReport($conn, 7, '2026-05-13');
    $summary = $report->getDrawerReconciliation(['tenant' => 2, 'branch' => 3]);
    phase4ZReportAssert($summary['drawer_session']['id'] === $session['id'], 'ShiftReport should expose drawer session reconciliation');
    phase4ZReportAssert($summary['payments']['cash'] === '25.000', 'ShiftReport reconciliation cash total expected');
    phase4ZReportAssert($summary['drawer']['expected_cash'] === '35.000', 'ShiftReport reconciliation expected cash should include opening cash');
    phase4ZReportAssert($summary['reconciliation']['cash_difference'] === '0.000', 'ShiftReport reconciliation cash difference expected');

    echo "phase4-z-report-drawer-contract-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4ZReportCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            aname VARCHAR(120) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO acc_head (id, aname) VALUES (7, 'Cashier')");
    $conn->query("
        CREATE TABLE closed_orders (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user VARCHAR(120) NULL,
            date DATE NULL,
            endtime TIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            table_id INT NULL,
            pro_date DATE NULL,
            payment_date DATETIME NULL,
            user INT NULL,
            pro_tybe INT NULL,
            payment_status VARCHAR(40) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            created_by INT NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function phase4ZReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
