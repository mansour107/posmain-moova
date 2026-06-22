<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../classes/Pos/Service/OrderFulfillmentService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $service = new OrderFulfillmentService();
    $count = $service->countPendingDeliveryOrders($conn);
    echo json_encode(['success' => true, 'pending_count' => $count], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => true, 'pending_count' => 0], JSON_UNESCAPED_UNICODE);
}
