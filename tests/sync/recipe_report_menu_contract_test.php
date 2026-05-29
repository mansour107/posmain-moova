<?php

$root = dirname(__DIR__, 2);
$reports = recipeReportMenuSource($root . '/reports.php');
$salesReports = recipeReportMenuSource($root . '/sales-reports.php');
$sidebar = recipeReportMenuSource($root . '/includes/sidebar.php');
$permissions = recipeReportMenuSource($root . '/includes/recipe_report_permissions.php');
require_once $root . '/includes/recipe_report_permissions.php';

recipeReportMenuAssert(strpos($permissions, 'recipe_permissions.php') !== false, 'shared helper should load recipe-sensitive permissions');
recipeReportMenuAssert(strpos($permissions, 'posmain_recipe_report_link_permissions') !== false, 'shared helper should expose recipe link permission map');
recipeReportMenuAssert(strpos($permissions, "auth_guard_session_has_permission('reports.view'") !== false, 'shared helper should allow report viewers only for report-safe recipe links');
recipeReportMenuAssert(strpos($permissions, "auth_guard_session_has_permission('menu.edit'") !== false, 'shared helper should use menu permission for recipe editor/management links');
recipeReportMenuAssert(strpos($permissions, 'posmain_recipe_has_stock_sensitive_access') !== false, 'shared helper should use stock-sensitive permission for sensitive recipe links');
recipeReportMenuAssert(strpos($permissions, 'posmain_recipe_has_accounting_access') !== false, 'shared helper should use accounting permission for cost/report links');
recipeReportMenuAssert(strpos($reports, "require_once __DIR__ . '/includes/recipe_report_permissions.php'") !== false, 'reports index should load shared recipe link permissions');
recipeReportMenuAssert(strpos($reports, 'posmain_recipe_report_link_permissions($conn)') !== false, 'reports index should calculate recipe link permissions');

foreach ([
    "'stock_reconciliation'",
    "'audit'",
    "'editor'",
    "'manage'",
    "'production'",
    "'waste'",
    "'operations'",
    "'dashboard'",
] as $linkKey) {
    recipeReportMenuAssert(strpos($reports, '$recipeReportLinks[' . $linkKey . ']') !== false, 'reports index should gate recipe link: ' . $linkKey);
}

recipeReportMenuAssert(strpos($salesReports, "require_once __DIR__ . '/includes/recipe_report_permissions.php'") !== false, 'sales reports should load shared recipe link permissions');
recipeReportMenuAssert(strpos($salesReports, 'posmain_recipe_report_can_view_sales_reconciliation($conn)') !== false, 'sales reports should calculate recipe reconciliation visibility');
recipeReportMenuAssert(strpos($salesReports, '$canViewRecipeSalesReconciliation') !== false, 'sales reports should gate recipe reconciliation link');
recipeReportMenuAssert(strpos($sidebar, 'recipe_manage.php') !== false, 'sidebar inventory menu should link recipe management');
recipeReportMenuAssert(strpos($sidebar, 'الوصفات') !== false, 'sidebar inventory menu should show Arabic recipe management label');
recipeReportMenuAssert(strpos($sidebar, '$lang_inventory_management') < strpos($sidebar, 'الوصفات'), 'recipe management should be under inventory management in the sidebar');

$adminLinks = posmain_recipe_report_link_permissions(null, ['login' => 1, 'userid' => 1, 'usrole' => 1], []);
recipeReportMenuAssert(!in_array(false, $adminLinks, true), 'admin should see every recipe report menu link');

$reportLinks = posmain_recipe_report_link_permissions(null, ['login' => 1, 'userid' => 2, 'usrole' => 2], ['sid_reports' => 1]);
recipeReportMenuAssert($reportLinks['stock_reconciliation'] === true, 'reports.view should see reconciliation link');
recipeReportMenuAssert($reportLinks['operations'] === true, 'reports.view should see operations report link');
recipeReportMenuAssert($reportLinks['audit'] === false, 'reports.view should not see sensitive audit link');
recipeReportMenuAssert($reportLinks['dashboard'] === false, 'reports.view should not see sensitive dashboard link');
recipeReportMenuAssert($reportLinks['manage'] === false, 'reports.view should not see mutation-oriented management link');

$menuLinks = posmain_recipe_report_link_permissions(null, ['login' => 1, 'userid' => 3, 'usrole' => 3], ['add_items' => 1]);
recipeReportMenuAssert($menuLinks['editor'] === true, 'menu.edit should see recipe editor link');
recipeReportMenuAssert($menuLinks['manage'] === true, 'menu.edit should see recipe management link');
recipeReportMenuAssert($menuLinks['audit'] === false, 'menu.edit alone should not see sensitive audit link');
recipeReportMenuAssert(posmain_recipe_can_view_costs(null, ['login' => 1, 'userid' => 3, 'usrole' => 3], ['add_items' => 1]) === false, 'menu.edit alone should not see recipe costs');

$inventoryLinks = posmain_recipe_report_link_permissions(null, ['login' => 1, 'userid' => 4, 'usrole' => 4], ['edit_stock' => 1]);
recipeReportMenuAssert(!in_array(false, $inventoryLinks, true), 'inventory.edit should see all recipe operational links');
recipeReportMenuAssert(posmain_recipe_can_manage_stock_operations(null, ['login' => 1, 'userid' => 4, 'usrole' => 4], ['edit_stock' => 1]) === true, 'stock edit should allow recipe stock operations');

$accountingLinks = posmain_recipe_report_link_permissions(null, ['login' => 1, 'userid' => 5, 'usrole' => 5], ['sid_accounts' => 1]);
recipeReportMenuAssert($accountingLinks['stock_reconciliation'] === true, 'accounting.view should see reconciliation link');
recipeReportMenuAssert($accountingLinks['audit'] === true, 'accounting.view should see audit link');
recipeReportMenuAssert($accountingLinks['manage'] === false, 'accounting.view alone should not see management link');
recipeReportMenuAssert(posmain_recipe_report_can_view_sales_reconciliation(null, ['login' => 1, 'userid' => 5, 'usrole' => 5], ['sid_accounts' => 1]) === true, 'sales report helper should mirror reconciliation visibility');

echo "recipe-report-menu-contract-ok\n";

function recipeReportMenuSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeReportMenuAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
