<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerService.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerAnalyticsService.php';

header('Content-Type: application/json; charset=utf-8');
require_admin_or_permission('customers.manage', $conn);

try {
    posmain_ensure_pos_customer_schema($conn);
    $customerId = (int) ($_GET['id'] ?? 0);
    if ($customerId < 1) {
        echo json_encode(['success' => false, 'message' => 'CUSTOMER_ID_REQUIRED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $customerService = new PosCustomerService();
    $analytics = new PosCustomerAnalyticsService();
    $profile = $customerService->getProfile($conn, $customerId, true);
    if (!$profile) {
        echo json_encode(['success' => false, 'message' => 'CUSTOMER_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $orders = $analytics->customerOrders(
        $conn,
        $customerId,
        (int) ($_GET['page'] ?? 1),
        (int) ($_GET['per_page'] ?? 20)
    );

    echo json_encode([
        'success' => true,
        'customer' => $profile,
        'orders' => $orders,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
