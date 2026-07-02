<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerService.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerAnalyticsService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    posmain_ensure_pos_customer_schema($conn);
    $customerId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($customerId < 1) {
        echo json_encode(['success' => false, 'message' => 'CUSTOMER_ID_REQUIRED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $service = new PosCustomerService();
    $profile = $service->getProfile($conn, $customerId, true);
    if (!$profile) {
        echo json_encode(['success' => false, 'message' => 'CUSTOMER_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = ['success' => true, 'customer' => $profile];
    $includeOrders = filter_var($_GET['include_orders'] ?? $_POST['include_orders'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($includeOrders) {
        $analytics = new PosCustomerAnalyticsService();
        $payload['orders'] = $analytics->customerOrders(
            $conn,
            $customerId,
            max(1, (int) ($_GET['page'] ?? 1)),
            max(1, min(20, (int) ($_GET['per_page'] ?? 10)))
        );
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
