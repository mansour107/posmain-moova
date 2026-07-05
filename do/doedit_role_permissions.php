<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_role_permissions.php');

require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

$roleId = (int) ($_POST['role_id'] ?? 0);
if ($roleId < 1) {
    header('Location: ../myroles.php');
    exit;
}

require_once __DIR__ . '/../classes/Security/PermissionService.php';
try {
    (new PermissionService($conn))->assertRoleEditable($roleId);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'SYSTEM_ROLE_LOCKED') {
        http_response_code(403);
        echo 'SYSTEM_ROLE_LOCKED';
        exit;
    }
    header('Location: ../myroles.php');
    exit;
}

$stmt = $conn->prepare('SELECT id, rollname FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
$stmt->bind_param('i', $roleId);
$stmt->execute();
$roleRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$roleRow) {
    header('Location: ../myroles.php');
    exit;
}

$submitted = $_POST['permissions'] ?? [];
if (!is_array($submitted)) {
    $submitted = [];
}

$enabled = [];
foreach ($submitted as $permissionKey) {
    $permissionKey = trim((string) $permissionKey);
    if ($permissionKey !== '') {
        $enabled[] = $permissionKey;
    }
}

$legacyValues = RolePermissionSyncService::legacyFlagValuesForPermissions($enabled);
if ($legacyValues === []) {
    header('Location: ../role_permissions.php?id=' . $roleId);
    exit;
}

$setParts = [];
$types = '';
$bindValues = [];
foreach ($legacyValues as $column => $value) {
    if (!preg_match('/^[a-z0-9_]+$/', $column)) {
        continue;
    }
    $setParts[] = '`' . $column . '` = ?';
    $types .= 'i';
    $bindValues[] = (int) $value;
}

if ($setParts !== []) {
    $sql = 'UPDATE usr_pwrs SET ' . implode(', ', $setParts) . ' WHERE id = ? AND COALESCE(isdeleted, 0) != 1';
    $types .= 'i';
    $bindValues[] = $roleId;
    $stmtUpdate = $conn->prepare($sql);
    $stmtUpdate->bind_param($types, ...$bindValues);
    $stmtUpdate->execute();
    $stmtUpdate->close();
}

auth_guard_invalidate_capabilities_cache();
(new PermissionService($conn))->bumpPermissionsVersion();

$limitValues = $_POST['limit_value'] ?? [];
$limitUnlimited = $_POST['limit_unlimited'] ?? [];
if (is_array($limitValues) || is_array($limitUnlimited)) {
    $limitsPayload = [];
    foreach (RolePermissionSyncService::limitablePermissions() as $limitPermission) {
        if (!in_array($limitPermission, $enabled, true)) {
            continue;
        }
        $unlimited = is_array($limitUnlimited) && !empty($limitUnlimited[$limitPermission]);
        $rawLimit = is_array($limitValues) ? ($limitValues[$limitPermission] ?? null) : null;
        $limitsPayload[$limitPermission] = [
            'is_unlimited' => $unlimited,
            'limit_value' => $unlimited ? null : (float) $rawLimit,
        ];
    }
    if ($limitsPayload !== []) {
        RolePermissionSyncService::syncRoleCapabilityLimits($conn, $roleId, $limitsPayload);
    }
}

(new SecurityAuditLogger())->record($conn, 'role_permissions_updated', [
    'target_type' => 'role',
    'target_id' => $roleId,
    'metadata' => [
        'rollname' => $roleRow['rollname'],
        'permissions' => $enabled,
    ],
]);

header('Location: ../role_permissions.php?id=' . $roleId . '&saved=1');
exit;
