<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/ShiftReport.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_shift_session_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    shiftSessionIntegrationCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $cashierId = 7;
    $cashierName = 'shift_cashier';
    $today = date('Y-m-d');
    $beforeOpen = date('Y-m-d H:i:s', time() - 3600);
    $afterOpen = date('Y-m-d H:i:s', time() - 60);

    $conn->query("
        INSERT INTO users (id, uname, password, usrole)
        VALUES ({$cashierId}, '{$cashierName}', 'x', 3)
    ");

    // Sale from a prior shift today — must not appear in the new shift close.
    $conn->query("
        INSERT INTO ot_head (id, pro_date, user, pro_tybe, fat_net, isdeleted, crtime)
        VALUES (100, '{$today}', '{$cashierId}', 9, 50.00, 0, '{$beforeOpen}')
    ");

    $_SESSION = [
        'login' => $cashierName,
        'userid' => $cashierId,
    ];
    $service = new ShiftSessionService();
    $opened = $service->openForCashier($conn, $cashierId, [
        'opening_cash' => '0',
        'opened_at' => date('Y-m-d H:i:s', time() - 120),
    ]);
    $unlockedSession = $_SESSION;
    shiftSessionIntegrationAssert($opened['status'] === 'open', 'openForCashier should return open drawer session');
    shiftSessionIntegrationAssert(!empty($_SESSION['pos_drawer_session_id']), 'session should store drawer id');
    shiftSessionIntegrationAssert(!empty($_SESSION['pos_authenticated']), 'session should be barcode unlocked');

    $openStatus = $service->sessionStatus($conn, $unlockedSession);
    shiftSessionIntegrationAssert($openStatus['shift_open'] === true, 'unlocked session should report shift open');

    $conn->query("
        INSERT INTO ot_head (id, pro_date, user, pro_tybe, fat_net, isdeleted, crtime)
        VALUES (101, '{$today}', '{$cashierId}', 9, 25.00, 0, '{$afterOpen}')
    ");

    $report = new ShiftReport($conn, $cashierId, $today, [
        'shift_opened_at' => $opened['opened_at'],
        'drawer_session_id' => (int) $opened['id'],
    ]);
    $totals = $report->getTotals();
    shiftSessionIntegrationAssert((int) $totals['total_orders'] === 1, 'active shift should include only post-open sales');
    shiftSessionIntegrationAssert(abs((float) $totals['total_net'] - 25.0) < 0.01, 'active shift net sales expected');

    $closed = $service->closeSimpleShift($conn, $cashierId, [
        'expenses' => 0,
        'cash' => 25,
        'fund_after' => 25,
    ]);
    shiftSessionIntegrationAssert((int) $closed['total_orders'] === 1, 'close should persist one order in shift');
    shiftSessionIntegrationAssert(!empty($_SESSION['pos_shift_closed_for_session']), 'close should mark session closed');
    shiftSessionIntegrationAssert(empty($_SESSION['pos_authenticated']), 'close should clear barcode auth');

    $status = $service->sessionStatus($conn, $_SESSION);
    shiftSessionIntegrationAssert($status['authenticated'] === false, 'closed session should not authorize writes');
    shiftSessionIntegrationAssert($status['shift_open'] === false, 'closed session should report shift closed');

    // Re-open a fresh shift — prior sales must not reappear.
    unset($_SESSION['pos_shift_closed_for_session']);
    $reopened = $service->openForCashier($conn, $cashierId, ['opening_cash' => '0']);
    shiftSessionIntegrationAssert($reopened['status'] === 'open', 'reopen should create a new open drawer session');
    shiftSessionIntegrationAssert((int) $reopened['id'] !== (int) $opened['id'], 'reopen should be a different drawer session');

    $emptyReport = new ShiftReport($conn, $cashierId, $today, [
        'shift_opened_at' => $reopened['opened_at'],
        'drawer_session_id' => (int) $reopened['id'],
    ]);
    $emptyTotals = $emptyReport->getTotals();
    shiftSessionIntegrationAssert((int) $emptyTotals['total_orders'] === 0, 'new shift should exclude prior shift sales');

    $conn->query("
        INSERT INTO ot_head (id, pro_date, user, pro_tybe, fat_net, isdeleted, crtime)
        VALUES (102, '{$today}', '{$cashierId}', 9, 10.00, 0, '" . date('Y-m-d H:i:s') . "')
    ");
    $secondClose = $service->closeSimpleShift($conn, $cashierId, [
        'expenses' => 0,
        'cash' => 10,
        'fund_after' => 10,
    ]);
    shiftSessionIntegrationAssert((int) $secondClose['total_orders'] === 1, 'second shift close should count only new sale');
    shiftSessionIntegrationAssert(abs((float) $secondClose['total_sales'] - 10.0) < 0.01, 'second shift sales total expected');

    // The legacy close_shift.php caller can still arrive without an explicitly
    // opened drawer. It must materialize and close one canonical session rather
    // than rejecting the cashier after the migration.
    unset($_SESSION['pos_shift_closed_for_session'], $_SESSION['pos_drawer_session_id']);
    $conn->query("
        INSERT INTO ot_head (id, pro_date, user, pro_tybe, fat_net, isdeleted, crtime)
        VALUES (103, '{$today}', '{$cashierId}', 9, 5.00, 0, '" . date('Y-m-d H:i:s') . "')
    ");
    $legacyClose = $service->closeSimpleShift($conn, $cashierId, [
        'expenses' => 0,
        'cash' => 5,
        'fund_after' => 5,
        'close_path' => 'close_shift.php',
    ]);
    shiftSessionIntegrationAssert(!empty($legacyClose['legacy_drawer_session_recovered']), 'legacy close without a drawer must report recovery');
    shiftSessionIntegrationAssert((int) $legacyClose['drawer_session_id'] > 0, 'legacy close must return the recovered drawer id');
    shiftSessionIntegrationAssert((int) $legacyClose['close_summary_id'] > 0, 'legacy close must persist a canonical close summary');
    $legacyDrawerId = (int) $legacyClose['drawer_session_id'];
    $legacyDrawer = $conn->query("SELECT status, notes FROM drawer_sessions WHERE id = {$legacyDrawerId}")->fetch_assoc();
    shiftSessionIntegrationAssert(($legacyDrawer['status'] ?? '') === 'closed', 'recovered legacy drawer must be closed atomically');
    shiftSessionIntegrationAssert(($legacyDrawer['notes'] ?? '') === 'legacy_close_shift_recovery', 'recovered drawer must retain an audit marker');

    shiftSessionIntegrationExpectException(function () use ($service, $conn, $cashierId) {
        $service->closeSimpleShift($conn, $cashierId, ['cash' => 0, 'fund_after' => 0]);
    }, 'SHIFT_ALREADY_CLOSED');

    echo "shift-session-open-close-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function shiftSessionIntegrationCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            def_pos_client INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (id, isdeleted) VALUES (1, 0)");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            parent_id INT NOT NULL DEFAULT 0,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
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
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            crtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function shiftSessionIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shiftSessionIntegrationExpectException(callable $callback, string $expectedMessage): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== $expectedMessage) {
            throw new RuntimeException('Expected ' . $expectedMessage . ', got ' . $exception->getMessage());
        }

        return;
    }

    throw new RuntimeException('Expected exception: ' . $expectedMessage);
}
