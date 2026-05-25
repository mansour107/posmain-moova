<?php

$root = dirname(__DIR__, 2);
$page = recipeProductionUiSource($root . '/recipe_production.php');
$mutation = recipeProductionUiSource($root . '/classes/Recipe/ProductionBatchMutationService.php');
$read = recipeProductionUiSource($root . '/classes/Recipe/ProductionBatchReadService.php');
$service = recipeProductionUiSource($root . '/classes/Recipe/ProductionBatchService.php');
$repository = recipeProductionUiSource($root . '/classes/Recipe/Repository/ProductionBatchRepository.php');
$runtimeTest = recipeProductionUiSource($root . '/tests/sync/recipe_production_endpoint_runtime_test.php');
$reports = recipeProductionUiSource($root . '/reports.php');

recipeProductionUiAssert(strpos($page, 'ProductionBatchMutationService') !== false, 'production page should delegate mutations');
recipeProductionUiAssert(strpos($page, 'ProductionBatchReadService') !== false, 'production page should delegate reads');
recipeProductionUiAssert(strpos($page, 'ProductionBatchService') !== false, 'production page should delegate preview/commit behavior');
recipeProductionUiAssert(strpos($page, 'require_login()') !== false, 'production page should require login');
recipeProductionUiAssert(strpos($page, 'require_csrf(\'recipe_production\')') !== false, 'production page should require CSRF');
recipeProductionUiAssert(strpos($page, 'posmain_recipe_production_can_view') !== false, 'production page should enforce view permission');
recipeProductionUiAssert(strpos($page, 'posmain_recipe_production_can_commit') !== false, 'production page should gate commit permission');
recipeProductionUiAssert(strpos($page, 'posmain_recipe_production_can_view_cost') !== false, 'production page should separate cost visibility from production view permission');
recipeProductionUiAssert(substr_count($page, 'if ($canViewProductionCost)') >= 4, 'production page should gate preview and committed-line costs');
recipeProductionUiAssert(strpos($page, 'RecipeInventoryMovementService') === false, 'production page should not call movement service directly');
recipeProductionUiAssert(strpos($page, 'RecipeAccountingService') === false, 'production page should not call accounting service directly');
foreach (['Create Draft Batch', 'Input Preview', 'Commit Batch', 'Cancel Draft', 'Committed Lines'] as $label) {
    recipeProductionUiAssert(strpos($page, $label) !== false, 'production page missing operator surface: ' . $label);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeProductionUiAssert(strpos($page, $writeNeedle) === false, 'production page should not contain direct write SQL: ' . $writeNeedle);
}

recipeProductionUiAssert(strpos($mutation, 'class ProductionBatchMutationService') !== false, 'mutation service class missing');
recipeProductionUiAssert(strpos($mutation, 'ProductionBatchService') !== false, 'mutation service should call production service');
recipeProductionUiAssert(strpos($mutation, 'RecipeDecimal') !== false, 'mutation service should validate production quantities with decimal-safe helpers');
recipeProductionUiAssert(strpos($mutation, '(float)') === false, 'mutation service should not coerce production quantities through floats');
foreach (['create_draft', 'commit', 'cancel'] as $action) {
    recipeProductionUiAssert(strpos($mutation, $action) !== false, 'mutation service missing action: ' . $action);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService'] as $forbidden) {
    recipeProductionUiAssert(strpos($mutation, $forbidden) === false, 'mutation adapter should not bypass production domain: ' . $forbidden);
}

recipeProductionUiAssert(strpos($read, 'class ProductionBatchReadService') !== false, 'read service class missing');
foreach (['listBatches', 'batchDetail', 'activeProductionRecipes'] as $method) {
    recipeProductionUiAssert(strpos($read, $method) !== false, 'read service missing method: ' . $method);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeProductionUiAssert(strpos($read, $writeNeedle) === false, 'read service should not contain write operation: ' . $writeNeedle);
}

recipeProductionUiAssert(strpos($service, 'recordProductionInput') !== false, 'production service should record production inputs centrally');
recipeProductionUiAssert(strpos($service, 'recordProductionOutput') !== false, 'production service should record production output centrally');
recipeProductionUiAssert(strpos($service, 'isConsumptionEnabledForItem') !== false, 'production service should require active consumption feature and pilot scope before production stock writes');
recipeProductionUiAssert(strpos($service, 'postProductionBatch') !== false, 'production service should post production accounting centrally after movement writes');
recipeProductionUiAssert(strpos($service, 'RecipeAvailabilityService') !== false, 'production service should own production availability refresh');
recipeProductionUiAssert(strpos($service, 'refreshForIngredient') !== false, 'production service should refresh availability for changed production items');
recipeProductionUiAssert(strpos($service, 'availability_refreshes') !== false, 'production commit result should expose refreshed availability evidence');
foreach (['Production batch status is invalid', 'Production batch planned_output_qty must be positive', 'Production batch line_type is invalid', 'actual_qty', 'cannot be negative'] as $guard) {
    recipeProductionUiAssert(strpos($repository, $guard) !== false, 'production repository missing invariant guard: ' . $guard);
}
foreach (['draft', 'committed', 'cancelled', 'input', 'output', 'variance'] as $enumValue) {
    recipeProductionUiAssert(strpos($repository, $enumValue) !== false, 'production repository missing schema enum allowlist value: ' . $enumValue);
}
recipeProductionUiAssert(strpos($runtimeTest, 'recipe_production.php') !== false, 'runtime test should execute the real production page');
recipeProductionUiAssert(strpos($runtimeTest, "CREATE DATABASE `{\$db}`") !== false, 'runtime test should use an isolated temporary database');
recipeProductionUiAssert(strpos($runtimeTest, "DROP DATABASE IF EXISTS `{\$db}`") !== false, 'runtime test should drop the temporary database');
recipeProductionUiAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_MODE' => 'consume_pilot'") !== false, 'runtime test should use active pilot production mode');
recipeProductionUiAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_CONSUMPTION' => '1'") !== false, 'runtime test should enable consumption only inside the child process');
recipeProductionUiAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_PILOT_ITEM_IDS' => '2001'") !== false, 'runtime test should keep production writes scoped to one pilot item');
recipeProductionUiAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_ACCOUNTING' => '0'") !== false, 'runtime test should keep accounting disabled for the isolated smoke');
foreach (['production_batches', 'production_batch_lines', 'inventory_movements', 'inventory_item_balances'] as $table) {
    recipeProductionUiAssert(strpos($runtimeTest, $table) !== false, 'runtime test should verify table: ' . $table);
}
recipeProductionUiAssert(strpos($runtimeTest, 'commit replay should not duplicate movements') !== false, 'runtime test should prove committed replay does not double-write stock');
recipeProductionUiAssert(strpos($reports, 'recipe_production.php') !== false, 'reports page should link production batches');

echo "recipe-production-ui-contract-ok\n";

function recipeProductionUiSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeProductionUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
