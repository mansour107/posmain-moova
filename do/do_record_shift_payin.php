<?php

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pos_cache_control.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $error['message'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

try {
    posmain_send_no_store_headers();
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('METHOD_NOT_ALLOWED');
    }

    ob_start();
    include __DIR__ . '/../includes/connect.php';
    ob_end_clean();

    if (!isset($_SESSION['userid'])) {
        throw new RuntimeException('LOGIN_REQUIRED');
    }

    if (!auth_guard_is_pos_barcode_unlocked()) {
        throw new RuntimeException('POS_UNLOCK_REQUIRED');
    }

    require_csrf('shift_payin');

    if (!isset($conn) || $conn->connect_error) {
        throw new RuntimeException('DB_CONNECTION_FAILED');
    }

    $conn->set_charset('utf8mb4');
    $userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];
    $payload = $_POST;
    $shiftService = new ShiftSessionService();

    if (!auth_guard_has_permission('pos.drawer.payin', $conn)) {
        $drawerSession = $shiftService->currentDrawerSession($conn, $userId, $shiftService->resolveScope($payload));
        $approvalService = new ManagerApprovalService();
        $approval = $approvalService->requireApprovedIfNeeded(
            $conn,
            'pos.drawer.payin',
            'drawer_session',
            $drawerSession ? (int) $drawerSession['id'] : null,
            (float) ($payload['amount'] ?? 0),
            $payload,
            [
                'user_id' => $userId,
                'require_manager_approval' => true,
            ]
        );
        if ($approval) {
            $approvalService->consumeApproval($conn, (int) $approval['id'], $userId);
            $payload['manager_approval_id'] = (int) $approval['id'];
        }
    }

    $result = $shiftService->recordShiftPayIn($conn, $userId, [
        'amount' => $payload['amount'] ?? 0,
        'reason' => $payload['reason'] ?? '',
        'manager_approval_id' => $payload['manager_approval_id'] ?? null,
    ]);

    while (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode([
        'success' => true,
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (RuntimeException $exception) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $message = $exception->getMessage();
    $status = 400;
    if (in_array($message, ['LOGIN_REQUIRED', 'POS_UNLOCK_REQUIRED'], true)) {
        $status = 401;
    } elseif ($message === 'METHOD_NOT_ALLOWED') {
        $status = 405;
    } elseif (in_array($message, ['SHIFT_WRITE_BLOCKED', 'MANAGER_APPROVAL_REQUIRED', 'PERMISSION_DENIED'], true)) {
        $status = 403;
    }
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'SERVER_ERROR',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
