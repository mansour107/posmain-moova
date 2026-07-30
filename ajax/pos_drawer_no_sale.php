<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../includes/pos_cache_control.php';
require_once __DIR__ . '/../includes/shift_handover_idempotency.php';
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
$idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));

try {
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
        throw new RuntimeException('IDEMPOTENCY_KEY_REQUIRED');
    }
    $requiresPermissionOverride = !auth_guard_has_permission('pos.drawer.no_sale', $conn);
    $approvalId = (int) ($_POST['manager_approval_id'] ?? $_POST['approval_id'] ?? 0);
    if ($requiresPermissionOverride) {
        if ($approvalId < 1) {
            throw new ManagerApprovalRequiredException('pos.drawer.no_sale');
        }
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

    $requestPayload = $_POST;
    $requestPayload['idempotency_key'] = $idempotencyKey;
    $response = pos_shift_handover_idempotent(
        $conn,
        'pos.drawer.no_sale',
        $requestPayload,
        $_SERVER,
        $userId,
        static function (array $txContext = []) use (
            $conn,
            $session,
            $userId,
            $reason,
            $idempotencyKey,
            $requiresPermissionOverride,
            $approvalId
        ): array {
            if ($requiresPermissionOverride) {
                $approvalService = new ManagerApprovalService();
                $approvalService->validateApprovedPermissionOverride(
                    $conn,
                    $approvalId,
                    'pos.drawer.no_sale',
                    $userId
                );
                $approvalService->consumeApproval($conn, $approvalId, $userId);
            }
            $movement = (new DrawerSessionService())->recordMovement($conn, (int) $session['id'], [
                'movement_type' => 'no_sale',
                'amount' => '0.000',
                'allow_zero_amount' => true,
                'reason' => $reason !== '' ? $reason : 'no_sale',
                'created_by' => $userId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'success' => true,
                'movement_id' => (int) ($movement['id'] ?? 0),
            ];
        }
    );

    if (!empty($response['idempotency_replayed'])) {
        $hardwareResult = [
            'success' => true,
            'status' => 'idempotency_replayed',
            'opened' => false,
        ];
    } else {
        $hardwareService = new CashDrawerHardwareService();
        $hardwareConfig = $hardwareService->resolveDriverConfig($conn, $scope);
        $hardwareResult = $hardwareService->open($hardwareConfig);
    }

    $response['hardware'] = $hardwareResult;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
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
