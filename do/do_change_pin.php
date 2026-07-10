<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/LocalSecurityBootstrapService.php';
require_once __DIR__ . '/../classes/Security/MainAuthenticationService.php';
require_once __DIR__ . '/../classes/Security/PostLoginRouteService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
require_csrf('change_pin');

$userId = (int) ($_SESSION['userid'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'AUTH_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentPin = (string) ($_POST['current_pin'] ?? '');
$newPin = (string) ($_POST['new_pin'] ?? '');
$confirmPin = (string) ($_POST['confirm_pin'] ?? '');

if ($newPin !== $confirmPin) {
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => 'PIN_CONFIRM_MISMATCH'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bootstrap = new LocalSecurityBootstrapService();
$isBootstrap = $bootstrap->isPending($conn) && !empty($_SESSION['posmain_bootstrap_pending']);
$sessionAuthMethod = (string) ($_SESSION['posmain_auth_method'] ?? 'main_pin');
$pinService = new PinService();
$auth = new MainAuthenticationService();
$user = $auth->loadActiveUser($conn, $userId);
if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'USER_INACTIVE'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Verify current PIN (bootstrap 0000 allowed while pending).
    $currentOk = false;
    if ($isBootstrap && $currentPin === LocalSecurityBootstrapService::BOOTSTRAP_PIN) {
        $currentOk = $pinService->verifyPin($currentPin, (string) ($user['pin_hash'] ?? ''))
            || PasswordService::verifyPassword($currentPin, (string) ($user['pin_hash'] ?? ''));
    } else {
        $currentOk = $pinService->verifyPin($currentPin, (string) ($user['pin_hash'] ?? ''));
    }
    if (!$currentOk) {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'CURRENT_PIN_INVALID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($newPin === $currentPin) {
        http_response_code(422);
        echo json_encode(['success' => false, 'code' => 'PIN_UNCHANGED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($isBootstrap) {
        $bootstrap->completeBootstrap($conn, $userId, $newPin);
    } else {
        $pinService->setPinForUser($conn, $userId, $newPin, [
            'must_change' => false,
            'bump_auth_version' => true,
        ]);
        try {
            (new SecurityAuditLogger())->record($conn, 'user_pin_changed', [
                'user_id' => $userId,
                'target_type' => 'user',
                'target_id' => $userId,
            ]);
        } catch (Throwable $ignored) {
        }
    }

    // Refresh session with new auth_version and clear must-change flags.
    $fresh = $auth->loadActiveUser($conn, $userId);
    if (!$fresh) {
        throw new RuntimeException('USER_INACTIVE');
    }
    $auth->establishSession($conn, $fresh, [
        'auth_method' => $isBootstrap ? 'main_pin' : $sessionAuthMethod,
        'bootstrap_pending' => false,
        'must_change_pin' => false,
    ]);
    unset($_SESSION['posmain_bootstrap_pending'], $_SESSION['posmain_pin_must_change']);

    $redirect = (new PostLoginRouteService())->resolveRedirect($conn, $userId);

    echo json_encode([
        'success' => true,
        'redirect' => $redirect,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    $code = $exception->getMessage();
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => $code], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'PIN_ALREADY_IN_USE' ? 409 : 422;
    http_response_code($status);
    echo json_encode(['success' => false, 'code' => $code], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('change_pin failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
}
