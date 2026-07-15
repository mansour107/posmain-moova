<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_end_drawer_override.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerOverrideService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('shift_override');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$periodId = (int) ($_POST['override_period_id'] ?? $_SESSION['pos_override_period_id'] ?? 0);
$endReason = trim((string) ($_POST['end_reason'] ?? DrawerOverrideService::END_REASON_EXPLICIT));

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }

    $service = new DrawerOverrideService();
    if ($periodId < 1) {
        $ended = $service->endActiveForOperator($conn, $userId, $endReason, true);
        if (!$ended) {
            throw new RuntimeException('OVERRIDE_NOT_FOUND');
        }
    } else {
        $period = $service->periodById($conn, $periodId);
        if ((int) ($period['operator_user_id'] ?? 0) !== $userId
            && !auth_guard_has_permission('pos.shift.override', $conn)) {
            throw new RuntimeException('OVERRIDE_PERMISSION_DENIED');
        }
        $ended = $service->endOverride($conn, $periodId, $endReason, $userId, true);
    }

    // Ending override leaves the cashier shift open; drop POS unlock so the
    // next person re-enters. Do NOT use logout=1 — under PIN main auth that
    // wipes the whole ERP session (manager would be fully logged out).
    if (function_exists('posmain_clear_pos_shift_session')) {
        posmain_clear_pos_shift_session(false);
    }
    unset($_SESSION['posmain_shift_entry_state'], $_SESSION['posmain_shift_entry_message'], $_SESSION['pos_drawer_session_id']);

    echo json_encode([
        'success' => true,
        'override_period' => $ended,
        'redirect' => 'pos_barcode.php',
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = match ($code) {
        'AUTH_REQUIRED' => 401,
        'OVERRIDE_PERMISSION_DENIED' => 403,
        'OVERRIDE_NOT_FOUND' => 404,
        default => 400,
    };
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('do_end_drawer_override failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'OVERRIDE_END_FAILED'], JSON_UNESCAPED_UNICODE);
}
