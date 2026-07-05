<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_shift_expense_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    shiftExpenseIntegrationCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $cashierId = 9;
    $_SESSION = [
        'login' => 'expense_cashier',
        'userid' => $cashierId,
    ];

    $service = new ShiftSessionService();
    $opened = $service->openForCashier($conn, $cashierId, ['opening_cash' => '100.000']);
    shiftExpenseIntegrationAssert($opened['status'] === 'open', 'shift should open');

    $recorded = $service->recordShiftExpense($conn, $cashierId, [
        'amount' => 15.5,
        'reason' => 'توصيل طلب',
    ]);
    shiftExpenseIntegrationAssert($recorded['movement']['movement_type'] === 'paid_out', 'movement should be paid_out');
    shiftExpenseIntegrationAssert(abs((float) $recorded['summary']['total'] - 15.5) < 0.01, 'expense total expected');

    $service->recordShiftExpense($conn, $cashierId, [
        'amount' => 4.5,
        'reason' => 'مشتريات صغيرة',
    ]);

    $summary = $service->shiftExpenseSummary($conn, $cashierId);
    shiftExpenseIntegrationAssert($summary['count'] === 2, 'two expenses expected');
    shiftExpenseIntegrationAssert(abs((float) $summary['total'] - 20.0) < 0.01, 'combined expense total expected');
    shiftExpenseIntegrationAssert(strpos($summary['notes'], 'توصيل') !== false, 'notes should include first reason');

    $expectedCash = (float) $summary['expected_cash'];
    shiftExpenseIntegrationAssert(abs($expectedCash - 80.0) < 0.01, 'expected cash should subtract paid_out from opening');

    $closed = $service->closeSimpleShift($conn, $cashierId, [
        'cash' => 80,
        'fund_after' => 80,
        'expenses' => 999,
        'exp_notes' => 'ignored when drawer active',
    ]);
    shiftExpenseIntegrationAssert(abs((float) $closed['total_sales'] >= 0), 'close should succeed');

    $row = $conn->query('SELECT expenses, exp_notes FROM closed_orders ORDER BY id DESC LIMIT 1')->fetch_assoc();
    shiftExpenseIntegrationAssert(abs((float) $row['expenses'] - 20.0) < 0.01, 'close should persist drawer expense total not POST override');
    shiftExpenseIntegrationAssert($row['exp_notes'] === 'ignored when drawer active', 'close should keep provided notes when present');

    shiftExpenseIntegrationExpectException(function () use ($service, $conn, $cashierId) {
        $service->recordShiftExpense($conn, $cashierId, ['amount' => 1, 'reason' => 'late']);
    }, 'SHIFT_WRITE_BLOCKED');

    echo "shift-expense-record-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function shiftExpenseIntegrationCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(120) NOT NULL,
            password VARCHAR(255) NULL,
            usrole INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO users (id, uname, password, usrole) VALUES (9, 'expense_cashier', 'x', 3)");
    $conn->query("
        CREATE TABLE closed_orders (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            shift VARCHAR(64) NULL,
            date DATE NULL,
            user VARCHAR(120) NULL,
            endtime TIME NULL,
            total_sales DECIMAL(15,4) NULL,
            expenses DECIMAL(15,4) NULL,
            exp_notes VARCHAR(30) NULL,
            cash DECIMAL(15,4) NULL,
            fund_after DECIMAL(15,4) NULL,
            info TEXT NULL,
            json_details JSON NULL
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

function shiftExpenseIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shiftExpenseIntegrationExpectException(callable $callback, string $expectedMessage): void
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
