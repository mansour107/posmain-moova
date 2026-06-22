<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/OrderFulfillmentService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_csrf('delivery_dispatch');

$orderId = (int) ($_POST['order_id'] ?? 0);
$newStatus = trim((string) ($_POST['delivery_status'] ?? ''));
$driverName = trim((string) ($_POST['driver_name'] ?? ''));
$driverPhone = trim((string) ($_POST['driver_phone'] ?? ''));

if ($orderId < 1 || $newStatus === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'order_id and delivery_status are required']);
    exit;
}

try {
    $service = new OrderFulfillmentService();
    $result = $service->transitionDeliveryStatus($conn, $orderId, $newStatus, [
        'actor_user_id' => (int) ($_SESSION['userid'] ?? 0),
        'driver_name' => $driverName,
        'driver_phone' => $driverPhone,
        'force' => !empty($_POST['force']),
    ]);
    echo json_encode(['success' => true, 'fulfillment' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
