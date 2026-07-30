<?php

require_once __DIR__ . '/ItemFormInput.php';
require_once __DIR__ . '/ItemUnitConversion.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../Financial/UnitPrice.php';
require_once __DIR__ . '/../Financial/Decimal.php';

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
     *   price1: string,
     *   price2: string,
     *   price3: string,
     *   market_price: string,
     *   cost_price: string,
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
        $sellPrice1 = self::money($post['sell_price1'] ?? $post['price1'] ?? '0');
        $sellPrice2 = self::money('0');
        $sellMarket = self::money('0');
        $purchaseCost = self::money($post['purchase_cost'] ?? '0');
        $costSource = self::costSource($post['cost_source'] ?? 'direct');
        $directCost = self::money($post['direct_cost_price'] ?? $post['cost_price'] ?? '0');
        $recipeCost = self::money($post['recipe_cost_price'] ?? '0');

        if (
            $sellActive
            && !ItemFormInput::hasVariantRows($post)
            && FinancialDecimal::compare($sellPrice1, '0', UnitPrice::SCALE) <= 0
        ) {
            throw new InvalidArgumentException('sell_price_required');
        }

        if (!$sellActive) {
            $sellUnitId = $storageUnitId;
            $sellBarcode = '';
            $sellPrice1 = self::money('0');
            $sellPrice2 = self::money('0');
            $sellMarket = self::money('0');
        }

        $sellToStorage = self::quantity($post['sell_storage_factor'] ?? '1', '1');
        $purchaseToStorage = self::quantity($post['purchase_storage_factor'] ?? '1', '1');
        $sellStorageSwapped = self::isTruthy($post['sell_storage_swapped'] ?? null);
        $purchaseStorageSwapped = self::isTruthy($post['purchase_storage_swapped'] ?? null);
        if (
            FinancialDecimal::compare($sellToStorage, '0', ItemUnitConversion::DISPLAY_SCALE) <= 0
            || FinancialDecimal::compare($purchaseToStorage, '0', ItemUnitConversion::DISPLAY_SCALE) <= 0
        ) {
            throw new InvalidArgumentException('invalid unit factor');
        }

        if (
            $sellActive
            && $sellUnitId !== $storageUnitId
            && FinancialDecimal::compare($sellToStorage, '0', ItemUnitConversion::DISPLAY_SCALE) <= 0
        ) {
            throw new InvalidArgumentException('sell_storage_factor_required');
        }
        if (
            $purchaseActive
            && $purchaseUnitId !== $storageUnitId
            && FinancialDecimal::compare($purchaseToStorage, '0', ItemUnitConversion::DISPLAY_SCALE) <= 0
        ) {
            throw new InvalidArgumentException('purchase_storage_factor_required');
        }
        if ($costSource === 'purchase' && $itemType !== 'service') {
            if (!$purchaseActive) {
                throw new InvalidArgumentException('purchase_cost_source_requires_purchase');
            }
            self::assertPositive($purchaseCost, 'purchase_cost_required');
        }
        if ($costSource === 'direct' && FinancialDecimal::compare($directCost, '0', UnitPrice::SCALE) < 0) {
            throw new InvalidArgumentException('direct_cost_invalid');
        }
        if ($costSource === 'recipe' && FinancialDecimal::compare($recipeCost, '0', UnitPrice::SCALE) <= 0) {
            throw new InvalidArgumentException('recipe_cost_required');
        }

        $rows = [];
        $stockKey = 'u:' . $storageUnitId;
        $rows[$stockKey] = self::blankRow($storageUnitId, self::quantity('1'), [
            'def_stock' => 1,
            'unit_barcode' => '',
            'cost_price' => self::money('0'),
            'price1' => self::money('0'),
            'price2' => self::money('0'),
            'price3' => self::money('0'),
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
            $stockCost = self::purchaseCostPerStockUnitDecimal(
                $purchaseCost,
                $purchaseToStorage,
                $purchaseStorageSwapped
            );
            $costPrice = self::sellUnitCostFromStockCostDecimal(
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
            $costPrice = (string) $header['cost_price'];
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
            'price1' => self::money($sell['price1'] ?? '0'),
            'price2' => self::money($sell['price2'] ?? '0'),
            'price3' => self::money($sell['price3'] ?? '0'),
            'cost_price' => self::money($buy['cost_price'] ?? ($units[0]['cost_price'] ?? '0')),
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
        return (float) self::purchaseCostPerStockUnitDecimal(
            UnitPrice::fromLegacy($purchaseCost)->toString(),
            FinancialDecimal::normalize((string) $purchaseToStorage, ItemUnitConversion::DISPLAY_SCALE),
            $swapped
        );
    }

    private static function purchaseCostPerStockUnitDecimal(
        string $purchaseCost,
        string $purchaseToStorage,
        bool $swapped
    ): string {
        $factor = ItemUnitConversion::inventoryFactorDecimal(
            $purchaseToStorage,
            $swapped,
            'purchase',
            ItemUnitConversion::INVENTORY_SCALE
        );
        if (RecipeDecimal::compare($factor, '0', ItemUnitConversion::INVENTORY_SCALE) <= 0) {
            return $purchaseCost;
        }

        return UnitPrice::from(RecipeDecimal::divide(
            $purchaseCost,
            $factor,
            UnitPrice::SCALE
        ))->toString();
    }

    private static function sellUnitCostFromStockCost(
        float $stockCost,
        float $sellToStorage,
        bool $sellSwapped,
        bool $sellSameAsStorage
    ): float {
        return (float) self::sellUnitCostFromStockCostDecimal(
            UnitPrice::fromLegacy($stockCost)->toString(),
            FinancialDecimal::normalize((string) $sellToStorage, ItemUnitConversion::DISPLAY_SCALE),
            $sellSwapped,
            $sellSameAsStorage
        );
    }

    private static function sellUnitCostFromStockCostDecimal(
        string $stockCost,
        string $sellToStorage,
        bool $sellSwapped,
        bool $sellSameAsStorage
    ): string {
        if ($sellSameAsStorage) {
            return $stockCost;
        }

        $factor = ItemUnitConversion::inventoryFactorDecimal(
            $sellToStorage,
            $sellSwapped,
            'sell',
            ItemUnitConversion::INVENTORY_SCALE
        );

        return UnitPrice::from(RecipeDecimal::multiply(
            $stockCost,
            $factor,
            UnitPrice::SCALE
        ))->toString();
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

    private static function blankRow(int $unitId, string $uVal, array $overrides): array
    {
        return array_merge([
            'unit_id' => $unitId,
            'u_val' => $uVal,
            'def_sale' => 0,
            'def_buy' => 0,
            'def_stock' => 0,
            'conversion_swapped' => 0,
            'unit_barcode' => '',
            'cost_price' => self::money('0'),
            'price1' => self::money('0'),
            'price2' => self::money('0'),
            'price3' => self::money('0'),
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

    private static function money($value, string $default = '0'): string
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return UnitPrice::from($default)->toString();
        }

        return UnitPrice::from($value)->toString();
    }

    private static function quantity($value, string $default = '0'): string
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return FinancialDecimal::normalize($default, ItemUnitConversion::DISPLAY_SCALE);
        }

        return FinancialDecimal::normalize($value, ItemUnitConversion::DISPLAY_SCALE);
    }

    private static function assertPositive(string $value, string $message): void
    {
        if (FinancialDecimal::compare($value, '0', UnitPrice::SCALE) <= 0) {
            throw new InvalidArgumentException($message);
        }
    }
}
