<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosRegisterService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_shift_handover_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function handoverAssert(bool $condition, string $message): void
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
    $_SESSION['userid'] = 55;
    $registerService = new PosRegisterService();
    $register = $registerService->ensureDefaultRegister($conn, 1, 2);
    $_COOKIE[PosRegisterService::COOKIE_NAME] = (string) ($register['_pairing_token_once'] ?? '');
    $_SESSION['pos_register_id'] = (int) $register['id'];

    $countService = new ShiftCountService();
    $shiftService = new ShiftSessionService();
    $drawer = new DrawerSessionService();
    $floatService = new DrawerFloatExpectationService();

    handoverAssert($countService->handoverEnabled($conn), 'handover should be enabled after migration');

    $floatService->setOpeningBaseline($conn, 1, 2, 0.0, 55);

    $countService->beginOpenCount($conn, 55);
    $openMatch = $countService->submitOpenCount($conn, 55, '0.000');
    handoverAssert(($openMatch['status'] ?? '') === 'opened', 'opening match on first attempt when expected is zero');
    handoverAssert((int) ($openMatch['drawer_session_id'] ?? 0) > 0, 'drawer session created');

    $sessionId = (int) $openMatch['drawer_session_id'];
    $openedSession = $drawer->sessionById($conn, $sessionId);
    handoverAssert(
        (int) ($openedSession['register_id'] ?? 0) === (int) $register['id'],
        'opening count must preserve paired register ownership'
    );
    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'sale_cash',
        'amount' => '40.000',
        'created_by' => 55,
    ]);

    require_once __DIR__ . '/../../includes/auth_guard.php';
    $_SESSION['pos_drawer_session_id'] = $sessionId;
    posmain_begin_pos_shift_session(55);
    $_SESSION['pos_shift_closed_for_session'] = false;

    $countService->beginCloseCount($conn, 55, ['drawer_session_id' => $sessionId]);
    $closeMatch = $countService->submitCloseCount($conn, 55, '40.000', ['drawer_session_id' => $sessionId]);
    handoverAssert(($closeMatch['status'] ?? '') === 'ready_to_close', 'close should match expected 140');
    handoverAssert(!empty($closeMatch['close_token']), 'close token issued');

    $attemptRows = $countService->countAttemptsForSession($conn, $sessionId);
    handoverAssert(count($attemptRows) >= 1, 'should record close attempt');

    $drawer->closeSession($conn, $sessionId, [
        'closed_by' => 55,
        'counted_cash' => '40.000',
    ]);
    unset($_SESSION['pos_drawer_session_id'], $_SESSION['pos_shift_close_count'], $_SESSION['pos_shift_open_count']);

    $countService->beginOpenCount($conn, 55);
    $openVarianceFirst = $countService->submitOpenCount($conn, 55, '50.000');
    handoverAssert(($openVarianceFirst['status'] ?? '') === 'recount', 'first opening mismatch should recount');
    $openVarianceFinal = $countService->submitOpenCount($conn, 55, '45.000');
    handoverAssert(in_array($openVarianceFinal['status'] ?? '', ['opened_with_variance', 'opened'], true), 'second opening attempt completes');

    $varianceSessionId = (int) ($openVarianceFinal['drawer_session_id'] ?? 0);
    if (($openVarianceFinal['status'] ?? '') === 'opened_with_variance') {
        handoverAssert($varianceSessionId > 0, 'variance session created');
        $sessionRow = $drawer->sessionById($conn, $varianceSessionId);
        handoverAssert(($sessionRow['variance_status'] ?? '') === 'unresolved', 'opening variance unresolved');
    }

    echo "shift_count_handover_integration_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
