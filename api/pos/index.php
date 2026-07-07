<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../includes/connect.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/pos_api_dispatch.php';
require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../../classes/Pos/Http/PosResponse.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    PosResponse::json(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed'], 405);
    exit;
}

$route = pos_api_resolve_route_from_request($_SERVER);
if ($route === '') {
    $route = trim((string) ($_GET['route'] ?? 'orders.table'));
}

try {
    $result = pos_api_dispatch($conn, $route);
    PosResponse::json($result['payload'], (int) ($result['http_status'] ?? 200));
} catch (InvalidArgumentException $e) {
    $payload = pos_api_dispatch_exception_payload($e, $route);
    PosResponse::json($payload['payload'], (int) $payload['http_status']);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    $payload = pos_api_dispatch_exception_payload($e, $route);
    PosResponse::json($payload['payload'], (int) $payload['http_status']);
}
