<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../includes/pos_cache_control.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';

posmain_send_no_store_headers();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('pos_override');

if (!auth_guard_is_pos_barcode_unlocked()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'POS_UNLOCK_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = pos_acting_user_id();
$reason = trim((string) ($_POST['reason'] ?? 'فتح درج بدون بيع'));

try {
    if (!auth_guard_has_permission('pos.drawer.no_sale', $conn)) {
        $approvalService = new ManagerApprovalService();
        $approval = $approvalService->requireApprovedIfNeeded(
            $conn,
            'pos.drawer.no_sale',
            'drawer_session',
            (int) ($_SESSION['pos_drawer_session_id'] ?? 0) ?: null,
            1.0,
            $_POST,
            ['user_id' => $userId]
        );
        if ($approval) {
            $approvalService->consumeApproval($conn, (int) $approval['id'], $userId);
        }
    }

    $shiftService = new ShiftSessionService();
    $session = $shiftService->currentDrawerSession($conn, $userId, []);
    if (!$session) {
        throw new RuntimeException('DRAWER_SESSION_REQUIRED');
    }

    $drawerService = new DrawerSessionService();
    $movement = $drawerService->recordMovement($conn, (int) $session['id'], [
        'movement_type' => 'paid_in',
        'amount' => '0.01',
        'reason' => $reason !== '' ? $reason : 'no_sale',
        'created_by' => $userId,
    ]);

    echo json_encode([
        'success' => true,
        'movement_id' => (int) ($movement['id'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'MANAGER_APPROVAL_REQUIRED' ? 403 : 400;
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'code' => $code,
        'message' => $code,
    ], JSON_UNESCAPED_UNICODE);
}
