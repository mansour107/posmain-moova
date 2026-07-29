<?php

require_once __DIR__ . '/../../classes/Items/ItemFormInput.php';

$payload = ItemFormInput::normalizeAddPayload([
    'iname' => 'Tea',
    'name2' => '',
    'code' => '10',
    'barcode' => '123',
    'info' => '',
    'item_type' => 'ingredient',
    'track_stock' => '1',
    'preferred_unit_id' => '1',
    'group1' => '',
    'group2' => '',
    'cost_price' => [''],
    'price1' => ['12.5'],
    'price2' => [''],
    'market_price' => ['15.75'],
    'unit_id' => ['1'],
    'u_val' => ['1'],
    'unit_barcode' => ['123'],
], 7);

itemFormInputAssert($payload['group1'] === 0, 'blank group1 should normalize to 0');
itemFormInputAssert($payload['group2'] === 0, 'blank group2 should normalize to 0');
itemFormInputAssert(abs($payload['cost_price'] - 0.0) < 0.0001, 'blank cost should normalize to zero');
itemFormInputAssert(abs($payload['price1'] - 12.5) < 0.0001, 'price1 should normalize to decimal');
itemFormInputAssert(abs($payload['price2'] - 0.0) < 0.0001, 'price2 should always normalize to zero');
itemFormInputAssert(abs($payload['market_price'] - 0.0) < 0.0001, 'market price should always normalize to zero');
itemFormInputAssert(abs($payload['price3'] - 0.0) < 0.0001, 'price3 should always normalize to zero');
itemFormInputAssert($payload['item_type'] === 'ingredient', 'item type should normalize for recipe catalog metadata');
itemFormInputAssert($payload['track_stock'] === 1, 'track stock should normalize to enabled for stock items');
itemFormInputAssert($payload['preferred_unit_id'] === 1, 'preferred unit should normalize to integer');
itemFormInputAssert($payload['user'] === 7, 'user should normalize to session user');
itemFormInputAssert(count($payload['units']) === 1, 'one unit row should be normalized');
itemFormInputAssert(abs($payload['units'][0]['price3'] - 0.0) < 0.0001, 'unit price3 should always normalize to zero');

$defaultUnitPayload = ItemFormInput::normalizeAddPayload([
    'iname' => 'No Unit Configured',
    'u_val' => ['1'],
    'unit_barcode' => ['555'],
    'price1' => ['10'],
], 1, 5);
itemFormInputAssert($defaultUnitPayload['units'][0]['unit_id'] === 5, 'missing unit select should use resolved default unit');

$explicitPrice3 = ItemFormInput::normalizeAddPayload([
    'iname' => 'Coffee',
    'unit_id' => ['1'],
    'u_val' => ['1'],
    'unit_barcode' => ['321'],
    'cost_price' => ['1'],
    'price1' => ['2'],
    'price2' => ['3'],
    'market_price' => ['4'],
    'price3' => ['5'],
], 0);
itemFormInputAssert(abs($explicitPrice3['price3'] - 0.0) < 0.0001, 'explicit price3 should be zeroed');
itemFormInputAssert(abs($explicitPrice3['price2'] - 0.0) < 0.0001, 'explicit price2 should be zeroed');
itemFormInputAssert(abs($explicitPrice3['market_price'] - 0.0) < 0.0001, 'explicit market price should be zeroed');
itemFormInputAssert($explicitPrice3['user'] === 1, 'missing session user should fall back to legacy default user');

$directCostPayload = ItemFormInput::normalizeAddPayload([
    'iname' => 'Crepe',
    'item_unit_profile_present' => '1',
    'item_type' => 'sellable',
    'sell_active' => '1',
    'purchase_active' => '0',
    'sell_unit_id' => '1',
    'storage_unit_id' => '1',
    'sell_price1' => '60',
    'cost_source' => 'direct',
    'direct_cost_price' => '18.5',
], 3, 1);
itemFormInputAssert(abs($directCostPayload['cost_price'] - 18.5) < 0.0001, 'direct cost source should save manual item cost');
itemFormInputAssert($directCostPayload['cost_source'] === 'direct', 'direct cost source should be preserved in payload');

$servicePayload = ItemFormInput::normalizeAddPayload([
    'iname' => 'Delivery Fee',
    'item_type' => 'service',
    'track_stock' => '1',
    'unit_id' => ['1'],
    'u_val' => ['1'],
    'unit_barcode' => ['svc'],
    'price1' => ['5'],
], 1);
itemFormInputAssert($servicePayload['item_type'] === 'service', 'service item type should be accepted');
itemFormInputAssert($servicePayload['track_stock'] === 0, 'service items should always normalize to non-stock');

itemFormInputExpectInvalid(function () {
    ItemFormInput::normalizeAddPayload([
        'iname' => 'Duplicate Units',
        'unit_id' => ['1', '1'],
        'u_val' => ['1', '6'],
        'unit_barcode' => ['111', ''],
        'price1' => ['10', '20'],
    ], 1);
}, 'duplicate units should be rejected server-side');

itemFormInputExpectInvalid(function () {
    ItemFormInput::normalizeAddPayload([
        'iname' => 'Missing Sell Price',
        'unit_id' => ['1'],
        'u_val' => ['1'],
        'unit_barcode' => ['111'],
        'price1' => ['0'],
    ], 1);
}, 'zero sell price should be rejected when no variants exist');

itemFormInputExpectInvalid(function () {
    ItemFormInput::normalizeAddPayload([
        'iname' => 'Float Price',
        'unit_id' => ['1'],
        'u_val' => ['1'],
        'price1' => [12.5],
    ], 1);
}, 'binary floating-point catalog prices must be rejected');

itemFormInputExpectInvalid(function () {
    ItemFormInput::normalizeAddPayload([
        'iname' => 'Exponent Price',
        'unit_id' => ['1'],
        'u_val' => ['1'],
        'price1' => ['1e2'],
    ], 1);
}, 'exponent notation catalog prices must be rejected');

itemFormInputExpectInvalid(function () {
    ItemFormInput::normalizeAddPayload([
        'iname' => 'Over Precision Price',
        'unit_id' => ['1'],
        'u_val' => ['1'],
        'price1' => ['1.0000001'],
    ], 1);
}, 'catalog prices beyond six decimal places must be rejected');

itemFormInputExpectInvalid(function () {
    ItemFormInput::normalizeAddPayload([
        'iname' => 'Float Conversion',
        'unit_id' => ['1'],
        'u_val' => [1.5],
        'price1' => ['2.00'],
    ], 1);
}, 'binary floating-point unit conversions must be rejected');

$variantParentPayload = ItemFormInput::normalizeAddPayload([
    'iname' => 'Parent With Variants',
    'unit_id' => ['1'],
    'u_val' => ['1'],
    'unit_barcode' => ['111'],
    'price1' => ['0'],
    'variant_label' => ['Large'],
    'variant_name' => ['Coffee - Large'],
    'variant_price1' => ['12'],
], 1);
itemFormInputAssert(abs($variantParentPayload['units'][0]['price1'] - 0.0) < 0.0001, 'parent unit sell price may be zero when variants exist');

itemFormInputAssert(
    ItemFormInput::hasVariantRows([
        'variant_label' => ['Large'],
        'variant_name' => [''],
        'variant_item_id' => ['0'],
    ]),
    'variant rows should be detected from label'
);

echo "item-form-input-ok\n";

function itemFormInputAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function itemFormInputExpectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }

    throw new RuntimeException($message);
}
