<?php

require_once __DIR__ . '/../../classes/Pos/DTO/OrderCreateRequest.php';
require_once __DIR__ . '/../../classes/Pos/Service/PreparationSelectionService.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeExplosionService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

function preparation_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$selection = new PreparationSelectionService();
preparation_contract_assert($selection->isEnabled(['preparation_fields_enabled' => true]), 'preparation feature must be available when enabled');
$normalize = new ReflectionMethod(PreparationSelectionService::class, 'normalizeSubmitted');
$normalized = $normalize->invoke($selection, [
    ['id' => 'pos-preparation-sugar_spoons', 'value' => 0],
]);
preparation_contract_assert($normalized === ['sugar_spoons' => 0], 'counter option must preserve an explicit zero');

$line = new OrderLineRequest([
    'id' => 42,
    'qty' => 1,
    'preparation_values' => [['code' => 'sugar_spoons', 'value' => 3]],
]);
preparation_contract_assert(
    ($line->toArray()['preparation_values'][0]['value'] ?? null) === 3,
    'order DTO must preserve preparation values'
);

$recipeLine = new RecipeOrderLineContext([
    'item_id' => 42,
    'qty' => '2',
    'preparation_values' => [[
        'code' => 'sugar_spoons',
        'value' => 3,
        'inventory_item_id' => 99,
        'inventory_qty_per_value' => '0.004',
    ]],
]);
preparation_contract_assert(count($recipeLine->preparationValues) === 1, 'recipe line context must retain preparation snapshots');

$preparationRequirementsMethod = new ReflectionMethod(RecipeExplosionService::class, 'preparationRequirements');
$preparationRequirements = $preparationRequirementsMethod->invoke(new RecipeExplosionService(), $recipeLine);
preparation_contract_assert(count($preparationRequirements) === 1, 'mapped sugar must create one inventory requirement');
preparation_contract_assert(($preparationRequirements[0]->ingredientItemId ?? 0) === 99, 'mapped sugar must consume the configured inventory item');
preparation_contract_assert(($preparationRequirements[0]->requiredQtyBase ?? '') === '0.024000', 'inventory usage must multiply order quantity by spoon count and per-spoon quantity');

$schema = new SyncSchemaManager();
$planned = $schema->plannedStatements();
preparation_contract_assert(isset($planned['item_preparation_configs']), 'preparation config schema must be planned');
preparation_contract_assert(isset($planned['item_group_preparation_configs']), 'category preparation config schema must be planned');
preparation_contract_assert(isset($planned['order_line_preparation_values']), 'order-line preparation schema must be planned');
preparation_contract_assert(strpos($planned['kds_ticket_lines'], 'preparation_json') !== false, 'KDS schema must reserve preparation payload storage');
preparation_contract_assert(strpos($planned['recipe_order_line_usage'], 'preparation_json') !== false, 'recipe usage must snapshot preparation payloads');

$mutationSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php');
preparation_contract_assert(strpos($mutationSource, 'validateForItem(') !== false, 'order mutation must validate preparation values');
preparation_contract_assert(strpos($mutationSource, 'persistLineValues(') !== false, 'order mutation must persist preparation values');
$tableInsertStart = strpos($mutationSource, 'private function insertTableOrderItems');
$tableInsertEnd = strpos($mutationSource, 'private function loadRecipeOrderLineContexts', (int) $tableInsertStart);
$tableInsertSource = $tableInsertStart === false
    ? ''
    : substr(
        $mutationSource,
        (int) $tableInsertStart,
        $tableInsertEnd === false ? null : $tableInsertEnd - $tableInsertStart
    );
preparation_contract_assert(
    strpos($tableInsertSource, "'preparation_values' => is_array(\$item['preparation_values'] ?? null)") !== false,
    'table inventory normalization must retain the validated preparation selection, including explicit zero'
);

$explosionSource = file_get_contents(__DIR__ . '/../../classes/Recipe/RecipeExplosionService.php');
preparation_contract_assert(strpos($explosionSource, 'preparationRequirements') !== false, 'recipe explosion must add mapped preparation inventory requirements');
preparation_contract_assert(strpos($explosionSource, 'mergeRequirements') !== false, 'recipe explosion must aggregate duplicate inventory ingredients');
preparation_contract_assert(strpos($explosionSource, "'preparation_only'") !== false, 'mapped preparation inventory must work without a drink recipe');

$lifecycleSource = file_get_contents(__DIR__ . '/../../classes/Recipe/RecipeOrderLifecycleService.php');
preparation_contract_assert(strpos($lifecycleSource, 'applyPreparationCosts') !== false, 'payment must freeze preparation internal cost');
preparation_contract_assert(strpos($lifecycleSource, 'costForInventoryItem') !== false, 'payment must use inventory costing for preparation consumption');

