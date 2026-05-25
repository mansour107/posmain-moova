<?php

$root = dirname(__DIR__, 2);
require_once $root . '/includes/recipe_permissions.php';

$adminSession = ['login' => 1, 'userid' => 1, 'usrole' => 1];
$menuSession = ['login' => 1, 'userid' => 2, 'usrole' => 2];
$stockSession = ['login' => 1, 'userid' => 3, 'usrole' => 3];
$accountingSession = ['login' => 1, 'userid' => 4, 'usrole' => 4];
$reportSession = ['login' => 1, 'userid' => 5, 'usrole' => 5];

recipeSensitivePermissionsAssert(
    posmain_recipe_can_view_costs(null, $adminSession, []) === true
        && posmain_recipe_can_manage_stock_operations(null, $adminSession, []) === true,
    'admin should have recipe cost and stock-operation access'
);

recipeSensitivePermissionsAssert(
    posmain_recipe_can_view_costs(null, $menuSession, ['add_items' => 1]) === false
        && posmain_recipe_can_view_sensitive_reports(null, $menuSession, ['add_items' => 1]) === false
        && posmain_recipe_can_manage_stock_operations(null, $menuSession, ['add_items' => 1]) === false,
    'menu/item editors should not get recipe cost, audit/dashboard, or stock-operation access'
);

recipeSensitivePermissionsAssert(
    posmain_recipe_has_stock_sensitive_access(null, $stockSession, ['add_stock' => 1]) === true
        && posmain_recipe_can_view_costs(null, $stockSession, ['add_stock' => 1]) === true
        && posmain_recipe_can_manage_stock_operations(null, $stockSession, ['add_stock' => 1]) === true,
    'stock add permission should allow recipe stock-sensitive operations'
);

recipeSensitivePermissionsAssert(
    posmain_recipe_has_stock_sensitive_access(null, $stockSession, ['edit_stock' => 1]) === true
        && posmain_recipe_can_manage_stock_operations(null, $stockSession, ['edit_stock' => 1]) === true,
    'stock edit permission should allow recipe stock-sensitive operations'
);

recipeSensitivePermissionsAssert(
    posmain_recipe_can_view_costs(null, $accountingSession, ['sid_accounts' => 1]) === true
        && posmain_recipe_can_view_sensitive_reports(null, $accountingSession, ['sid_accounts' => 1]) === true
        && posmain_recipe_can_manage_stock_operations(null, $accountingSession, ['sid_accounts' => 1]) === false,
    'accounting users should see costs/sensitive reports without stock-operation access'
);

recipeSensitivePermissionsAssert(
    posmain_recipe_can_view_costs(null, $reportSession, ['sid_reports' => 1]) === false
        && posmain_recipe_can_view_sensitive_reports(null, $reportSession, ['sid_reports' => 1]) === false
        && posmain_recipe_can_manage_stock_operations(null, $reportSession, ['sid_reports' => 1]) === false,
    'report viewers should not get cost, audit/dashboard, or stock-operation access'
);

echo "recipe-sensitive-permissions-contract-ok\n";

function recipeSensitivePermissionsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
