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
$db = 'posmain_shift_payin_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    shiftPayinIntegrationCreateSchema($conn);
    (new SyncSchemaManager())->apply($conn);

    $cashierId = 11;
    $_SESSION = [
        'login' => 'payin_cashier',
        'userid' => $cashierId,
    ];

    $service = new ShiftSessionService();
    $opened = $service->openForCashier($conn, $cashierId, ['opening_cash' => '100.000']);
    shiftPayinIntegrationAssert($opened['status'] === 'open', 'shift should open');
    shiftPayinIntegrationAssert((int) ($opened['fund_account_id'] ?? 0) > 0, 'drawer session should resolve fund account');

    $recorded = $service->recordShiftPayIn($conn, $cashierId, [
        'amount' => '25.00',
        'reason' => 'تعبئة صندوق',
        'idempotency_key' => 'shift-payin-record-25',
    ]);
    shiftPayinIntegrationAssert($recorded['movement']['movement_type'] === 'paid_in', 'movement should be paid_in');
    shiftPayinIntegrationAssert(abs((float) $recorded['summary']['total'] - 25.0) < 0.01, 'payin total expected');
    shiftPayinIntegrationAssert((int) ($recorded['movement']['ref_ot_head_id'] ?? 0) > 0, 'payin should link voucher');

    $summary = $service->shiftPayInSummary($conn, $cashierId);
    shiftPayinIntegrationAssert($summary['count'] === 1, 'one payin expected');
    shiftPayinIntegrationAssert(abs((float) $summary['expected_cash'] - 125.0) < 0.01, 'expected cash should include payin');

    $voucher = $conn->query('SELECT pro_tybe, pro_value, acc1, acc2 FROM ot_head ORDER BY id DESC LIMIT 1')->fetch_assoc();
    shiftPayinIntegrationAssert((int) $voucher['pro_tybe'] === 1, 'payin voucher should be receipt');
    shiftPayinIntegrationAssert(abs((float) $voucher['pro_value'] - 25.0) < 0.01, 'voucher amount expected');

    $journal = $conn->query('SELECT SUM(debit) AS debit_total, SUM(credit) AS credit_total FROM journal_entries')->fetch_assoc();
    shiftPayinIntegrationAssert(abs((float) $journal['debit_total'] - 25.0) < 0.01, 'journal debit expected');
    shiftPayinIntegrationAssert(abs((float) $journal['credit_total'] - 25.0) < 0.01, 'journal credit expected');

    $closed = $service->closeSimpleShift($conn, $cashierId, [
        'cash' => 125,
        'fund_after' => 125,
    ]);
    shiftPayinIntegrationAssert($closed['close_summary_id'] > 0, 'close should create a close summary');

    $json = $conn->query('SELECT report_snapshot_json FROM drawer_session_close_summaries ORDER BY id DESC LIMIT 1')->fetch_assoc();
    $details = json_decode((string) ($json['report_snapshot_json'] ?? '{}'), true);
    shiftPayinIntegrationAssert(abs((float) ($details['payin_total'] ?? 0) - 25.0) < 0.01, 'close json should include payin total');
    shiftPayinIntegrationAssert((int) ($details['payin_count'] ?? 0) === 1, 'close json should include payin count');

    shiftPayinIntegrationExpectException(function () use ($service, $conn, $cashierId) {
        $service->recordShiftPayIn($conn, $cashierId, [
            'amount' => '1.00',
            'reason' => 'late',
            'idempotency_key' => 'shift-payin-late',
        ]);
    }, 'SHIFT_WRITE_BLOCKED');

    echo "shift-payin-record-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function shiftPayinIntegrationCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(120) NOT NULL,
            password VARCHAR(255) NULL,
            usrole INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO users (id, uname, password, usrole) VALUES (11, 'payin_cashier', 'x', 3)");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            branch_id INT NULL,
            pro_tybe INT NULL,
            is_finance TINYINT(1) NULL,
            is_journal TINYINT(1) NULL,
            journal_tybe INT NULL,
            info VARCHAR(255) NULL,
            pro_date DATE NULL,
            pro_num INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(15,4) NULL,
            cost_center INT NULL,
            user VARCHAR(20) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            fat_total DECIMAL(15,4) NULL,
            fat_disc DECIMAL(15,4) NULL,
            fat_net DECIMAL(15,4) NULL,
            crtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NULL,
            total DECIMAL(15,4) NULL,
            jdate DATE NULL,
            details VARCHAR(255) NULL,
            op_id INT NULL,
            op2 INT NULL,
            user INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_entries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NULL,
            account_id INT NULL,
            debit DECIMAL(15,4) NULL,
            credit DECIMAL(15,4) NULL,
            tybe INT NULL,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            aname VARCHAR(120) NOT NULL,
            parent_id INT NOT NULL DEFAULT 0,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_acc_head_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted)
        VALUES (1, '121001', 'الصندوق الافتراضي', 0, 0, 0, 1, 0)
    ");
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            def_pos_client INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (id, def_pos_fund, isdeleted) VALUES (1, 1, 0)");
}

function shiftPayinIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shiftPayinIntegrationExpectException(callable $callback, string $expectedMessage): void
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
