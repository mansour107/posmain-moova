<?php

require_once __DIR__ . '/../../classes/Items/ItemUnitCatalogLabel.php';

function itemUnitCatalogLabelAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rows = [
    ['id' => 3, 'uname' => 'كرتونة', 'u_val' => '1', 'def_stock' => 1],
    ['id' => 2, 'uname' => 'كوباية', 'u_val' => '12'],
    ['id' => 1, 'uname' => 'دستة', 'u_val' => '24'],
];

$sorted = ItemUnitCatalogLabel::sortRowsSmallToLarge($rows);
itemUnitCatalogLabelAssert($sorted[0]['uname'] === 'دستة', 'smallest unit should sort first');
itemUnitCatalogLabelAssert($sorted[2]['uname'] === 'كرتونة', 'largest unit should sort last');

$options = ItemUnitCatalogLabel::buildSelectOptions($rows);
itemUnitCatalogLabelAssert($options[0]['label'] === 'دستة', 'smallest unit should show plain name');
itemUnitCatalogLabelAssert($options[1]['label'] === 'كوباية × 2 دستة', 'middle unit should relate to smaller unit');
itemUnitCatalogLabelAssert($options[2]['label'] === 'كرتونة × 12 كوباية', 'largest unit should relate to next smaller unit');

$stock = ItemUnitCatalogLabel::stockRow($rows);
itemUnitCatalogLabelAssert($stock !== null && $stock['uname'] === 'كرتونة', 'stock row should prefer def_stock unit');

echo "item_unit_catalog_label_test: OK\n";
