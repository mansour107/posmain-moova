<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../includes/pos_api_dispatch.php');
require_once('../includes/pos_order_api_router_guard.php');

pos_order_api_router_guard_direct_access('ajax/save_order.php');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    pos_api_emit_dispatch_result(pos_api_dispatch($conn, 'orders.table'));
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    $payload = pos_api_dispatch_exception_payload($e, 'orders.table');
    pos_api_emit_dispatch_result($payload);
}
