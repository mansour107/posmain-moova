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
$variantLineRepository = recipeManagementUiSource($root . '/classes/Recipe/Repository/RecipeVariantLineRepository.php');
$explosionService = recipeManagementUiSource($root . '/classes/Recipe/RecipeExplosionService.php');
$schemaManager = recipeManagementUiSource($root . '/classes/Sync/SchemaManager.php');
$reports = recipeManagementUiSource($root . '/reports.php');

recipeManagementUiAssert(strpos($page, 'RecipeEditorMutationService') !== false, 'management page should delegate mutations');
recipeManagementUiAssert(strpos($page, 'RecipeEditorReadService') !== false, 'management page should reuse read service');
recipeManagementUiAssert(strpos($page, 'RecipeEditorPreviewService') !== false, 'management page should delegate previews');
recipeManagementUiAssert(strpos($page, 'require_login()') !== false, 'management page should require login');
recipeManagementUiAssert(strpos($page, 'require_csrf(\'recipe_editor\')') !== false, 'management page should require recipe editor CSRF');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_can_edit') !== false, 'management page should enforce edit permission');
recipeManagementUiAssert(strpos($page, 'RecipeOrderLifecycleService') === false, 'management page must not call order lifecycle');
recipeManagementUiAssert(strpos($page, 'حفظ التغييرات') !== false, 'management page should expose Arabic save action');
recipeManagementUiAssert(strpos($page, 'id="recipe-global-save-button"') !== false, 'management page should expose one global save button');
recipeManagementUiAssert(strpos($page, 'data-recipe-save-form') !== false, 'management page should route the global save button to the active edit form');
recipeManagementUiAssert(strpos($page, 'requestSubmit') !== false, 'global save should preserve browser validation before posting');
recipeManagementUiAssert(strpos($page, 'id="create_recipe_name"') === false, 'main recipe library create form should not ask for a recipe name');
recipeManagementUiAssert(strpos($page, '<label>اسم الوصفة</label>') === false, 'main recipe library should not expose recipe-name entry');
recipeManagementUiAssert(strpos($page, '<th>الوصفة</th>') === false, 'main recipe library should not show a recipe-name column');
recipeManagementUiAssert(strpos($page, "<th>الصنف المرتبط</th>\n                                                        <th>النوع</th>") === false, 'main recipe library should not show the recipe type column');
recipeManagementUiAssert(strpos($page, 'placeholder="ابحث باسم الصنف"') !== false, 'main recipe library search should focus on item name');
recipeManagementUiAssert(strpos($page, 'recipe_manage.php?create_recipe=1') !== false, 'main recipe library should expose a top create recipe button');
recipeManagementUiAssert(strpos($page, 'recipe-create-top-button') !== false, 'main recipe library create action should be prominent');
recipeManagementUiAssert(strpos($page, 'data-auto-submit-form') !== false, 'create recipe page should create/open the draft automatically after item selection');
recipeManagementUiAssert(strpos($page, 'submitForm.requestSubmit') !== false, 'item selection should submit the create form without an extra create button');
recipeManagementUiAssert(strpos($page, 'recipe-create-variant-list') === false, 'create recipe page should not show non-clickable variation preview rows');
recipeManagementUiAssert(strpos($page, '<div class="col-lg-4 mb-3">') === false, 'main recipe library should not keep a right-side create panel');
foreach (['>حفظ المكون</button>', '>حفظ التنويعات', '>حفظ وصفة التنويعة</button>'] as $localSaveButton) {
    recipeManagementUiAssert(strpos($page, $localSaveButton) === false, 'management page should not expose local save buttons: ' . $localSaveButton);
}
recipeManagementUiAssert(strpos($page, 'إضافة مكون') !== false, 'management page should expose Arabic component creation');
recipeManagementUiAssert(strpos($page, '<label>الوحدة</label>') !== false, 'management page should let users choose a unit by name');
recipeManagementUiAssert(strpos($page, '<th>الوحدة</th>') !== false, 'management page should show component units in the grid');
recipeManagementUiAssert(strpos($page, 'الإصدارات والنشاط') !== false, 'management page should expose clean version activity');
recipeManagementUiAssert(strpos($page, 'التكلفة والتوفر') !== false, 'management page should expose cost and availability');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_can_view_cost') !== false, 'management page should guard cost visibility');
recipeManagementUiAssert(strpos($page, 'recipe-lookup-input') !== false, 'management page should expose lookup inputs');
recipeManagementUiAssert(strpos($page, 'ajax/recipe_editor_lookup.php') !== false, 'management page should call lookup endpoint');
recipeManagementUiAssert(strpos($page, 'مكون') !== false, 'management page should use user-facing component wording');
recipeManagementUiAssert(strpos($page, 'recipe-side-card') !== false, 'management page should expose mockup-style side summary cards');
recipeManagementUiAssert(strpos($page, 'recipe-more-menu') !== false, 'management page should expose compact more menu actions');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_component_icon_class') !== false, 'management page should use visual component type icons');
recipeManagementUiAssert(strpos($page, 'recipe-top-metrics') !== false && strpos($page, '.recipe-top-metrics') !== false, 'management page should keep duplicate top metrics hidden');
recipeManagementUiAssert(strpos($page, 'recipe-tab-panel') !== false, 'management page should render real tab panels');
recipeManagementUiAssert(strpos($page, 'activateRecipeTab') !== false, 'management page should switch tabs without laying all sections out at once');
recipeManagementUiAssert(strpos($page, '.recipe-tab-panel {') !== false && strpos($page, 'display: none;') !== false, 'inactive recipe tab panels should be hidden');
recipeManagementUiAssert(strpos($page, '.recipe-tab-panel.active') !== false && strpos($page, 'display: block;') !== false, 'active recipe tab panel should be visible');
recipeManagementUiAssert(strpos($page, 'تفاصيل الوصفة') !== false, 'management page should merge overview and components into recipe details tab');
recipeManagementUiAssert(strpos($page, 'نظرة عامة') === false, 'management page should not keep a separate overview tab');
recipeManagementUiAssert(strpos($page, 'id="recipe-components-tab"') === false, 'management page should not keep a separate components tab');
recipeManagementUiAssert(strpos($page, 'id="recipe-info-tab"') === false, 'management page should not keep a separate info tab');
recipeManagementUiAssert(strpos($page, 'id="recipe-details"') < strpos($page, 'معلومات الوصفة'), 'recipe details tab should show components before recipe info');
recipeManagementUiAssert(strpos($page, 'قاعدة البيع حسب المخزون') === false, 'recipe info should not show stock rule heading copy');
recipeManagementUiAssert(strpos($page, 'اتركها غير محددة لمنع البيع عند عدم توفر المكونات المطلوبة.') === false, 'recipe info should not show stock rule helper copy');
recipeManagementUiAssert(substr_count($page, 'role="tabpanel"') >= 4, 'each recipe tab should own a dedicated content panel');
recipeManagementUiAssert(strpos($page, 'save_variations') === false, 'management page should not edit item variations that belong to the item page');
recipeManagementUiAssert(strpos($page, 'save_variant_recipe') !== false, 'management page should save each variation recipe from the details tab');
recipeManagementUiAssert(strpos($page, 'id="recipe-variations-tab"') === false, 'management page should not expose a separate variations tab');
recipeManagementUiAssert(strpos($page, 'id="recipe-variations"') === false, 'management page should not expose a separate variations panel');
recipeManagementUiAssert(strpos($page, 'recipe-variation-table') === false, 'management page should not expose variation barcode/price management table');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_variant_row') === false, 'management page should not render item variation rows from the recipe page');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_variant_recipe_card') !== false, 'management page should render premium variation recipe editor cards inside recipe details');
recipeManagementUiAssert(strpos($page, 'فتح صفحة الصنف') !== false, 'variation details should keep a shortcut to the item page');
recipeManagementUiAssert(strpos($page, 'recipe-section-fade') !== false, 'recipe details should separate components and info with a subtle centered fade line');
recipeManagementUiAssert(strpos($page, 'يرث وصفة الصنف الرئيسي') === false, 'variation cards should not show inherited recipe wording');
recipeManagementUiAssert(strpos($page, 'recipe-variation-badge') === false, 'variation cards should not show status badges in the simplified recipe UI');
recipeManagementUiAssert(strpos($page, 'وصفة ' . "' . posmain_recipe_manage_h(") === false, 'variation cards should not prefix variation names with recipe wording');
recipeManagementUiAssert(strpos($page, 'variant_recipe_qty_per_yield') !== false, 'variation recipe editor should make amount editing straightforward');
recipeManagementUiAssert(strpos($page, 'recipe-add-variant-component') !== false, 'variation recipe editor should allow adding ingredients/components');
recipeManagementUiAssert(strpos($page, 'recipe-remove-variant-component') !== false, 'variation recipe editor should allow removing ingredients/components');
recipeManagementUiAssert(strpos($page, 'وصفات التنويعات') === false, 'variation recipe editor should not add a redundant heading above variation cards');
recipeManagementUiAssert(strpos($page, 'عدّل كميات كل تنويعة بسرعة') === false, 'variation recipe editor should not show explanatory copy above variation cards');
recipeManagementUiAssert(strpos($page, '<th>نشطة</th>') === false, 'variation table should not expose active controls on recipe page');
recipeManagementUiAssert(strpos($page, '<th>افتراضية</th>') === false, 'variation table should not expose default controls on recipe page');
recipeManagementUiAssert(strpos($page, 'recipe-variation-default') === false, 'variation table should not expose default checkbox behavior');
recipeManagementUiAssert(strpos($page, '<details class="recipe-variation-recipe-card" data-variant-recipe-card') !== false, 'variation recipe cards should be minimized by default');
foreach (['مكتبة الوصفات', 'معلومات الوصفة', 'مكونات الوصفة', 'قواعد متقدمة'] as $sectionLabel) {
    recipeManagementUiAssert(strpos($page, $sectionLabel) !== false, 'management page should expose item recipe section: ' . $sectionLabel);
}
foreach (['يصنع عند الطلب', 'يحضر كدفعة', 'مكون محضر'] as $recipeTypeLabel) {
    recipeManagementUiAssert(strpos($page, $recipeTypeLabel) !== false, 'management page should expose simplified recipe type label: ' . $recipeTypeLabel);
}
foreach (['Packaging bundle', 'Modifier only'] as $technicalTypeLabel) {
    recipeManagementUiAssert(strpos($page, $technicalTypeLabel) === false, 'management page should hide technical recipe type label: ' . $technicalTypeLabel);
}
foreach ([
    '<label>Sellable Item ID</label>',
    '<label>Ingredient Item ID</label>',
    '<label>Sub-recipe ID</label>',
    '<label>Modifier Group ID</label>',
    '<label>Modifier Option ID</label>',
    '<label>Costing Method</label>',
    '<label>Safety Stock</label>',
    '<label>Conversion</label>',
    '<label>Order Type</label>',
    '<label>Channel</label>',
    '<label>Modifier Option</label>',
    '<label>Modifier Behavior</label>',
    '<label>Substitution Group</label>',
    '<label>Store</label>',
    '<th>ID</th>',
    '<th>Scope</th>',
    'Add Line',
    'Edit Line #',
    'Read View',
    'Recipe Draft Management',
    'Create Draft',
    'Save Draft Header',
    'Version History',
    'Cost And Availability Preview',
    'Allow sale without stock',
    'Requires recipe for sale',
    'Made when sold',
    'Save Changes',
    'Save Component',
    'Add Component',
    'Recipe Components',
    'Recipe Library',
    'Recipe Info',
    'Modifier Effects',
    'تأثيرات الإضافات',
    'Advanced Rules',
    'Made per order',
    'Prepared in batch',
    'Prepared component',
    '>Required<',
    "Sellable item id is required.",
    "' - item '",
] as $manualIdUi) {
    recipeManagementUiAssert(strpos($page, $manualIdUi) === false, 'management page should not expose manual database-id UI: ' . $manualIdUi);
}
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_ui_message') !== false, 'management page should translate backend flashes and preview messages for the Arabic UI');
recipeManagementUiAssert(strpos($page, 'posmain_recipe_manage_sellable_item_label') !== false, 'management page should display sellable item names instead of raw ids');
recipeManagementUiAssert(strpos($page, 'name="unit_conversion_to_base" value="1.00000000"') !== false, 'management page should keep default conversion hidden for backend compatibility');
recipeManagementUiAssert(strpos($page, 'name="is_required" value="1"') !== false, 'management page should keep base components required by default without showing a checkbox');

foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeManagementUiAssert(strpos($page, $writeNeedle) === false, 'management page should not contain direct write SQL: ' . $writeNeedle);
}

recipeManagementUiAssert(strpos($service, 'class RecipeEditorMutationService') !== false, 'mutation service class missing');
recipeManagementUiAssert(strpos($service, 'RecipeDefinitionService') !== false, 'mutation service should call definition service');
recipeManagementUiAssert(strpos($service, 'RecipeDecimal') !== false, 'mutation service should validate recipe editor decimals with decimal-safe helpers');
recipeManagementUiAssert(strpos($service, '(float)') === false, 'mutation service should not coerce recipe editor decimals through floats');
recipeManagementUiAssert(strpos($service, 'Please choose an item by name.') !== false, 'mutation service should keep create-draft validation user-facing');
recipeManagementUiAssert(strpos($service, 'Sellable item id is required.') === false, 'mutation service should not expose database-id language to users');
recipeManagementUiAssert(strpos($service, 'mainItemIdForSellable') !== false, 'mutation service should normalize variation child selections to the main item');
recipeManagementUiAssert(strpos($service, 'ItemVariantService') !== false, 'mutation service should delegate variation saves to the item variant service');
foreach (['create_draft', 'update_draft', 'add_line', 'update_line', 'approve', 'activate', 'archive', 'clone_new_version', 'save_variations', 'save_variant_recipe'] as $action) {
    recipeManagementUiAssert(strpos($service, $action) !== false, 'mutation service missing action: ' . $action);
}
recipeManagementUiAssert(strpos($service, 'RecipeVariantLineRepository') !== false, 'mutation service should persist variation recipe rows through a repository');
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
foreach ([
    'Variation recipe line UUID is required',
    'Variation recipe component is required',
    'Variation recipe amount must be positive',
    'replaceLinesForVariant',
] as $guard) {
    recipeManagementUiAssert(strpos($variantLineRepository, $guard) !== false, 'variation line repository should guard variation recipe invariant: ' . $guard);
}
recipeManagementUiAssert(strpos($schemaManager, "'recipe_variant_lines'") !== false, 'schema manager should install recipe variation line table');
recipeManagementUiAssert(strpos($schemaManager, 'CREATE TABLE IF NOT EXISTS recipe_variant_lines') !== false, 'schema manager should define recipe variation line table');
recipeManagementUiAssert(strpos($explosionService, 'variantLinesForRecipe') !== false, 'explosion service should use variation recipe overrides when present');
recipeManagementUiAssert(strpos($explosionService, 'variantParentForChild') !== false, 'explosion service should resolve child variation sales to the parent recipe');

