<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_begin_shift_open_count.php');

require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerBranchBlockedException.php';

header('Content-Type: application/json; charset=utf-8');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    if (!auth_guard_is_pos_barcode_unlocked()) {
        throw new RuntimeException('POS_UNLOCK_REQUIRED');
    }

    $service = new ShiftCountService();
    $data = $service->beginOpenCount($conn, $userId);

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (DrawerBranchBlockedException $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage(),
        'blocking_session' => $exception->blockingSession(),
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
