#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';

$conn = posmain_db_connect();
$conn->set_charset('utf8mb4');

if (!function_exists('auth_guard_permission_map')) {
    require_once __DIR__ . '/../includes/auth_guard.php';
}

$rolesResult = $conn->query('SELECT * FROM usr_pwrs WHERE COALESCE(isdeleted, 0) != 1');
if (!$rolesResult) {
    fwrite(STDERR, "unable to load roles\n");
    exit(1);
}

$mismatches = [];
$permissionKeys = array_keys(auth_guard_permission_map());

while ($role = $rolesResult->fetch_assoc()) {
    $roleId = (int) ($role['id'] ?? 0);
    if ($roleId < 1) {
        continue;
    }

    $legacyEnabled = array_fill_keys(RolePermissionSyncService::enabledPermissionsFromLegacyFlags($role), true);
    $capabilityEnabled = array_fill_keys(
        RolePermissionSyncService::enabledPermissionsFromCapabilities($conn, $roleId) ?? [],
        true
    );

    foreach ($permissionKeys as $permission) {
        $legacyFlags = auth_guard_permission_map()[$permission] ?? [];
        if (in_array('__admin_only', $legacyFlags, true)) {
            continue;
        }

        $legacyOn = !empty($legacyEnabled[$permission]);
        $capabilityOn = !empty($capabilityEnabled[$permission]);
        if ($legacyOn !== $capabilityOn) {
            $mismatches[] = sprintf(
                'role=%d permission=%s legacy=%s capability=%s',
                $roleId,
                $permission,
                $legacyOn ? '1' : '0',
                $capabilityOn ? '1' : '0'
            );
        }
    }
}

if ($mismatches !== []) {
    fwrite(STDERR, "permission-parity-failed count=" . count($mismatches) . "\n");
    foreach (array_slice($mismatches, 0, 20) as $line) {
        fwrite(STDERR, $line . "\n");
    }
    exit(1);
}

echo "permission-parity-ok roles_checked\n";
