<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_begin_takeover_close_count.php');

require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';

header('Content-Type: application/json; charset=utf-8');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$sessionId = (int) ($_GET['drawer_session_id'] ?? $_POST['drawer_session_id'] ?? 0);

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    if (!auth_guard_is_pos_barcode_unlocked()) {
        throw new RuntimeException('POS_UNLOCK_REQUIRED');
    }
    if ($sessionId < 1) {
        throw new RuntimeException('DRAWER_SESSION_REQUIRED');
    }
    if (!auth_guard_has_permission('pos.shift.force_close', $conn)) {
        throw new RuntimeException('PERMISSION_DENIED');
    }

    $service = new ShiftCountService();
    $data = $service->beginTakeoverCloseCount($conn, $userId, $sessionId);

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    http_response_code($code === 'PERMISSION_DENIED' ? 403 : 422);
    echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
}
