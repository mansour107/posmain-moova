<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Security/MainAuthenticationService.php';
require_once __DIR__ . '/../classes/Security/LocalSecurityBootstrapService.php';

header('Content-Type: application/json; charset=utf-8');
$requestStartedAt = hrtime(true);

function posmain_main_pin_failure_delay(int $startedAt): void
{
    $minimumNanoseconds = (350 + random_int(0, 40)) * 1_000_000;
    $elapsed = hrtime(true) - $startedAt;
    if ($elapsed < $minimumNanoseconds) {
        usleep((int) ceil(($minimumNanoseconds - $elapsed) / 1000));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!posmain_is_pin_main_auth()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'MAIN_AUTH_MODE_PASSWORD'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('main_pin');

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'code' => 'DB_UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pin = (string) ($_POST['pin'] ?? '');
$registerKey = trim((string) ($_COOKIE['posmain_register_token'] ?? ''));

try {
    // Ensure bootstrap state exists for fresh local installs.
    $bootstrap = new LocalSecurityBootstrapService();
    if ($bootstrap->tableExists($conn) && !$bootstrap->isCompleted($conn)) {
        try {
            $bootstrap->ensureLocalBootstrap($conn);
        } catch (Throwable $ignored) {
            // Owner may not exist yet; login will fail closed.
        }
    }

    require_once __DIR__ . '/../includes/auth_guard.php';
    $auth = new MainAuthenticationService();
    $result = $auth->authenticateWithPin($conn, $pin, [
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        'register_key' => $registerKey !== '' ? hash('sha256', $registerKey) : '',
    ]);

    echo json_encode([
        'success' => true,
        'redirect' => $result['redirect'],
        'bootstrap_pending' => !empty($result['bootstrap_pending']),
        'must_change_pin' => !empty($_SESSION['posmain_pin_must_change']),
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = match ($code) {
        'PIN_TERMINAL_FROZEN' => 429,
        'PIN_SECRET_MISSING', 'DB_UNAVAILABLE' => 503,
        'MAIN_AUTH_MODE_PASSWORD' => 403,
        default => 401,
    };
    if ($code === 'PIN_TERMINAL_FROZEN') {
        $code = 'PIN_RETRY_LATER';
    } elseif (!in_array($code, ['PIN_SECRET_MISSING', 'MAIN_AUTH_MODE_PASSWORD'], true)) {
        $code = 'PIN_INVALID';
    }
    posmain_main_pin_failure_delay($requestStartedAt);
    http_response_code($status);
    echo json_encode(['success' => false, 'code' => $code], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('main_pin_login failed: ' . $exception->getMessage());
    posmain_main_pin_failure_delay($requestStartedAt);
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
}
