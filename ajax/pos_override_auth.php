<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/PinService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_pos_authenticated();
require_csrf('pos_override');

$pin = trim((string) ($_POST['manager_pin'] ?? $_POST['pin'] ?? ''));
$permissionKey = trim((string) ($_POST['permission_key'] ?? $_POST['permission'] ?? ''));
$actionType = trim((string) ($_POST['action_type'] ?? ''));
if ($actionType === '') {
    $actionType = $permissionKey !== '' ? $permissionKey : 'manager.override';
}
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

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$pinService = new PinService();
$auditLogger = new SecurityAuditLogger();

try {
    if ($pinService->isTerminalFrozen($conn, $ip)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'code' => 'PIN_TERMINAL_FROZEN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $service = new ManagerApprovalService();
    $overrideContext = [
        'action_type' => $actionType,
        'target_type' => $targetType,
        'target_id' => $targetId > 0 ? $targetId : null,
        'reason' => trim((string) ($_POST['reason'] ?? '')) ?: null,
        'metadata' => [
            'terminal_user_id' => pos_terminal_user_id(),
            'permission_key' => $permissionKey,
        ],
    ];
    if ($amount !== null && $amount > 0) {
        $overrideContext['amount'] = $amount;
    }
    if ($limitPermissionKey !== '') {
        $overrideContext['limit_permission_key'] = $limitPermissionKey;
    }
    if (!empty($_POST['require_same_user'])) {
        $overrideContext['require_same_user'] = true;
    }

    $approval = $service->authenticateManagerOverride($conn, $pin, $permissionKey, pos_acting_user_id(), $overrideContext);

    try {
        $auditLogger->record($conn, 'manager_override_granted', [
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
    $deniedCodes = [
        'MANAGER_PIN_INVALID',
        'MANAGER_PIN_MISMATCH',
        'MANAGER_PERMISSION_DENIED',
        'APPROVER_LIMIT_EXCEEDED',
        'MANAGER_PIN_LOCKED',
        'PIN_TERMINAL_FROZEN',
    ];
    if (in_array($code, $deniedCodes, true)) {
        try {
            $auditLogger->record($conn, 'manager_override_denied', [
                'user_id' => pos_acting_user_id(),
                'target_type' => 'permission',
                'target_id' => null,
                'metadata' => [
                    'permission_key' => $permissionKey,
                    'code' => $code,
                    'terminal_user_id' => pos_terminal_user_id(),
                ],
            ]);
        } catch (Throwable $ignored) {
        }
    }
    $status = in_array($code, $deniedCodes, true) ? 403 : 400;
    if ($code === 'PIN_TERMINAL_FROZEN' || $code === 'MANAGER_PIN_LOCKED') {
        $status = 429;
    }
    $responseCode = $status === 429 ? 'PIN_RETRY_LATER' : (
        in_array($code, $deniedCodes, true) ? 'MANAGER_OVERRIDE_DENIED' : $code
    );
    http_response_code($status);
    echo json_encode(['success' => false, 'code' => $responseCode], JSON_UNESCAPED_UNICODE);
}
