<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    posmain_ensure_pos_customer_schema($conn);
    $phone = trim((string) ($_GET['phone'] ?? $_POST['phone'] ?? ''));
    if ($phone === '') {
        echo json_encode(['success' => false, 'message' => 'PHONE_REQUIRED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $service = new PosCustomerService();
    $result = $service->searchByPhone($conn, $phone);
    echo json_encode([
        'success' => true,
        'exact' => $result['exact'],
        'suggestions' => $result['suggestions'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
