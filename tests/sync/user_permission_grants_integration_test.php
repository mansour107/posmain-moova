<?php

require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../classes/Security/UserPermissionGrantService.php';

$roleFlags = ['rollname' => 'cashier', 'show_sales' => 1];
$session = ['login' => 'cashier', 'userid' => 42, 'usrole' => 2, 'permission_mode' => 'role_with_overrides'];

// Without DB overrides table, base role should apply.
grantsIntegrationAssert(
    auth_guard_session_has_permission('pos.open', $roleFlags, $session, null),
    'cashier should have pos.open from role flags'
);
grantsIntegrationAssert(
    !auth_guard_session_has_permission('users.manage', $roleFlags, $session, null),
    'cashier should not have users.manage'
);

echo "user-permission-grants-integration-ok\n";

function grantsIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