recipeManagementUiAssert(strpos($preview, 'RecipeCostService') !== false, 'preview service should use cost service');
recipeManagementUiAssert(strpos($preview, 'RecipeAvailabilityService') !== false, 'preview service should use availability service');
recipeManagementUiAssert(strpos($availability, 'previewForRecipe') !== false, 'availability service should expose no-write recipe preview');
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeManagementUiAssert(strpos($preview, $writeNeedle) === false, 'preview service should not contain write operation: ' . $writeNeedle);
}

recipeManagementUiAssert(strpos($lookup, 'class RecipeEditorLookupService') !== false, 'lookup service class missing');
recipeManagementUiAssert(strpos($lookup, 'searchComponents') !== false, 'lookup service should expose unified component search');
recipeManagementUiAssert(strpos($lookup, 'iv_child.variant_item_id = myitems.id') !== false, 'sellable item lookup should hide active variation children');
recipeManagementUiAssert(strpos($lookup, 'cost_price') === false, 'lookup service should not expose item cost');
recipeManagementUiAssert(strpos($lookup, 'price_delta') === false, 'lookup service should not expose modifier prices');
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeManagementUiAssert(strpos($lookup, $writeNeedle) === false, 'lookup service should be read-only: ' . $writeNeedle);
}
recipeManagementUiAssert(strpos($lookupEndpoint, 'require_login()') !== false, 'lookup endpoint should require login');
recipeManagementUiAssert(strpos($lookupEndpoint, 'posmain_recipe_lookup_can_view') !== false, 'lookup endpoint should enforce recipe view permission');
recipeManagementUiAssert(strpos($lookupEndpoint, 'RecipeEditorLookupService') !== false, 'lookup endpoint should delegate to lookup service');
recipeManagementUiAssert(strpos($lookupEndpoint, "type === 'components'") !== false, 'lookup endpoint should route unified component searches');

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
