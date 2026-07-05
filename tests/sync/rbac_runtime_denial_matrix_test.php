<?php

require_once __DIR__ . '/../../includes/auth_guard.php';

$manifest = require __DIR__ . '/../../config/rbac_route_manifest.php';

$denialMatrix = [
    ['permission' => 'roles.manage', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1, 'show_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'users.manage', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'menu.edit', 'roleFlags' => ['rollname' => 'waiter', 'show_items' => 1], 'session' => ['login' => 'w', 'userid' => 8, 'usrole' => 4]],
    ['permission' => 'inventory.edit', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'accounting.view', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'reports.view', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'pos.refund', 'roleFlags' => ['rollname' => 'manager', 'edit_sales' => 1, 'show_sales' => 1], 'session' => ['login' => 'm', 'userid' => 7, 'usrole' => 3]],
    ['permission' => 'pos.shift.close', 'roleFlags' => ['rollname' => 'kitchen', 'sid_kds' => 1], 'session' => ['login' => 'k', 'userid' => 10, 'usrole' => 5]],
    ['permission' => 'system.tools.run', 'roleFlags' => ['rollname' => 'manager', 'edit_sales' => 1], 'session' => ['login' => 'm', 'userid' => 7, 'usrole' => 3]],
    ['permission' => 'moova.manage', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'delivery.dispatch', 'roleFlags' => ['rollname' => 'kitchen', 'sid_kds' => 1], 'session' => ['login' => 'k', 'userid' => 10, 'usrole' => 5]],
    ['permission' => 'kds.manage', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'customers.manage', 'roleFlags' => ['rollname' => 'manager', 'edit_sales' => 1], 'session' => ['login' => 'm', 'userid' => 7, 'usrole' => 3]],
];

foreach ($denialMatrix as $case) {
    rbacRuntimeAssert(
        !auth_guard_session_has_permission($case['permission'], $case['roleFlags'], $case['session'], null),
        'expected denial for ' . $case['permission']
    );
}

$allowMatrix = [
    ['permission' => 'pos.sell.takeaway', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1, 'show_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'kds.view', 'roleFlags' => ['rollname' => 'kitchen', 'sid_kds' => 1], 'session' => ['login' => 'k', 'userid' => 10, 'usrole' => 5]],
    ['permission' => 'menu.edit', 'roleFlags' => ['rollname' => 'manager', 'add_items' => 1, 'edit_items' => 1], 'session' => ['login' => 'm', 'userid' => 7, 'usrole' => 3]],
];

foreach ($allowMatrix as $case) {
    rbacRuntimeAssert(
        auth_guard_session_has_permission($case['permission'], $case['roleFlags'], $case['session'], null),
        'expected allow for ' . $case['permission']
    );
}

rbacRuntimeAssert(isset($manifest['do/doadd_role.php']), 'manifest must guard role creation');
rbacRuntimeAssert(isset($manifest['do/doadd_item.php']), 'manifest must guard item creation');
rbacRuntimeAssert(count($manifest) >= 170, 'manifest should cover full write surface sweep');

$offline = file_get_contents(__DIR__ . '/../../do/offline_sync.php');
rbacRuntimeAssert(strpos($offline, 'POSMAIN_ENABLE_LEGACY_OFFLINE_PROTOTYPE') !== false, 'offline_sync should gate legacy prototype flag');

echo 'rbac-runtime-denial-matrix-ok cases=' . (count($denialMatrix) + count($allowMatrix)) . "\n";

function rbacRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
