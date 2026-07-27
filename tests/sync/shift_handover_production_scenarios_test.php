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
require_once __DIR__ . '/../../classes/Pos/Service/PosRegisterService.php';

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

    $registerService = new PosRegisterService();
    $register = $registerService->ensureDefaultRegister($conn, 1, 9);
    $_COOKIE[PosRegisterService::COOKIE_NAME] = (string) ($register['_pairing_token_once'] ?? '');
    $_SESSION['pos_register_id'] = (int) $register['id'];

    $float = new DrawerFloatExpectationService();
    $count = new ShiftCountService();
    $drawer = new DrawerSessionService();

    prodAssert($count->handoverEnabled($conn), 'handover enabled');

    // Fresh branch with handover enabled must require opening count even before
    // any drawer_sessions rows exist (cold-start / first shift).
    prodAssert($count->needsOpeningCount($conn, 501), 'fresh branch requires opening count');

    $conn->query('CREATE TABLE users (id INT PRIMARY KEY, uname VARCHAR(50) NOT NULL DEFAULT \'\', display_name VARCHAR(50) NULL)');
    $conn->query('INSERT INTO users (id, uname, display_name) VALUES (501, \'prod-test-cashier\', \'Prod Cashier\')');
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

    $float->setOpeningBaseline($conn, 1, 9, '200.000', 501);
    prodAssert($float->canSetOpeningBaseline($conn, 1, 9), 'baseline mutable before any session');

    $count->beginOpenCount($conn, 501);
    $opened = $count->submitOpenCount($conn, 501, '200.000');
    prodAssert(($opened['status'] ?? '') === 'opened', 'matched open on baseline');

    $baselineLocked = false;
    try {
        $float->setOpeningBaseline($conn, 1, 9, '250.000', 501);
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
    prodAssert(($closeFinal['status'] ?? '') === 'counted_pending_review', 'nonzero close count waits for authorized variance review');
    prodAssert(!empty($closeFinal['close_token']), 'close token issued');
    $pendingReview = $drawer->sessionById($conn, $sessionId);
    prodAssert(($pendingReview['status'] ?? '') === 'open', 'pending variance must not finalize the drawer or Z report');
    prodAssert(($pendingReview['variance_status'] ?? '') === 'counted_pending_review', 'pending variance state is durable');
    prodAssert(
        CashAmount::compare($pendingReview['counted_cash'] ?? '0.00', '240.00') === 0,
        'pending variance preserves counted cash'
    );

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

    $unresolved = $count->unresolvedSessions($conn, 1, 9);
    prodAssert(count($unresolved) >= 1, 'variance-review queue lists counted-pending session');
    prodAssert(($unresolved[0]['variance_status'] ?? '') === 'counted_pending_review', 'queue preserves pending-review state');

    $resolvedCount = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM drawer_session_resolutions WHERE drawer_session_id = {$sessionId}"
    )->fetch_assoc()['c'];
    prodAssert($resolvedCount === 0, 'no resolution before admin resolve');

    $_SESSION['usrole'] = 1;
    $certifiedRejected = false;
    try {
        $count->resolveSession($conn, 501, $sessionId, [
            'resolution_reason_code' => 'recount_confirmed',
            'resolution_notes' => 'Must not resolve without a journal subsystem.',
        ], ['financial_certified_mode' => true]);
    } catch (RuntimeException $exception) {
        $certifiedRejected = $exception->getMessage() === 'LEDGER_SUBSYSTEM_UNAVAILABLE';
    }
    prodAssert($certifiedRejected, 'certified mode rejects variance resolution without a durable journal');
    $afterRejectedResolve = $drawer->sessionById($conn, $sessionId);
    prodAssert(($afterRejectedResolve['variance_status'] ?? '') === 'counted_pending_review', 'failed certified resolution rolls back status');

    $resolution = $count->resolveSession($conn, 501, $sessionId, [
        'resolution_reason_code' => 'recount_confirmed',
        'resolution_notes' => 'Manager reviewed the blind recount.',
    ]);
    prodAssert(($resolution['variance_status'] ?? '') === 'resolved', 'authorized reason resolves pending variance');
    prodAssert(
        CashAmount::compare($resolution['variance_amount'] ?? '0.00', '-15.00') === 0,
        'resolution uses durable server variance'
    );

    $reviewedBegin = $count->beginCloseCount($conn, 501, ['drawer_session_id' => $sessionId]);
    prodAssert(!empty($reviewedBegin['reviewed_variance']), 'resolved variance can resume final close without a third count attempt');
    $reviewedFinal = $count->submitCloseCount($conn, 501, '240.000', ['drawer_session_id' => $sessionId]);
    prodAssert(($reviewedFinal['status'] ?? '') === 'ready_to_close_after_review', 'reviewed count is eligible for final close');
    $closed = $count->closeWithValidatedCount($conn, 501, [
        'close_token' => $reviewedFinal['close_token'],
        'counted_cash' => '240.000',
        'matched' => false,
        'drawer_session_id' => $sessionId,
        'notes' => 'Reviewed shift close',
    ]);
    prodAssert((int) ($closed['drawer_session_id'] ?? 0) === $sessionId, 'final close uses reviewed drawer');
    $closedRow = $drawer->sessionById($conn, $sessionId);
    prodAssert(($closedRow['status'] ?? '') === 'closed', 'final Z close occurs only after review');
    prodAssert(($closedRow['variance_status'] ?? '') === 'resolved', 'final close preserves resolved variance status');

    echo "shift_handover_production_scenarios_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
