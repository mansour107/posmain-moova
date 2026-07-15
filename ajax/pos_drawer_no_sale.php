<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../includes/pos_cache_control.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/CashDrawerHardwareService.php';
require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';

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
        $approvalId = (int) ($_POST['manager_approval_id'] ?? $_POST['approval_id'] ?? 0);
        if ($approvalId < 1) {
            throw new ManagerApprovalRequiredException('pos.drawer.no_sale');
        }
        $approvalService = new ManagerApprovalService();
        $approvalService->validateApprovedPermissionOverride(
            $conn,
            $approvalId,
            'pos.drawer.no_sale',
            $userId
        );
        $approvalService->consumeApproval($conn, $approvalId, $userId);
    }

    $shiftService = new ShiftSessionService();
    $scope = $shiftService->resolveScope([]);
    $session = $shiftService->currentDrawerSession($conn, $userId, $scope);
    if (!$session) {
        try {
            $opened = $shiftService->openForCashier($conn, $userId, $scope);
            if (!empty($opened['id'])) {
                $session = $shiftService->currentDrawerSession($conn, $userId, $scope);
            }
        } catch (Throwable $openException) {
            // fall through to required error
        }
    }
    if (!$session) {
        throw new RuntimeException('DRAWER_SESSION_REQUIRED');
    }

    $drawerService = new DrawerSessionService();
    $movement = $drawerService->recordMovement($conn, (int) $session['id'], [
        'movement_type' => 'no_sale',
        'amount' => '0.000',
        'allow_zero_amount' => true,
        'reason' => $reason !== '' ? $reason : 'no_sale',
        'created_by' => $userId,
    ]);

    $hardwareService = new CashDrawerHardwareService();
    $hardwareConfig = $hardwareService->resolveDriverConfig($conn, $scope);
    $hardwareResult = $hardwareService->open($hardwareConfig);

    echo json_encode([
        'success' => true,
        'movement_id' => (int) ($movement['id'] ?? 0),
        'hardware' => $hardwareResult,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'MANAGER_APPROVAL_REQUIRED' ? 403 : 400;
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'code' => $code,
        'message' => CashDrawerHardwareService::userMessageForCode($code),
    ], JSON_UNESCAPED_UNICODE);
}
