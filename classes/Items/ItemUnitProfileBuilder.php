<?php

require_once __DIR__ . '/ItemFormInput.php';
require_once __DIR__ . '/ItemUnitConversion.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

final class ItemUnitProfileBuilder
{
    public static function usesProfileForm(array $post): bool
    {
        return !empty($post['item_unit_profile_present']);
    }

    /**
     * @return array{
     *   units: list<array<string, mixed>>,
     *   preferred_unit_id: int,
     *   purchase_unit_id: int,
     *   price1: float,
     *   price2: float,
     *   price3: float,
     *   market_price: float,
     *   cost_price: float,
     *   barcode: string
     * }
     */
    public static function buildFromPost(array $post, int $defaultUnitId = 0): array
    {
        $itemType = self::itemType($post['item_type'] ?? 'sellable');
        $sellActive = self::isTruthy($post['sell_active'] ?? null);
        $purchaseActive = self::isTruthy($post['purchase_active'] ?? null);

        if ($itemType === 'sellable' || $itemType === 'made' || $itemType === 'service') {
            $sellActive = true;
        }
        if ($itemType === 'service') {
            $purchaseActive = false;
        }

        $sellUnitId = self::intValue($post['sell_unit_id'] ?? null, $defaultUnitId);
        $storageUnitId = self::intValue($post['storage_unit_id'] ?? null, 0);
        $purchaseUnitId = self::intValue($post['purchase_unit_id'] ?? null, 0);

        if ($sellUnitId < 1) {
            $sellUnitId = $defaultUnitId > 0 ? $defaultUnitId : 1;
        }

        if (!$purchaseActive) {
            if ($itemType === 'ingredient' || $itemType === 'packaging') {
                if ($storageUnitId < 1) {
                    throw new InvalidArgumentException('storage_unit_required');
                }
            } else {
                $storageUnitId = $sellActive ? $sellUnitId : ($storageUnitId > 0 ? $storageUnitId : $sellUnitId);
            }
            $purchaseUnitId = $storageUnitId;
        } else {
            if ($storageUnitId < 1) {
                $storageUnitId = $sellActive ? $sellUnitId : $defaultUnitId;
            }
            if ($purchaseUnitId < 1) {
                $purchaseUnitId = $storageUnitId;
            }
        }

        if (($itemType === 'ingredient' || $itemType === 'packaging') && $storageUnitId < 1) {
            throw new InvalidArgumentException('storage_unit_required');
        }

        $sellBarcode = trim((string) ($post['sell_barcode'] ?? $post['barcode'] ?? ''));
        $purchaseBarcode = trim((string) ($post['purchase_barcode'] ?? ''));
        $sellPrice1 = self::decimal($post['sell_price1'] ?? $post['price1'] ?? 0);
        $sellPrice2 = 0.0;
        $sellMarket = 0.0;
        $purchaseCost = self::decimal($post['purchase_cost'] ?? 0);
        $costSource = self::costSource($post['cost_source'] ?? 'direct');
        $directCost = self::decimal($post['direct_cost_price'] ?? $post['cost_price'] ?? 0);
        $recipeCost = self::decimal($post['recipe_cost_price'] ?? 0);

        if ($sellActive && !ItemFormInput::hasVariantRows($post) && $sellPrice1 <= 0) {
            throw new InvalidArgumentException('sell_price_required');
        }

        if (!$sellActive) {
            $sellUnitId = $storageUnitId;
            $sellBarcode = '';
            $sellPrice1 = 0;
            $sellPrice2 = 0;
            $sellMarket = 0;
        }

        $sellToStorage = self::decimal($post['sell_storage_factor'] ?? 1, 1.0);
        $purchaseToStorage = self::decimal($post['purchase_storage_factor'] ?? 1, 1.0);
        $sellStorageSwapped = self::isTruthy($post['sell_storage_swapped'] ?? null);
        $purchaseStorageSwapped = self::isTruthy($post['purchase_storage_swapped'] ?? null);
        if ($sellToStorage <= 0 || $purchaseToStorage <= 0) {
            throw new InvalidArgumentException('invalid unit factor');
        }

        if ($sellActive && $sellUnitId !== $storageUnitId && $sellToStorage <= 0) {
            throw new InvalidArgumentException('sell_storage_factor_required');
        }
        if ($purchaseActive && $purchaseUnitId !== $storageUnitId && $purchaseToStorage <= 0) {
            throw new InvalidArgumentException('purchase_storage_factor_required');
        }
        if ($costSource === 'purchase' && $itemType !== 'service') {
            if (!$purchaseActive) {
                throw new InvalidArgumentException('purchase_cost_source_requires_purchase');
            }
            self::assertPositive($purchaseCost, 'purchase_cost_required');
        }
        if ($costSource === 'direct' && $directCost < 0) {
            throw new InvalidArgumentException('direct_cost_invalid');
        }
        if ($costSource === 'recipe' && $recipeCost <= 0) {
            throw new InvalidArgumentException('recipe_cost_required');
        }

        $rows = [];
        $stockKey = 'u:' . $storageUnitId;
        $rows[$stockKey] = self::blankRow($storageUnitId, 1.0, [
            'def_stock' => 1,
            'unit_barcode' => '',
            'cost_price' => 0,
            'price1' => 0,
            'price2' => 0,
            'price3' => 0,
        ]);

        if ($sellActive) {
            if ($sellUnitId === $storageUnitId) {
                $rows[$stockKey]['def_sale'] = 1;
                $rows[$stockKey]['unit_barcode'] = $sellBarcode;
                $rows[$stockKey]['price1'] = $sellPrice1;
                $rows[$stockKey]['price2'] = $sellPrice2;
                $rows[$stockKey]['price3'] = $sellMarket;
            } else {
                $sellKey = 'u:' . $sellUnitId;
                $rows[$sellKey] = self::blankRow($sellUnitId, $sellToStorage, [
                    'def_sale' => 1,
                    'unit_barcode' => $sellBarcode,
                    'price1' => $sellPrice1,
                    'price2' => $sellPrice2,
                    'price3' => $sellMarket,
                    'conversion_swapped' => $sellUnitId !== $storageUnitId && $sellStorageSwapped ? 1 : 0,
                ]);
            }
        }

        if ($purchaseActive) {
            if ($purchaseUnitId === $storageUnitId) {
                $rows[$stockKey]['def_buy'] = 1;
                $rows[$stockKey]['cost_price'] = $purchaseCost;
                if ($purchaseBarcode !== '') {
                    $rows[$stockKey]['unit_barcode'] = $rows[$stockKey]['unit_barcode'] !== ''
                        ? $rows[$stockKey]['unit_barcode']
                        : $purchaseBarcode;
                }
            } else {
                $buyKey = 'u:' . $purchaseUnitId;
                if (isset($rows[$buyKey])) {
                    $rows[$buyKey]['def_buy'] = 1;
                    $rows[$buyKey]['cost_price'] = $purchaseCost;
                    if ($purchaseBarcode !== '') {
                        $rows[$buyKey]['unit_barcode'] = $purchaseBarcode;
                    }
                } else {
                    $rows[$buyKey] = self::blankRow($purchaseUnitId, $purchaseToStorage, [
                        'def_buy' => 1,
                        'unit_barcode' => $purchaseBarcode,
                        'cost_price' => $purchaseCost,
                        'conversion_swapped' => $purchaseUnitId !== $storageUnitId && $purchaseStorageSwapped ? 1 : 0,
                    ]);
                }
            }
        }

        $units = array_values($rows);
        $header = self::headerFromRows($units, $sellBarcode);
        if ($costSource === 'purchase') {
            $stockCost = self::purchaseCostPerStockUnit($purchaseCost, $purchaseToStorage, $purchaseStorageSwapped);
            $costPrice = self::sellUnitCostFromStockCost(
                $stockCost,
                $sellToStorage,
                $sellStorageSwapped,
                $sellUnitId === $storageUnitId
            );
        } elseif ($costSource === 'direct') {
            $costPrice = $directCost;
        } elseif ($costSource === 'recipe') {
            $costPrice = $recipeCost;
        } else {
            $costPrice = (float) $header['cost_price'];
        }

        return [
            'units' => $units,
            'preferred_unit_id' => $storageUnitId,
            'purchase_unit_id' => $purchaseActive ? $purchaseUnitId : 0,
            'price1' => $header['price1'],
            'price2' => $header['price2'],
            'price3' => $header['price3'],
            'market_price' => $header['price3'],
            'cost_price' => $costPrice,
            'barcode' => $header['barcode'],
            'sell_active' => $sellActive,
            'purchase_active' => $purchaseActive,
            'cost_source' => $costSource,
            'direct_cost_price' => $directCost,
            'recipe_cost_price' => $recipeCost,
        ];
    }

