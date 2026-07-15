<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Security/MainAuthenticationService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../config/app_config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'code' => 'DATABASE_CONNECTION_FAILED'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
require_csrf('main_lock');

// write_bootstrap/connect may close the session for other AJAX; this endpoint
// must persist identity clears, so ensure the session is writable first.
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (function_exists('posmain_session_start')) {
        posmain_session_start();
    } else {
        session_start();
    }
}

$auth = new MainAuthenticationService();
$userId = (int) ($_SESSION['userid'] ?? 0);

try {
    require_once __DIR__ . '/../classes/Pos/Service/DrawerOverrideService.php';
    (new DrawerOverrideService())->endActiveForOperator(
        $conn,
        $userId,
        DrawerOverrideService::END_REASON_LOCK,
        true
    );
} catch (Throwable $ignored) {
}

$auth->lockToLoginScreen();

try {
    (new SecurityAuditLogger())->record($conn, 'main_session_locked', [
        'user_id' => $userId > 0 ? $userId : null,
        'target_type' => 'user',
        'target_id' => $userId > 0 ? $userId : null,
        'metadata' => [
            'auth_mode' => posmain_main_auth_mode(),
        ],
    ]);
} catch (Throwable $ignored) {
}

echo json_encode([
    'success' => true,
    'redirect' => 'index.php',
], JSON_UNESCAPED_UNICODE);
