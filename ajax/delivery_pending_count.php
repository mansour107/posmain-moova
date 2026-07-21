<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../classes/Pos/Service/OrderFulfillmentService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $service = new OrderFulfillmentService();
    $count = $service->countPendingDeliveryOrders($conn, [
        'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
        'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
    ]);
    echo json_encode(['success' => true, 'pending_count' => $count], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => true, 'pending_count' => 0], JSON_UNESCAPED_UNICODE);
}
