<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_set_opening_float_baseline.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerFloatExpectationService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('shift_baseline');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$amountRaw = trim((string) ($_POST['opening_float_baseline'] ?? $_POST['amount'] ?? ''));

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    if (!auth_guard_has_permission('pos.shift.set_opening_baseline', $conn)) {
        throw new RuntimeException('PERMISSION_DENIED');
    }
    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        throw new RuntimeException('BASELINE_AMOUNT_REQUIRED');
    }

    $tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
    $branch = (int) ($_SESSION['pos_branch'] ?? 0);
    if ($tenant < 1 || $branch < 1) {
        throw new RuntimeException('BRANCH_SCOPE_REQUIRED');
    }

    $response = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.set_opening_baseline',
        $_POST,
        $_SERVER,
        $userId,
        static function () use ($conn, $tenant, $branch, $userId, $amountRaw): array {
            $service = new DrawerFloatExpectationService();
            $data = $service->setOpeningBaseline($conn, $tenant, $branch, $amountRaw, $userId);

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
