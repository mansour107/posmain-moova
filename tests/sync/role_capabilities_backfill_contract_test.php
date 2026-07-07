<?php

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Security/RolePermissionSyncService.php';

$conn = posmain_db_connect();
$conn->set_charset('utf8mb4');

$table = $conn->query("SHOW TABLES LIKE 'role_capabilities'");
roleCapBackfillAssert($table && $table->num_rows > 0, 'role_capabilities table required');

RolePermissionSyncService::backfillRoleCapabilitiesFromLegacyFlags($conn);

$roles = $conn->query('SELECT id FROM usr_pwrs WHERE COALESCE(isdeleted, 0) != 1');
roleCapBackfillAssert($roles instanceof mysqli_result, 'unable to load roles');

$missing = [];
while ($role = $roles->fetch_assoc()) {
    $roleId = (int) ($role['id'] ?? 0);
    if ($roleId < 1) {
        continue;
    }
    $stmt = $conn->prepare('SELECT 1 FROM role_capabilities WHERE role_id = ? LIMIT 1');
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        $missing[] = $roleId;
    }
}

roleCapBackfillAssert($missing === [], 'roles missing capability rows: ' . implode(',', $missing));

$seeded = RolePermissionSyncService::seedPresetRoles($conn);
$cashierRoleId = (int) ($seeded['cashier'] ?? 0);
if ($cashierRoleId > 0) {
    $voidStmt = $conn->prepare(
        'SELECT is_enabled FROM role_capabilities WHERE role_id = ? AND permission_key = ? LIMIT 1'
    );
    $voidKey = 'pos.void.item_after_send';
    $voidStmt->bind_param('is', $cashierRoleId, $voidKey);
    $voidStmt->execute();
    $voidRow = $voidStmt->get_result()->fetch_assoc();
    $voidStmt->close();
    roleCapBackfillAssert((int) ($voidRow['is_enabled'] ?? 1) === 0, 'cashier preset must not inherit void via legacy backfill');
}

echo "role-capabilities-backfill-contract-ok\n";

function roleCapBackfillAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
