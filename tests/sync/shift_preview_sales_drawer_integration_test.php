<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/ShiftReport.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_shift_preview_sales_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "shift-preview-sales-drawer-integration-skipped-db-unavailable\n";
    exit(0);
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    shiftPreviewSalesCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);
    shiftPreviewSalesSeed($conn);

    $cashierId = 7;
    $today = date('Y-m-d');
    $_SESSION = [
        'login' => 'preview_cashier',
        'userid' => $cashierId,
        'pos_tenant' => 9,
        'pos_branch' => 8,
    ];

    $shiftSessions = new ShiftSessionService();
    $opened = $shiftSessions->openForCashier($conn, $cashierId, [
        'tenant' => 9,
        'branch' => 8,
        'opening_cash' => '50.000',
    ]);
    shiftPreviewSalesAssert($opened['status'] === 'open', 'drawer session should open');
    shiftPreviewSalesAssert((int) ($_SESSION['pos_drawer_session_id'] ?? 0) === (int) $opened['id'], 'session should store drawer id');

    $saleTime = date('Y-m-d H:i:s');
    $conn->query("
        INSERT INTO ot_head (id, pro_date, user, pro_tybe, fat_total, fat_disc, fat_net, payment_status, payment_date, isdeleted)
        VALUES (501, '{$today}', '{$cashierId}', 9, 28.00, 0.00, 28.00, 'paid', '{$saleTime}', 0)
    ");

    $report = new ShiftReport($conn, $cashierId, $today, [
        'shift_opened_at' => $opened['opened_at'],
        'drawer_session_id' => (int) $opened['id'],
    ]);
    $totals = $report->getTotals();
    shiftPreviewSalesAssert((int) ($totals['total_orders'] ?? 0) === 1, 'shift preview should count sale without crtime when payment_date exists');
    shiftPreviewSalesAssert(abs((float) ($totals['total_net'] ?? 0) - 28.0) < 0.01, 'shift preview should include sale net');

    $payments = new PaymentService();
    $payments->recordCollectedOrderPayments($conn, 501, 28.0, 0.0, $cashierId, [], 'shift_preview_sale');

    $expenseSummary = $shiftSessions->drawerExpenseSummary($conn, $opened);
    shiftPreviewSalesAssert(abs((float) ($expenseSummary['expected_cash'] ?? 0) - 78.0) < 0.01, 'expected cash should include opening and cash sale');

    $drawer = new DrawerSessionService();
    $resolved = $drawer->resolveOpenSessionForUser($conn, $cashierId, ['tenant' => 0, 'branch' => 0]);
    shiftPreviewSalesAssert((int) ($resolved['id'] ?? 0) === (int) $opened['id'], 'drawer resolver should find session via session id even when tenant/branch mismatch');

    echo "shift-preview-sales-drawer-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function shiftPreviewSalesCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            edit_pass VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(120) NOT NULL,
            password VARCHAR(255) NULL,
            usrole INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_date DATE NULL,
            user VARCHAR(20) NULL,
            pro_tybe INT NULL,
            fat_total DECIMAL(15,4) NULL,
            fat_disc DECIMAL(15,4) NULL,
            fat_net DECIMAL(15,4) NULL,
            payment_status VARCHAR(40) NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            crtime DATETIME NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(40) NULL,
            created_by INT NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function shiftPreviewSalesSeed(mysqli $conn): void
{
    $conn->query("INSERT INTO settings (id, def_pos_client, edit_pass, isdeleted) VALUES (1, 501, '1234', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, isdeleted) VALUES
            (91, '400001', 'Sales', 0),
            (501, '122001', 'Walk-in Customer', 0),
            (51, '101001', 'Cash Drawer', 0)
    ");
    $conn->query("INSERT INTO users (id, uname, password, usrole) VALUES (7, 'preview_cashier', 'x', 3)");

    $methods = new PaymentMethodService();
    $methods->saveMethod($conn, [
        'code' => 'cash',
        'name_ar' => 'Cash',
        'name_en' => 'Cash',
        'type' => 'cash',
        'account_id' => 51,
        'requires_reference' => false,
    ]);
}

function shiftPreviewSalesAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
