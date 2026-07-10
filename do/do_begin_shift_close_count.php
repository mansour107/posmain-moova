<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_begin_shift_close_count.php');

require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';

header('Content-Type: application/json; charset=utf-8');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }

    $service = new ShiftCountService();
    $data = $service->beginCloseCount($conn, $userId);

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
