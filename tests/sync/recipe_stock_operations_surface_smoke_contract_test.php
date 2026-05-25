<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_stock_operations_surface_smoke.php';
$tool = recipeStockOperationsSurfaceSmokeSource($toolPath);
$preflight = recipeStockOperationsSurfaceSmokeSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$pilotEvidence = recipeStockOperationsSurfaceSmokeSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');
$doc = recipeStockOperationsSurfaceSmokeSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeStockOperationsSurfaceSmokeAssert($helpCode === 0, 'stock operations surface smoke help should exit cleanly');
recipeStockOperationsSurfaceSmokeAssert(strpos($help, 'authenticated GET smoke') !== false, 'help should describe authenticated GET smoke');
recipeStockOperationsSurfaceSmokeAssert(strpos($help, 'production and waste/stock-adjustment operator surfaces') !== false, 'help should describe production/waste scope');
recipeStockOperationsSurfaceSmokeAssert(strpos($help, 'does not log in') !== false, 'help should state that it does not log in');
recipeStockOperationsSurfaceSmokeAssert(strpos($help, 'does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync') !== false, 'help should document read-only behavior');
recipeStockOperationsSurfaceSmokeAssert(strpos($help, '--batch-id=123') !== false, 'help should document selected batch id input');
recipeStockOperationsSurfaceSmokeAssert(strpos($help, 'curl/Netscape cookie jar') !== false, 'help should document cookie jar input');

foreach ([
    'recipe_production.php',
    'recipe_waste.php',
    'Recipe Production Batches',
    'Create Draft Batch',
    'name="action" value="create_draft"',
    'name="planned_output_qty"',
    'Commit Batch',
    'Cancel Draft',
    'name="actual_output_qty"',
    'name="variance_reason"',
    'Recipe Waste and Stock Adjustments',
    'name="action" value="record_waste"',
    'name="waste_uuid"',
    'name="action" value="record_adjustment"',
    'name="adjustment_uuid"',
    'Source',
    'mode_off_found',
    'Production batch writes are disabled by the current recipe feature flags',
    'Recipe waste and stock adjustment writes are disabled by the current feature flags',
] as $needle) {
    recipeStockOperationsSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should check stock operation surface token: ' . $needle);
}

foreach ([
    'recipeStockOperationsSurfaceSmokeNormalizeCookieSource',
    '#HttpOnly_',
    'batch_id_missing_selected_batch_controls_not_rendered',
    'requires_authenticated_session_cookie',
    'recipe_stock_operations_surface_smoke_cookie_missing',
] as $needle) {
    recipeStockOperationsSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should expose smoke guard: ' . $needle);
}

foreach (['method\' => \'GET\'', 'CURLOPT_HTTPGET'] as $needle) {
    recipeStockOperationsSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should only use GET requests: ' . $needle);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'SyncOutboxEventService'] as $needle) {
    recipeStockOperationsSurfaceSmokeAssert(strpos($tool, $needle) === false, 'tool must stay read-only and avoid: ' . $needle);
}

recipeStockOperationsSurfaceSmokeAssert(strpos($preflight, 'tools/recipe_stock_operations_surface_smoke.php') !== false, 'runtime preflight should require stock operations surface smoke tool');
recipeStockOperationsSurfaceSmokeAssert(strpos($pilotEvidence, 'tools/recipe_stock_operations_surface_smoke.php') !== false, 'pilot evidence service should accept stock operations surface smoke evidence command');
recipeStockOperationsSurfaceSmokeAssert(strpos($doc, 'tools/recipe_stock_operations_surface_smoke.php') !== false, 'rollout doc should document stock operations surface smoke command');
recipeStockOperationsSurfaceSmokeAssert(strpos($doc, 'production and waste/stock-adjustment surface evidence') !== false, 'rollout doc should describe production/waste surface evidence');
recipeStockOperationsSurfaceSmokeAssert(strpos($doc, 'It does not log in, submit forms, create batches, commit batches, cancel batches, record waste, record adjustments') !== false, 'rollout doc should document mutation-free scope');
recipeStockOperationsSurfaceSmokeAssert(strpos($doc, 'records a fixture-selection warning') !== false, 'rollout doc should explain no selected batch warning');

echo "recipe-stock-operations-surface-smoke-contract-ok\n";

function recipeStockOperationsSurfaceSmokeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeStockOperationsSurfaceSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
