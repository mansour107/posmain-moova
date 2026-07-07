<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../includes/pos_cache_control.php';
require_once __DIR__ . '/../classes/Pos/Service/CashDrawerHardwareService.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';

posmain_send_no_store_headers();
header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!auth_guard_is_pos_barcode_unlocked()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'POS_UNLOCK_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!auth_guard_has_permission('pos.drawer.no_sale', $conn)
    && !auth_guard_has_permission('pos.open', $conn)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'PERMISSION_DENIED'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $shiftService = new ShiftSessionService();
    $scope = $shiftService->resolveScope([]);
    $hardware = new CashDrawerHardwareService();
    $config = $hardware->resolveDriverConfig($conn, $scope);
    $status = $hardware->readStatus($config);

    echo json_encode([
        'success' => true,
        'hardware' => $status,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'code' => $code,
        'message' => CashDrawerHardwareService::userMessageForCode($code),
    ], JSON_UNESCAPED_UNICODE);
}
