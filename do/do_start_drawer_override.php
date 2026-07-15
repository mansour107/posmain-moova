<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_start_drawer_override.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerOverrideService.php';
require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('shift_override');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$sessionId = (int) ($_POST['drawer_session_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));
$managerApprovalId = (int) ($_POST['manager_approval_id'] ?? $_POST['approval_id'] ?? 0);

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    if (!auth_guard_is_pos_barcode_unlocked()) {
        throw new RuntimeException('POS_UNLOCK_REQUIRED');
    }

    $service = new DrawerOverrideService();
    $period = $service->startOverride($conn, $userId, $sessionId, $reason, $managerApprovalId, [
        'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
        'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
    ]);

    pos_set_acting_user($userId);
    posmain_begin_pos_shift_session($userId);

    echo json_encode([
        'success' => true,
        'override_period' => $period,
        'drawer_session_id' => (int) ($period['drawer_session_id'] ?? 0),
        'redirect' => 'pos_barcode.php',
    ], JSON_UNESCAPED_UNICODE);
} catch (ManagerApprovalRequiredException $exception) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'MANAGER_APPROVAL_REQUIRED',
        'permission' => $exception->permissionKey() ?: 'pos.shift.override',
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = match ($code) {
        'AUTH_REQUIRED', 'POS_UNLOCK_REQUIRED' => 401,
        'OVERRIDE_PERMISSION_DENIED', 'MANAGER_APPROVAL_NOT_APPROVED' => 403,
        'OVERRIDE_ALREADY_ACTIVE' => 409,
        'OVERRIDE_REASON_REQUIRED', 'DRAWER_SESSION_REQUIRED' => 422,
        default => 400,
    };
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('do_start_drawer_override failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'OVERRIDE_START_FAILED'], JSON_UNESCAPED_UNICODE);
}
