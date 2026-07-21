<?php

$root = dirname(__DIR__, 2);
$report = recipeReconciliationReportSource($root . '/recipe_stock_reconciliation.php');
$service = recipeReconciliationReportSource($root . '/classes/Recipe/RecipeReconciliationService.php');
$reportsIndex = recipeReconciliationReportSource($root . '/reports.php');
$salesReports = recipeReconciliationReportSource($root . '/sales-reports.php');

recipeReconciliationReportAssert(
    strpos($report, 'RecipeReconciliationService.php') !== false,
    'recipe stock reconciliation report should load the shared reconciliation service'
);
recipeReconciliationReportAssert(
    strpos($report, 'require_login()') !== false,
    'recipe stock reconciliation report should require login through the auth guard'
);
recipeReconciliationReportAssert(
    strpos($report, 'posmain_recipe_reconciliation_can_view($conn)') !== false
        && strpos($report, "auth_guard_has_permission('reports.view'") !== false
        && strpos($report, 'recipe_permissions.php') !== false
        && strpos($report, 'posmain_recipe_can_view_sensitive_reports($conn)') !== false,
    'recipe stock reconciliation report should enforce named report or stock/accounting permissions'
);
recipeReconciliationReportAssert(
    strpos($report, '->report($conn, $recipeReconciliationFilters)') !== false,
    'recipe stock reconciliation report should delegate calculations to the service'
);
recipeReconciliationReportAssert(
    strpos($report, "header('Content-Type: text/csv; charset=utf-8')") !== false,
    'recipe stock reconciliation report should support CSV export'
);
recipeReconciliationReportAssert(
    strpos($report, "require_once __DIR__ . '/includes/csv_export.php'") !== false
        && strpos($report, 'posmain_csv_safe_row') !== false,
    'recipe stock reconciliation report should sanitize exported CSV cells'
);
recipeReconciliationReportAssert(
    strpos($report, 'posmain_recipe_reconciliation_filters_from_request') !== false
        && strpos($report, 'in_array((string) ($request[\'movement_type\'] ?? \'\'), $movementTypes, true)') !== false
        && strpos($report, 'in_array((string) ($request[\'source_type\'] ?? \'\'), $sourceTypes, true)') !== false,
    'recipe stock reconciliation report should sanitize movement/source filters through whitelists'
);
recipeReconciliationReportAssert(
    !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE)\b/i', $report),
    'recipe stock reconciliation report must remain read-only'
);
recipeReconciliationReportAssert(
    strpos($service, 'date_from') !== false
        && strpos($service, 'movement_type') !== false
        && strpos($service, 'source_type') !== false
        && strpos($service, 'recommended_action') !== false,
    'recipe reconciliation service should support rollout filters and operator action hints'
);
recipeReconciliationReportAssert(
    preg_match('/RecipeDecimal::|require_once\s+.*RecipeDecimal|bc(add|sub|mul|div|comp|scale)/i', $service) !== 1,
    'recipe reconciliation service must not require RecipeDecimal/bcmath for read-only report rendering'
);
recipeReconciliationReportAssertNoBcMathDecimalBehavior($root . '/classes/Recipe/RecipeReconciliationService.php');
recipeReconciliationReportAssert(
    strpos($reportsIndex, 'recipe_stock_reconciliation.php') !== false
        && strpos($salesReports, 'cash_flow_report.php?tab=overview') !== false,
    'recipe reconciliation stays discoverable from the report index while legacy POS reports redirect'
);

echo "recipe-stock-reconciliation-report-contract-ok\n";

function recipeReconciliationReportSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeReconciliationReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recipeReconciliationReportAssertNoBcMathDecimalBehavior(string $servicePath): void
{
    require_once $servicePath;
    $service = new RecipeReconciliationService();
    $subtract = Closure::bind(function ($left, $right) {
        return $this->decimalSubtract($left, $right);
    }, $service, get_class($service));
    $compare = Closure::bind(function ($left, $right) {
        return $this->decimalCompare($left, $right);
    }, $service, get_class($service));
    $normalize = Closure::bind(function ($value) {
        return $this->decimalNormalize($value);
    }, $service, get_class($service));

    $cases = [
        ['10', '2.5', '7.500000'],
        ['2.5', '10', '-7.500000'],
        ['-2', '3', '-5.000000'],
        ['-2', '-3', '1.000000'],
        ['0.000001', '0.000002', '-0.000001'],
    ];
    foreach ($cases as $case) {
        [$left, $right, $expected] = $case;
        recipeReconciliationReportAssert(
            $subtract($left, $right) === $expected,
            'recipe reconciliation decimal subtract should handle signed fixed-scale values'
        );
    }

    recipeReconciliationReportAssert(
        $normalize('1.2345678') === '1.234568',
        'recipe reconciliation decimal normalize should preserve expected rounding'
    );
    recipeReconciliationReportAssert(
        $compare('-0.000000', '0') === 0
            && $compare('-0.000001', '0') < 0
            && $compare('0.000001', '0') > 0,
        'recipe reconciliation decimal compare should handle zero and signed values'
    );
}
