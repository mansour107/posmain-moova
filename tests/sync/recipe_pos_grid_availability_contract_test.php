<?php

$root = dirname(__DIR__, 2);
$cardSource = recipePosGridSource($root . '/includes/pos_item_card.php');
$posContent = recipePosGridSource($root . '/includes/pos_content.php');
$lazyEndpoint = recipePosGridSource($root . '/ajax/load_items_lazy.php');
$categoryEndpoint = recipePosGridSource($root . '/ajax/get_category_items.php');
$barcodeJs = recipePosGridSource($root . '/js/pos_barcode.js');
$runtimeTest = recipePosGridSource($root . '/tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php');
$surfaceSmoke = recipePosGridSource($root . '/tools/recipe_pos_grid_availability_surface_smoke.php');

require_once $root . '/includes/pos_item_card.php';

$shortageHtml = pos_render_item_card([
    'id' => 101,
    'iname' => 'Cheeseburger',
    'price1' => '90.00',
    'is_available' => 1,
    'availability_status' => 'recipe_shortage',
    'availability_can_add' => true,
    'availability_warn_only' => true,
    'availability_low_stock' => true,
    'unavailable_reason' => 'Required ingredient out of stock.',
    'recipe_enabled' => true,
    'recipe_effective_available_qty' => '-4.000000',
    'recipe_cashier_available_qty' => '0',
    'recipe_availability_revision' => 12,
]);

$lowStockHtml = pos_render_item_card([
    'id' => 102,
    'iname' => 'Latte',
    'price1' => '55.00',
    'is_available' => 1,
    'availability_status' => 'recipe_low',
    'availability_can_add' => true,
    'availability_low_stock' => true,
    'recipe_enabled' => true,
    'recipe_effective_available_qty' => '3.000000',
    'recipe_availability_revision' => 13,
]);

$directPositiveLowHtml = pos_render_item_card([
    'id' => 103,
    'iname' => 'Direct stock item',
    'price1' => '20.00',
    'is_available' => 1,
    'availability_status' => 'inventory_low',
    'availability_can_add' => true,
    'availability_low_stock' => true,
    'inventory_stock_tracked' => true,
    'inventory_cashier_qty_available' => '3.000000',
]);
$directZeroHtml = pos_render_item_card_compact([
    'id' => 104,
    'iname' => 'Zero stock item',
    'price1' => '25.00',
    'is_available' => 1,
    'availability_status' => 'inventory_shortage',
    'availability_can_add' => true,
    'availability_low_stock' => true,
    'inventory_stock_tracked' => true,
    'inventory_qty_available' => '-7.000000',
    'inventory_cashier_qty_available' => '0',
]);
$nonStockHtml = pos_render_item_card([
    'id' => 105,
    'iname' => 'Service item',
    'price1' => '30.00',
    'is_available' => 1,
    'availability_can_add' => true,
]);

recipePosGridAssert(strpos($shortageHtml, 'data-is-available="1"') !== false, 'recipe shortage card should remain available');
recipePosGridAssert(strpos($shortageHtml, 'data-availability-can-add="1"') !== false, 'recipe shortage card should remain addable');
recipePosGridAssert(strpos($shortageHtml, 'data-availability-status="recipe_shortage"') !== false, 'recipe shortage card should expose shortage status');
recipePosGridAssert(strpos($shortageHtml, 'data-recipe-enabled="1"') !== false, 'recipe card should expose recipe-enabled flag');
recipePosGridAssert(strpos($shortageHtml, 'item-unavailable') === false, 'recipe shortage card must not receive the unavailable class');
recipePosGridAssert(strpos($shortageHtml, 'متبقي 0') !== false, 'recipe shortage should display exactly zero');
recipePosGridAssert(
    strpos($shortageHtml, 'data-recipe-effective-available-qty="-4') === false,
    'raw negative recipe stock must never enter cashier card state'
);
recipePosGridAssert(strpos($shortageHtml, 'Required ingredient out of stock.') !== false, 'recipe shortage card should carry cashier warning reason');
recipePosGridAssert(strpos($lowStockHtml, 'item-low-stock') !== false, 'low stock card should have low-stock class');
recipePosGridAssert(strpos($lowStockHtml, 'متبقي 3') !== false, 'low stock card should show remaining quantity badge');
recipePosGridAssert(strpos($directPositiveLowHtml, 'متبقي 3') !== false, 'positive direct low stock should reuse the existing quantity badge');
recipePosGridAssert(strpos($directZeroHtml, 'متبقي 0') !== false, 'zero or negative direct stock should display exactly zero');
recipePosGridAssert(strpos($directZeroHtml, '-7') === false, 'raw negative stock must never be emitted to the cashier card');
recipePosGridAssert(strpos($directZeroHtml, 'data-availability-can-add="1"') !== false, 'zero-status direct stock must remain addable');
recipePosGridAssert(strpos($nonStockHtml, 'pos-item-availability-badge') === false, 'non-stock items must not receive a stock badge');
recipePosGridAssert(strpos($nonStockHtml, 'data-inventory-stock-tracked="0"') !== false, 'non-stock items should explicitly remain untracked in the cashier DOM');

