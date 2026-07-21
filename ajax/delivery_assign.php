<?php
include __DIR__ . '/../includes/ajax_header.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/delivery_schema_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/DeliveryWorkerService.php';

header('Content-Type: application/json; charset=utf-8');
require_permission('delivery.assign', $conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}
require_csrf('delivery_operations');
try {
    posmain_require_delivery_schema_ready($conn);
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $workerId = (int) ($_POST['delivery_worker_id'] ?? 0);
    $result = (new DeliveryWorkerService())->assignOrder($conn, $orderId, $workerId > 0 ? $workerId : null, [
        'user_id' => (int) ($_SESSION['userid'] ?? 0),
        'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
        'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
    ]);
    echo json_encode(['success' => true, 'assignment' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    posmain_emit_delivery_api_error($e);
}
