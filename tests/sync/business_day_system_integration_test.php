<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/ShiftReport.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/BusinessDayService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/../../includes/business_day.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_business_day_system_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    businessDaySystemCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $businessDays = new BusinessDayService();
    $drawer = new DrawerSessionService();
    $period = new CashFlowPeriodService();
    $methods = new PaymentMethodService();

    $methods->saveMethod($conn, [
        'code' => 'cash_drawer',
        'name_ar' => 'Cash drawer',
        'type' => 'cash',
        'account_id' => 51,
    ]);

    $savedCutoff = $businessDays->setCutoffHourForBranch($conn, 1, 1, 6);
    businessDaySystemAssert($savedCutoff === 6, 'cutoff setter should persist 6');
    businessDaySystemAssert(
        $businessDays->cutoffHourForBranch($conn, 1, 1) === 6,
        'cutoff reader should return saved value'
    );

    $openedAt = '2026-07-10 01:30:00';
    $expectedBusinessDay = '2026-07-09';
    $_SESSION = ['userid' => 41, 'pos_tenant' => 1, 'pos_branch' => 1];

    $session = $drawer->openSession($conn, [
        'user_id' => 41,
        'opened_by' => 41,
        'tenant' => 1,
        'branch' => 1,
        'opening_cash' => '100.000',
        'opened_at' => $openedAt,
    ]);
    businessDaySystemAssert(
        (string) ($session['business_day'] ?? '') === $expectedBusinessDay,
        'drawer open should persist business_day for late-night open'
    );

    $current = posmain_current_business_day($conn, 1, 1, '2026-07-10 02:00:00');
    businessDaySystemAssert($current === $expectedBusinessDay, 'helper current day should match cutoff');

    $conn->query("
        INSERT INTO ot_head (id, table_id, pro_date, payment_date, crtime, user, pro_tybe, payment_status, order_status, fat_total, fat_disc, fat_net, pro_value, isdeleted)
        VALUES
            (501, 1, '{$expectedBusinessDay}', '2026-07-10 01:45:00', '2026-07-10 01:45:00', 41, 9, 'paid', 'completed', 40.000, 0, 40.000, 40.000, 0),
            (502, 1, '2026-07-10', '2026-07-10 08:00:00', '2026-07-10 08:00:00', 41, 9, 'paid', 'completed', 15.000, 0, 15.000, 15.000, 0)
    ");
    $conn->query("
        INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
        VALUES
            (501, 40.000, 'cash_drawer', 41, '2026-07-10 01:45:00'),
            (502, 15.000, 'cash_drawer', 41, '2026-07-10 08:00:00')
    ");
    $drawer->recordMovement($conn, (int) $session['id'], [
        'movement_type' => 'sale_cash',
        'amount' => '40.000',
        'order_id' => 501,
        'created_by' => 41,
    ]);

    $sessions = $period->sessions($conn, [
        'date_from' => $expectedBusinessDay,
        'date_to' => $expectedBusinessDay,
        'tenant' => 1,
        'branch' => 1,
    ]);
    businessDaySystemAssert(count($sessions) === 1, 'cash flow sessions should find late-night session on prior business day');
    businessDaySystemAssert((string) $sessions[0]['business_day'] === $expectedBusinessDay, 'session business_day should match');

    $payments = $period->paymentBreakdown($conn, [
        'date_from' => $expectedBusinessDay,
        'date_to' => $expectedBusinessDay,
        'tenant' => 1,
        'branch' => 1,
        'cashier_id' => 41,
    ]);
    businessDaySystemAssert(
        abs((float) ($payments['by_type']['cash'] ?? 0) - 40.0) < 0.01,
        'payment breakdown for business day should include late-night stamped order only'
    );

    $nextDayPayments = $period->paymentBreakdown($conn, [
        'date_from' => '2026-07-10',
        'date_to' => '2026-07-10',
        'tenant' => 1,
        'branch' => 1,
        'cashier_id' => 41,
    ]);
    businessDaySystemAssert(
        abs((float) ($nextDayPayments['by_type']['cash'] ?? 0) - 15.0) < 0.01,
        'next business day payments should exclude late-night order'
    );

    $report = new ShiftReport($conn, 41, null, [
        'tenant' => 1,
        'branch' => 1,
        'drawer_session_id' => (int) $session['id'],
        'shift_opened_at' => $openedAt,
    ]);
    $totals = $report->getTotals();
    businessDaySystemAssert((int) ($totals['total_orders'] ?? 0) === 1, 'ShiftReport should count orders for session business day');
    businessDaySystemAssert(abs((float) ($totals['total_net'] ?? 0) - 40.0) < 0.01, 'ShiftReport net should match late-night order');

    $recon = (new ShiftDrawerReconciliationService())->buildForUser($conn, [
        'user_id' => 41,
        'tenant' => 1,
        'branch' => 1,
        'drawer_session_id' => (int) $session['id'],
    ]);
    businessDaySystemAssert((string) ($recon['date'] ?? '') === $expectedBusinessDay, 'reconciliation date should be session business day');
    businessDaySystemAssert(
        abs((float) ($recon['payments']['cash'] ?? 0) - 40.0) < 0.01,
        'reconciliation cash should include late-night payment on business day'
    );

    $changed = $businessDays->setCutoffHourForBranch($conn, 1, 1, 0);
    businessDaySystemAssert($changed === 0, 'cutoff can be set to midnight');
    businessDaySystemAssert(
        $businessDays->businessDayForTimestamp($openedAt, 0) === '2026-07-10',
        'with cutoff 0 late-night open belongs to calendar day'
    );
    businessDaySystemAssert(
        posmain_current_business_day($conn, 1, 1, '2026-07-10 01:30:00') === '2026-07-10',
        'helper should respect updated cutoff'
    );

    // Restore restaurant-friendly cutoff and ensure persisted session stamp remains historical.
    $businessDays->setCutoffHourForBranch($conn, 1, 1, 6);
    $reloaded = $drawer->sessionById($conn, (int) $session['id']);
    businessDaySystemAssert(
        (string) ($reloaded['business_day'] ?? '') === $expectedBusinessDay,
        'persisted session business_day should not rewrite when cutoff changes later'
    );

    echo "business-day-system-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function businessDaySystemCreateSchema(mysqli $conn): void
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
            display_name VARCHAR(120) NULL,
            password VARCHAR(255) NULL,
            usrole INT NULL
        ) ENGINE=InnoDB
    ");
    $conn->query("
        INSERT INTO users (id, uname, display_name, password, usrole) VALUES
            (41, 'night_cashier', 'Night Cashier', 'x', 3)
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            table_id INT NULL,
            pro_date DATE NULL,
            payment_date DATETIME NULL,
            crtime DATETIME NULL,
            user INT NULL,
            pro_tybe INT NULL,
            payment_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            fat_total DECIMAL(12,3) NULL,
            fat_disc DECIMAL(12,3) NULL,
            fat_net DECIMAL(12,3) NULL,
            pro_value DECIMAL(12,3) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            created_by INT NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB
    ");
    $conn->query("
        CREATE TABLE closed_orders (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user VARCHAR(120) NULL,
            date DATE NULL,
            endtime TIME NULL
        ) ENGINE=InnoDB
    ");
}

function businessDaySystemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
