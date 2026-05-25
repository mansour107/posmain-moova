<?php

$root = dirname(__DIR__, 2);
$tool = recipeAvailabilityRefreshSource($root . '/tools/recipe_refresh_availability.php');
$service = recipeAvailabilityRefreshSource($root . '/classes/Recipe/RecipeAvailabilityRefreshService.php');
$availability = recipeAvailabilityRefreshSource($root . '/classes/Recipe/RecipeAvailabilityService.php');
$availabilityCacheRepository = recipeAvailabilityRefreshSource($root . '/classes/Recipe/Repository/RecipeAvailabilityCacheRepository.php');
$dependencies = recipeAvailabilityRefreshSource($root . '/classes/Recipe/RecipeDependencyResolverService.php');

exec('php ' . escapeshellarg($root . '/tools/recipe_refresh_availability.php') . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeAvailabilityRefreshAssert($helpCode === 0, 'availability refresh help should exit cleanly');
recipeAvailabilityRefreshAssert(strpos($help, 'Dry-run is the default') !== false, 'help should make dry-run default explicit');
recipeAvailabilityRefreshAssert(strpos($help, '--apply') !== false, 'help should document apply flag');
recipeAvailabilityRefreshAssert(strpos($help, '--ingredient-id=ID') !== false, 'help should document ingredient scoped refresh');
recipeAvailabilityRefreshAssert(strpos($help, 'including through sub-recipes') !== false, 'help should document sub-recipe dependency refresh');
recipeAvailabilityRefreshAssert(strpos($tool, 'RecipeAvailabilityRefreshService') !== false, 'tool should delegate to shared availability refresh service');
recipeAvailabilityRefreshAssert(strpos($service, 'recipeIdsAffectedByIngredient') !== false, 'refresh service should use dependency resolver for ingredient changes');
recipeAvailabilityRefreshAssert(strpos($dependencies, 'recipe_lines') !== false, 'dependency resolver should resolve impacted recipes from recipe_lines');
recipeAvailabilityRefreshAssert(strpos($dependencies, "sub_recipe_id IN") !== false, 'dependency resolver should include parent recipes through sub-recipe lines');
recipeAvailabilityRefreshAssert(strpos($availability, 'refreshForIngredient') !== false, 'availability service should expose ingredient refresh method');
recipeAvailabilityRefreshAssert(strpos($availability, 'refreshForRecipe') !== false, 'availability service should expose recipe refresh method');
foreach (['Recipe availability cache order_type is invalid', 'Recipe availability cache channel is invalid', 'computed_available_qty', 'cannot be negative', 'Recipe availability cache effective_is_available must be 0 or 1', 'Recipe availability cache availability_revision must be positive'] as $guardNeedle) {
    recipeAvailabilityRefreshAssert(strpos($availabilityCacheRepository, $guardNeedle) !== false, 'availability cache repository should guard cache invariant: ' . $guardNeedle);
}
foreach (['dine_in', 'takeaway', 'delivery', 'moova', 'cofe', 'api'] as $enumNeedle) {
    recipeAvailabilityRefreshAssert(strpos($availabilityCacheRepository, $enumNeedle) !== false, 'availability cache repository should allow schema enum value: ' . $enumNeedle);
}

foreach (['RecipeInventoryMovementService', 'RecipeAccountingService', 'stock_reservations', 'inventory_movements'] as $forbiddenNeedle) {
    recipeAvailabilityRefreshAssert(strpos($tool, $forbiddenNeedle) === false, 'tool must not invoke stock/accounting surface: ' . $forbiddenNeedle);
    recipeAvailabilityRefreshAssert(strpos($service, $forbiddenNeedle) === false, 'service must not invoke stock/accounting surface: ' . $forbiddenNeedle);
}
foreach (['shell_exec', 'passthru', 'system('] as $unsafeNeedle) {
    recipeAvailabilityRefreshAssert(strpos($tool, $unsafeNeedle) === false, 'tool must not execute shell commands internally: ' . $unsafeNeedle);
}

echo "recipe-availability-refresh-contract-ok\n";

function recipeAvailabilityRefreshSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeAvailabilityRefreshAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
