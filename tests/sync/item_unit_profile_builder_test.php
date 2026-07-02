<?php

require_once __DIR__ . '/../../classes/Items/ItemUnitProfileBuilder.php';
require_once __DIR__ . '/../../classes/Items/ItemFormInput.php';

function itemUnitProfileBuilderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sellable = ItemUnitProfileBuilder::buildFromPost([
    'item_unit_profile_present' => '1',
    'item_type' => 'sellable',
    'sell_active' => '1',
    'purchase_active' => '0',
    'sell_unit_id' => '3',
    'storage_unit_id' => '3',
    'sell_price1' => '12.5',
    'barcode' => '111',
], 3);

itemUnitProfileBuilderAssert(count($sellable['units']) === 1, 'sellable default should produce one unit row');
itemUnitProfileBuilderAssert((int) $sellable['units'][0]['def_sale'] === 1, 'sellable row should be def_sale');
itemUnitProfileBuilderAssert((int) $sellable['units'][0]['def_stock'] === 1, 'sellable row should be def_stock');
itemUnitProfileBuilderAssert(abs($sellable['price1'] - 12.5) < 0.0001, 'header price1 should mirror sell price');

$purchasePack = ItemUnitProfileBuilder::buildFromPost([
    'item_unit_profile_present' => '1',
    'item_type' => 'sellable',
    'sell_active' => '1',
    'purchase_active' => '1',
    'sell_unit_id' => '3',
    'storage_unit_id' => '3',
    'purchase_unit_id' => '5',
    'purchase_storage_factor' => '12',
    'purchase_cost' => '48',
    'sell_price1' => '5',
], 3);

itemUnitProfileBuilderAssert(count($purchasePack['units']) === 2, 'sell + purchase pack should produce two rows');
itemUnitProfileBuilderAssert((int) $purchasePack['purchase_unit_id'] === 5, 'purchase unit id should be exposed');
itemUnitProfileBuilderAssert((int) $purchasePack['preferred_unit_id'] === 3, 'preferred unit should be storage unit');

$ingredient = ItemUnitProfileBuilder::buildFromPost([
    'item_unit_profile_present' => '1',
    'item_type' => 'ingredient',
    'sell_active' => '0',
    'purchase_active' => '1',
    'storage_unit_id' => '2',
    'purchase_unit_id' => '4',
    'purchase_storage_factor' => '6',
    'purchase_cost' => '3',
], 2);

itemUnitProfileBuilderAssert((int) $ingredient['units'][0]['def_stock'] === 1, 'ingredient should have stock row');
itemUnitProfileBuilderAssert((int) $ingredient['units'][1]['def_buy'] === 1, 'ingredient purchase row should be def_buy');

try {
    ItemUnitProfileBuilder::buildFromPost([
        'item_unit_profile_present' => '1',
        'item_type' => 'ingredient',
        'sell_active' => '0',
        'purchase_active' => '0',
        'storage_unit_id' => '',
    ], 1);
    throw new RuntimeException('ingredient without storage unit should fail');
} catch (InvalidArgumentException $exception) {
    itemUnitProfileBuilderAssert($exception->getMessage() === 'storage_unit_required', 'missing storage should throw storage_unit_required');
}

$service = ItemUnitProfileBuilder::buildFromPost([
    'item_unit_profile_present' => '1',
    'item_type' => 'service',
    'sell_unit_id' => '1',
    'sell_price1' => '20',
], 1);

itemUnitProfileBuilderAssert(count($service['units']) === 1, 'service should have one sell row');
itemUnitProfileBuilderAssert(empty($service['purchase_active']), 'service should not activate purchase');

$conversion = ItemUnitProfileBuilder::buildFromPost([
    'item_unit_profile_present' => '1',
    'item_type' => 'sellable',
    'sell_active' => '1',
    'purchase_active' => '1',
    'sell_unit_id' => '3',
    'storage_unit_id' => '2',
    'purchase_unit_id' => '2',
    'purchase_storage_factor' => '1',
    'purchase_cost' => '1',
    'sell_storage_factor' => '0.25',
    'sell_price1' => '9',
], 2);

itemUnitProfileBuilderAssert(count($conversion['units']) === 2, 'sell != storage should create two rows');
$sellRow = null;
foreach ($conversion['units'] as $unitRow) {
    if ((int) ($unitRow['def_sale'] ?? 0) === 1) {
        $sellRow = $unitRow;
    }
}
itemUnitProfileBuilderAssert($sellRow !== null, 'conversion profile should include sell row');
itemUnitProfileBuilderAssert(abs((float) $sellRow['u_val'] - 0.25) < 0.0001, 'sell row should store conversion factor');

$profilePayload = ItemFormInput::normalizeAddPayload([
    'iname' => 'Profile Item',
    'item_unit_profile_present' => '1',
    'item_type' => 'sellable',
    'sell_active' => '1',
    'purchase_active' => '0',
    'sell_unit_id' => '1',
    'storage_unit_id' => '1',
    'sell_price1' => '7.5',
    'barcode' => '9001',
], 1, 1);

itemUnitProfileBuilderAssert(abs($profilePayload['price1'] - 7.5) < 0.0001, 'ItemFormInput should accept profile payload');
itemUnitProfileBuilderAssert((int) $profilePayload['units'][0]['def_sale'] === 1, 'normalized units should include def_sale');

echo "item-unit-profile-builder-ok\n";
