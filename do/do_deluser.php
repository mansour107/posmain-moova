<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_deluser.php');

require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: ../users.php');
    exit;
}

$currentUserId = current_user_id();
if ($id === $currentUserId) {
    header('Location: ../users.php?error=self_delete');
    exit;
}

try {
    (new UserLifecycleGuardService())->softDeleteUser($conn, $id);
} catch (RuntimeException $exception) {
    header('Location: ../users.php?error=' . urlencode($exception->getMessage()));
    exit;
}

(new SecurityAuditLogger())->record($conn, 'user_deleted', [
    'target_type' => 'user',
    'target_id' => $id,
]);

header('Location: ../users.php');
exit;
