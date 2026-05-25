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
itemFormInputAssert(abs($payload['price2'] - 0.0) < 0.0001, 'blank price2 should normalize to zero');
itemFormInputAssert(abs($payload['market_price'] - 15.75) < 0.0001, 'market price should normalize to decimal');
itemFormInputAssert(abs($payload['price3'] - 15.75) < 0.0001, 'price3 should fall back to market price for myitems');
itemFormInputAssert($payload['item_type'] === 'ingredient', 'item type should normalize for recipe catalog metadata');
itemFormInputAssert($payload['track_stock'] === 1, 'track stock should normalize to enabled for stock items');
itemFormInputAssert($payload['preferred_unit_id'] === 1, 'preferred unit should normalize to integer');
itemFormInputAssert($payload['user'] === 7, 'user should normalize to session user');
itemFormInputAssert(count($payload['units']) === 1, 'one unit row should be normalized');
itemFormInputAssert(abs($payload['units'][0]['price3'] - 15.75) < 0.0001, 'unit price3 should fall back to market price');

$defaultUnitPayload = ItemFormInput::normalizeAddPayload([
    'iname' => 'No Unit Configured',
    'u_val' => ['1'],
    'unit_barcode' => ['555'],
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
itemFormInputAssert(abs($explicitPrice3['price3'] - 5.0) < 0.0001, 'explicit price3 should win when present');
itemFormInputAssert($explicitPrice3['user'] === 1, 'missing session user should fall back to legacy default user');

$servicePayload = ItemFormInput::normalizeAddPayload([
    'iname' => 'Delivery Fee',
    'item_type' => 'service',
    'track_stock' => '1',
    'unit_id' => ['1'],
    'u_val' => ['1'],
    'unit_barcode' => ['svc'],
], 1);
itemFormInputAssert($servicePayload['item_type'] === 'service', 'service item type should be accepted');
itemFormInputAssert($servicePayload['track_stock'] === 0, 'service items should always normalize to non-stock');

itemFormInputExpectInvalid(function () {
    ItemFormInput::normalizeAddPayload([
        'iname' => 'Duplicate Units',
        'unit_id' => ['1', '1'],
        'u_val' => ['1', '6'],
        'unit_barcode' => ['111', ''],
    ], 1);
}, 'duplicate units should be rejected server-side');

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
