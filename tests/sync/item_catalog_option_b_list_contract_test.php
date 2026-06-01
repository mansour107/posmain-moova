<?php

$root = dirname(__DIR__, 2);
$catalog = itemCatalogOptionBSource($root . '/myitems.php');
$toggle = itemCatalogOptionBSource($root . '/do/toggle_item_active.php');
$status = itemCatalogOptionBSource($root . '/classes/Items/ItemCatalogStatus.php');
$schema = itemCatalogOptionBSource($root . '/classes/Sync/SchemaManager.php');
$moova = itemCatalogOptionBSource($root . '/ajax/moova_menu_sync_payload.php');
$syncOutbox = itemCatalogOptionBSource($root . '/classes/Sync/SyncOutboxEventService.php');

foreach ([
    'class="item-catalog-shell"',
    'data-edit-url="<?= item_catalog_h($editUrl) ?>"',
    'class="item-open-link"',
    'اضغط على اسم الصنف أو الصف',
    'item_summery.php?id=<?= $itemid ?>',
    'do/toggle_item_active.php',
    'data-target="#deleteitm<?= $itemid ?>"',
] as $needle) {
    itemCatalogOptionBAssert(strpos($catalog, $needle) !== false, 'item catalog should expose Option B list behavior: ' . $needle);
}

foreach ([
    '<th>الباركود</th>',
    '<th>الاسم</th>',
    '<th>الكميه</th>',
    '<th>الوحدة</th>',
    '<th>سعر البيع</th>',
    '<th>سعر الشراء</th>',
    '<th>سعر التكلفة</th>',
    '<th>عمليات</th>',
] as $needle) {
    itemCatalogOptionBAssert(strpos($catalog, $needle) !== false, 'item catalog should keep requested column: ' . $needle);
}

foreach ([
    '<th>رقم الصنف</th>',
    '<th>الوصف</th>',
    'fa-pen',
] as $needle) {
    itemCatalogOptionBAssert(strpos($catalog, $needle) === false, 'item catalog should remove duplicate/legacy surface: ' . $needle);
}

foreach ([
    'UPDATE myitems SET is_active = ?',
    'COALESCE(isdeleted, 0) = 0',
    'posmain_record_menu_item_sync',
] as $needle) {
    itemCatalogOptionBAssert(strpos($toggle, $needle) !== false, 'toggle endpoint should safely update item active state: ' . $needle);
}

foreach ([
    'activeOnlySql',
    'activeSelectSql',
    "SHOW COLUMNS FROM myitems LIKE 'is_active'",
] as $needle) {
    itemCatalogOptionBAssert(strpos($status, $needle) !== false, 'status helper should provide active column guards: ' . $needle);
}

foreach ([
    "'is_active' => \"ALTER TABLE myitems ADD COLUMN is_active",
    'idx_myitems_active_deleted',
] as $needle) {
    itemCatalogOptionBAssert(strpos($schema, $needle) !== false, 'schema manager should add active status support: ' . $needle);
}

foreach ([
    'ajax/get_items.php',
    'ajax/search_item.php',
    'ajax/search_items.php',
    'ajax/load_items_lazy.php',
    'ajax/get_category_items.php',
    'api/items.php',
    'includes/pos_content.php',
    'js/ajax/sales_myitems.php',
    'js/ajax/search_items.php',
    'js/ajax/searchitem.php',
    'js/ajax/getbycode.php',
] as $relativePath) {
    $source = itemCatalogOptionBSource($root . '/' . $relativePath);
    itemCatalogOptionBAssert(strpos($source, 'ItemCatalogStatus') !== false, $relativePath . ' should use item catalog active status helper');
}

foreach ([
    'COALESCE(i.is_active, 1) = 1',
    'COALESCE(is_active, 1)',
] as $needle) {
    itemCatalogOptionBAssert(strpos($moova, $needle) !== false, 'Moova menu payload should respect item active status: ' . $needle);
}

foreach ([
    '$catalogActive',
    "'available_online' => \$catalogActive",
    "'is_active' => \$catalogActive",
] as $needle) {
    itemCatalogOptionBAssert(strpos($syncOutbox, $needle) !== false, 'menu sync snapshot should publish active status: ' . $needle);
}

echo "item-catalog-option-b-list-contract-ok\n";

function itemCatalogOptionBSource(string $path): string
{
    $source = file_get_contents($path);
    itemCatalogOptionBAssert(is_string($source), 'Unable to read ' . $path);

    return (string) $source;
}

function itemCatalogOptionBAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}
