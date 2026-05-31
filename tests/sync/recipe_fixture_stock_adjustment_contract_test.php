<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_fixture_stock_adjustment.php';
$tool = recipeFixtureStockAdjustmentContractSource($toolPath);
$preflight = recipeFixtureStockAdjustmentContractSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$readiness = recipeFixtureStockAdjustmentContractSource($root . '/classes/Recipe/RecipeRolloutReadinessService.php');
$doc = recipeFixtureStockAdjustmentContractSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeFixtureStockAdjustmentContractAssert($helpCode === 0, 'fixture stock adjustment help should exit cleanly');
recipeFixtureStockAdjustmentContractAssert(strpos($help, 'InventoryAdjustmentService') !== false, 'help should state the shared inventory service write path');
recipeFixtureStockAdjustmentContractAssert(strpos($help, 'Dry-run is the default') !== false, 'help should state dry-run default');

foreach ([
    'InventoryAdjustmentService',
    'recordAdjustment',
    'recipe_fixture_stock_adjustment_refuses_production_runtime',
    'recipe_fixture_stock_adjustment_hosted_staging_requires_explicit_allow',
    'recipe_fixture_stock_adjustment_requires_writable_recipe_mode',
    'recipe_fixture_stock_adjustment_requires_writable_inventory_ledger',
    'recipe_fixture_stock_adjustment_refuses_non_fixture_item',
    'Recipe QA',
    'RQA-',
    'idempotency_replayed',
    "!empty(\$first['idempotent_replay'])",
    'recipe_fixture_stock_adjustment_replay_not_idempotent',
    'recipe_fixture_stock_adjustment_balance_not_increased',
] as $needle) {
    recipeFixtureStockAdjustmentContractAssert(strpos($tool, $needle) !== false, 'fixture stock adjustment tool missing contract token: ' . $needle);
}

foreach ([
    'UPDATE inventory_item_balances',
    'INSERT INTO inventory_item_balances',
    'DELETE FROM inventory_item_balances',
    'new PosOrderMutationService',
    'record_outbox',
    'new RecipeWasteAdjustmentService',
] as $forbidden) {
    recipeFixtureStockAdjustmentContractAssert(strpos($tool, $forbidden) === false, 'fixture stock adjustment tool must not bypass service/write unrelated surfaces: ' . $forbidden);
}

recipeFixtureStockAdjustmentContractAssert(strpos($preflight, 'tools/recipe_fixture_stock_adjustment.php') !== false, 'runtime preflight should require fixture stock adjustment tool');
recipeFixtureStockAdjustmentContractAssert(strpos($readiness, 'recipe_fixture_stock_adjustment.php --json --apply') !== false, 'readiness should expose fixture stock adjustment command');
recipeFixtureStockAdjustmentContractAssert(strpos($doc, 'tools/recipe_fixture_stock_adjustment.php --json --apply') !== false, 'rollout doc should document fixture stock adjustment command');
recipeFixtureStockAdjustmentContractAssert(strpos($doc, 'does not update balances directly') !== false, 'rollout doc should document no direct balance update scope');

echo "recipe-fixture-stock-adjustment-contract-ok\n";

function recipeFixtureStockAdjustmentContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeFixtureStockAdjustmentContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