recipePosGridAssert(strpos($cardSource, 'data-recipe-effective-available-qty') !== false, 'card renderer should emit recipe available quantity data');
recipePosGridAssert(strpos($cardSource, "recipe_cashier_available_qty") !== false, 'card renderer should expose the clamped recipe quantity to cashier JavaScript');
recipePosGridAssert(strpos($cardSource, 'data-inventory-cashier-qty') !== false, 'card renderer should emit only the clamped cashier stock quantity');
recipePosGridAssert(strpos($posContent, 'ItemAvailabilityService.php') !== false, 'initial POS grid should load ItemAvailabilityService');
recipePosGridAssert(strpos($posContent, 'decorateItems($conn, $posInitialItems, $availabilityScope)') !== false, 'initial POS grid should decorate items before rendering');
recipePosGridAssert(strpos($lazyEndpoint, 'decorateItems($conn, $items, $availabilityScope)') < strpos($lazyEndpoint, "pos_render_item_card_compact(\$item)"), 'lazy grid should render cards after decoration');
recipePosGridAssert(strpos($categoryEndpoint, 'decorateItems($conn, $items') !== false, 'category endpoint should decorate items before returning JSON');
recipePosGridAssert(strpos($barcodeJs, 'itemAvailabilityContext') !== false, 'POS JS should read item availability data attributes');
recipePosGridAssert(strpos($barcodeJs, 'showUnavailableItemMessage') !== false, 'POS JS should explain unavailable item clicks');
recipePosGridAssert(strpos($barcodeJs, 'requestRecipeStockOverride') !== false, 'POS JS should request manager override for allowed recipe stock overrides');
recipePosGridAssert(strpos($barcodeJs, 'name="itmmanagerapproval[]"') !== false, 'POS cart rows should preserve recipe stock manager approval id');
recipePosGridAssert(strpos($barcodeJs, 'if (!availability.canAdd)') !== false, 'POS JS should block items that are not addable before cart mutation');
recipePosGridAssert(strpos($runtimeTest, 'ajax/get_category_items.php') !== false, 'runtime test should execute the real category endpoint');
recipePosGridAssert(strpos($runtimeTest, "CREATE DATABASE `{\$db}`") !== false, 'runtime test should use an isolated temporary database');
recipePosGridAssert(strpos($runtimeTest, "DROP DATABASE IF EXISTS `{\$db}`") !== false, 'runtime test should drop the temporary database');
recipePosGridAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_MODE' => 'availability_pilot'") !== false, 'runtime test should enable availability pilot only inside the child process');
recipePosGridAssert(strpos($runtimeTest, "'POSMAIN_RECIPE_PILOT_ITEM_IDS' => '7001,7002'") !== false, 'runtime test should keep availability scoped to explicit pilot items');
recipePosGridAssert(strpos($runtimeTest, 'recipe_availability_cache') !== false, 'runtime test should verify cache refresh');
recipePosGridAssert(strpos($runtimeTest, 'recipe_shortage') !== false, 'runtime test should verify permissive recipe shortage payload');
recipePosGridAssert(strpos($runtimeTest, 'recipe_low') !== false, 'runtime test should verify low-stock recipe payload');
recipePosGridAssert(strpos($runtimeTest, 'POS availability payload should not expose cost key') !== false, 'runtime test should guard cost leakage');
recipePosGridAssert(strpos($surfaceSmoke, 'recipe_pos_grid_availability_surface_smoke.php') !== false, 'surface smoke should exist for POS grid availability QA');
recipePosGridAssert(strpos($surfaceSmoke, 'data-recipe-effective-available-qty') !== false, 'surface smoke should check rendered recipe quantity attributes');
recipePosGridAssert(strpos($surfaceSmoke, 'ajax/get_category_items.php') !== false, 'surface smoke should check category payload shape');
recipePosGridAssert(strpos($surfaceSmoke, 'sensitive_cost_keys_exposed') !== false, 'surface smoke should guard POS payload cost leakage');
recipePosGridAssert(strpos($surfaceSmoke, 'CURLOPT_HTTPGET') !== false, 'surface smoke should stay read-only GET-only');

echo "recipe-pos-grid-availability-contract-ok\n";

function recipePosGridSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipePosGridAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
