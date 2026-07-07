<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_team_create_role.php');

require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rollname = trim((string) ($_POST['rollname'] ?? ''));
$info = trim((string) ($_POST['info'] ?? ''));
$cloneFrom = trim((string) ($_POST['clone_from'] ?? 'cashier'));

if ($rollname === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => 'ROLLNAME_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$definitions = RolePermissionSyncService::presetRoleDefinitions();
$permissions = [];
if ($cloneFrom !== '' && $cloneFrom !== 'empty' && isset($definitions[$cloneFrom])) {
    $permissions = $definitions[$cloneFrom]['permissions'] ?? [];
} elseif ($cloneFrom !== 'empty') {
    $cloneRoleId = (int) ($_POST['clone_role_id'] ?? 0);
    if ($cloneRoleId > 0) {
        $stmt = $conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
        $stmt->bind_param('i', $cloneRoleId);
        $stmt->execute();
        $flags = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $permissions = RolePermissionSyncService::enabledPermissionsFromRoleFlags($flags);
    }
}

$legacyValues = RolePermissionSyncService::legacyFlagValuesForPermissions($permissions);
$stmt = $conn->prepare('INSERT INTO usr_pwrs (rollname, info, is_active, is_system, role_key) VALUES (?, ?, 1, 0, NULL)');
$stmt->bind_param('ss', $rollname, $info);
$stmt->execute();
$roleId = (int) $conn->insert_id;
$stmt->close();

if ($roleId < 1) {
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'CREATE_FAILED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($legacyValues !== []) {
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
        $sql = 'UPDATE usr_pwrs SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $types .= 'i';
        $bindValues[] = $roleId;
        $update = $conn->prepare($sql);
        $update->bind_param($types, ...$bindValues);
        $update->execute();
        $update->close();
    }
}

(new PermissionService($conn))->bumpPermissionsVersion();
(new SecurityAuditLogger())->record($conn, 'role_created', [
    'target_type' => 'role',
    'target_id' => $roleId,
    'metadata' => ['rollname' => $rollname, 'clone_from' => $cloneFrom],
]);

echo json_encode([
    'success' => true,
    'role_id' => $roleId,
    'name' => $rollname,
], JSON_UNESCAPED_UNICODE);
