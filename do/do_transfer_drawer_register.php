<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_transfer_drawer_register.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/PosRegisterService.php';
require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../includes/db_transaction.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('shift_register_transfer');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$sessionId = (int) ($_POST['drawer_session_id'] ?? 0);
$managerApprovalId = (int) ($_POST['manager_approval_id'] ?? $_POST['approval_id'] ?? 0);
$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    if ($sessionId < 1) {
        throw new RuntimeException('DRAWER_SESSION_REQUIRED');
    }

    $registers = new PosRegisterService();
    $register = $registers->requirePairedRegister($conn, $tenant, $branch);
    $targetRegisterId = (int) $register['id'];

    $drawers = new DrawerSessionService();
    if ($managerApprovalId < 1) {
        throw new ManagerApprovalRequiredException('pos.shift.force_close');
    }

    $conn->begin_transaction();
    try {
        $lockStmt = $conn->prepare(
            'SELECT * FROM drawer_sessions WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $lockStmt->bind_param('i', $sessionId);
        $lockStmt->execute();
        $session = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if (!$session || ($session['status'] ?? '') !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }
        if ((int) ($session['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('DRAWER_OWNER_MISMATCH');
        }

        $currentRegisterId = (int) ($session['register_id'] ?? 0);
        if ($currentRegisterId > 0 && $currentRegisterId === $targetRegisterId) {
            $conn->commit();
            echo json_encode([
                'success' => true,
                'already_on_register' => true,
                'drawer_session' => $session,
                'register_id' => $targetRegisterId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $approvalService = new ManagerApprovalService();
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

        $updated = $drawers->transferOpenSessionRegister($conn, $sessionId, $targetRegisterId, [
            'authorized_by' => $authorizedBy,
            'in_transaction' => true,
        ]);
        $approvalService->consumeApproval($conn, $managerApprovalId, $userId);
        $conn->commit();
    } catch (Throwable $transactionException) {
        $conn->rollback();
        throw $transactionException;
    }

    $_SESSION['pos_register_id'] = $targetRegisterId;
    $_SESSION['pos_drawer_session_id'] = $sessionId;
    unset($_SESSION['posmain_shift_entry_state'], $_SESSION['posmain_shift_entry_message']);

    try {
        (new SecurityAuditLogger())->record($conn, 'drawer_register_transfer', [
            'user_id' => $userId,
            'target_type' => 'drawer_session',
            'target_id' => $sessionId,
            'metadata' => [
                'from_register_id' => $currentRegisterId,
                'to_register_id' => $targetRegisterId,
                'authorized_by' => $authorizedBy,
            ],
        ]);
    } catch (Throwable $ignored) {
    }

    echo json_encode([
        'success' => true,
        'drawer_session' => $updated,
        'register_id' => $targetRegisterId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    $code = $exception->getMessage();
    $status = match ($code) {
        'AUTH_REQUIRED', 'DRAWER_OWNER_MISMATCH' => 403,
        'DRAWER_SESSION_REQUIRED', 'REGISTER_UNPAIRED' => 422,
        'REGISTER_DRAWER_ALREADY_OPEN' => 409,
        default => 400,
    };
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
}
