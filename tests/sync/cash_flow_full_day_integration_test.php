<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentService.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cash_flow_full_day_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    cashFlowFullDayCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $cashierId = 11;
    $_SESSION = [
        'userid' => $cashierId,
        'pos_tenant' => 1,
        'pos_branch' => 2,
    ];

    $shift = new ShiftSessionService();
    $drawer = new DrawerSessionService();
    $payment = new PaymentService();

    $opened = $shift->openForCashier($conn, $cashierId, ['opening_cash' => '100.000', 'tenant' => 1, 'branch' => 2]);
    $sessionId = (int) $opened['id'];
    cashFlowFullDayAssert(abs((float) $drawer->expectedCash($conn, $sessionId) - 100.0) < 0.01, 'opening expected cash');

    $payment->recordCashDrawerMovementForPayment($conn, 'cash', 50.0, 201, $cashierId, [
        'tenant' => 1,
        'branch' => 2,
        'drawer_session_id' => $sessionId,
        'drawer_reason' => 'takeaway_cash_payment',
    ], $opened, null, 9001);
    cashFlowFullDayAssert(abs((float) $drawer->expectedCash($conn, $sessionId) - 150.0) < 0.01, 'after sale');

    $shift->recordShiftPayIn($conn, $cashierId, ['amount' => 25, 'reason' => 'float top-up']);
    cashFlowFullDayAssert(abs((float) $drawer->expectedCash($conn, $sessionId) - 175.0) < 0.01, 'after payin');

    $shift->recordShiftExpense($conn, $cashierId, ['amount' => 15, 'reason' => 'supplies']);
    cashFlowFullDayAssert(abs((float) $drawer->expectedCash($conn, $sessionId) - 160.0) < 0.01, 'after payout');

    $shift->recordShiftSafeDrop($conn, $cashierId, ['amount' => 100, 'reason' => 'vault']);
    cashFlowFullDayAssert(abs((float) $drawer->expectedCash($conn, $sessionId) - 60.0) < 0.01, 'after safe drop');

    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'no_sale',
        'amount' => '0.000',
        'allow_zero_amount' => true,
        'reason' => 'audit open',
        'created_by' => $cashierId,
    ]);
    cashFlowFullDayAssert(abs((float) $drawer->expectedCash($conn, $sessionId) - 60.0) < 0.01, 'no_sale should not change expected');

    $closed = $drawer->closeSession($conn, $sessionId, [
        'closed_by' => $cashierId,
        'counted_cash' => '65.000',
    ]);
    cashFlowFullDayAssert(abs((float) $closed['expected_cash'] - 65.0) < 0.01, 'close should absorb variance');
    cashFlowFullDayAssert(abs((float) $closed['difference'] - 5.0) < 0.01, 'difference keeps pre-close over (65-60)');

    $today = date('Y-m-d');
    $period = new CashFlowPeriodService();
    $summary = $period->summary($conn, ['date_from' => $today, 'date_to' => $today, 'tenant' => 1, 'branch' => 2]);
    cashFlowFullDayAssert(($summary['source'] ?? '') === 'drawer', 'period summary source drawer');
    cashFlowFullDayAssert(abs((float) ($summary['movement_totals']['sale_cash'] ?? 0) - 50.0) < 0.01, 'summary sale_cash');
    cashFlowFullDayAssert(abs((float) ($summary['movement_totals']['paid_in'] ?? 0) - 25.0) < 0.01, 'summary payin');
    cashFlowFullDayAssert(abs((float) ($summary['movement_totals']['paid_out'] ?? 0) - 15.0) < 0.01, 'summary payout');
    cashFlowFullDayAssert(abs((float) ($summary['movement_totals']['safe_drop'] ?? 0) - 100.0) < 0.01, 'summary safe drop');

    echo "cash-flow-full-day-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function cashFlowFullDayCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            edit_pass VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ");
    $conn->query("INSERT INTO settings (id, def_pos_client, edit_pass, isdeleted) VALUES (1, 501, '1234', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, isdeleted) VALUES
            (91, '400001', 'Sales', 0),
            (501, '122001', 'Walk-in Customer', 0),
            (51, '101001', 'Cash Drawer', 0)
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(120) NOT NULL,
            password VARCHAR(255) NULL,
            usrole INT NULL
        ) ENGINE=InnoDB
    ");
    $conn->query("INSERT INTO users (id, uname, password, usrole) VALUES (11, 'full_day_cashier', 'x', 3)");
    $conn->query("
        CREATE TABLE order_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(12,3) NOT NULL,
            payment_method VARCHAR(40) NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user VARCHAR(50) NULL,
            pro_date DATE NULL,
            payment_date DATETIME NULL
        ) ENGINE=InnoDB
    ");
}

function cashFlowFullDayAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
