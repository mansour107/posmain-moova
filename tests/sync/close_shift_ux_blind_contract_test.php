<?php

/**
 * Contract: get_shift_preview blinds nested expected_cash for non cash-flow users.
 * Runtime sticky close-count attempts + blind submitCloseCount response.
 */

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosRegisterService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function closeUxAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(__DIR__ . '/../../do/get_shift_preview.php');
closeUxAssert($source !== false, 'Unable to read get_shift_preview.php');
closeUxAssert(
    strpos($source, "unset(\$expenseSummary['expected_cash']") !== false
        || strpos($source, "\$expenseSummary['expected_cash'] = null") !== false,
    'preview must null nested expenses.expected_cash for blind users'
);
closeUxAssert(
    strpos($source, "\$payInSummary['expected_cash'] = null") !== false,
    'preview must null nested payins.expected_cash for blind users'
);
closeUxAssert(
    strpos($source, "\$safeDropSummary['expected_cash'] = null") !== false,
    'preview must null nested safe_drops.expected_cash for blind users'
);

$wizard = file_get_contents(__DIR__ . '/../../js/pos_shift_count_wizard.js');
closeUxAssert($wizard !== false, 'Unable to read pos_shift_count_wizard.js');
closeUxAssert(
    strpos($wizard, 'showCloseVariance') === false,
    'close path must not use showCloseVariance (auto-finalize instead)'
);
closeUxAssert(
    strpos($wizard, 'Never show over/short while the shift is still open') !== false,
    'wizard documents auto-close without pre-close variance'
);

$resetPos = strpos($wizard, 'resetCloseWizard: function');
closeUxAssert($resetPos !== false, 'resetCloseWizard exists');
$resetSlice = substr($wizard, $resetPos, 600);
closeUxAssert(
    strpos($resetSlice, "data-psh-close-submit-count']').removeClass('psh-hidden')") === false,
    'resetCloseWizard must not re-show submit-count'
);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_close_ux_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $_SESSION['pos_tenant'] = 1;
    $_SESSION['pos_branch'] = 3;
    $_SESSION['userid'] = 91;
    $register = (new PosRegisterService())->ensureDefaultRegister($conn, 1, 3);
    $_COOKIE[PosRegisterService::COOKIE_NAME] = (string) ($register['_pairing_token_once'] ?? '');
    $_SESSION['pos_register_id'] = (int) $register['id'];

    $count = new ShiftCountService();
    $drawer = new DrawerSessionService();
    $float = new DrawerFloatExpectationService();

    closeUxAssert($count->handoverEnabled($conn), 'handover enabled');
    $float->setOpeningBaseline($conn, 1, 3, '100.000', 91);

    $count->beginOpenCount($conn, 91);
    $opened = $count->submitOpenCount($conn, 91, '100.000');
    closeUxAssert(($opened['status'] ?? '') === 'opened', 'open matched');
    $sessionId = (int) ($opened['drawer_session_id'] ?? 0);
    closeUxAssert($sessionId > 0, 'session id');

    $drawer->recordMovement($conn, $sessionId, [
        'movement_type' => 'sale_cash',
        'amount' => '50.000',
        'created_by' => 91,
    ]);

    $_SESSION['pos_drawer_session_id'] = $sessionId;

    $begin1 = $count->beginCloseCount($conn, 91, ['drawer_session_id' => $sessionId]);
    closeUxAssert((int) ($begin1['attempt_number'] ?? -1) === 0, 'fresh begin starts at 0');
    closeUxAssert(!empty($begin1['close_token']), 'begin issues token');

    $tokenPayload = json_decode(base64_decode((string) $begin1['close_token'], true) ?: '', true);
    closeUxAssert(is_array($tokenPayload), 'token decodes');
    closeUxAssert(!array_key_exists('exp', $tokenPayload), 'client token must not embed expected cash');

    $wrong1 = $count->submitCloseCount($conn, 91, '10.000', ['drawer_session_id' => $sessionId]);
    closeUxAssert(($wrong1['status'] ?? '') === 'recount', 'first mismatch recounts');
    closeUxAssert(!array_key_exists('expected_cash', $wrong1), 'recount must not leak expected');
    closeUxAssert(!array_key_exists('variance', $wrong1), 'recount must not leak variance');

    // Re-begin must use the drawer record, even when browser state was lost.
    unset($_SESSION['pos_shift_close_count']);
    $begin2 = $count->beginCloseCount($conn, 91, ['drawer_session_id' => $sessionId]);
    closeUxAssert((int) ($begin2['attempt_number'] ?? -1) === 1, 'begin resumes drawer-wide attempt_number=1');

    $wrong2 = $count->submitCloseCount($conn, 91, '10.000', ['drawer_session_id' => $sessionId]);
    closeUxAssert(($wrong2['status'] ?? '') === 'close_with_variance', 'second mismatch accepts with variance');
    closeUxAssert(!array_key_exists('expected_cash', $wrong2), 'blind close response omits expected_cash');
    closeUxAssert(!array_key_exists('variance', $wrong2), 'blind close response omits variance');
    closeUxAssert(!empty($wrong2['close_token']), 'variance path still issues close token');
    closeUxAssert(($wrong2['matched'] ?? true) === false, 'matched false on variance');

    $begin3Blocked = false;
    try {
        $count->beginCloseCount($conn, 91, ['drawer_session_id' => $sessionId]);
    } catch (RuntimeException $exception) {
        $begin3Blocked = $exception->getMessage() === 'CLOSE_COUNT_MAX_ATTEMPTS';
    }
    closeUxAssert($begin3Blocked, 'begin after max attempts must be blocked');

    // A manager takeover may still finalize the drawer, but it must not add a
    // third close-count attempt. The final amount is stored by the close itself.
    $_SESSION['userid'] = 92;
    $takeoverBegin = $count->beginTakeoverCloseCount($conn, 92, $sessionId, [
        'tenant' => 1,
        'branch' => 3,
    ]);
    closeUxAssert(($takeoverBegin['status'] ?? '') === 'final_amount_required', 'takeover switches to finalization after two attempts');
    $takeoverFinal = $count->submitTakeoverCloseCount($conn, 92, '120.000', [
        'tenant' => 1,
        'branch' => 3,
    ]);
    closeUxAssert(($takeoverFinal['count_source'] ?? '') === 'manager_finalization', 'manager amount is a finalization, not another attempt');
    closeUxAssert((float) ($takeoverFinal['counted_cash'] ?? -1) === 120.0, 'manager final amount retained');
    $closeAttemptCount = (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_count_attempts WHERE drawer_session_id = {$sessionId} AND count_phase = 'close'")->fetch_assoc()['c'];
    closeUxAssert($closeAttemptCount === 2, 'drawer-wide close attempts remain capped at two');

    echo "close_shift_ux_blind_contract_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
