<?php

declare(strict_types=1);

/**
 * Ensure manager/admin preset roles have handover money-tracking permissions.
 * Safe to run repeatedly before Playwright production scenarios.
 */

require_once __DIR__ . '/../../../config/app_config.php';
require_once __DIR__ . '/../../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../../classes/Security/RolePermissionSyncService.php';
require_once __DIR__ . '/../../../classes/Security/PermissionService.php';

$conn = posmain_db_connect();

$managerRoleId = RolePermissionSyncService::restorePresetRole($conn, 'manager');
$ownerRoleId = RolePermissionSyncService::restorePresetRole($conn, 'owner');

$svc = PermissionService::forConnection($conn);
$managerUser = $conn->query("SELECT id FROM users WHERE uname = 'p6_manager' LIMIT 1")->fetch_assoc();
$adminUser = $conn->query("SELECT id FROM users WHERE uname = 'p6_admin' LIMIT 1")->fetch_assoc();

$checks = [
    'pos.shift.force_close',
    'pos.drawer.payin',
    'pos.drawer.safe_drop',
    'pos.shift.resolve_variance',
    'pos.shift.set_opening_baseline',
    'reports.cash_flow',
];

$report = [
    'manager_role_id' => $managerRoleId,
    'owner_role_id' => $ownerRoleId,
    'manager' => [],
    'admin' => [],
];

if ($managerUser) {
    foreach ($checks as $permission) {
        $report['manager'][$permission] = $svc->check((int) $managerUser['id'], $permission) ? 1 : 0;
    }
}
if ($adminUser) {
    foreach ($checks as $permission) {
        $report['admin'][$permission] = $svc->check((int) $adminUser['id'], $permission) ? 1 : 0;
    }
}

fwrite(STDOUT, 'sync-manager-handover-perms-ok ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n");
