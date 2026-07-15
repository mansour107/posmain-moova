<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
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

$requestedWith = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
if (strcasecmp($requestedWith, 'XMLHttpRequest') !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'XHR_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('posmain_is_pin_main_auth') || !posmain_is_pin_main_auth()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'code' => 'NOT_AVAILABLE'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();

// write_bootstrap/connect may close the session for other AJAX; this endpoint
// must persist the heartbeat timestamp, so ensure the session is writable first.
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (function_exists('posmain_session_start')) {
        posmain_session_start();
    } else {
        session_start();
    }
}

$_SESSION['posmain_heartbeat_last_at'] = time();

echo json_encode([
    'success' => true,
    'heartbeat_at' => (int) $_SESSION['posmain_heartbeat_last_at'],
], JSON_UNESCAPED_UNICODE);
