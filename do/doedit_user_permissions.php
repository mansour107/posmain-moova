<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_user_permissions.php');

$userId = (int) ($_POST['user_id'] ?? 0);
if ($userId < 1) {
    header('Location: ../users.php');
    exit;
}

$permissionMode = ($_POST['permission_mode'] ?? 'role_only') === 'role_with_overrides'
    ? 'role_with_overrides'
    : 'role_only';

$stmt = $conn->prepare("UPDATE users SET permission_mode = ? WHERE id = ? AND COALESCE(isdeleted, 0) != 1");
$stmt->bind_param('si', $permissionMode, $userId);
$stmt->execute();
$stmt->close();

if (!class_exists('UserPermissionGrantService', false)) {
    require_once __DIR__ . '/../classes/Security/UserPermissionGrantService.php';
}
$grantService = new UserPermissionGrantService();

if ($grantService->tableExists($conn)) {
    $conn->query('DELETE FROM user_permission_grants WHERE user_id = ' . (int) $userId);

    if ($permissionMode === 'role_with_overrides') {
        $grants = $_POST['grant'] ?? [];
        $denies = $_POST['deny'] ?? [];
        if (!is_array($grants)) {
            $grants = [];
        }
        if (!is_array($denies)) {
            $denies = [];
        }

        $insert = $conn->prepare("
            INSERT INTO user_permission_grants (user_id, permission_key, effect, created_by, tenant, branch)
            VALUES (?, ?, ?, ?, 0, 0)
        ");
        $actorId = current_user_id();
        foreach ($grants as $permissionKey) {
            $permissionKey = trim((string) $permissionKey);
            if ($permissionKey === '') {
                continue;
            }
            $effect = 'grant';
            $insert->bind_param('issi', $userId, $permissionKey, $effect, $actorId);
            $insert->execute();
        }
        foreach ($denies as $permissionKey) {
            $permissionKey = trim((string) $permissionKey);
            if ($permissionKey === '') {
                continue;
            }
            $effect = 'deny';
            $insert->bind_param('issi', $userId, $permissionKey, $effect, $actorId);
            $insert->execute();
        }
        $insert->close();
    }
}

auth_guard_invalidate_capabilities_cache();
$grantService->invalidateSessionCapabilities();

require_once __DIR__ . '/../classes/Security/PermissionService.php';
(new PermissionService($conn))->bumpPermissionsVersion();

require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
(new SecurityAuditLogger())->record($conn, 'user_permissions_updated', [
    'target_type' => 'user',
    'target_id' => $userId,
    'metadata' => ['permission_mode' => $permissionMode],
]);

header('Location: ../edit_user.php?id=' . $userId);
exit;
