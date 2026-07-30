<?php

require_once __DIR__ . '/security_test_database.php';

$fixture = SecurityTestDatabase::create();
try {
    require_once __DIR__ . '/../../config/app_config.php';
    require_once __DIR__ . '/../../includes/db_bootstrap.php';
    require_once __DIR__ . '/../../includes/auth_guard.php';
    require_once __DIR__ . '/../../classes/Security/RolePermissionSyncService.php';
    require_once __DIR__ . '/../../classes/Security/PermissionService.php';
    require_once __DIR__ . '/../../classes/Security/UserPermissionGrantService.php';

    $conn = posmain_db_connect();
    $conn->set_charset('utf8mb4');
    $fixture->provisionPermissionSchema($conn, RolePermissionSyncService::allManagedLegacyColumns());

    $seeded = RolePermissionSyncService::seedPresetRoles($conn);
    $svc = new PermissionService($conn);

    /** @var array<string, string> */
    $denyCases = [
        'owner' => 'pos.open',
        'manager' => 'menu.edit',
        'cashier' => 'pos.open',
        'waiter' => 'pos.table.move',
        'kitchen' => 'kds.view',
    ];

    foreach ($denyCases as $roleKey => $permissionKey) {
        $roleId = (int) ($seeded[$roleKey] ?? 0);
        overrideMatrixAssert($roleId > 0, 'missing seeded role ' . $roleKey);

        $userId = overrideMatrixCreateUser($conn, $roleId);

        $roleFlags = overrideMatrixRoleFlags($conn, $roleId);
        $session = ['login' => true, 'userid' => $userId, 'usrole' => $roleId];

        overrideMatrixAssert($svc->check($userId, $permissionKey, $roleId), $roleKey . ' should have ' . $permissionKey . ' by role');
        overrideMatrixAssert(
            auth_guard_session_has_permission($permissionKey, $roleFlags, $session, $conn),
            $roleKey . ' session should allow ' . $permissionKey . ' before deny'
        );

        overrideMatrixEnableOverrides($conn, $userId);
        overrideMatrixSetGrant($conn, $userId, $permissionKey, 'deny');
        $svc->bumpPermissionsVersion();

        overrideMatrixAssert(!$svc->check($userId, $permissionKey, $roleId), $roleKey . ' deny should block ' . $permissionKey);
        overrideMatrixAssert(
            !auth_guard_session_has_permission($permissionKey, $roleFlags, $session, $conn),
            $roleKey . ' deny should block session bypass for ' . $permissionKey
        );
    }

    $cashierRoleId = (int) $seeded['cashier'];
    $grantUserId = overrideMatrixCreateUser($conn, $cashierRoleId);
    $grantPermission = 'users.manage';
    overrideMatrixAssert(!$svc->check($grantUserId, $grantPermission, $cashierRoleId), 'cashier should not have users.manage by role');

    overrideMatrixEnableOverrides($conn, $grantUserId);
    overrideMatrixSetGrant($conn, $grantUserId, $grantPermission, 'grant');
    $svc->bumpPermissionsVersion();

    $cashierFlags = overrideMatrixRoleFlags($conn, $cashierRoleId);
    $cashierSession = ['login' => true, 'userid' => $grantUserId, 'usrole' => $cashierRoleId];
    overrideMatrixAssert($svc->check($grantUserId, $grantPermission, $cashierRoleId), 'cashier grant override should allow users.manage');
    overrideMatrixAssert(
        auth_guard_session_has_permission($grantPermission, $cashierFlags, $cashierSession, $conn),
        'cashier grant override should allow session users.manage'
    );

    $ownerRoleId = (int) $seeded['owner'];
    $ownerUserId = overrideMatrixCreateUser($conn, $ownerRoleId);
    overrideMatrixEnableOverrides($conn, $ownerUserId);
    $ownerFlags = overrideMatrixRoleFlags($conn, $ownerRoleId);
    $ownerSession = ['login' => true, 'userid' => $ownerUserId, 'usrole' => $ownerRoleId];

    foreach (array_keys(auth_guard_permission_map()) as $permissionKey) {
        $conn->query('DELETE FROM user_permission_grants WHERE user_id = ' . (int) $ownerUserId);
        overrideMatrixAssert(
            $svc->check($ownerUserId, $permissionKey, $ownerRoleId),
            'owner should allow ' . $permissionKey . ' before deny'
        );
        overrideMatrixSetGrant($conn, $ownerUserId, $permissionKey, 'deny');
        $svc->bumpPermissionsVersion();
        overrideMatrixAssert(
            !$svc->check($ownerUserId, $permissionKey, $ownerRoleId),
            'owner deny should block ' . $permissionKey
        );
        overrideMatrixAssert(
            !auth_guard_session_has_permission($permissionKey, $ownerFlags, $ownerSession, $conn),
            'owner deny should block session for ' . $permissionKey
        );
    }

    $conn->close();
    echo 'user-override-deny-matrix-ok roles=' . count($denyCases)
        . ' fixture=' . $fixture->databaseName() . "\n";
} finally {
    $fixture->close();
}

function overrideMatrixCreateUser(mysqli $conn, int $roleId): int
{
    $uname = 'om_' . bin2hex(random_bytes(3));
    $pass = password_hash('x', PASSWORD_DEFAULT);
    $img = '';
    $stmt = $conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, ?, ?, ?, 0)');
    $stmt->bind_param('ssiis', $uname, $pass, $roleId, $roleId, $img);
    $stmt->execute();
    $userId = (int) $conn->insert_id;
    $stmt->close();

    if ($userId < 1) {
        throw new RuntimeException('Failed to create test user');
    }

    return $userId;
}

function overrideMatrixRoleFlags(mysqli $conn, int $roleId): array
{
    $stmt = $conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $row;
}

function overrideMatrixEnableOverrides(mysqli $conn, int $userId): void
{
    $mode = 'role_with_overrides';
    $stmt = $conn->prepare('UPDATE users SET permission_mode = ? WHERE id = ?');
    $stmt->bind_param('si', $mode, $userId);
    $stmt->execute();
    $stmt->close();
}

function overrideMatrixSetGrant(mysqli $conn, int $userId, string $permissionKey, string $effect): void
{
    $stmt = $conn->prepare(
        'INSERT INTO user_permission_grants (user_id, permission_key, effect, tenant, branch) VALUES (?, ?, ?, 0, 0)'
    );
    $stmt->bind_param('iss', $userId, $permissionKey, $effect);
    $stmt->execute();
    $stmt->close();
}

function overrideMatrixAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
