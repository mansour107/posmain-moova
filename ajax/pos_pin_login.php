<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftEntryService.php';
require_once __DIR__ . '/../classes/PasswordService.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'code' => 'DATABASE_CONNECTION_FAILED'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$requestStartedAt = hrtime(true);

function posmain_pos_pin_failure_delay(int $startedAt): void
{
    $minimumNanoseconds = (300 + random_int(0, 40)) * 1_000_000;
    $elapsed = hrtime(true) - $startedAt;
    if ($elapsed < $minimumNanoseconds) {
        usleep((int) ceil(($minimumNanoseconds - $elapsed) / 1000));
    }
}

function posmain_pos_pin_dummy_verify(string $pin): void
{
    PasswordService::verifyPassword(
        $pin,
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.'
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
require_csrf('pos_pin');

$pin = trim((string) ($_POST['pin'] ?? ''));
if ($pin === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => 'PIN_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$pinService = new PinService();

try {
    if ($pinService->isTerminalFrozen($conn, $ip)) {
        posmain_pos_pin_failure_delay($requestStartedAt);
        http_response_code(429);
        echo json_encode(['success' => false, 'code' => 'PIN_RETRY_LATER'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $user = $pinService->findUserByPin($conn, $pin);
    } catch (InvalidArgumentException $exception) {
        posmain_pos_pin_dummy_verify($pin);
        $pinService->recordTerminalFailure($conn, $ip);
        throw new RuntimeException('PIN_INVALID', 0, $exception);
    }
    if (!$user) {
        posmain_pos_pin_dummy_verify($pin);
        $pinService->recordTerminalFailure($conn, $ip);
        posmain_pos_pin_failure_delay($requestStartedAt);
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'PIN_INVALID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($pinService->isUserLocked($user)) {
        posmain_pos_pin_dummy_verify($pin);
        $pinService->recordTerminalFailure($conn, $ip);
        posmain_pos_pin_failure_delay($requestStartedAt);
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'PIN_INVALID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$pinService->verifyPin($pin, (string) ($user['pin_hash'] ?? ''))) {
        $pinService->recordUserFailure($conn, (int) $user['id']);
        $pinService->recordTerminalFailure($conn, $ip);
        posmain_pos_pin_failure_delay($requestStartedAt);
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'PIN_INVALID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $actingId = (int) $user['id'];
    $displayName = trim((string) ($user['display_name'] ?? $user['uname'] ?? ''));

    $pinService->clearUserFailures($conn, $actingId);
    $pinService->clearTerminalFailures($conn, $ip);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $entry = (new ShiftEntryService())->resolveForUser($conn, $actingId, [
        'opening_cash' => $_POST['opening_cash'] ?? '0',
    ]);
    $state = (string) ($entry['state'] ?? '');
    if ($state === ShiftEntryService::STATE_REGISTER_UNPAIRED) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'code' => 'REGISTER_UNPAIRED',
            'redirect' => 'register_pair.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($state === ShiftEntryService::STATE_PERMISSION_DENIED) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'code' => 'PERMISSION_DENIED',
            'redirect' => 'no_access.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    pos_set_acting_user($actingId, $displayName !== '' ? $displayName : null);
    $_SESSION['pos_authenticated'] = true;
    $_SESSION['pos_user_id'] = $actingId;
    $_SESSION['posmain_shift_entry_state'] = $state;
    $_SESSION['posmain_shift_entry_message'] = (string) ($entry['message'] ?? '');
    if (!empty($entry['drawer_session']['id'])) {
        $_SESSION['pos_drawer_session_id'] = (int) $entry['drawer_session']['id'];
    }
    $_SESSION['pos_user_name'] = $displayName !== '' ? $displayName : (string) $user['uname'];
    // Successful POS unlock starts a new selling attempt — drop the post-close lockout.
    unset($_SESSION['pos_shift_closed_for_session']);
    pos_touch_activity();

    try {
        (new SecurityAuditLogger())->record($conn, 'pos_pin_login', [
            'user_id' => $actingId,
            'target_type' => 'user',
            'target_id' => $actingId,
            'metadata' => [
                'terminal_user_id' => pos_terminal_user_id(),
            ],
        ]);
    } catch (Throwable $ignored) {
    }

    echo json_encode([
        'success' => true,
        'acting_user_id' => $actingId,
        'acting_user_name' => $_SESSION['pos_user_name'],
        'cart_park_required' => !empty($_SESSION['pos_cart_park_required']),
        'previous_acting_user_id' => (int) ($_SESSION['pos_previous_acting_user_id'] ?? 0),
        'state' => $state,
        'redirect' => (string) ($entry['redirect'] ?? 'pos_barcode.php'),
    ], JSON_UNESCAPED_UNICODE);
    unset($_SESSION['pos_cart_park_required']);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'PIN_SECRET_MISSING') {
        http_response_code(503);
        echo json_encode(['success' => false, 'code' => 'PIN_SECRET_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($exception->getMessage() === 'PIN_INVALID') {
        posmain_pos_pin_failure_delay($requestStartedAt);
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'PIN_INVALID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    error_log('pos_pin_login failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
}
