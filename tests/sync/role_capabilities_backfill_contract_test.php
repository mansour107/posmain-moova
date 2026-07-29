<?php

require_once __DIR__ . '/security_test_database.php';

$fixture = SecurityTestDatabase::create();
require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../classes/Security/RolePermissionSyncService.php';

$conn = posmain_db_connect();
$conn->set_charset('utf8mb4');
$fixture->provisionPermissionSchema($conn, RolePermissionSyncService::allManagedLegacyColumns());

try {
    $permissionMap = auth_guard_permission_map();
    $legacyPermission = '';
    $legacyColumn = '';
    foreach ($permissionMap as $permissionKey => $columns) {
        foreach ($columns as $column) {
            if ($column !== '__admin_only') {
                $legacyPermission = (string) $permissionKey;
                $legacyColumn = (string) $column;
                break 2;
            }
        }
    }
    roleCapBackfillAssert($legacyPermission !== '' && $legacyColumn !== '', 'legacy permission fixture required');

    $conn->query(
        "INSERT INTO usr_pwrs (rollname, isdeleted, is_active, role_key, is_system, `"
        . $legacyColumn
        . "`) VALUES ('Legacy fixture', 0, 1, NULL, 0, 1)"
    );
    $legacyRoleId = (int) $conn->insert_id;

    RolePermissionSyncService::backfillRoleCapabilitiesFromLegacyFlags($conn);

    $stmt = $conn->prepare(
        'SELECT is_enabled FROM role_capabilities WHERE role_id = ? AND permission_key = ? LIMIT 1'
    );
    $stmt->bind_param('is', $legacyRoleId, $legacyPermission);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    roleCapBackfillAssert((int) ($row['is_enabled'] ?? 0) === 1, 'legacy role permission was not backfilled');

    $seeded = RolePermissionSyncService::seedPresetRoles($conn);
    $cashierRoleId = (int) ($seeded['cashier'] ?? 0);
    roleCapBackfillAssert($cashierRoleId > 0, 'cashier preset must be seeded');
    $voidStmt = $conn->prepare(
        'SELECT is_enabled FROM role_capabilities WHERE role_id = ? AND permission_key = ? LIMIT 1'
    );
    $voidKey = 'pos.void.item_after_send';
    $voidStmt->bind_param('is', $cashierRoleId, $voidKey);
    $voidStmt->execute();
    $voidRow = $voidStmt->get_result()->fetch_assoc();
    $voidStmt->close();
    roleCapBackfillAssert((int) ($voidRow['is_enabled'] ?? 1) === 0, 'cashier preset must not inherit void via legacy backfill');

    echo "role-capabilities-backfill-contract-ok fixture=" . $fixture->databaseName() . "\n";
} finally {
    $conn->close();
    $fixture->close();
}

function roleCapBackfillAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
