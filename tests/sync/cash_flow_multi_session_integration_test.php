<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cash_flow_multi_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    cashFlowMultiCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $shift = new ShiftSessionService();
    $drawer = new DrawerSessionService();
    $period = new CashFlowPeriodService();
    $today = date('Y-m-d');

    $_SESSION = ['userid' => 21, 'pos_tenant' => 1, 'pos_branch' => 1];
    $branchOne = $shift->openForCashier($conn, 21, [
        'opening_cash' => '100.000',
        'tenant' => 1,
        'branch' => 1,
    ]);
    $branchOneId = (int) $branchOne['id'];

    $_SESSION = ['userid' => 22, 'pos_tenant' => 1, 'pos_branch' => 2];
    $branchTwo = $shift->openForCashier($conn, 22, [
        'opening_cash' => '200.000',
        'tenant' => 1,
        'branch' => 2,
    ]);
    $branchTwoId = (int) $branchTwo['id'];

    $branchOneSessions = $period->sessions($conn, [
        'date_from' => $today,
        'date_to' => $today,
        'tenant' => 1,
        'branch' => 1,
    ]);
    cashFlowMultiAssert(count($branchOneSessions) === 1, 'branch filter should isolate branch 1 session');
    cashFlowMultiAssert((int) $branchOneSessions[0]['id'] === $branchOneId, 'branch 1 session id should match');

    $branchTwoSessions = $period->sessions($conn, [
        'date_from' => $today,
        'date_to' => $today,
        'tenant' => 1,
        'branch' => 2,
    ]);
    cashFlowMultiAssert(count($branchTwoSessions) === 1, 'branch filter should isolate branch 2 session');
    cashFlowMultiAssert((int) $branchTwoSessions[0]['id'] === $branchTwoId, 'branch 2 session id should match');

    $drawer->forceCloseSession($conn, $branchTwoId, [
        'closed_by' => 99,
        'counted_cash' => '200.000',
        'reason' => 'manager force close',
    ]);

    $forcedSessions = $period->sessions($conn, [
        'date_from' => $today,
        'date_to' => $today,
        'tenant' => 1,
        'branch' => 2,
        'status' => 'forced_closed',
    ]);
    cashFlowMultiAssert(count($forcedSessions) === 1, 'forced close session should appear with forced_closed status');
    cashFlowMultiAssert(($forcedSessions[0]['status'] ?? '') === 'forced_closed', 'session status should be forced_closed');

    $openSessions = $period->sessions($conn, [
        'date_from' => $today,
        'date_to' => $today,
        'tenant' => 1,
        'status' => 'open',
    ]);
    cashFlowMultiAssert(count($openSessions) === 1, 'only branch 1 session should remain open');
    cashFlowMultiAssert((int) $openSessions[0]['id'] === $branchOneId, 'open session should be branch 1');

    // Simulate an interrupted or migrated close where status was persisted but
    // no physical closing count exists. It must remain unknown, not balanced.
    $_SESSION = ['userid' => 23, 'pos_tenant' => 1, 'pos_branch' => 3];
    $pending = $drawer->openSession($conn, [
        'user_id' => 23,
        'tenant' => 1,
        'branch' => 3,
        'opening_cash' => '120.000',
    ]);
    $pendingId = (int) $pending['id'];
    $conn->query("UPDATE drawer_sessions SET status = 'closed', closed_at = NOW(), counted_cash = NULL, difference = NULL WHERE id = {$pendingId}");

    $pendingBreakdown = $drawer->sessionCashBreakdown($conn, $pendingId);
    cashFlowMultiAssert(($pendingBreakdown['count_pending'] ?? false) === true, 'closed session without a count should be pending');
    cashFlowMultiAssert(array_key_exists('counted_cash', $pendingBreakdown) && $pendingBreakdown['counted_cash'] === null, 'pending close count should remain null');
    cashFlowMultiAssert(array_key_exists('close_variance', $pendingBreakdown) && $pendingBreakdown['close_variance'] === null, 'pending close variance should remain unknown');

    $pendingSessions = $period->sessions($conn, [
        'date_from' => $today,
        'date_to' => $today,
        'tenant' => 1,
        'branch' => 3,
        'status' => 'closed',
    ]);
    cashFlowMultiAssert(count($pendingSessions) === 1, 'pending session should be returned by cash-flow sessions');
    cashFlowMultiAssert(($pendingSessions[0]['count_pending'] ?? false) === true, 'cash-flow session should identify pending close count');
    cashFlowMultiAssert($pendingSessions[0]['counted_cash'] === null, 'cash-flow session must not coerce a missing count to zero');
    cashFlowMultiAssert($pendingSessions[0]['close_variance'] === null, 'cash-flow session must not coerce unknown variance to zero');

    $pendingSummary = $period->summary($conn, [
        'date_from' => $today,
        'date_to' => $today,
        'tenant' => 1,
        'branch' => 3,
    ]);
    cashFlowMultiAssert((int) ($pendingSummary['count_pending_session_count'] ?? 0) === 1, 'summary should count sessions awaiting a close count');
    cashFlowMultiAssert(abs((float) ($pendingSummary['count_pending_expected_cash'] ?? 0) - 120.0) < 0.01, 'summary should expose expected cash awaiting count');
    cashFlowMultiAssert(abs((float) ($pendingSummary['close_variance_rollup'] ?? 0)) < 0.01, 'unknown variance must be excluded from variance rollup');

    echo "cash-flow-multi-session-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function cashFlowMultiCreateSchema(mysqli $conn): void
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
            (21, 'cashier_b1', 'x', 3),
            (22, 'cashier_b2', 'x', 3),
            (23, 'cashier_b3', 'x', 3),
            (99, 'manager_force', 'x', 2)
    ");
}

function cashFlowMultiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
