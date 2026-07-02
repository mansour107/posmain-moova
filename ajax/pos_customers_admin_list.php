<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerAnalyticsService.php';

header('Content-Type: application/json; charset=utf-8');
require_admin_or_permission('customers.manage', $conn);

try {
    posmain_ensure_pos_customer_schema($conn);
    $service = new PosCustomerAnalyticsService();
    $filters = [
        'q' => $_GET['q'] ?? '',
        'sort' => $_GET['sort'] ?? 'lifetime_paid',
        'dir' => $_GET['dir'] ?? 'DESC',
        'page' => $_GET['page'] ?? 1,
        'per_page' => $_GET['per_page'] ?? 25,
        'min_spend' => $_GET['min_spend'] ?? 0,
        'min_orders' => $_GET['min_orders'] ?? 0,
        'last_order_from' => $_GET['last_order_from'] ?? '',
        'last_order_to' => $_GET['last_order_to'] ?? '',
    ];
    $list = $service->listCustomers($conn, $filters);
    $dashboard = $service->dashboard($conn);
    echo json_encode([
        'success' => true,
        'dashboard' => $dashboard,
        'list' => $list,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