$menuOptionSource = file_get_contents(__DIR__ . '/../../classes/Items/ItemCustomerMenuOptions.php');
preparation_contract_assert(strpos($menuOptionSource, "'pos-preparation-'") !== false, 'customer menu payload must expose the preparation counter');
preparation_contract_assert(strpos($menuOptionSource, "'requiresExplicitValue'") !== false, 'customer channels must be told an explicit counter selection is required');

$printSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/OrderPrintPayloadService.php');
preparation_contract_assert(strpos($printSource, 'preparation_values') !== false, 'print payload must carry preparation values');

$receiptSource = file_get_contents(__DIR__ . '/../../print/receipt.php');
$kotSource = file_get_contents(__DIR__ . '/../../print/preparation.php');
preparation_contract_assert(strpos($receiptSource, 'line_preparation_values') !== false, 'receipt must render preparation values');
preparation_contract_assert(strpos($kotSource, 'preparationValues') !== false, 'KOT must render preparation values');
preparation_contract_assert(strpos($receiptSource, 'بدون سكر') !== false, 'receipt must render explicit zero as no sugar');
preparation_contract_assert(strpos($kotSource, 'بدون سكر') !== false, 'KOT must render explicit zero as no sugar');

$categorySource = file_get_contents(__DIR__ . '/../../mygroups.php');
preparation_contract_assert(strpos($categorySource, 'sugar_spoons_enabled') === false, 'category rows must not expose repetitive sugar controls');

$itemsSource = file_get_contents(__DIR__ . '/../../myitems.php');
preparation_contract_assert(strpos($itemsSource, 'إعداد السكر') !== false, 'item catalog must expose one sugar assignment button');
preparation_contract_assert(strpos($itemsSource, "\$menuWriteCsrfToken = csrf_token('menu_write');") !== false, 'item catalog must persist the menu token before header closes the session');
preparation_contract_assert(strpos($itemsSource, 'id="sugarAssignmentsModal"') !== false, 'the assignment flow must stay in one modal');
preparation_contract_assert(strpos($itemsSource, 'sugar-category-choice') !== false, 'the modal must support whole-category selection');
preparation_contract_assert(strpos($itemsSource, 'sugar-item-checkbox') !== false, 'the modal must support searchable individual-item selection');
preparation_contract_assert(strpos($itemsSource, '$sugarItemRows') !== false, 'the assignment modal must use a full catalog independent of table filters');
preparation_contract_assert(strpos($itemsSource, 'item-sugar-toggle') === false, 'item rows must not expose repetitive sugar checkboxes');
preparation_contract_assert(strpos($itemsSource, 'ajax/sugar_spoons_assignments_save.php') !== false, 'the modal must save all selections in one request');
preparation_contract_assert(strpos($itemsSource, 'csrf_token: sugarAssignmentsCsrf') !== false, 'bulk sugar save must submit CSRF in the POST body for proxy-safe verification');
preparation_contract_assert(strpos($itemsSource, "errorCode === 'CSRF_INVALID'") !== false, 'expired sugar assignment pages must show an actionable Arabic refresh message');

$bulkSaveSource = file_get_contents(__DIR__ . '/../../ajax/sugar_spoons_assignments_save.php');
preparation_contract_assert(strpos($bulkSaveSource, "'sugar_assignment_bulk_save'") !== false, 'bulk sugar save failures must be logged with a stable context');
preparation_contract_assert(strpos($bulkSaveSource, "'reference' => \$errorReference") !== false, 'bulk sugar save failures must return a safe diagnostic reference');

$editorSource = file_get_contents(__DIR__ . '/../../add_item.php');
preparation_contract_assert(strpos($editorSource, 'sugar_spoons_inventory_item_id') === false, 'item editor must not mix inventory mapping into sugar allowance');
preparation_contract_assert(strpos($editorSource, 'item-preparation-section') === false, 'item editor must not add a separate sugar configuration panel');

