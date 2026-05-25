<?php

$root = dirname(__DIR__, 2);
$tool = file_get_contents($root . '/tools/recipe_migrated_write_smoke.php');
$costService = file_get_contents($root . '/classes/Recipe/RecipeCostService.php');

recipeMigratedWriteSmokeContractAssert($tool !== false, 'write smoke tool should exist');
recipeMigratedWriteSmokeContractAssert($costService !== false, 'recipe cost service should exist');

foreach ([
    "'apply' => isset",
    'recipe_migrated_write_smoke_refuses_production_runtime',
    'recipe_migrated_write_smoke_hosted_staging_requires_explicit_allow',
    'recipe_migrated_write_smoke_requires_consumption_pilot_flags',
    'recipe_migrated_write_smoke_fixture_not_verified_for_store',
    'recipe_migrated_write_smoke_insufficient_fixture_stock',
    'recipe_migrated_write_smoke_run_id_already_used',
    'recipeMigratedWriteSmokeStockPreflight',
    'new RecipeExplosionService($flags)',
    'RecipeDecimal::compare($available, $required)',
    'new PosOrderMutationService()',
    "'record_outbox' => false",
    'idempotency_replayed',
    'recipe_migrated_write_smoke_expected_positive_usage_cost',
    'recipe_migrated_write_smoke_expected_positive_movement_cost',
] as $needle) {
    recipeMigratedWriteSmokeContractAssert(strpos($tool, $needle) !== false, 'write smoke contract missing: ' . $needle);
}

foreach ([
    'snapshotHasIngredientCostRows',
    'return $this->createSnapshot($conn, $recipeId, $context);',
    "array_key_exists('ingredient_item_id'",
    "array_key_exists('unit_cost'",
] as $needle) {
    recipeMigratedWriteSmokeContractAssert(strpos($costService, $needle) !== false, 'cost snapshot repair contract missing: ' . $needle);
}

echo "recipe-migrated-write-smoke-contract-ok\n";

function recipeMigratedWriteSmokeContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
