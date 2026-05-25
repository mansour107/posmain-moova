<?php

$root = dirname(__DIR__, 2);
$page = recipeManagementUiSource($root . '/recipe_manage.php');
$service = recipeManagementUiSource($root . '/classes/Recipe/RecipeEditorMutationService.php');
$preview = recipeManagementUiSource($root . '/classes/Recipe/RecipeEditorPreviewService.php');
$availability = recipeManagementUiSource($root . '/classes/Recipe/RecipeAvailabilityService.php');
$lookup = recipeManagementUiSource($root . '/classes/Recipe/RecipeEditorLookupService.php');
$lookupEndpoint = recipeManagementUiSource($root . '/ajax/recipe_editor_lookup.php');
$headerRepository = recipeManagementUiSource($root . '/classes/Recipe/Repository/RecipeRepository.php');
$lineRepository = recipeManagementUiSource($root . '/classes/Recipe/Repository/RecipeLineRepository.php');
$reports = recipeManagementUiSource($root . '/reports.php');

recipeManagementUiAssert(strpos($page, 'RecipeEditorMutationService') !== false, 'management page should delegate mutations');
recipeManagementUiAssert(strpos($page, 'RecipeEditorReadService') !== false, 'management page should reuse read service');
recipeManagementUiAssert(strpos($page, 'RecipeEditorPreviewService') !== false, 'management page should delegate previews');
recipeManagementUiAssert(strpos($page, 'require_login()') !== false, 'management page should require login');
recipeManagementUiAssert(strpos($page, 'require_csrf(\'recipe_editor\')') !== false, 'management page should require recipe editor CSRF');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_can_edit') !== false, 'management page should enforce edit permission');
recipeManagementUiAssert(strpos($page, 'RecipeOrderLifecycleService') === false, 'management page must not call order lifecycle');
recipeManagementUiAssert(strpos($page, 'Save Draft Header') !== false, 'management page should expose draft header editing');
recipeManagementUiAssert(strpos($page, 'Save Line') !== false, 'management page should expose line editing');
recipeManagementUiAssert(strpos($page, 'Version History') !== false, 'management page should expose version history');
recipeManagementUiAssert(strpos($page, 'Cost And Availability Preview') !== false, 'management page should expose preview controls');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_can_view_cost') !== false, 'management page should guard cost visibility');
recipeManagementUiAssert(strpos($page, 'recipe-lookup-input') !== false, 'management page should expose lookup inputs');
recipeManagementUiAssert(strpos($page, 'ajax/recipe_editor_lookup.php') !== false, 'management page should call lookup endpoint');
recipeManagementUiAssert(strpos($page, 'Modifier Behavior') !== false, 'management page should expose modifier behavior editing');
recipeManagementUiAssert(strpos($page, 'Substitution Group') !== false, 'management page should expose substitution group editing');
foreach (['substitution_remove', 'substitution_add'] as $behavior) {
    recipeManagementUiAssert(strpos($page, $behavior) !== false, 'management page missing modifier behavior option: ' . $behavior);
}

foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeManagementUiAssert(strpos($page, $writeNeedle) === false, 'management page should not contain direct write SQL: ' . $writeNeedle);
}

recipeManagementUiAssert(strpos($service, 'class RecipeEditorMutationService') !== false, 'mutation service class missing');
recipeManagementUiAssert(strpos($service, 'RecipeDefinitionService') !== false, 'mutation service should call definition service');
recipeManagementUiAssert(strpos($service, 'RecipeDecimal') !== false, 'mutation service should validate recipe editor decimals with decimal-safe helpers');
recipeManagementUiAssert(strpos($service, '(float)') === false, 'mutation service should not coerce recipe editor decimals through floats');
foreach (['create_draft', 'update_draft', 'add_line', 'update_line', 'approve', 'activate', 'archive', 'clone_new_version'] as $action) {
    recipeManagementUiAssert(strpos($service, $action) !== false, 'mutation service missing action: ' . $action);
}
foreach (['modifier_behavior', 'substitution_group', 'substitution_remove', 'substitution_add'] as $field) {
    recipeManagementUiAssert(strpos($service, $field) !== false, 'mutation service missing substitution field: ' . $field);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeOrderLifecycleService', 'RecipeInventoryMovementService'] as $forbidden) {
    recipeManagementUiAssert(strpos($service, $forbidden) === false, 'mutation service should not bypass definition domain: ' . $forbidden);
}
foreach ([
    'Recipe header UUID is required',
    'Recipe header sellable_item_id must be positive',
    'Recipe header recipe_type is invalid',
    'Recipe header status is invalid',
    'Recipe header yield_qty must be positive',
    'Recipe header default_wastage_percent cannot be negative',
] as $guard) {
    recipeManagementUiAssert(strpos($headerRepository, $guard) !== false, 'recipe header repository should guard definition invariant: ' . $guard);
}
foreach ([
    'Recipe line UUID is required',
    'Recipe line recipe_id must be positive',
    'Recipe line line_type is invalid',
    'Recipe line ingredient_item_id must be positive',
    'Recipe line sub_recipe_id must be positive',
    'Recipe line qty_per_yield must be positive',
    'Recipe line unit_conversion_to_base must be positive',
    'Recipe line wastage_percent cannot be negative',
] as $guard) {
    recipeManagementUiAssert(strpos($lineRepository, $guard) !== false, 'recipe line repository should guard definition invariant: ' . $guard);
}

recipeManagementUiAssert(strpos($preview, 'RecipeCostService') !== false, 'preview service should use cost service');
recipeManagementUiAssert(strpos($preview, 'RecipeAvailabilityService') !== false, 'preview service should use availability service');
recipeManagementUiAssert(strpos($availability, 'previewForRecipe') !== false, 'availability service should expose no-write recipe preview');
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeManagementUiAssert(strpos($preview, $writeNeedle) === false, 'preview service should not contain write operation: ' . $writeNeedle);
}

recipeManagementUiAssert(strpos($lookup, 'class RecipeEditorLookupService') !== false, 'lookup service class missing');
recipeManagementUiAssert(strpos($lookup, 'cost_price') === false, 'lookup service should not expose item cost');
recipeManagementUiAssert(strpos($lookup, 'price_delta') === false, 'lookup service should not expose modifier prices');
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeManagementUiAssert(strpos($lookup, $writeNeedle) === false, 'lookup service should be read-only: ' . $writeNeedle);
}
recipeManagementUiAssert(strpos($lookupEndpoint, 'require_login()') !== false, 'lookup endpoint should require login');
recipeManagementUiAssert(strpos($lookupEndpoint, 'posmain_recipe_lookup_can_view') !== false, 'lookup endpoint should enforce recipe view permission');
recipeManagementUiAssert(strpos($lookupEndpoint, 'RecipeEditorLookupService') !== false, 'lookup endpoint should delegate to lookup service');

recipeManagementUiAssert(strpos($reports, 'recipe_manage.php') !== false, 'reports page should link recipe management');

echo "recipe-management-ui-contract-ok\n";

function recipeManagementUiSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeManagementUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
