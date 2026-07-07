<?php

$expectations = [
    'do/doadd_role.php' => ["rbac_guard_route('do/doadd_role.php')"],
    'do/doedit_role.php' => ["rbac_guard_route('do/doedit_role.php')"],
    'do/do_deluser.php' => ["rbac_guard_route('do/do_deluser.php')"],
    'do/doadd_item.php' => ["rbac_guard_route('do/doadd_item.php')"],
    'do/doedit_item.php' => ["rbac_guard_route('do/doedit_item.php')"],
    'do/dodel_item.php' => ["rbac_guard_route('do/dodel_item.php')"],
    'do/toggle_item_active.php' => ["rbac_guard_route('do/toggle_item_active.php')"],
    'myroles.php' => ["header('Location: team.php"],
    'add_role.php' => ["page_guard('roles.manage'", "header('Location: team.php"],
    'edit_role.php' => ["page_guard('roles.manage'", "header('Location: team.php"],
    'myitems.php' => ["page_guard('menu.edit'"],
    'add_item.php' => ["page_guard('menu.edit'"],
    'ajax/delete_order.php' => ["requireApprovedIfNeeded(", "'pos.cancel.unpaid'"],
    'ajax/move_table_order.php' => ["require_permission('pos.table.move'"],
    'ajax/merge_table_orders.php' => ["require_permission('pos.table.merge'"],
    'close_shift.php' => ["require_permission('pos.shift.close'"],
];

foreach ($expectations as $path => $snippets) {
    if (isset($snippets['admin_or'])) {
        unset($snippets['admin_or']);
    }
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    rbacCriticalAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        if (!is_string($snippet)) {
            continue;
        }
        rbacCriticalAssert(strpos($source, $snippet) !== false, $path . ' missing ' . $snippet);
    }
}

echo "rbac-critical-surfaces-contract-ok\n";

function rbacCriticalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