    private static function headerFromRows(array $units, string $fallbackBarcode): array
    {
        $sell = self::findRow($units, 'def_sale');
        $buy = self::findRow($units, 'def_buy');

        return [
            'price1' => (float) ($sell['price1'] ?? 0),
            'price2' => (float) ($sell['price2'] ?? 0),
            'price3' => (float) ($sell['price3'] ?? 0),
            'cost_price' => (float) ($buy['cost_price'] ?? ($units[0]['cost_price'] ?? 0)),
            'barcode' => (string) (($sell['unit_barcode'] ?? '') !== '' ? $sell['unit_barcode'] : $fallbackBarcode),
        ];
    }

    public static function displayCostPerSellUnit(float $storedCost, array $profile): float
    {
        if ($storedCost <= 0) {
            return 0.0;
        }

        $sellUnitId = (int) ($profile['sell_unit_id'] ?? 0);
        $storageUnitId = (int) ($profile['storage_unit_id'] ?? 0);
        if ($sellUnitId < 1 || $storageUnitId < 1 || $sellUnitId === $storageUnitId) {
            return $storedCost;
        }

        $purchaseCost = (float) ($profile['purchase_cost'] ?? 0);
        if (($profile['cost_source'] ?? 'purchase') === 'purchase' && $purchaseCost > 0) {
            $stockCost = self::purchaseCostPerStockUnit(
                $purchaseCost,
                (float) ($profile['purchase_storage_factor'] ?? 1),
                !empty($profile['purchase_storage_swapped'])
            );
            if (abs($storedCost - $stockCost) < 0.02) {
                return self::sellUnitCostFromStockCost(
                    $stockCost,
                    (float) ($profile['sell_storage_factor'] ?? 1),
                    !empty($profile['sell_storage_swapped']),
                    false
                );
            }
        }

        return $storedCost;
    }

