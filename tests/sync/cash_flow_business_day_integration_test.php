<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/BusinessDayService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cash_flow_business_day_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    cashFlowBusinessDayCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $conn->query("
        INSERT INTO pos_branch_settings (pos_tenant, pos_branch, business_day_cutoff_hour)
        VALUES (1, 1, 6), (1, 2, 4)
    ");

    $shift = new ShiftSessionService();
    $drawer = new DrawerSessionService();
    $period = new CashFlowPeriodService();
    $businessDays = new BusinessDayService();

    $_SESSION = ['userid' => 31, 'pos_tenant' => 1, 'pos_branch' => 1];
    $openedLate = $shift->openForCashier($conn, 31, [
        'opening_cash' => '50.000',
        'tenant' => 1,
        'branch' => 1,
        'opened_at' => date('Y-m-d', strtotime('-1 day')) . ' 02:30:00',
    ]);
    $lateSessionId = (int) $openedLate['id'];
    $lateBusinessDay = $businessDays->businessDayForTimestamp((string) $openedLate['opened_at'], 6);
    cashFlowBusinessDayAssert($lateBusinessDay === date('Y-m-d', strtotime('-2 day')), 'late night session should belong to previous business day');

    $_SESSION = ['userid' => 32, 'pos_tenant' => 1, 'pos_branch' => 2];
    $openedEarly = $shift->openForCashier($conn, 32, [
        'opening_cash' => '75.000',
        'tenant' => 1,
        'branch' => 2,
        'opened_at' => date('Y-m-d') . ' 03:00:00',
    ]);
    $earlyBusinessDay = $businessDays->businessDayForTimestamp((string) $openedEarly['opened_at'], 4);
    cashFlowBusinessDayAssert($earlyBusinessDay === date('Y-m-d', strtotime('-1 day')), 'branch cutoff 4 should roll 3am to previous day');

    $targetDay = $lateBusinessDay;
    $branchOneSessions = $period->sessions($conn, [
        'date_from' => $targetDay,
        'date_to' => $targetDay,
        'tenant' => 1,
        'branch' => 1,
    ]);
    cashFlowBusinessDayAssert(count($branchOneSessions) === 1, 'branch 1 filter should include only its late session');
    cashFlowBusinessDayAssert((int) $branchOneSessions[0]['id'] === $lateSessionId, 'branch 1 session id should match');

    $drawer->recordMovement($conn, $lateSessionId, [
        'movement_type' => 'sale_cash',
        'amount' => '20.000',
        'created_by' => 31,
        'reason' => 'late night sale',
    ]);
    $closed = $drawer->closeSession($conn, $lateSessionId, [
        'closed_by' => 31,
        'counted_cash' => '75.000',
    ]);
    $breakdown = $drawer->sessionCashBreakdown($conn, $lateSessionId);
    cashFlowBusinessDayAssert(abs((float) $breakdown['pre_close_expected_cash'] - 70.0) < 0.01, 'pre-close expected should exclude closing adjustment');
    cashFlowBusinessDayAssert(abs((float) $breakdown['close_variance'] - 5.0) < 0.01, 'close variance should equal counted - pre-close');
    cashFlowBusinessDayAssert(abs((float) $closed['expected_cash'] - 75.0) < 0.01, 'post-close expected should equal counted');

    $summary = $period->summary($conn, [
        'date_from' => $targetDay,
        'date_to' => $targetDay,
        'tenant' => 1,
        'branch' => 1,
    ]);
    cashFlowBusinessDayAssert(abs((float) ($summary['close_variance_rollup'] ?? 0) - 5.0) < 0.01, 'summary should expose close variance rollup');

    echo "cash-flow-business-day-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function cashFlowBusinessDayCreateSchema(mysqli $conn): void
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
    $conn->query("
        INSERT INTO users (id, uname, password, usrole) VALUES
            (31, 'late_cashier', 'x', 3),
            (32, 'early_cashier', 'x', 3)
    ");
}

function cashFlowBusinessDayAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
