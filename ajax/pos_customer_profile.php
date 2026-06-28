<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerService.php';

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

    echo json_encode(['success' => true, 'customer' => $profile], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
