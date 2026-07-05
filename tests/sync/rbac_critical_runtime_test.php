<?php

require_once __DIR__ . '/../../includes/auth_guard.php';

$manifest = require __DIR__ . '/../../config/rbac_route_manifest.php';

$criticalDenials = [
    ['permission' => 'roles.manage', 'roleFlags' => ['rollname' => 'cashier', 'show_sales' => 1, 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'menu.edit', 'roleFlags' => ['rollname' => 'waiter', 'show_items' => 1], 'session' => ['login' => 'w', 'userid' => 8, 'usrole' => 4]],
    ['permission' => 'pos.refund', 'roleFlags' => ['rollname' => 'manager', 'edit_sales' => 1, 'show_sales' => 1], 'session' => ['login' => 'm', 'userid' => 7, 'usrole' => 3]],
    ['permission' => 'users.manage', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'system.tools.run', 'roleFlags' => ['rollname' => 'manager', 'edit_sales' => 1], 'session' => ['login' => 'm', 'userid' => 7, 'usrole' => 3]],
    ['permission' => 'accounting.view', 'roleFlags' => ['rollname' => 'cashier', 'add_sales' => 1], 'session' => ['login' => 'c', 'userid' => 9, 'usrole' => 2]],
    ['permission' => 'inventory.edit', 'roleFlags' => ['rollname' => 'waiter', 'show_items' => 1], 'session' => ['login' => 'w', 'userid' => 8, 'usrole' => 4]],
];

foreach ($criticalDenials as $case) {
    rbacCriticalRuntimeAssert(
        !auth_guard_session_has_permission($case['permission'], $case['roleFlags'], $case['session'], null),
        'expected denial for ' . $case['permission']
    );
}

$highRiskHandlers = [
    'do/doadd_role.php',
    'do/doadd_item.php',
    'do/doadd_user.php',
    'do/do_deluser.php',
    'do/doadd_invoice.php',
    'ajax/delete_order.php',
    'ajax/refund_order.php',
    'close_shift.php',
    'do_close_shift_z.php',
    'do/settle_credit.php',
    'do/doadd_journal.php',
    'ajax/inventory_adjustment.php',
    'do/offline_sync.php',
    'do/doadd_employee.php',
    'do/doadd_account.php',
    'ajax/pulse_ajax.php',
    'do/doadd_customer_visit.php',
    'do/doadd_production.php',
    'ajax/activate_table.php',
    'ajax/get_tables.php',
];

foreach ($highRiskHandlers as $path) {
    rbacCriticalRuntimeAssert(isset($manifest[$path]), 'manifest must guard ' . $path);
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    rbacCriticalRuntimeAssert(is_string($source), 'unable to read ' . $path);
    $guarded = strpos($source, 'rbac_guard_route') !== false
        || strpos($source, 'require_permission') !== false
        || strpos($source, 'require_admin_or_permission') !== false
        || strpos($source, 'require_pos_authenticated') !== false
        || strpos($source, 'pos_api_dispatch') !== false
        || strpos($source, 'production_guard_deny_route') !== false;
    rbacCriticalRuntimeAssert($guarded, $path . ' must enforce auth at runtime entry');
}

echo "rbac-critical-runtime-ok handlers=" . count($highRiskHandlers) . "\n";

function rbacCriticalRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
