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

$auth = new MainAuthenticationService();
$userId = (int) ($_SESSION['userid'] ?? 0);
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
