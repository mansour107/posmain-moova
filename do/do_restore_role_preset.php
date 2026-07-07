<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_restore_role_preset.php');

require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

$roleId = (int) ($_POST['role_id'] ?? 0);
$roleKey = trim((string) ($_POST['role_key'] ?? ''));
$confirm = trim((string) ($_POST['confirm_diff'] ?? ''));

if ($roleId < 1 || $roleKey === '' || $confirm !== 'restore') {
    header('Location: ../role_permissions.php?id=' . max(1, $roleId));
    exit;
}

try {
    (new PermissionService($conn))->assertRolePermissionsEditable($roleId);
} catch (RuntimeException $exception) {
    http_response_code(403);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

try {
    RolePermissionSyncService::restorePresetRole($conn, $roleKey);
} catch (InvalidArgumentException $exception) {
    header('Location: ../role_permissions.php?id=' . $roleId . '&error=preset');
    exit;
}

(new SecurityAuditLogger())->record($conn, 'role_preset_restored', [
    'target_type' => 'role',
    'target_id' => $roleId,
    'metadata' => ['role_key' => $roleKey],
]);

header('Location: ../team.php?tab=roles&role_saved=' . $roleId);
exit;
