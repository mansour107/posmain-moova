<?php

require_once __DIR__ . '/ItemUnitColumnSupport.php';
require_once __DIR__ . '/ItemUnitConversion.php';

final class ItemUnitProfileReader
{
    public static function readForItem(mysqli $conn, int $itemId, string $itemType = 'sellable'): array
    {
        $rows = self::loadRows($conn, $itemId);
        if (!$rows) {
            return self::emptyProfile($itemType);
        }

        if (self::hasDefFlags($rows)) {
            return self::fromDefFlags($rows, $itemType, ItemUnitColumnSupport::hasConversionSwapped($conn));
        }

        return self::fromLegacyRows($rows, $itemType);
    }

    public static function defaultProfile(string $itemType, int $defaultUnitId): array
    {
        $profile = self::emptyProfile($itemType);
        if ($defaultUnitId > 0) {
            $profile['sell_unit_id'] = $defaultUnitId;
            $profile['storage_unit_id'] = $defaultUnitId;
            $profile['purchase_unit_id'] = $defaultUnitId;
        }

        return $profile;
    }

    private static function loadRows(mysqli $conn, int $itemId): array
    {
        $stmt = $conn->prepare('
            SELECT iu.*, u.uname
            FROM item_units iu
            LEFT JOIN myunits u ON u.id = iu.unit_id
            WHERE iu.item_id = ?
              AND COALESCE(iu.isdeleted, 0) = 0
            ORDER BY iu.id ASC
        ');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private static function hasDefFlags(array $rows): bool
    {
        foreach ($rows as $row) {
            if ((int) ($row['def_sale'] ?? 0) === 1
                || (int) ($row['def_stock'] ?? 0) === 1
                || (int) ($row['def_buy'] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function fromDefFlags(array $rows, string $itemType, bool $hasSwapColumn): array
    {
        $stock = self::pick($rows, 'def_stock') ?? $rows[0];
        $sell = self::pick($rows, 'def_sale');
        $buy = self::pick($rows, 'def_buy');

        $storageUnitId = (int) ($stock['unit_id'] ?? 0);
        $sellUnitId = (int) (($sell['unit_id'] ?? null) ?: $storageUnitId);
        $purchaseUnitId = (int) (($buy['unit_id'] ?? null) ?: $storageUnitId);

        $sellActive = $sell !== null || in_array($itemType, ['sellable', 'service'], true);
        $purchaseActive = $buy !== null && (float) ($buy['cost_price'] ?? 0) > 0;

        if (in_array($itemType, ['ingredient', 'packaging'], true)) {
            $sellActive = $sell !== null && (float) ($sell['price1'] ?? 0) > 0;
            $purchaseActive = $buy !== null;
        }

        $sellConversion = ItemUnitConversion::displayProfileFromRow($sell, $stock, 'sell', $hasSwapColumn);
        $purchaseConversion = ItemUnitConversion::displayProfileFromRow($buy, $stock, 'purchase', $hasSwapColumn);

        return [
            'sell_active' => $sellActive,
            'purchase_active' => $purchaseActive,
            'sell_unit_id' => $sellUnitId,
            'storage_unit_id' => $storageUnitId,
            'purchase_unit_id' => $purchaseUnitId,
            'sell_barcode' => (string) ($sell['unit_barcode'] ?? ''),
            'purchase_barcode' => (string) ($buy['unit_barcode'] ?? ''),
            'sell_price1' => (float) ($sell['price1'] ?? 0),
            'sell_price2' => 0.0,
            'sell_market_price' => 0.0,
            'purchase_cost' => (float) ($buy['cost_price'] ?? 0),
            'cost_source' => $buy !== null && (float) ($buy['cost_price'] ?? 0) > 0 ? 'purchase' : 'direct',
            'direct_cost_price' => 0,
            'recipe_cost_price' => 0,
            'sell_storage_factor' => $sellConversion['factor'],
            'sell_storage_swapped' => $sellConversion['swapped'],
            'purchase_storage_factor' => $purchaseConversion['factor'],
            'purchase_storage_swapped' => $purchaseConversion['swapped'],
            'unit_names' => self::unitNameMap($rows),
        ];
    }

    private static function fromLegacyRows(array $rows, string $itemType): array
    {
        $stock = $rows[0];
        $buy = count($rows) > 1 ? self::largestUValRow($rows) : null;
        if ($buy !== null && $buy === $stock) {
            $buy = count($rows) > 1 ? $rows[1] : null;
        }

        $storageUnitId = (int) ($stock['unit_id'] ?? 0);
        $purchaseUnitId = (int) (($buy['unit_id'] ?? null) ?: $storageUnitId);
        $purchaseActive = $buy !== null && (
            (float) ($buy['cost_price'] ?? 0) > 0
            || (float) ($buy['u_val'] ?? 1) > 1
        );

        $sellActive = in_array($itemType, ['sellable', 'service'], true)
            || (float) ($stock['price1'] ?? 0) > 0;

        return [
            'sell_active' => $sellActive,
            'purchase_active' => $purchaseActive,
            'sell_unit_id' => $storageUnitId,
            'storage_unit_id' => $storageUnitId,
            'purchase_unit_id' => $purchaseUnitId,
            'sell_barcode' => (string) ($stock['unit_barcode'] ?? ''),
            'purchase_barcode' => (string) ($buy['unit_barcode'] ?? ''),
            'sell_price1' => (float) ($stock['price1'] ?? 0),
            'sell_price2' => 0.0,
            'sell_market_price' => 0.0,
            'purchase_cost' => (float) ($buy['cost_price'] ?? $stock['cost_price'] ?? 0),
            'cost_source' => $purchaseActive ? 'purchase' : 'direct',
            'direct_cost_price' => 0,
            'recipe_cost_price' => 0,
            'sell_storage_factor' => 1.0,
            'sell_storage_swapped' => false,
            'purchase_storage_factor' => (float) ($buy['u_val'] ?? 1),
            'purchase_storage_swapped' => false,
            'unit_names' => self::unitNameMap($rows),
        ];
    }

    private static function emptyProfile(string $itemType): array
    {
        return [
            'sell_active' => in_array($itemType, ['sellable', 'service'], true),
            'purchase_active' => false,
            'sell_unit_id' => 0,
            'storage_unit_id' => 0,
            'purchase_unit_id' => 0,
            'sell_barcode' => '',
            'purchase_barcode' => '',
            'sell_price1' => 0,
            'sell_price2' => 0,
            'sell_market_price' => 0,
            'purchase_cost' => 0,
            'cost_source' => 'direct',
            'direct_cost_price' => 0,
            'recipe_cost_price' => 0,
            'sell_storage_factor' => 1.0,
            'sell_storage_swapped' => false,
            'purchase_storage_factor' => 1.0,
            'purchase_storage_swapped' => false,
            'unit_names' => [],
        ];
    }

    private static function pick(array $rows, string $flag): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row[$flag] ?? 0) === 1) {
                return $row;
            }
        }

        return null;
    }

    private static function largestUValRow(array $rows): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($best === null || (float) $row['u_val'] > (float) $best['u_val']) {
                $best = $row;
            }
        }

        return $best;
    }

    private static function unitNameMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['unit_id']] = (string) ($row['uname'] ?? '');
        }

        return $map;
    }
}
