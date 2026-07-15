<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_submit_takeover_close_count.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('shift_takeover_count');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$countedAmount = trim((string) ($_POST['counted_amount'] ?? ''));

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    if (!auth_guard_is_pos_barcode_unlocked()) {
        throw new RuntimeException('POS_UNLOCK_REQUIRED');
    }
    if ($countedAmount === '') {
        throw new RuntimeException('COUNTED_AMOUNT_REQUIRED');
    }
    if (!auth_guard_has_permission('pos.shift.force_close', $conn)) {
        throw new RuntimeException('PERMISSION_DENIED');
    }

    $response = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.takeover_close_count',
        $_POST,
        $_SERVER,
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $countedAmount): array {
            $service = new ShiftCountService();
            $data = $service->submitTakeoverCloseCount($conn, $userId, $countedAmount, $txContext);

            return ['success' => true, 'data' => $data];
        }
    );

    if (($response['code'] ?? '') === 'IDEMPOTENCY_CONFLICT') {
        http_response_code(409);
    } elseif (!($response['success'] ?? false)) {
        http_response_code(422);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    http_response_code($code === 'PERMISSION_DENIED' ? 403 : 422);
    echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
}
