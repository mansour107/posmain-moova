<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_pos_authenticated();
require_csrf('pos_override');

$pin = trim((string) ($_POST['manager_pin'] ?? $_POST['pin'] ?? ''));
$permissionKey = trim((string) ($_POST['permission_key'] ?? ''));
$actionType = trim((string) ($_POST['action_type'] ?? 'manager.override'));
$targetType = trim((string) ($_POST['target_type'] ?? 'pos_action'));
$targetId = isset($_POST['target_id']) ? (int) $_POST['target_id'] : null;

if ($pin === '' || $permissionKey === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => 'OVERRIDE_INPUT_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$amount = null;
if (isset($_POST['amount']) && $_POST['amount'] !== '') {
    $amount = (float) $_POST['amount'];
}
$limitPermissionKey = trim((string) ($_POST['limit_permission_key'] ?? ''));

try {
    $service = new ManagerApprovalService();
    $overrideContext = [
        'action_type' => $actionType,
        'target_type' => $targetType,
        'target_id' => $targetId > 0 ? $targetId : null,
        'reason' => trim((string) ($_POST['reason'] ?? '')) ?: null,
        'metadata' => [
            'terminal_user_id' => pos_terminal_user_id(),
        ],
    ];
    if ($amount !== null && $amount > 0) {
        $overrideContext['amount'] = $amount;
    }
    if ($limitPermissionKey !== '') {
        $overrideContext['limit_permission_key'] = $limitPermissionKey;
    }

    $approval = $service->authenticateManagerOverride($conn, $pin, $permissionKey, pos_acting_user_id(), $overrideContext);

    try {
        (new SecurityAuditLogger())->record($conn, 'manager_override_granted', [
            'user_id' => (int) ($approval['approved_by'] ?? 0),
            'target_type' => 'approval',
            'target_id' => (int) ($approval['id'] ?? 0),
            'metadata' => ['permission_key' => $permissionKey],
        ]);
    } catch (Throwable $ignored) {
    }

    echo json_encode([
        'success' => true,
        'approval_id' => (int) $approval['id'],
        'expires_at' => $approval['expires_at'] ?? null,
        'permission_key' => $permissionKey,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = in_array($code, ['MANAGER_PIN_INVALID', 'MANAGER_PERMISSION_DENIED', 'APPROVER_LIMIT_EXCEEDED'], true) ? 403 : 400;
    http_response_code($status);
    echo json_encode(['success' => false, 'code' => $code], JSON_UNESCAPED_UNICODE);
}
