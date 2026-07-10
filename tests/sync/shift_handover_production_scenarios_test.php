<?php

/**
 * Production-style shift handover scenarios via services (no browser).
 * Complements Playwright specs in tests/e2e/manager/shift-handover-production.spec.ts
 */

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_handover_prod_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function prodAssert(bool $condition, string $message): void
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
    $_SESSION['pos_branch'] = 9;
    $_SESSION['userid'] = 501;

    $float = new DrawerFloatExpectationService();
    $count = new ShiftCountService();
    $drawer = new DrawerSessionService();

    prodAssert($count->handoverEnabled($conn), 'handover enabled');

    // Fresh branch with handover enabled must require opening count even before
    // any drawer_sessions rows exist (cold-start / first shift).
    prodAssert($count->needsOpeningCount($conn, 501), 'fresh branch requires opening count');

    $conn->query('CREATE TABLE users (id INT PRIMARY KEY, uname VARCHAR(50) NOT NULL DEFAULT \'\', display_name VARCHAR(50) NULL)');
    $conn->query('INSERT INTO users (id, uname, display_name) VALUES (501, \'prod-test-cashier\', \'Prod Cashier\')');
    $conn->query('CREATE TABLE closed_orders (
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
    )');
    $conn->query('CREATE TABLE ot_head (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pro_date DATE NULL,
        user VARCHAR(50) NULL,
        pro_tybe INT NULL,
        isdeleted TINYINT NULL,
        fat_total DOUBLE NULL,
        fat_disc DOUBLE NULL,
        fat_net DOUBLE NULL
    )');

    $cold = $float->expectedOpeningFloat($conn, 1, 9);
    prodAssert(!empty($cold['baseline_required']), 'cold branch requires baseline');

    $float->setOpeningBaseline($conn, 1, 9, 200.0, 501);
    prodAssert($float->canSetOpeningBaseline($conn, 1, 9), 'baseline mutable before any session');

    $count->beginOpenCount($conn, 501);
    $opened = $count->submitOpenCount($conn, 501, '200.000');
    prodAssert(($opened['status'] ?? '') === 'opened', 'matched open on baseline');

    $baselineLocked = false;
    try {
        $float->setOpeningBaseline($conn, 1, 9, 250.0, 501);
    } catch (RuntimeException $exception) {
        $baselineLocked = $exception->getMessage() === 'BASELINE_LOCKED';
    }
    prodAssert($baselineLocked, 'baseline locked after session exists');

    $sessionId = (int) ($opened['drawer_session_id'] ?? 0);
    prodAssert($sessionId > 0, 'session created');

    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'sale_cash',
        'amount' => '45.000',
        'created_by' => 501,
    ]);
    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'paid_in',
        'amount' => '10.000',
        'created_by' => 501,
    ]);

    $_SESSION['pos_drawer_session_id'] = $sessionId;
    if (function_exists('posmain_begin_pos_shift_session')) {
        posmain_begin_pos_shift_session(501);
    }

    $count->beginCloseCount($conn, 501, ['drawer_session_id' => $sessionId]);
    $closeWrong = $count->submitCloseCount($conn, 501, '240.000', ['drawer_session_id' => $sessionId]);
    prodAssert(($closeWrong['status'] ?? '') === 'recount', 'first close mismatch recounts');
    $closeFinal = $count->submitCloseCount($conn, 501, '240.000', ['drawer_session_id' => $sessionId]);
    prodAssert(in_array($closeFinal['status'] ?? '', ['close_with_variance', 'ready_to_close'], true), 'close completes after max attempts');
    prodAssert(!empty($closeFinal['close_token']), 'close token issued');

    $closeKey = 'prod-close-count-' . getmypid();
    $_POST = [
        'counted_amount' => '240.000',
        'idempotency_key' => $closeKey,
    ];
    $closeOnce = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_close_count',
        $_POST,
        [],
        501,
        static function () use ($closeFinal): array {
            return ['success' => true, 'data' => $closeFinal];
        }
    );
    prodAssert(($closeOnce['success'] ?? false) === true, 'close count idempotency first call');

    $closeReplay = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_close_count',
        $_POST,
        [],
        501,
        static function (): array {
            throw new RuntimeException('handler should not run on replay');
        }
    );
    prodAssert(($closeReplay['success'] ?? false) === true, 'close count idempotency replay');
    prodAssert(!empty($closeReplay['idempotency_replayed']), 'close count replay flagged');

    $attempts = $count->countAttemptsForSession($conn, $sessionId);
    prodAssert(count($attempts) >= 2, 'open and close attempts recorded');

    $conn->query("UPDATE drawer_sessions SET variance_status = 'unresolved', variance_type = 'closing' WHERE id = {$sessionId}");
    $unresolved = $count->unresolvedSessions($conn, 1, 9);
    prodAssert(count($unresolved) >= 1, 'unresolved queue lists variance session');

    $resolvedCount = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM drawer_session_resolutions WHERE drawer_session_id = {$sessionId}"
    )->fetch_assoc()['c'];
    prodAssert($resolvedCount === 0, 'no resolution before admin resolve');

    echo "shift_handover_production_scenarios_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
