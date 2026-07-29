<?php

$root = dirname(__DIR__, 2);
$tool = recipeRuntimePreflightSource($root . '/tools/recipe_runtime_preflight.php');
$service = recipeRuntimePreflightSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$doc = recipeRuntimePreflightSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($root . '/tools/recipe_runtime_preflight.php') . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeRuntimePreflightAssert($helpCode === 0, 'runtime preflight help should exit cleanly');
recipeRuntimePreflightAssert(strpos($help, 'prepared for recipe browser/operator QA') !== false, 'help should describe operator QA purpose');
recipeRuntimePreflightAssert(strpos($help, 'read-only') !== false, 'help should state read-only behavior');
recipeRuntimePreflightAssert(strpos($tool, 'RecipeRuntimePreflightService') !== false, 'tool should delegate to runtime preflight service');
recipeRuntimePreflightAssert(strpos($service, "'runtime_dependencies'") !== false, 'service should expose runtime dependency checks');
recipeRuntimePreflightAssert(strpos($service, "function_exists('bcadd')") !== false, 'service should check bcmath for recipe decimal math');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_bcmath_missing') !== false, 'service should fail closed when bcmath is missing');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_reserve_only_requires_recipe_reservations') !== false, 'service should block reserve_only preflight when reservations are disabled');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_full_requires_recipe_reservations') !== false, 'service should block full preflight when reservations are disabled');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_consume_pilot_requires_recipe_consumption') !== false, 'service should block consume_pilot preflight when consumption is disabled');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_accounting_pilot_requires_recipe_accounting') !== false, 'service should block accounting_pilot preflight when accounting is disabled');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_availability_pilot_requires_recipe_availability') !== false, 'service should block availability_pilot preflight when availability is disabled');
recipeRuntimePreflightAssert(strpos($service, 'InventoryFeatureFlags') !== false, 'service should resolve the inventory quantity capability');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_active_mode_requires_inventory_quantity_tracking') !== false, 'service should block active recipe modes when quantity tracking is disabled');
recipeRuntimePreflightAssert(strpos($service, "'inventory_quantity_tracking_enabled'") !== false, 'service should expose the inventory quantity dependency');
recipeRuntimePreflightAssert(strpos($service, 'NegativeStockSalePolicyService') !== false, 'service should resolve the durable branch negative-stock policy');
recipeRuntimePreflightAssert(strpos($service, "'negative_stock_sale_policy'") !== false, 'service should expose the resolved negative-stock policy');
recipeRuntimePreflightAssert(strpos($service, "['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot', 'full']") !== false, 'service should warn reserve_only and later active modes to use pilot evidence');
recipeRuntimePreflightAssert(strpos($service, 'SyncSchemaManager') !== false, 'service should inspect schema manager pending statements');
recipeRuntimePreflightAssert(strpos($service, 'recipe_manage.php') !== false, 'service should check recipe management surface');
recipeRuntimePreflightAssert(strpos($service, 'recipe_production.php') !== false, 'service should check production surface');
recipeRuntimePreflightAssert(strpos($service, 'posmain_recipe_production_can_view_cost') !== false, 'service should check production cost visibility guard');
recipeRuntimePreflightAssert(strpos($service, 'inventory_adjustments.php') !== false, 'service should check Inventory waste/adjustment surface');
recipeRuntimePreflightAssert(strpos($service, "csrf_meta_tag('inventory_adjustment'") !== false, 'service should check Inventory adjustment CSRF surface');
recipeRuntimePreflightAssert(strpos($service, 'ajax/inventory_adjustment.php') !== false, 'service should check Inventory adjustment endpoint wiring');
recipeRuntimePreflightAssert(strpos($service, 'posmain_recipe_reconciliation_can_view') !== false, 'service should check stock reconciliation named permission guard');
recipeRuntimePreflightAssert(strpos($service, 'posmain_recipe_audit_can_view') !== false, 'service should check audit named permission guard');
recipeRuntimePreflightAssert(strpos($service, 'csv_export.php') !== false, 'service should check recipe report CSV sanitizer wiring');
recipeRuntimePreflightAssert(strpos($service, 'includes/recipe_permissions.php') !== false, 'service should check shared recipe-sensitive permission helper');
recipeRuntimePreflightAssert(strpos($service, 'posmain_recipe_can_view_costs') !== false, 'service should check recipe cost visibility uses sensitive helper');
recipeRuntimePreflightAssert(strpos($service, 'includes/recipe_report_permissions.php') !== false, 'service should check shared recipe report menu permission helper');
recipeRuntimePreflightAssert(strpos($service, 'posmain_recipe_report_link_permissions') !== false, 'service should check role-aware recipe report menu permissions');
recipeRuntimePreflightAssert(strpos($service, "\$recipeReportLinks['audit']") !== false, 'service should check sensitive recipe report menu links are gated');
recipeRuntimePreflightAssert(strpos($service, 'posmain_recipe_report_can_view_sales_reconciliation') !== false, 'service should check sales report recipe link visibility guard');
recipeRuntimePreflightAssert(strpos($service, 'ajax/refund_order.php') !== false, 'service should check paid reversal surface');
recipeRuntimePreflightAssert(strpos($service, 'recipe_pilot_evidence.php') !== false, 'service should expose pilot evidence operator commands');
recipeRuntimePreflightAssert(strpos($service, 'recipe_pilot_evidence_bundle.php') !== false, 'service should expose draft pilot evidence bundle operator command');
recipeRuntimePreflightAssert(strpos($service, 'recipe_hosted_schema_preflight.php') !== false, 'service should expose hosted/router schema preflight tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_runtime_proof_suite.php') !== false, 'service should expose isolated runtime proof suite tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_operator_surface_smoke.php') !== false, 'service should expose repeatable operator surface smoke tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_management_surface_smoke.php') !== false, 'service should expose repeatable management surface smoke tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_stock_operations_surface_smoke.php') !== false, 'service should expose repeatable stock operations surface smoke tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_report_export_smoke.php') !== false, 'service should expose repeatable report export smoke tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_cashier_browser_fixture.php') !== false, 'service should expose isolated cashier-browser add/pay fixture tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_pos_grid_availability_surface_smoke.php') !== false, 'service should expose repeatable POS grid availability surface smoke tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_paid_reversal_surface_smoke.php') !== false, 'service should expose repeatable paid reversal surface smoke tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_manager_override_surface_smoke.php') !== false, 'service should expose repeatable manager override surface smoke tool');
recipeRuntimePreflightAssert(strpos($service, 'recipe_pilot_fixture.php') !== false, 'service should expose guarded pilot fixture tool');

