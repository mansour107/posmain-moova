<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_submit_shift_close_count.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('shift_close_count');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$countedAmount = trim((string) ($_POST['counted_amount'] ?? ''));

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    if ($countedAmount === '') {
        throw new RuntimeException('COUNTED_AMOUNT_REQUIRED');
    }

    $response = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.submit_close_count',
        $_POST,
        $_SERVER,
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $countedAmount): array {
            $service = new ShiftCountService();
            $data = $service->submitCloseCount($conn, $userId, $countedAmount, $txContext);

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
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
