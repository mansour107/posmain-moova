<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';

header('Content-Type: application/json; charset=utf-8');

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
        http_response_code(429);
        echo json_encode(['success' => false, 'code' => 'PIN_TERMINAL_FROZEN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user = $pinService->findUserByPin($conn, $pin);
    if (!$user) {
        $pinService->recordTerminalFailure($conn, $ip);
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'PIN_INVALID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($pinService->isUserLocked($user)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'code' => 'PIN_USER_LOCKED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$pinService->verifyPin($pin, (string) ($user['pin_hash'] ?? ''))) {
        $pinService->recordUserFailure($conn, (int) $user['id']);
        $pinService->recordTerminalFailure($conn, $ip);
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

    pos_set_acting_user($actingId, $displayName !== '' ? $displayName : null);
    posmain_begin_pos_shift_session($actingId);
    (new ShiftSessionService())->openForCashier($conn, $actingId, [
        'opened_by' => pos_terminal_user_id(),
        'opening_cash' => $_POST['opening_cash'] ?? '0',
    ]);
    $_SESSION['pos_user_name'] = $displayName !== '' ? $displayName : (string) $user['uname'];
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
    ], JSON_UNESCAPED_UNICODE);
    unset($_SESSION['pos_cart_park_required']);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'PIN_SECRET_MISSING') {
        http_response_code(503);
        echo json_encode(['success' => false, 'code' => 'PIN_SECRET_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throw $exception;
}
