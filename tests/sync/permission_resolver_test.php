<?php

require_once __DIR__ . '/../../includes/auth_guard.php';

$map = auth_guard_permission_map();
permissionResolverAssert(count($map) >= 20, 'permission map should define core keys');

$roleFlags = [
    'rollname' => 'cashier',
    'show_sales' => 1,
    'add_sales' => 1,
    'sid_sales' => 1,
    'add_users' => 0,
    'edit_users' => 0,
];

permissionResolverAssert(
    auth_guard_session_has_permission('pos.open', $roleFlags, ['login' => 'cashier', 'userid' => 5, 'usrole' => 2], null),
    'cashier role flags should allow pos.open'
);
permissionResolverAssert(
    !auth_guard_session_has_permission('users.manage', $roleFlags, ['login' => 'cashier', 'userid' => 5, 'usrole' => 2], null),
    'cashier role flags should deny users.manage'
);

permissionResolverAssert(
    auth_guard_session_has_permission('pos.refund', $roleFlags, ['login' => 'admin', 'userid' => 1, 'usrole' => 1], null),
    'admin session should bypass to all permissions'
);

echo "permission-resolver-ok\n";

function permissionResolverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