$cardSource = file_get_contents(__DIR__ . '/../../includes/pos_item_card.php');
$cashierSource = file_get_contents(__DIR__ . '/../../js/pos_barcode.js');
$apiSource = file_get_contents(__DIR__ . '/../../js/pos_order_api.js');
$tableCatalogSource = file_get_contents(__DIR__ . '/../../ajax/get_items.php');
$tableVariantSource = file_get_contents(__DIR__ . '/../../ajax/get_item_variants.php');
$tableOrderSource = file_get_contents(__DIR__ . '/../../ajax/get_table_order.php');
$tablePosSource = file_get_contents(__DIR__ . '/../../js/pos_tables.js');
preparation_contract_assert(strpos($cardSource, 'data-sugar-spoons') !== false, 'cashier item cards must carry sugar eligibility');
preparation_contract_assert(strpos($cashierSource, 'بدون سكر') !== false, 'cashier must be able to explicitly choose zero sugar');
preparation_contract_assert(strpos($cashierSource, 'id="sugarSpoonsDecrease"') !== false, 'cashier sugar quantity must have a decrement control');
preparation_contract_assert(strpos($cashierSource, 'id="sugarSpoonsIncrease"') !== false, 'cashier sugar quantity must have an increment control');
preparation_contract_assert(strpos($cashierSource, 'id="sugarSpoonsValue"') !== false, 'cashier must be able to enter a spoon quantity directly');
preparation_contract_assert(strpos($cashierSource, 'sugarSpoonsChoice') === false, 'cashier must not be constrained to fixed preset quantities');
preparation_contract_assert(strpos($cashierSource, 'name="itmpreparation[]"') !== false, 'cashier cart must persist preparation values');
preparation_contract_assert(strpos($cashierSource, 'function escapeHtmlAttribute') !== false, 'cashier must provide attribute-safe encoding for preparation JSON');
preparation_contract_assert(strpos($cashierSource, 'const safePreparationJson = escapeHtmlAttribute(preparationJson);') !== false, 'cashier must not truncate preparation JSON at embedded quotes');
preparation_contract_assert(strpos($cashierSource, "find('.preparationValuesInput').val() || '[]'") !== false, 'cart line grouping must include preparation values so different spoon counts remain separate');
preparation_contract_assert(strpos($apiSource, 'payload.itmpreparation') !== false, 'POS API payload must carry preparation values');
preparation_contract_assert(strpos($apiSource, "PREPARATION_VALUE_REQUIRED: 'اختر عدد ملاعق السكر") !== false, 'cashier must see an actionable Arabic message instead of an internal preparation error code');
preparation_contract_assert(strpos($tableCatalogSource, 'decorateItems(') !== false, 'table catalog must expose sugar eligibility');
preparation_contract_assert(strpos($tableVariantSource, 'decorateItems(') !== false, 'table variants must expose sugar eligibility');
preparation_contract_assert(strpos($tableOrderSource, 'fetchLineValues(') !== false, 'table order reload must restore persisted preparation values');
preparation_contract_assert(strpos($tablePosSource, 'data-sugar-spoons') !== false, 'table item cards must carry sugar eligibility');
preparation_contract_assert(strpos($tablePosSource, 'id="tablePreparationModal"') !== false, 'table ordering must request an explicit preparation selection');
preparation_contract_assert(strpos($tablePosSource, 'ويُسمح بصفر') !== false, 'table ordering must visibly support explicit zero sugar');
preparation_contract_assert(strpos($tablePosSource, 'preparation_values: preparationValues') !== false, 'table order lines must retain preparation values');
preparation_contract_assert(strpos($tablePosSource, 'preparation_values: Array.isArray(item.preparation_values)') !== false, 'table save payload must serialize preparation values');
preparation_contract_assert(strpos($tablePosSource, 'preparation_values: Array.isArray(item.preparation_values) ? item.preparation_values : []') !== false, 'table idempotency fingerprint must include preparation values');
preparation_contract_assert(strpos($tablePosSource, 'preparationFingerprint') !== false, 'table line grouping must separate different preparation values');

$kdsSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/KdsTicketService.php');
$kdsBoardSource = file_get_contents(__DIR__ . '/../../js/kds_board.js');
preparation_contract_assert(strpos($kdsSource, "'preparation_values' => \$preparation") !== false, 'KDS API must carry preparation separately from free-form notes');
preparation_contract_assert(strpos($kdsBoardSource, 'line.preparation_values') !== false, 'KDS board must render structured preparation values');
preparation_contract_assert(strpos($kdsBoardSource, 'بدون سكر') !== false, 'KDS must render explicit zero as no sugar');

$appConfigSource = file_get_contents(__DIR__ . '/../../config/app_config.php');
preparation_contract_assert(strpos($appConfigSource, "['POSMAIN_PREPARATION_FIELDS_ENABLED'], '1'") !== false, 'sugar preparation must be enabled by default');

$routeManifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
preparation_contract_assert(
    ($routeManifest['ajax/sugar_spoons_assignments_save.php']['permission'] ?? '') === 'menu.edit',
    'bulk sugar assignment must require menu editing permission'
);

echo "preparation_fields_contract_test: OK\n";
