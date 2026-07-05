<?php

$root = dirname(__DIR__, 2);
$form = itemRecipeCatalogSource($root . '/add_item.php')
    . itemRecipeCatalogSource($root . '/elements/sales/item_unit_profile_panel.php');
$add = itemRecipeCatalogSource($root . '/do/doadd_item.php');
$edit = itemRecipeCatalogSource($root . '/do/doedit_item.php');
$input = itemRecipeCatalogSource($root . '/classes/Items/ItemFormInput.php');
$service = itemRecipeCatalogSource($root . '/classes/Items/ItemRecipeCatalogService.php');
$recipeManage = itemRecipeCatalogSource($root . '/recipe_manage.php');

foreach (['item_type', 'track_stock'] as $field) {
    itemRecipeCatalogAssert(strpos($form, 'name="' . $field . '"') !== false, 'item form should expose ' . $field);
}
foreach (['item_type', 'track_stock', 'preferred_unit_id'] as $field) {
    itemRecipeCatalogAssert(strpos($input, "'" . $field . "'") !== false, 'item form input should normalize ' . $field);
}

itemRecipeCatalogAssert(strpos($form, 'recipe_manage.php?item_id=') !== false, 'item form should link to recipe management for the item');
itemRecipeCatalogAssert(strpos($add, 'ItemRecipeCatalogService') !== false, 'add item path should use recipe catalog metadata service');
itemRecipeCatalogAssert(strpos($edit, 'ItemRecipeCatalogService') !== false, 'edit item path should use recipe catalog metadata service');
itemRecipeCatalogAssert(substr_count($add, 'saveMetadata') >= 1, 'add item path should persist metadata');
itemRecipeCatalogAssert(substr_count($edit, 'saveMetadata') >= 1, 'edit item path should persist metadata');
itemRecipeCatalogAssert(strpos($service, 'columnExists') !== false, 'metadata service should tolerate old schemas');
itemRecipeCatalogAssert(strpos($service, 'item_type') !== false, 'metadata service should write item_type when present');
itemRecipeCatalogAssert(strpos($service, 'track_stock') !== false, 'metadata service should write track_stock when present');
itemRecipeCatalogAssert(strpos($service, 'preferred_unit_id') !== false, 'metadata service should write preferred_unit_id when present');
itemRecipeCatalogAssert(strpos($recipeManage, '$recipeManageCreateItemId') !== false, 'recipe management should accept item_id from catalog link');

echo "item-recipe-catalog-contract-ok\n";

function itemRecipeCatalogSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function itemRecipeCatalogAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
