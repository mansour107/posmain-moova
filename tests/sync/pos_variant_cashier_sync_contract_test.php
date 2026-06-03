<?php

$root = __DIR__ . '/../..';
$js = posVariantSource($root . '/js/pos_barcode.js');
$posContent = posVariantSource($root . '/includes/pos_content.php');
$addItem = posVariantSource($root . '/add_item.php');
$doAddItem = posVariantSource($root . '/do/doadd_item.php');
$doEditItem = posVariantSource($root . '/do/doedit_item.php');
$variantEndpoint = posVariantSource($root . '/ajax/get_item_variants.php');
$lazyItems = posVariantSource($root . '/ajax/load_items_lazy.php');
$apiItems = posVariantSource($root . '/api/items.php');
$menuSync = posVariantSource($root . '/classes/Sync/SyncOutboxEventService.php');
$moovaMenu = posVariantSource($root . '/ajax/moova_menu_sync_payload.php');
$schema = posVariantSource($root . '/classes/Sync/SchemaManager.php');

posVariantAssert(strpos($schema, "'item_variants' => \$this->itemVariantsSql()") !== false, 'schema manager should plan item_variants table');
posVariantAssert(strpos($schema, 'UNIQUE KEY uq_item_variant_parent_child') !== false, 'item_variants should protect parent-child uniqueness');

posVariantAssert(strpos($addItem, 'id="item-variations-card"') !== false, 'item editor should expose variations card');
posVariantAssert(strpos($addItem, 'name="item_variants_payload_present"') !== false, 'item editor should explicitly mark variation submissions');
posVariantAssert(strpos($addItem, 'name="variant_label[]"') !== false, 'item editor should post variant labels');
posVariantAssert(strpos($addItem, 'name="variant_price1[]"') !== false, 'item editor should post child sale prices');
posVariantAssert(strpos($addItem, 'mod_group_name_ar') === false, 'item editor should not show modifier groups for variations');
posVariantAssert(strpos($doAddItem, 'ItemVariantService') !== false, 'add item route should use variant service');
posVariantAssert(strpos($doAddItem, "array_key_exists('item_variants_payload_present', \$_POST)") !== false, 'add item route should only save variation rows from the explicit editor payload');
posVariantAssert(strpos($doEditItem, "array_key_exists('item_variants_payload_present', \$_POST)") !== false, 'edit item route should not deactivate variants for legacy item submissions');
posVariantAssert(strpos($doEditItem, 'saveVariantsFromPost') !== false, 'edit item route should save variant rows');
posVariantAssert(strpos($doAddItem, 'foreach ($changedItemIds as $changedItemId)') !== false, 'add item route should sync parent and children');

posVariantAssert(strpos($js, 'ajax/get_item_variants.php') !== false, 'cashier should fetch variants before ordering parent');
posVariantAssert(strpos($js, 'if (hasVariantHint === false)') !== false, 'cashier should add known normal items without variant lookup');
posVariantAssert(strpos($js, 'cachedItemVariants') !== false, 'cashier should use cached variants before endpoint fallback');
posVariantAssert(strpos($js, 'itemVariantModal') !== false, 'cashier should render a variant picker modal');
posVariantAssert(strpos($js, 'modal fade" id="itemVariantModal"') === false, 'cashier variant picker should open without Bootstrap fade animation');
posVariantAssert(strpos($js, 'itemVariantChoice') !== false, 'cashier should add selected child variant');
posVariantAssert(strpos($js, 'addItemToOrder(id, name, price, barcode, qty = 1, imageHtml = \'\', lineNote = \'\')') !== false, 'cart add signature should stay line-note compatible');
posVariantAssert(strpos($js, 'name="itmmodifiers[]"') === false, 'cashier rows should not post modifier payloads for variations');
posVariantAssert(strpos($js, 'ajax/get_item_modifiers.php') === false, 'cashier should not fetch item modifiers for variation ordering');
posVariantAssert(strpos($posContent, 'name="itmmodifiers[]"') === false, 'edit-mode cart rows should not post modifier payloads for variations');

posVariantAssert(strpos($variantEndpoint, 'variantsForParent') !== false, 'variant lookup endpoint should use item variant service');
posVariantAssert(strpos($lazyItems, 'item_variants ivc') !== false, 'POS item grid should hide active variant child cards by default');
posVariantAssert(strpos($lazyItems, 'has_variants') !== false, 'POS item grid should flag parent chooser cards');
posVariantAssert(strpos($lazyItems, 'activeVariantsForParents') !== false, 'POS lazy loader should preload visible parent variants in one batch');
posVariantAssert(strpos($posContent, 'activeVariantsForParents') !== false, 'initial POS render should preload visible parent variants in one batch');

posVariantAssert(strpos($apiItems, "'variants'") !== false, 'public items API should expose variant metadata');
posVariantAssert(strpos($apiItems, "'is_orderable'") !== false, 'public items API should expose parent orderability');
posVariantAssert(strpos($menuSync, "'has_variants'") !== false, 'menu sync payload should mark parent chooser items');
posVariantAssert(strpos($menuSync, "'is_variant_child'") !== false, 'menu sync payload should mark child variation items');
posVariantAssert(strpos($menuSync, "'variants'") !== false, 'menu sync payload should include child variant metadata');
posVariantAssert(strpos($moovaMenu, 'ItemCustomerMenuOptions') !== false, 'Moova payload should build customer menu options from variants and modifiers');
posVariantAssert(strpos($moovaMenu, 'ivc.variant_item_id = i.id') !== false, 'Moova payload should hide active variant child rows from the flat menu list');
posVariantAssert(strpos($moovaMenu, 'has_variants') !== false, 'Moova payload should include parent chooser rows with has_variants');
posVariantAssert(strpos($moovaMenu, "'options'") !== false, 'Moova payload should attach native option groups for Moova menu import');
$menuOptions = posVariantSource($root . '/classes/Items/ItemCustomerMenuOptions.php');
posVariantAssert(strpos($menuOptions, 'pos-variant-group-') !== false, 'variant option groups should use stable pos-variant-group ids');

echo "pos-variant-cashier-sync-contract-ok\n";

function posVariantSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function posVariantAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
