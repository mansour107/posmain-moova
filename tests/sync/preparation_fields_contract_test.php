<?php

require_once __DIR__ . '/../../classes/Pos/DTO/OrderCreateRequest.php';
require_once __DIR__ . '/../../classes/Pos/Service/PreparationSelectionService.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

function preparation_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$selection = new PreparationSelectionService();
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

$schema = new SyncSchemaManager();
$planned = $schema->plannedStatements();
preparation_contract_assert(isset($planned['item_preparation_configs']), 'preparation config schema must be planned');
preparation_contract_assert(isset($planned['order_line_preparation_values']), 'order-line preparation schema must be planned');
preparation_contract_assert(strpos($planned['kds_ticket_lines'], 'preparation_json') !== false, 'KDS schema must reserve preparation payload storage');
preparation_contract_assert(strpos($planned['recipe_order_line_usage'], 'preparation_json') !== false, 'recipe usage must snapshot preparation payloads');

$mutationSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php');
preparation_contract_assert(strpos($mutationSource, 'validateForItem(') !== false, 'order mutation must validate preparation values');
preparation_contract_assert(strpos($mutationSource, 'persistLineValues(') !== false, 'order mutation must persist preparation values');

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

echo "preparation_fields_contract_test: OK\n";
