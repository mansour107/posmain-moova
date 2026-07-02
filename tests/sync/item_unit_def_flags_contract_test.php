<?php

require_once __DIR__ . '/../../classes/Items/ItemUnitProfileBuilder.php';
require_once __DIR__ . '/../../classes/Items/ItemUnitProfileReader.php';
require_once __DIR__ . '/../../classes/Items/ItemUnitResolver.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderPricingService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorLookupService.php';

function itemUnitDefContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$builderSource = file_get_contents(__DIR__ . '/../../classes/Items/ItemUnitProfileBuilder.php');
$itemFormSource = file_get_contents(__DIR__ . '/../../classes/Items/ItemFormInput.php');
$searchSource = file_get_contents(__DIR__ . '/../../ajax/search_item.php');
$lazySource = file_get_contents(__DIR__ . '/../../ajax/load_items_lazy.php');
$pricingSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/OrderPricingService.php');
$variantSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ItemVariantService.php');
$recipeLookupSource = file_get_contents(__DIR__ . '/../../classes/Recipe/RecipeEditorLookupService.php');
$recipeMutationSource = file_get_contents(__DIR__ . '/../../classes/Recipe/RecipeEditorMutationService.php');
$migrationSource = file_get_contents(__DIR__ . '/../../tools/migrate_item_unit_profiles.php');
$panelSource = file_get_contents(__DIR__ . '/../../elements/sales/item_unit_profile_panel.php');

itemUnitDefContractAssert(strpos($builderSource, 'def_sale') !== false, 'builder should write def_sale');
itemUnitDefContractAssert(strpos($builderSource, 'def_stock') !== false, 'builder should write def_stock');
itemUnitDefContractAssert(strpos($builderSource, 'def_buy') !== false, 'builder should write def_buy');
itemUnitDefContractAssert(strpos($itemFormSource, 'ItemUnitProfileBuilder') !== false, 'ItemFormInput should use profile builder');
itemUnitDefContractAssert(strpos($searchSource, 'ItemUnitResolver::sellPriceForItem') !== false, 'search_item should resolve sell price');
itemUnitDefContractAssert(strpos($lazySource, 'ItemUnitResolver::sellPriceForItem') !== false, 'lazy items should resolve sell price');
itemUnitDefContractAssert(strpos($pricingSource, 'ItemUnitResolver::sellPriceForItem') !== false, 'OrderPricingService should resolve sell price');
itemUnitDefContractAssert(strpos($variantSource, 'cloneParentUnitsForVariant') !== false, 'variants should clone parent unit profile');
itemUnitDefContractAssert(strpos($recipeLookupSource, 'stock_unit_id') !== false, 'recipe lookup should expose stock unit');
itemUnitDefContractAssert(strpos($recipeMutationSource, 'ItemUnitResolver::stockUnitIdForItem') !== false, 'recipe mutation should default ingredient unit');
itemUnitDefContractAssert(strpos($migrationSource, 'migrate_item_unit_profiles') !== false || strpos($migrationSource, 'def_stock') !== false, 'migration script should set def flags');
itemUnitDefContractAssert(strpos($panelSource, 'item_unit_profile_present') !== false, 'item editor panel should post profile marker');
itemUnitDefContractAssert(strpos($panelSource, 'شراء وتخزين') !== false, 'item editor panel should include purchase section');

echo "item-unit-def-flags-contract-ok\n";
