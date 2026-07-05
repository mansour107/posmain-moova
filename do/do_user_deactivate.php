<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_user_deactivate.php');

require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';

$id = (int) ($_POST['user_id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: ../users.php');
    exit;
}

$currentUserId = current_user_id();
if ($id === $currentUserId) {
    header('Location: ../users.php?error=self_deactivate');
    exit;
}

try {
    (new UserLifecycleGuardService())->assertNoPrivilegeEscalation($conn, $currentUserId, $id, null);
    (new UserLifecycleGuardService())->softDeleteUser($conn, $id);
} catch (RuntimeException $exception) {
    $message = UserLifecycleGuardService::privilegeEscalationMessage($exception->getMessage());
    header('Location: ../users.php?error=' . urlencode($message));
    exit;
}

(new SecurityAuditLogger())->record($conn, 'user_deactivated', [
    'target_type' => 'user',
    'target_id' => $id,
]);

(new PermissionService($conn))->bumpPermissionsVersion();

header('Location: ../users.php?deactivated=1');
exit;
