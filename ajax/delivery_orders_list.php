<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../classes/Pos/Service/OrderFulfillmentService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $service = new OrderFulfillmentService();
    $includeTerminal = !empty($_GET['include_terminal']);
    $orders = $service->listActiveDeliveryOrders($conn, [
        'limit' => (int) ($_GET['limit'] ?? 100),
        'include_terminal' => $includeTerminal,
    ]);
    echo json_encode(['success' => true, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
