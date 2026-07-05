<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_user_reactivate.php');

require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';

$id = (int) ($_POST['user_id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: ../users.php');
    exit;
}

$currentUserId = current_user_id();
try {
    (new UserLifecycleGuardService())->assertNoPrivilegeEscalation($conn, $currentUserId, $id, null);
} catch (RuntimeException $exception) {
    $message = UserLifecycleGuardService::privilegeEscalationMessage($exception->getMessage());
    header('Location: ../users.php?error=' . urlencode($message));
    exit;
}

$stmt = $conn->prepare('UPDATE users SET isdeleted = 0 WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

(new SecurityAuditLogger())->record($conn, 'user_reactivated', [
    'target_type' => 'user',
    'target_id' => $id,
]);

(new PermissionService($conn))->bumpPermissionsVersion();

header('Location: ../users.php?reactivated=1');
exit;
