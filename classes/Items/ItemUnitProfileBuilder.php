<?php

require_once __DIR__ . '/ItemFormInput.php';

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

        if ($itemType === 'sellable' || $itemType === 'service') {
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
            self::assertPositive(self::decimal($post['purchase_cost'] ?? 0), 'purchase_cost_required');
        }

        if (($itemType === 'ingredient' || $itemType === 'packaging') && $storageUnitId < 1) {
            throw new InvalidArgumentException('storage_unit_required');
        }

        $sellBarcode = trim((string) ($post['sell_barcode'] ?? $post['barcode'] ?? ''));
        $purchaseBarcode = trim((string) ($post['purchase_barcode'] ?? ''));
        $sellPrice1 = self::decimal($post['sell_price1'] ?? $post['price1'] ?? 0);
        $sellPrice2 = self::decimal($post['sell_price2'] ?? $post['price2'] ?? 0);
        $sellMarket = self::decimal($post['sell_market_price'] ?? $post['market_price'] ?? 0);
        $purchaseCost = self::decimal($post['purchase_cost'] ?? 0);

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

        return [
            'units' => $units,
            'preferred_unit_id' => $storageUnitId,
            'purchase_unit_id' => $purchaseActive ? $purchaseUnitId : 0,
            'price1' => $header['price1'],
            'price2' => $header['price2'],
            'price3' => $header['price3'],
            'market_price' => $header['price3'],
            'cost_price' => $header['cost_price'],
            'barcode' => $header['barcode'],
            'sell_active' => $sellActive,
            'purchase_active' => $purchaseActive,
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

        return in_array($type, ['sellable', 'ingredient', 'packaging', 'service'], true) ? $type : 'sellable';
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
