<?php

$root = dirname(__DIR__, 2);
$page = recipeOperationsReportSource($root . '/recipe_operations_report.php');
$service = recipeOperationsReportSource($root . '/classes/Recipe/RecipeOperationsReportService.php');
$costSnapshotRepository = recipeOperationsReportSource($root . '/classes/Recipe/Repository/RecipeCostSnapshotRepository.php');
$reports = recipeOperationsReportSource($root . '/reports.php');

recipeOperationsReportAssert(
    strpos($page, 'RecipeOperationsReportService') !== false,
    'operations report page should load the shared operations report service'
);
recipeOperationsReportAssert(
    strpos($page, 'require_login()') !== false,
    'operations report page should require login'
);
recipeOperationsReportAssert(
    strpos($page, 'posmain_recipe_operations_can_view') !== false,
    'operations report page should enforce internal report permission'
);
recipeOperationsReportAssert(
    strpos($page, "auth_guard_has_permission('reports.view'") !== false,
    'operations report page should allow read-only report viewers without granting cost access'
);
recipeOperationsReportAssert(
    strpos($page, 'posmain_recipe_operations_can_view_cost') !== false,
    'operations report page should separate cost visibility from page visibility'
);
recipeOperationsReportAssert(
    strpos($page, 'Cost and margin columns are hidden') !== false,
    'operations report page should disclose when cost columns are hidden'
);
recipeOperationsReportAssert(
    strpos($page, 'export') !== false && strpos($page, 'text/csv') !== false,
    'operations report page should support CSV export'
);
recipeOperationsReportAssert(
    strpos($page, "require_once __DIR__ . '/includes/csv_export.php'") !== false
        && strpos($page, 'posmain_csv_safe_row') !== false,
    'operations report CSV export should sanitize exported cells'
);
recipeOperationsReportAssert(
    strpos($page, 'posmain_recipe_operations_columns($recipeOperationsReport, $recipeOperationsCanViewCost)') !== false,
    'operations report CSV/table columns should use the cost visibility gate'
);
foreach ([
    'cost_history',
    'ingredient_consumption',
    'recipe_cogs',
    'production_variance',
    'low_stock_impact',
    'cogs_reconciliation',
    'expected_vs_actual_usage',
    'modifier_revenue_cost',
] as $reportKey) {
    recipeOperationsReportAssert(strpos($page, $reportKey) !== false, 'operations page missing report selector: ' . $reportKey);
    recipeOperationsReportAssert(strpos($service, $reportKey) !== false, 'operations service missing dispatch key: ' . $reportKey);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeOperationsReportAssert(strpos($page, $writeNeedle) === false, 'operations page must remain read-only: ' . $writeNeedle);
    recipeOperationsReportAssert(strpos($service, $writeNeedle) === false, 'operations service must remain read-only: ' . $writeNeedle);
}

recipeOperationsReportAssert(strpos($service, 'costHistory') !== false, 'service should expose cost history');
recipeOperationsReportAssert(strpos($service, 'ingredientConsumption') !== false, 'service should expose ingredient consumption');
recipeOperationsReportAssert(strpos($service, 'recipeCogsByItem') !== false, 'service should expose recipe COGS by item');
recipeOperationsReportAssert(strpos($service, 'productionVariance') !== false, 'service should expose production variance');
recipeOperationsReportAssert(strpos($service, 'lowStockAffectedItems') !== false, 'service should expose low stock affected items');
recipeOperationsReportAssert(strpos($service, 'cogsJournalReconciliation') !== false, 'service should expose COGS journal reconciliation');
recipeOperationsReportAssert(strpos($service, 'expectedVsActualUsage') !== false, 'service should expose expected-vs-actual usage');
recipeOperationsReportAssert(strpos($service, 'modifierRevenueCost') !== false, 'service should expose modifier revenue vs cost');
recipeOperationsReportAssert(strpos($page, 'modifier_option_id') !== false, 'operations page should expose modifier option filtering');
recipeOperationsReportAssert(strpos($page, 'unset($safeColumns[$costColumn])') !== false, 'operations page should suppress cost columns for non-cost viewers');
recipeOperationsReportAssert(strpos($page, 'modifier_margin_percent') !== false, 'operations page should treat modifier margin percent as cost-sensitive');
recipeOperationsReportAssert(strpos($page, 'if ($canViewCost)') !== false, 'operations page should expose cost-heavy reports only behind the cost gate');
foreach (['Recipe cost snapshot UUID is required', 'recipe_id', 'must be positive', 'cost_per_yield', 'cannot be negative', 'Recipe cost snapshot ingredient_cost_json must be valid JSON', 'Recipe cost snapshot calculated_at is required'] as $guard) {
    recipeOperationsReportAssert(strpos($costSnapshotRepository, $guard) !== false, 'cost snapshot repository should guard historical cost invariant: ' . $guard);
}
recipeOperationsReportAssert(strpos($reports, 'recipe_operations_report.php') !== false, 'reports page should link operations reports');

echo "recipe-operations-report-contract-ok\n";

function recipeOperationsReportSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeOperationsReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
