<?php

require_once __DIR__ . '/../../classes/Items/ItemUnitConversion.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDecimal.php';

function erpConversionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scale = ItemUnitConversion::INVENTORY_SCALE;

// 1 carton = 12 cups => sell 12 cups consumes exactly 1 base unit
$stockFromTwelve = ItemUnitConversion::stockQuantityFromEnteredQty('12', '12', false, 'sell', $scale);
erpConversionAssert(
    RecipeDecimal::compare($stockFromTwelve, '1', $scale) === 0,
    '12 sell units at factor 12 should consume 1 base unit'
);

$stockFromTwentyFour = ItemUnitConversion::stockQuantityFromEnteredQty('24', '12', false, 'sell', $scale);
erpConversionAssert(
    RecipeDecimal::compare($stockFromTwentyFour, '2', $scale) === 0,
    '24 sell units at factor 12 should consume 2 base units'
);

// Reverse arrow: user typed 0.083333 with swapped=true means multiply path
$swappedStock = ItemUnitConversion::stockQuantityFromEnteredQty('12', '0.083333', true, 'sell', $scale);
erpConversionAssert(
    RecipeDecimal::compare($swappedStock, '1', 4) === 0,
    'swapped sell conversion should still consume one base unit for 12 cups'
);

// Purchase path: receive 2 cartons at factor 12
$purchaseStock = ItemUnitConversion::stockQuantityFromEnteredQty('2', '12', false, 'purchase', $scale);
erpConversionAssert(
    RecipeDecimal::compare($purchaseStock, '24', $scale) === 0,
    'purchase conversion should multiply entered qty by factor'
);

// Inventory factor decimal for display factor 12
$factor = ItemUnitConversion::inventoryFactorDecimal('12', false, 'sell', $scale);
erpConversionAssert(
    RecipeDecimal::compare(RecipeDecimal::multiply('12', $factor, $scale), '1', 4) === 0,
    'inventory factor should normalize 12 sell units to 1 base unit'
);

// Legacy inverse sell row (<1 stored, not swapped)
$legacyRow = ['u_val' => '0.083333', 'conversion_swapped' => 0, 'def_sale' => 1];
$legacyFactor = ItemUnitConversion::inventoryFactorFromRow($legacyRow, 'sell', true, $scale);
erpConversionAssert(
    RecipeDecimal::compare($legacyFactor, '0.083333', 6) === 0,
    'legacy inverse sell rows should keep stored inventory factor'
);

echo "item_unit_conversion_test: OK\n";
