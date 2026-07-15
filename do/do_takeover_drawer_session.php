<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_takeover_drawer_session.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('shift_takeover');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$sessionId = (int) ($_POST['drawer_session_id'] ?? 0);
$countedAmount = trim((string) ($_POST['counted_amount'] ?? $_POST['counted_cash'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
$managerApprovalId = (int) ($_POST['manager_approval_id'] ?? $_POST['approval_id'] ?? 0);

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
    if ($countedAmount === '' || !is_numeric($countedAmount) || (float) $countedAmount < 0) {
        throw new RuntimeException('COUNTED_AMOUNT_INVALID');
    }
    if (mb_strlen($reason) < 3) {
        throw new RuntimeException('FORCE_CLOSE_REASON_REQUIRED');
    }

    // Prefer server-side finalized takeover close-count (recount + variance logged).
    $countService = new ShiftCountService();
    $finalCount = $countService->peekTakeoverCloseCount($userId, $sessionId);
    if (is_array($finalCount)) {
        $countedAmount = number_format((float) $finalCount['counted_cash'], 3, '.', '');
    }

    // Always require a consumed manager PIN approval — even if the unlocked POS
    // user already has pos.shift.force_close. No permission short-circuit.
    if ($managerApprovalId < 1) {
        throw new ManagerApprovalRequiredException('pos.shift.force_close');
    }

    $approvalService = new ManagerApprovalService();
    // Pre-check before opening the money TX; forceCloseDrawerForUser consumes.
    $approval = $approvalService->validateApprovedPermissionOverride(
        $conn,
        $managerApprovalId,
        'pos.shift.force_close',
        $userId
    );
    $authorizedBy = (int) ($approval['approved_by'] ?? 0);
    if ($authorizedBy < 1) {
        throw new RuntimeException('MANAGER_APPROVAL_NOT_APPROVED');
    }

    $shiftSessions = new ShiftSessionService();
    $drawerSessions = new DrawerSessionService();
    $scope = $shiftSessions->resolveScope();
    $blocking = $drawerSessions->findOpenSessionForBranch($conn, $scope['tenant'], $scope['branch']);
    if (!$blocking || (int) ($blocking['id'] ?? 0) !== $sessionId) {
        throw new RuntimeException('BLOCKING_SESSION_MISMATCH');
    }
    if ((int) ($blocking['user_id'] ?? 0) === $userId) {
        throw new RuntimeException('CANNOT_TAKEOVER_OWN_SESSION');
    }

    $response = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.takeover_drawer',
        $_POST,
        $_SERVER,
        $userId,
        static function (array $txContext = []) use (
            $conn,
            $userId,
            $sessionId,
            $countedAmount,
            $reason,
            $managerApprovalId,
            $authorizedBy,
            $scope,
            $blocking,
            $countService
        ): array {
            $closed = (new ShiftSessionService())->forceCloseDrawerForUser($conn, $userId, $sessionId, [
                'tenant' => $scope['tenant'],
                'branch' => $scope['branch'],
                'counted_cash' => $countedAmount,
                'reason' => $reason,
                'manager_approval_id' => $managerApprovalId,
                'takeover' => true,
                'incoming_user_id' => $userId,
                'in_transaction' => !empty($txContext['in_transaction']),
            ]);

            $countService->clearTakeoverCloseCount();

            $_SESSION['pos_pending_takeover'] = [
                'preceding_session_id' => (int) ($closed['id'] ?? $sessionId),
                'preceding_user_id' => (int) ($blocking['user_id'] ?? $closed['user_id'] ?? 0),
                'authorized_by' => $authorizedBy,
                'incoming_user_id' => $userId,
                'closed_at' => time(),
            ];

            // Reuse the close-count cash as the manager opening float — no second count.
            $opened = $countService->openFromTakeoverCountedCash(
                $conn,
                $userId,
                (float) $countedAmount,
                [
                    'tenant' => $scope['tenant'],
                    'branch' => $scope['branch'],
                    'in_transaction' => !empty($txContext['in_transaction']),
                ]
            );

            unset($_SESSION['posmain_shift_blocking']);
            $_SESSION['posmain_shift_entry_state'] = 'selling_ready';
            $_SESSION['posmain_shift_entry_message'] = '';
            unset($_SESSION['pos_unlocked_pending_open']);

            return [
                'success' => true,
                'data' => [
                    'closed_session_id' => (int) ($closed['id'] ?? $sessionId),
                    'counted_cash' => (float) ($closed['counted_cash'] ?? $countedAmount),
                    'variance_status' => (string) ($closed['variance_status'] ?? 'none'),
                    'status' => (string) ($closed['status'] ?? 'forced_closed'),
                    'preceding_user_id' => (int) ($blocking['user_id'] ?? 0),
                    'authorized_by' => $authorizedBy,
                    'opened_session_id' => (int) ($opened['drawer_session_id'] ?? 0),
                    'open_status' => (string) ($opened['status'] ?? 'opened'),
                    'next_state' => 'selling_ready',
                    'redirect' => 'pos_barcode.php',
                ],
            ];
        }
    );

    if (($response['code'] ?? '') === 'IDEMPOTENCY_CONFLICT') {
        http_response_code(409);
    } elseif (!($response['success'] ?? false)) {
        http_response_code(422);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (ManagerApprovalRequiredException $exception) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage(),
        'code' => 'MANAGER_APPROVAL_REQUIRED',
        'permission_key' => $exception->permissionKey(),
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = in_array($code, ['PERMISSION_DENIED', 'MANAGER_APPROVAL_REQUIRED'], true) ? 403 : 422;
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
