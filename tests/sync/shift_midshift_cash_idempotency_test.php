<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_midshift_idem_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function midshiftIdemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function midshiftIdemPayIn(mysqli $conn, int $userId, string $amount, string $reason, string $key): array
{
    $_POST = [
        'amount' => $amount,
        'reason' => $reason,
        'idempotency_key' => $key,
    ];

    return pos_shift_handover_idempotent(
        $conn,
        'pos.shift.payin',
        $_POST,
        [],
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $amount, $reason): array {
            $result = (new ShiftSessionService())->recordShiftPayIn($conn, $userId, [
                'amount' => $amount,
                'reason' => $reason,
            ], $txContext);

            return ['success' => true, 'data' => $result];
        }
    );
}

function midshiftIdemPayout(mysqli $conn, int $userId, string $amount, string $reason, string $key): array
{
    $_POST = [
        'amount' => $amount,
        'reason' => $reason,
        'idempotency_key' => $key,
    ];

    return pos_shift_handover_idempotent(
        $conn,
        'pos.shift.payout',
        $_POST,
        [],
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $amount, $reason): array {
            $result = (new ShiftSessionService())->recordShiftExpense($conn, $userId, [
                'amount' => $amount,
                'reason' => $reason,
            ], $txContext);

            return ['success' => true, 'data' => $result];
        }
    );
}

function midshiftIdemSafeDrop(mysqli $conn, int $userId, string $amount, string $reason, string $key): array
{
    $_POST = [
        'amount' => $amount,
        'reason' => $reason,
        'idempotency_key' => $key,
    ];

    return pos_shift_handover_idempotent(
        $conn,
        'pos.shift.safe_drop',
        $_POST,
        [],
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $amount, $reason): array {
            $result = (new ShiftSessionService())->recordShiftSafeDrop($conn, $userId, [
                'amount' => $amount,
                'reason' => $reason,
            ], $txContext);

            return ['success' => true, 'data' => $result];
        }
    );
}

function midshiftIdemMovementCount(mysqli $conn, string $type): int
{
    $type = $conn->real_escape_string($type);
    $row = $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE movement_type = '{$type}'")->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $_SESSION['pos_tenant'] = 1;
    $_SESSION['pos_branch'] = 2;
    $_SESSION['userid'] = 55;
    $_SESSION['login'] = 'midshift_cashier';

    $conn->query("CREATE TABLE users (id INT PRIMARY KEY, uname VARCHAR(50) NOT NULL DEFAULT '', usrole INT NULL)");
    $conn->query("INSERT INTO users (id, uname, usrole) VALUES (55, 'midshift_cashier', 3)");
    $conn->query("CREATE TABLE ot_head (
        id INT AUTO_INCREMENT PRIMARY KEY,
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
    )");
    $conn->query("CREATE TABLE journal_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        journal_id INT NULL,
        total DECIMAL(15,4) NULL,
        jdate DATE NULL,
        details VARCHAR(255) NULL,
        op2 INT NULL,
        user INT NULL
    )");
    $conn->query("CREATE TABLE journal_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        journal_id INT NULL,
        account_id INT NULL,
        debit DECIMAL(15,4) NULL,
        credit DECIMAL(15,4) NULL,
        tybe INT NULL,
        op2 INT NULL
    )");
    $conn->query("CREATE TABLE acc_head (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        aname VARCHAR(120) NOT NULL,
        parent_id INT NOT NULL DEFAULT 0,
        is_basic TINYINT(1) NOT NULL DEFAULT 0,
        is_stock TINYINT(1) NOT NULL DEFAULT 0,
        is_fund TINYINT(1) NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_acc_head_code (code)
    )");
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted)
        VALUES (1, '121001', 'الصندوق الافتراضي', 0, 0, 0, 1, 0)");
    $conn->query("CREATE TABLE settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        def_pos_fund INT NULL,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0
    )");
    $conn->query("INSERT INTO settings (id, def_pos_fund, isdeleted) VALUES (1, 1, 0)");

    $service = new ShiftSessionService();
    $opened = $service->openForCashier($conn, 55, ['opening_cash' => '100.000']);
    midshiftIdemAssert(($opened['status'] ?? '') === 'open', 'shift should open for mid-shift cash tests');

    $payinKey = 'midshift-payin-' . getmypid();
    $firstPayIn = midshiftIdemPayIn($conn, 55, '10.000', 'فكة', $payinKey);
    midshiftIdemAssert(($firstPayIn['success'] ?? false) === true, 'first payin should succeed');
    $replayPayIn = midshiftIdemPayIn($conn, 55, '10.000', 'فكة', $payinKey);
    midshiftIdemAssert(($replayPayIn['success'] ?? false) === true, 'payin replay should succeed');
    midshiftIdemAssert(!empty($replayPayIn['idempotency_replayed']), 'payin replay should be marked replayed');
    midshiftIdemAssert(midshiftIdemMovementCount($conn, 'paid_in') === 1, 'payin double-submit must not duplicate movement');

    $conflictPayIn = midshiftIdemPayIn($conn, 55, '11.000', 'فكة مختلفة', $payinKey);
    midshiftIdemAssert(($conflictPayIn['code'] ?? '') === 'IDEMPOTENCY_CONFLICT', 'payin same key different payload should conflict');
    midshiftIdemAssert(midshiftIdemMovementCount($conn, 'paid_in') === 1, 'payin conflict must not create movement');

    $payoutKey = 'midshift-payout-' . getmypid();
    $firstPayout = midshiftIdemPayout($conn, 55, '5.000', 'مصروف', $payoutKey);
    midshiftIdemAssert(($firstPayout['success'] ?? false) === true, 'first payout should succeed');
    $replayPayout = midshiftIdemPayout($conn, 55, '5.000', 'مصروف', $payoutKey);
    midshiftIdemAssert(!empty($replayPayout['idempotency_replayed']), 'payout replay should be marked replayed');
    midshiftIdemAssert(midshiftIdemMovementCount($conn, 'paid_out') === 1, 'payout double-submit must not duplicate movement');

    $safeDropKey = 'midshift-safe-drop-' . getmypid();
    $firstDrop = midshiftIdemSafeDrop($conn, 55, '20.000', 'خزنة', $safeDropKey);
    midshiftIdemAssert(($firstDrop['success'] ?? false) === true, 'first safe drop should succeed');
    $replayDrop = midshiftIdemSafeDrop($conn, 55, '20.000', 'خزنة', $safeDropKey);
    midshiftIdemAssert(!empty($replayDrop['idempotency_replayed']), 'safe drop replay should be marked replayed');
    midshiftIdemAssert(midshiftIdemMovementCount($conn, 'safe_drop') === 1, 'safe drop double-submit must not duplicate movement');

    echo "shift-midshift-cash-idempotency-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
