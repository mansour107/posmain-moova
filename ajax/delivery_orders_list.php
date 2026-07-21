<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/OrderFulfillmentService.php';
require_once __DIR__ . '/../classes/Pos/Service/DeliveryWorkerService.php';

header('Content-Type: application/json; charset=utf-8');

require_permission('delivery.dispatch', $conn);

try {
    $scope = ['tenant' => (int) ($_SESSION['pos_tenant'] ?? 0), 'branch' => (int) ($_SESSION['pos_branch'] ?? 0)];
    $service = new OrderFulfillmentService();
    $includeTerminal = !empty($_GET['include_terminal']);
    $orders = $service->listActiveDeliveryOrders($conn, [
        'limit' => (int) ($_GET['limit'] ?? 100),
        'include_terminal' => $includeTerminal,
    ] + $scope);
    $workers = [];
    $canAssign = auth_guard_has_permission('delivery.assign', $conn);
    if ($canAssign) {
        try {
            $workers = (new DeliveryWorkerService())->listWorkers($conn, $scope);
        } catch (Throwable $ignored) {
            $workers = [];
        }
    }
    echo json_encode(['success' => true, 'orders' => $orders, 'workers' => $workers, 'can_assign' => $canAssign], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
