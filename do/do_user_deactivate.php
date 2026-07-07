<?php

require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_user_deactivate.php');

require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';

$id = (int) ($_POST['user_id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: ../team.php?tab=staff');
    exit;
}

$currentUserId = current_user_id();
if ($id === $currentUserId) {
    header('Location: ../team.php?tab=staff&error=self_deactivate');
    exit;
}

try {
    (new UserLifecycleGuardService())->assertNoPrivilegeEscalation($conn, $currentUserId, $id, null);
    (new UserLifecycleGuardService())->softDeleteUser($conn, $id);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $message = UserLifecycleGuardService::privilegeEscalationMessage($code);
    if ($message === $code) {
        $known = [
            'LAST_ADMIN_BLOCKED' => 'LAST_ADMIN_BLOCKED',
            'DRAWER_SESSION_OPEN' => 'DRAWER_SESSION_OPEN',
            'USER_DELETE_BLOCKED' => 'USER_DELETE_BLOCKED',
        ];
        $message = $known[$code] ?? $code;
    }
    header('Location: ../team.php?tab=staff&error=' . urlencode($message));
    exit;
}

(new SecurityAuditLogger())->record($conn, 'user_deactivated', [
    'target_type' => 'user',
    'target_id' => $id,
]);

(new PermissionService($conn))->bumpPermissionsVersion();

header('Location: ../team.php?tab=staff&deactivated=1');
exit;
