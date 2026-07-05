<?php

require_once __DIR__ . '/../../classes/Security/RolePermissionSyncService.php';

$map = RolePermissionSyncService::permissionToLegacyColumns();
roleSyncAssert(isset($map['menu.edit']), 'menu.edit mapping required');
roleSyncAssert(in_array('add_items', $map['menu.edit'], true), 'menu.edit should map to add_items');

$columns = RolePermissionSyncService::columnsForPermission('pos.open');
roleSyncAssert(in_array('show_sales', $columns, true), 'pos.open should include show_sales');

$legacy = RolePermissionSyncService::legacyFlagValuesForPermissions(['menu.edit', 'inventory.edit']);
roleSyncAssert(($legacy['add_items'] ?? 0) === 1, 'menu.edit should enable add_items');
roleSyncAssert(($legacy['add_stock'] ?? 0) === 1, 'inventory.edit should enable add_stock');

$groups = RolePermissionSyncService::permissionGroups();
roleSyncAssert(isset($groups['POS']), 'permission groups should include POS');

echo "role-permission-sync-ok\n";

function roleSyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
