<?php

ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/pos_api_dispatch.php');
require_once('../includes/pos_order_api_router_guard.php');
ob_clean();

pos_order_api_router_guard_direct_access('ajax/cofe_create_order.php');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    pos_api_emit_dispatch_result(pos_api_dispatch($conn, 'integrations.cofe.orders'));
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    error_log('[Cofe] Order creation failed: ' . $e->getMessage());
    $payload = pos_api_dispatch_exception_payload($e, 'integrations.cofe.orders');
    pos_api_emit_dispatch_result($payload);
}
