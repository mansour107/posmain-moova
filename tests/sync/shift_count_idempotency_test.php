<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosRegisterService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_shift_idem_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function idemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function idemSubmitOpen(mysqli $conn, int $userId, string $amount, string $key): array
{
    $_POST = [
        'counted_amount' => $amount,
        'idempotency_key' => $key,
    ];

    return pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_open_count',
        $_POST,
        [],
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $amount): array {
            $service = new ShiftCountService();

            return ['success' => true, 'data' => $service->submitOpenCount($conn, $userId, $amount, $txContext)];
        }
    );
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
    $_SESSION['userid'] = 77;

    $registerService = new PosRegisterService();
    $register = $registerService->ensureDefaultRegister($conn, 1, 2);
    $_COOKIE[PosRegisterService::COOKIE_NAME] = (string) ($register['_pairing_token_once'] ?? '');
    $_SESSION['pos_register_id'] = (int) $register['id'];

    $countService = new ShiftCountService();
    $drawer = new DrawerSessionService();
    $floatService = new DrawerFloatExpectationService();

    idemAssert($countService->handoverEnabled($conn), 'handover enabled');

    $conn->query("CREATE TABLE users (id INT PRIMARY KEY, uname VARCHAR(50) NOT NULL DEFAULT '')");
    $conn->query("INSERT INTO users (id, uname) VALUES (77, 'test-cashier')");
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

    $floatService->setOpeningBaseline($conn, 1, 2, '100.000', 77);

    // This is the POS browser state that displays the opening-count overlay.
    // A successful count must clear it once the drawer session exists.
    $_SESSION['posmain_shift_entry_state'] = 'open_count_pending';
    $_SESSION['posmain_shift_entry_message'] = 'opening';
    $_SESSION['posmain_shift_blocking'] = ['id' => 999];

    $countService->beginOpenCount($conn, 77);
    $key = 'test-open-' . getmypid();
    $first = idemSubmitOpen($conn, 77, '100.000', $key);
    idemAssert(($first['success'] ?? false) === true, 'first open submit succeeds');
    idemAssert(($first['data']['status'] ?? '') === 'opened', 'first open completes');

    $sessionId = (int) ($first['data']['drawer_session_id'] ?? 0);
    idemAssert($sessionId > 0, 'drawer session created');
    idemAssert(!isset($_SESSION['posmain_shift_entry_state']), 'successful opening count clears stale POS entry state');
    idemAssert(!isset($_SESSION['posmain_shift_entry_message']), 'successful opening count clears stale POS entry message');
    idemAssert(!isset($_SESSION['posmain_shift_blocking']), 'successful opening count clears stale drawer blocking payload');

    $attemptCount = (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_count_attempts WHERE count_phase = 'open'")->fetch_assoc()['c'];
    idemAssert($attemptCount === 1, 'single open attempt row');

    $replay = idemSubmitOpen($conn, 77, '100.000', $key);
    idemAssert(($replay['success'] ?? false) === true, 'replay succeeds');
    idemAssert(!empty($replay['idempotency_replayed']), 'replay flagged');
    idemAssert((int) ($replay['data']['drawer_session_id'] ?? 0) === $sessionId, 'replay returns same session');

    $attemptCountAfter = (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_count_attempts WHERE count_phase = 'open'")->fetch_assoc()['c'];
    idemAssert($attemptCountAfter === 1, 'replay does not duplicate attempt');

    $openSessions = (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_sessions WHERE status = 'open'")->fetch_assoc()['c'];
    idemAssert($openSessions === 1, 'replay does not duplicate open session');

    $_POST = [
        'counted_amount' => '999.000',
        'idempotency_key' => $key,
    ];
    $conflict = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_open_count',
        $_POST,
        [],
        77,
        static function (): array {
            return ['success' => true, 'data' => []];
        }
    );
    idemAssert(($conflict['code'] ?? '') === 'IDEMPOTENCY_CONFLICT', 'conflicting payload rejected');

    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'sale_cash',
        'amount' => '50.000',
        'created_by' => 77,
    ]);
    $_SESSION['pos_drawer_session_id'] = $sessionId;
    if (function_exists('posmain_begin_pos_shift_session')) {
        posmain_begin_pos_shift_session(77);
    }
    $_SESSION['pos_shift_closed_for_session'] = false;

    $countService->beginCloseCount($conn, 77, ['drawer_session_id' => $sessionId]);
    $closeKey = 'test-close-' . getmypid();
    $_POST = [
        'counted_amount' => '150.000',
        'idempotency_key' => $closeKey,
    ];
    $closeSubmit = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_close_count',
        $_POST,
        [],
        77,
        static function () use ($conn, $sessionId): array {
            $service = new ShiftCountService();

            return [
                'success' => true,
                'data' => $service->submitCloseCount($conn, 77, '150.000', ['drawer_session_id' => $sessionId]),
            ];
        }
    );
    idemAssert(($closeSubmit['success'] ?? false) === true, 'close count submit succeeds');
    idemAssert(!empty($closeSubmit['data']['close_token']), 'close token issued');

    $closeReplay = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_close_count',
        $_POST,
        [],
        77,
        static function () use ($conn, $sessionId): array {
            $service = new ShiftCountService();

            return [
                'success' => true,
                'data' => $service->submitCloseCount($conn, 77, '150.000', ['drawer_session_id' => $sessionId]),
            ];
        }
    );
    idemAssert(($closeReplay['success'] ?? false) === true, 'close count replay succeeds');
    idemAssert(!empty($closeReplay['idempotency_replayed']), 'close count replay flagged');

    $closeAttempts = (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_count_attempts WHERE count_phase = 'close'")->fetch_assoc()['c'];
    idemAssert($closeAttempts === 1, 'close count replay does not duplicate attempt');

    $conn->query('CREATE TABLE idem_close_markers (id INT AUTO_INCREMENT PRIMARY KEY)');
    $closeShiftKey = 'test-shift-close-' . getmypid();
    $_POST = [
        'counted_cash' => '150.000',
        'idempotency_key' => $closeShiftKey,
    ];

    $closeHandler = static function () use ($conn): array {
        $conn->query('INSERT INTO idem_close_markers () VALUES ()');

        return ['success' => true, 'result' => ['closed' => true]];
    };

    $closeOnce = pos_shift_handover_idempotent($conn, 'pos.shift.close', $_POST, [], 77, $closeHandler);
    idemAssert(($closeOnce['success'] ?? false) === true, 'shift close idempotency succeeds');

    $markerCount = (int) $conn->query('SELECT COUNT(*) AS c FROM idem_close_markers')->fetch_assoc()['c'];
    idemAssert($markerCount === 1, 'shift close handler runs once');

    $closeTwice = pos_shift_handover_idempotent($conn, 'pos.shift.close', $_POST, [], 77, $closeHandler);
    idemAssert(($closeTwice['success'] ?? false) === true, 'shift close replay succeeds');
    idemAssert(!empty($closeTwice['idempotency_replayed']), 'shift close replay flagged');

    $markerCountAfter = (int) $conn->query('SELECT COUNT(*) AS c FROM idem_close_markers')->fetch_assoc()['c'];
    idemAssert($markerCountAfter === 1, 'shift close replay does not rerun handler');

    echo "shift_count_idempotency_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
