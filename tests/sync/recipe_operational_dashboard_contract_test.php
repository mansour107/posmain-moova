<?php

$root = dirname(__DIR__, 2);
$page = recipeOperationalDashboardSource($root . '/recipe_operational_dashboard.php');
$service = recipeOperationalDashboardSource($root . '/classes/Recipe/RecipeOperationalDashboardService.php');
$reports = recipeOperationalDashboardSource($root . '/reports.php');

recipeOperationalDashboardAssert(
    strpos($page, 'RecipeOperationalDashboardService') !== false,
    'dashboard page should load the shared operational dashboard service'
);
recipeOperationalDashboardAssert(
    strpos($page, 'require_login()') !== false,
    'dashboard page should require login'
);
recipeOperationalDashboardAssert(
    strpos($page, 'posmain_recipe_dashboard_can_view') !== false,
    'dashboard page should enforce internal permission checks'
);
recipeOperationalDashboardAssert(
    strpos($page, 'RecipeFeatureFlags') !== false,
    'dashboard page should expose recipe runtime flag state'
);

foreach ([
    'staleReservations',
    'negativeBalances',
    'invalidInventoryMovements',
    'missingCostSnapshots',
    'recipeSetupIssues',
    'movementWriteGaps',
    'availabilityCacheGaps',
    'menuSyncOutboxIssues',
    'lastReconciliationSignals',
] as $methodNeedle) {
    recipeOperationalDashboardAssert(strpos($service, $methodNeedle) !== false, 'dashboard service missing method: ' . $methodNeedle);
}

foreach ([
    'stock_reservations',
    'inventory_item_balances',
    'inventory_movements',
    'invalid_inventory_movements',
    'both_qty_in_and_qty_out',
    'blank_idempotency_key',
    'recipe_cost_snapshots',
    'recipe_lines',
    'recipe_order_line_usage',
    'recipe_availability_cache',
    'sync_outbox',
    'menu.item_availability_changed',
    'bcmath_loaded',
    'runtime_bcmath_missing',
    'Current PHP bcmath',
    'cost_public_payloads',
    'public_cost_payloads_enabled',
    'Public cost payloads',
    'production_variance_policy',
    'production_variance_policy_requires_accounting',
    'Production variance policy',
    'active_mode_flag_mismatches',
    'Active mode flags',
    'stock_policy_mismatches',
    'Stock policy flags',
    'availability_effective',
] as $signalNeedle) {
    recipeOperationalDashboardAssert(strpos($service, $signalNeedle) !== false, 'dashboard service missing rollout signal: ' . $signalNeedle);
}

foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeOperationalDashboardAssert(strpos($page, $writeNeedle) === false, 'dashboard page must remain read-only: ' . $writeNeedle);
    recipeOperationalDashboardAssert(strpos($service, $writeNeedle) === false, 'dashboard service must remain read-only: ' . $writeNeedle);
}

foreach (['RecipeOrderLifecycleService', 'RecipeInventoryMovementService', 'RecipeAccountingService'] as $bypassNeedle) {
    recipeOperationalDashboardAssert(strpos($page, $bypassNeedle) === false, 'dashboard page must not call mutating recipe service: ' . $bypassNeedle);
    recipeOperationalDashboardAssert(strpos($service, $bypassNeedle) === false, 'dashboard service must not call mutating recipe service: ' . $bypassNeedle);
}

recipeOperationalDashboardAssert(strpos($reports, 'recipe_operational_dashboard.php') !== false, 'reports page should link operational dashboard');

echo "recipe-operational-dashboard-contract-ok\n";

function recipeOperationalDashboardSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeOperationalDashboardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