foreach (['run_migrations.php --apply', '->apply(', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService'] as $forbiddenNeedle) {
    recipeRuntimePreflightAssert(strpos($tool, $forbiddenNeedle) === false, 'tool must not mutate runtime: ' . $forbiddenNeedle);
    recipeRuntimePreflightAssert(strpos($service, $forbiddenNeedle) === false, 'service must not mutate runtime: ' . $forbiddenNeedle);
}

foreach ([
    'tools/recipe_runtime_preflight.php --json',
    'does not apply migrations',
    'bcmath',
    'prepared for browser/operator QA',
    'tools/recipe_hosted_schema_preflight.php',
    'tools/recipe_pilot_evidence_bundle.php',
    'tools/recipe_runtime_proof_suite.php',
    'tools/recipe_operator_surface_smoke.php',
    'tools/recipe_management_surface_smoke.php',
    'tools/recipe_stock_operations_surface_smoke.php',
    'tools/recipe_report_export_smoke.php',
    'tools/recipe_cashier_browser_fixture.php',
    'tools/recipe_pos_grid_availability_surface_smoke.php',
    'tools/recipe_paid_reversal_surface_smoke.php',
    'tools/recipe_manager_override_surface_smoke.php',
    'tools/recipe_pilot_fixture.php',
] as $needle) {
    recipeRuntimePreflightAssert(strpos($doc, $needle) !== false, 'rollout doc missing runtime preflight guidance: ' . $needle);
}

echo "recipe-runtime-preflight-contract-ok\n";

function recipeRuntimePreflightSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeRuntimePreflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
