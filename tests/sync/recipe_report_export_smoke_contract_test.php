<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_report_export_smoke.php';
$tool = recipeReportExportSmokeSource($toolPath);
$preflight = recipeReportExportSmokeSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$doc = recipeReportExportSmokeSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeReportExportSmokeAssert($helpCode === 0, 'report export smoke help should exit cleanly');
recipeReportExportSmokeAssert(strpos($help, 'authenticated GET smoke') !== false, 'help should describe authenticated GET smoke');
recipeReportExportSmokeAssert(strpos($help, 'does not log in') !== false, 'help should state that it does not log in');
recipeReportExportSmokeAssert(strpos($help, 'curl/Netscape cookie jar') !== false, 'help should document cookie jar input');
recipeReportExportSmokeAssert(strpos($help, 'spreadsheet-formula-safe exported cells') !== false, 'help should describe formula safety check');

foreach ([
    'recipe_stock_reconciliation.php' => 'stock_reconciliation',
    'recipe_audit_report.php' => 'audit',
    'recipe_operations_report.php' => 'operations_',
] as $path => $nameNeedle) {
    recipeReportExportSmokeAssert(strpos($tool, $path) !== false, 'tool should smoke export page: ' . $path);
    recipeReportExportSmokeAssert(strpos($tool, $nameNeedle) !== false, 'tool should expose export name: ' . $nameNeedle);
}

foreach ([
    'cost_history',
    'ingredient_consumption',
    'recipe_cogs',
    'production_variance',
    'low_stock_impact',
    'cogs_reconciliation',
    'expected_vs_actual_usage',
    'modifier_revenue_cost',
] as $report) {
    recipeReportExportSmokeAssert(strpos($tool, "'{$report}'") !== false, 'tool should smoke operations report export: ' . $report);
}

foreach ([
    'recipeReportExportSmokeLoginDetected',
    'recipeReportExportSmokeAccessDenied',
    'recipeReportExportSmokeFatalText',
    'recipeReportExportSmokeNormalizeCookieSource',
    'recipeReportExportSmokeUnsafeCells',
    'str_getcsv($line, \',\', \'"\', \'\\\\\')',
    '#HttpOnly_',
    '$exportSpecificChecksAllowed = $exportBlockers === []',
    'recipe_report_export_smoke_cookie_missing',
] as $needle) {
    recipeReportExportSmokeAssert(strpos($tool, $needle) !== false, 'tool should expose export smoke guard: ' . $needle);
}

foreach (['method\' => \'GET\'', 'CURLOPT_HTTPGET', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'SyncOutboxEventService'] as $needle) {
    if ($needle === 'method\' => \'GET\'' || $needle === 'CURLOPT_HTTPGET') {
        recipeReportExportSmokeAssert(strpos($tool, $needle) !== false, 'tool should only use GET requests');
        continue;
    }
    recipeReportExportSmokeAssert(strpos($tool, $needle) === false, 'tool must stay read-only and avoid: ' . $needle);
}

recipeReportExportSmokeAssert(strpos($preflight, 'tools/recipe_report_export_smoke.php') !== false, 'runtime preflight should require report export smoke tool');
recipeReportExportSmokeAssert(strpos($doc, 'tools/recipe_report_export_smoke.php') !== false, 'rollout doc should document report export smoke command');
recipeReportExportSmokeAssert(strpos($doc, 'spreadsheet-formula-safe exported cells') !== false, 'rollout doc should document formula-safe export smoke');
recipeReportExportSmokeAssert(strpos($doc, 'It does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync') !== false, 'rollout doc should document read-only scope');

echo "recipe-report-export-smoke-contract-ok\n";

function recipeReportExportSmokeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeReportExportSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