    public static function purchaseCostPerSellUnit(
        float $purchaseCost,
        float $purchaseToStorage,
        bool $purchaseSwapped,
        float $sellToStorage,
        bool $sellSwapped,
        bool $sellSameAsStorage
    ): float {
        $stockCost = self::purchaseCostPerStockUnit($purchaseCost, $purchaseToStorage, $purchaseSwapped);

        return self::sellUnitCostFromStockCost($stockCost, $sellToStorage, $sellSwapped, $sellSameAsStorage);
    }

    private static function purchaseCostPerStockUnit(float $purchaseCost, float $purchaseToStorage, bool $swapped): float
    {
        $factor = ItemUnitConversion::inventoryFactorDecimal(
            (string) $purchaseToStorage,
            $swapped,
            'purchase',
            ItemUnitConversion::INVENTORY_SCALE
        );
        if (RecipeDecimal::compare($factor, '0', ItemUnitConversion::INVENTORY_SCALE) <= 0) {
            return $purchaseCost;
        }

        return (float) RecipeDecimal::divide(
            (string) $purchaseCost,
            $factor,
            ItemUnitConversion::INVENTORY_SCALE
        );
    }

    private static function sellUnitCostFromStockCost(
        float $stockCost,
        float $sellToStorage,
        bool $sellSwapped,
        bool $sellSameAsStorage
    ): float {
        if ($sellSameAsStorage) {
            return $stockCost;
        }

        $factor = ItemUnitConversion::inventoryFactorDecimal(
            (string) $sellToStorage,
            $sellSwapped,
            'sell',
            ItemUnitConversion::INVENTORY_SCALE
        );

        return (float) RecipeDecimal::multiply(
            (string) $stockCost,
            $factor,
            ItemUnitConversion::INVENTORY_SCALE
        );
    }

    private static function findRow(array $units, string $flag): ?array
    {
        foreach ($units as $unit) {
            if (!empty($unit[$flag])) {
                return $unit;
            }
        }

        return $units[0] ?? null;
    }

    private static function blankRow(int $unitId, float $uVal, array $overrides): array
    {
        return array_merge([
            'unit_id' => $unitId,
            'u_val' => $uVal,
            'def_sale' => 0,
            'def_buy' => 0,
            'def_stock' => 0,
            'conversion_swapped' => 0,
            'unit_barcode' => '',
            'cost_price' => 0.0,
            'price1' => 0.0,
            'price2' => 0.0,
            'price3' => 0.0,
        ], $overrides);
    }

    private static function itemType($value): string
    {
        $type = strtolower(trim((string) $value));

        return in_array($type, ['sellable', 'made', 'ingredient', 'packaging', 'service'], true) ? $type : 'sellable';
    }

    private static function costSource($value): string
    {
        $source = strtolower(trim((string) $value));

        return in_array($source, ['purchase', 'direct', 'recipe'], true) ? $source : 'purchase';
    }

    private static function isTruthy($value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function intValue($value, int $default = 0): int
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return $default;
        }

        return (int) $value;
    }

    private static function decimal($value, float $default = 0.0): float
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return $default;
        }

        return (float) $value;
    }

    private static function assertPositive(float $value, string $message): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($message);
        }
    }
}
