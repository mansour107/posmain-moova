<?php

require_once __DIR__ . '/../../classes/Items/ItemUnitCatalogLabel.php';

function itemUnitCatalogLabelAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rows = [
    ['id' => 3, 'item_id' => 1, 'unit_id' => 2, 'uname' => 'كرتونة', 'u_val' => '1', 'conversion_swapped' => 0, 'def_sale' => 0, 'def_buy' => 0, 'def_stock' => 1],
    ['id' => 2, 'item_id' => 1, 'unit_id' => 16, 'uname' => 'كوباية', 'u_val' => '12', 'conversion_swapped' => 0, 'def_sale' => 1, 'def_buy' => 0, 'def_stock' => 0],
    ['id' => 1, 'item_id' => 1, 'unit_id' => 3, 'uname' => 'دستة', 'u_val' => '24', 'conversion_swapped' => 0, 'def_sale' => 0, 'def_buy' => 1, 'def_stock' => 0],
];

$sorted = ItemUnitCatalogLabel::sortRowsSmallToLarge($rows);
itemUnitCatalogLabelAssert($sorted[0]['uname'] === 'كرتونة', 'stock unit should sort first');
itemUnitCatalogLabelAssert($sorted[1]['uname'] === 'كوباية', 'sell unit should sort after stock');
itemUnitCatalogLabelAssert($sorted[2]['uname'] === 'دستة', 'purchase unit should sort after sell');

$options = ItemUnitCatalogLabel::buildSelectOptions($rows);
itemUnitCatalogLabelAssert($options[0]['label'] === 'كرتونة', 'stock unit should show plain stock label');
itemUnitCatalogLabelAssert($options[0]['value'] === '1', 'stock unit display factor should be 1');
itemUnitCatalogLabelAssert($options[1]['label'] === 'كوباية (1 كرتونة = 12 كوباية)', 'sell unit should relate to stock unit only');
itemUnitCatalogLabelAssert($options[1]['value'] === '0.08333333', 'sell unit display factor should be one twelfth');
itemUnitCatalogLabelAssert($options[2]['label'] === 'دستة (1 دستة = 24 كرتونة)', 'purchase unit should relate to stock unit only');
itemUnitCatalogLabelAssert($options[2]['value'] === '24', 'purchase unit display factor should be 24 stock units');

$decorated = ItemUnitCatalogLabel::decorateRows($rows);
itemUnitCatalogLabelAssert($decorated[1]['unit_label'] === 'كوباية (1 كرتونة = 12 كوباية)', 'decorated row should expose safe unit label');
itemUnitCatalogLabelAssert($decorated[1]['inventory_factor'] === '0.08333333', 'decorated row should expose inventory factor');

$stock = ItemUnitCatalogLabel::stockRow($rows);
itemUnitCatalogLabelAssert($stock !== null && $stock['uname'] === 'كرتونة', 'stock row should prefer def_stock unit');

echo "item_unit_catalog_label_test: OK\n";
