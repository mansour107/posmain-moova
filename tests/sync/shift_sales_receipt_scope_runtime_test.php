<?php

/**
 * Runtime: a paid sale earlier in the business day but before the current drawer
 * open must not appear in shift-scoped receipt totals (same as close-shift preview).
 */

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/ShiftReport.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_shift_receipt_scope_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "shift-sales-receipt-scope-runtime-skipped-db-unavailable\n";
    exit(0);
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    shiftReceiptScopeCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);
    $conn->query("INSERT INTO users (id, uname, password, usrole) VALUES (35, 'p6_cashier', 'x', 3)");

    $cashierId = 35;
    $today = date('Y-m-d');
    $_SESSION = [
        'login' => 'p6_cashier',
        'userid' => $cashierId,
        'pos_tenant' => 0,
        'pos_branch' => 0,
    ];

    // Orphan/day sale before any drawer open for this cashier.
    $conn->query("
        INSERT INTO ot_head (id, pro_date, user, pro_tybe, fat_total, fat_disc, fat_net, payment_status, order_status, order_type, crtime, payment_date, completed_at, isdeleted)
        VALUES (501, '{$today}', '{$cashierId}', 9, 201.00, 0.00, 201.00, 'paid', 'completed', 'takeaway',
                '{$today} 13:28:09', '{$today} 16:28:09', '{$today} 16:28:09', 0)
    ");

    $shiftSessions = new ShiftSessionService();
    $opened = $shiftSessions->openForCashier($conn, $cashierId, [
        'tenant' => 0,
        'branch' => 0,
        'opening_cash' => '50.000',
    ]);
    shiftReceiptScopeAssert($opened['status'] === 'open', 'drawer should open');

    // Force opened_at after the orphan sale (mirrors live discrepancy).
    $openedAt = $today . ' 17:56:12';
    $sessionId = (int) $opened['id'];
    $conn->query("UPDATE drawer_sessions SET opened_at = '{$openedAt}' WHERE id = {$sessionId}");
    $_SESSION['pos_drawer_session_id'] = $sessionId;

    $context = $shiftSessions->buildShiftReportContext($conn, $cashierId);
    shiftReceiptScopeAssert((int) ($context['drawer_session']['id'] ?? 0) === $sessionId, 'context should use open drawer');
    shiftReceiptScopeAssert(($context['scope']['shift_opened_at'] ?? '') === $openedAt, 'context should expose shift window start');

    $report = new ShiftReport($conn, $cashierId, $context['business_day'], $context['scope']);
    $totals = $report->getTotals();
    shiftReceiptScopeAssert((int) ($totals['total_orders'] ?? -1) === 0, 'shift-scoped totals must exclude pre-open sale');
    shiftReceiptScopeAssert(abs((float) ($totals['total_net'] ?? -1)) < 0.01, 'shift-scoped net must be zero for current drawer');
    $orderTypes = $report->getOrderTypeCounts();
    shiftReceiptScopeAssert(($orderTypes['takeaway_count'] ?? -1) === 0, 'shift-scoped takeaway count must exclude pre-open sale');

    // Day-only query (old receipt behavior) still sees the orphan — proving the bug class.
    $dayOnly = $conn->query("
        SELECT COUNT(*) AS c, COALESCE(SUM(fat_net),0) AS net
        FROM ot_head
        WHERE DATE(pro_date) = '{$today}'
          AND user = '{$cashierId}'
          AND pro_tybe = 9
          AND payment_status = 'paid'
          AND order_status = 'completed'
          AND isdeleted = 0
    ")->fetch_assoc();
    shiftReceiptScopeAssert((int) $dayOnly['c'] === 1, 'day-only query still sees orphan sale');
    shiftReceiptScopeAssert(abs((float) $dayOnly['net'] - 201.0) < 0.01, 'day-only net still includes orphan sale');

    echo "shift-sales-receipt-scope-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function shiftReceiptScopeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            edit_pass VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(120) NOT NULL,
            password VARCHAR(255) NULL,
            usrole INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
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
            payment_status VARCHAR(20) NULL,
            order_status VARCHAR(20) NULL,
            order_type VARCHAR(20) NULL,
            crtime DATETIME NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("INSERT INTO settings (id, def_pos_client, edit_pass, isdeleted) VALUES (1, 501, '1234', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, isdeleted) VALUES
            (91, '400001', 'Sales', 0),
            (501, '122001', 'Walk-in Customer', 0),
            (51, '101001', 'Cash Drawer', 0)
    ");
}

function shiftReceiptScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
