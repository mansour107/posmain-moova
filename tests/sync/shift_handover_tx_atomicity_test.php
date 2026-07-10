<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../../includes/db_transaction.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCloseService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_tx_atomic_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function txAtomicAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
    $_SESSION['userid'] = 88;

    $conn->query("CREATE TABLE users (id INT PRIMARY KEY, uname VARCHAR(50) NOT NULL DEFAULT '')");
    $conn->query("INSERT INTO users (id, uname) VALUES (88, 'tx-cashier')");
    $conn->query("CREATE TABLE closed_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shift VARCHAR(50) NULL,
        date DATE NULL,
        user VARCHAR(50) NULL,
        endtime TIME NULL,
        total_sales DOUBLE NULL,
        expenses DOUBLE NULL,
        exp_notes VARCHAR(255) NULL,
        cash DOUBLE NULL,
        fund_after DOUBLE NULL,
        info TEXT NULL,
        json_details TEXT NULL,
        drawer_session_id BIGINT NULL
    )");
    $conn->query("CREATE TABLE ot_head (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pro_date DATE NULL,
        user VARCHAR(50) NULL,
        pro_tybe INT NULL,
        isdeleted TINYINT NULL,
        fat_total DOUBLE NULL,
        fat_disc DOUBLE NULL,
        fat_net DOUBLE NULL
    )");

    $floatService = new DrawerFloatExpectationService();
    $countService = new ShiftCountService();
    $drawer = new DrawerSessionService();
    $floatService->setOpeningBaseline($conn, 1, 2, 100.0, 88);

    $countService->beginOpenCount($conn, 88);
    $_POST = ['counted_amount' => '100.000', 'idempotency_key' => 'tx-open-' . getmypid()];
    $opened = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_open_count',
        $_POST,
        [],
        88,
        static function (array $txContext = []) use ($conn): array {
            return [
                'success' => true,
                'data' => (new ShiftCountService())->submitOpenCount($conn, 88, '100.000', $txContext),
            ];
        }
    );
    txAtomicAssert(($opened['success'] ?? false) === true, 'open under idempotency succeeds');
    $sessionId = (int) ($opened['data']['drawer_session_id'] ?? 0);
    txAtomicAssert($sessionId > 0, 'session opened');

    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'sale_cash',
        'amount' => '40.000',
        'created_by' => 88,
    ]);
    $_SESSION['pos_drawer_session_id'] = $sessionId;
    $_SESSION['pos_shift_closed_for_session'] = false;

    $countService->beginCloseCount($conn, 88, ['drawer_session_id' => $sessionId]);
    $closeCount = $countService->submitCloseCount($conn, 88, '140.000', ['drawer_session_id' => $sessionId]);
    $token = (string) ($closeCount['close_token'] ?? '');
    txAtomicAssert($token !== '', 'close token issued');

    // Fail after money writes but before idempotency complete → full rollback.
    $_POST = [
        'counted_cash' => '140.000',
        'fund_after' => '140.000',
        'close_token' => $token,
        'matched' => '1',
        'drawer_session_id' => (string) $sessionId,
        'idempotency_key' => 'tx-close-fail-' . getmypid(),
    ];

    $failed = false;
    try {
        pos_shift_handover_idempotent(
            $conn,
            'pos.shift.close',
            $_POST,
            [],
            88,
            static function (array $txContext = []) use ($conn, $token, $sessionId): array {
                (new ShiftSessionService())->closeSimpleShift($conn, 88, [
                    'close_token' => $token,
                    'counted_cash' => 140.0,
                    'fund_after' => 140.0,
                    'cash' => 140.0,
                    'matched' => true,
                    'drawer_session_id' => $sessionId,
                    'bypass_count_token' => false,
                ], $txContext);

                throw new RuntimeException('FORCED_FAIL_AFTER_MONEY_WRITES');
            }
        );
    } catch (RuntimeException $exception) {
        $failed = $exception->getMessage() === 'FORCED_FAIL_AFTER_MONEY_WRITES';
    }

    txAtomicAssert($failed, 'handler failure propagated');

    $closedOrders = (int) $conn->query('SELECT COUNT(*) AS c FROM closed_orders')->fetch_assoc()['c'];
    txAtomicAssert($closedOrders === 0, 'closed_orders rolled back after fail-after-write');

    $session = $drawer->sessionById($conn, $sessionId);
    txAtomicAssert(($session['status'] ?? '') === 'open', 'drawer still open after rollback');

    $keyStatus = $conn->query("
        SELECT status FROM pos_request_keys
        WHERE scope = 'pos.shift.close'
        ORDER BY id DESC LIMIT 1
    ")->fetch_assoc();
    txAtomicAssert(($keyStatus['status'] ?? '') !== 'completed', 'idempotency key not completed after rollback');

    // Successful close under same outer TX pattern.
    $_SESSION['pos_shift_closed_for_session'] = false;
    $_SESSION['pos_drawer_session_id'] = $sessionId;
    $countService->beginCloseCount($conn, 88, ['drawer_session_id' => $sessionId]);
    $closeCount2 = $countService->submitCloseCount($conn, 88, '140.000', ['drawer_session_id' => $sessionId]);
    $token2 = (string) ($closeCount2['close_token'] ?? '');
    $_POST = [
        'counted_cash' => '140.000',
        'fund_after' => '140.000',
        'close_token' => $token2,
        'matched' => '1',
        'drawer_session_id' => (string) $sessionId,
        'idempotency_key' => 'tx-close-ok-' . getmypid(),
    ];

    $ok = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.close',
        $_POST,
        [],
        88,
        static function (array $txContext = []) use ($conn, $token2, $sessionId): array {
            $result = (new ShiftSessionService())->closeSimpleShift($conn, 88, [
                'close_token' => $token2,
                'counted_cash' => 140.0,
                'fund_after' => 140.0,
                'cash' => 140.0,
                'matched' => true,
                'drawer_session_id' => $sessionId,
            ], $txContext);

            return ['success' => true, 'result' => $result];
        }
    );
    txAtomicAssert(($ok['success'] ?? false) === true, 'successful atomic close');

    $closedOrders = (int) $conn->query('SELECT COUNT(*) AS c FROM closed_orders')->fetch_assoc()['c'];
    txAtomicAssert($closedOrders === 1, 'closed_orders committed once');
    $session = $drawer->sessionById($conn, $sessionId);
    txAtomicAssert(($session['status'] ?? '') === 'closed', 'drawer closed');
    txAtomicAssert(abs((float) ($session['difference'] ?? 0)) < 0.001, 'matched close difference ~0');

    // Nested begin must not fire when in_transaction is set (contract on helpers).
    $owns = posmain_tx_begin_if_needed($conn, true);
    txAtomicAssert($owns === false, 'beginIfNeeded skips when already in TX');

    echo "shift_handover_tx_atomicity_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
