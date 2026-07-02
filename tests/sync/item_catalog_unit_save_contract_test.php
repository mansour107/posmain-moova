<?php

function itemCatalogUnitSaveContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = __DIR__ . '/../..';
$source = file_get_contents($root . '/ajax/item_catalog_unit_save.php');
$itemUnitPickerJs = file_get_contents($root . '/js/item_unit_picker.js');
$panel = file_get_contents($root . '/elements/sales/item_unit_profile_panel.php');
$addItem = file_get_contents($root . '/add_item.php');

itemCatalogUnitSaveContractAssert(strpos($source, 'myunits') !== false, 'endpoint should write to myunits');
itemCatalogUnitSaveContractAssert(strpos($source, 'prepare(') !== false, 'endpoint should use prepared statements');
itemCatalogUnitSaveContractAssert(strpos($source, 'add_items') !== false, 'endpoint should require add_items permission');
itemCatalogUnitSaveContractAssert(strpos($panel, 'item-unit-picker__add') !== false, 'unit profile panel should expose inline add button');
itemCatalogUnitSaveContractAssert(strpos($panel, 'item-unit-combobox') !== false, 'unit profile panel should use searchable combobox');
itemCatalogUnitSaveContractAssert(strpos($itemUnitPickerJs, 'initItemUnitPickers') !== false, 'unit picker js should expose init function');
itemCatalogUnitSaveContractAssert(strpos($itemUnitPickerJs, 'fetch(') !== false, 'unit picker should save via fetch API');
itemCatalogUnitSaveContractAssert(strpos($addItem, 'itemCatalogUnitModal') !== false, 'add item page should include unit modal');
itemCatalogUnitSaveContractAssert(strpos($addItem, 'item_catalog_unit_save.php') !== false, 'add item page should wire unit save endpoint');
itemCatalogUnitSaveContractAssert(strpos($addItem, 'includes/footer.php') !== false, 'add item page should include footer');
itemCatalogUnitSaveContractAssert(strpos($addItem, 'item_unit_profile.js') !== false, 'add item page should load item unit profile js after footer');

echo "item-catalog-unit-save-contract-ok\n";
