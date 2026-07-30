<?php

require_once __DIR__ . '/ItemUnitProfileBuilder.php';
require_once __DIR__ . '/../Financial/UnitPrice.php';
require_once __DIR__ . '/../Financial/Decimal.php';

final class ItemFormInput
{
    public static function normalizeAddPayload(array $post, int $userId, int $defaultUnitId = 0): array
    {
        $itemType = self::itemType($post['item_type'] ?? null);
        $profileUnits = null;
        $preferredUnitId = self::intValue($post['preferred_unit_id'] ?? null);
        $purchaseUnitId = 0;
        $marketPrice = self::decimalArrayValue($post, 'market_price', 0);
        $price3 = self::decimalArrayValue($post, 'price3', 0, $marketPrice);
        $price1 = self::decimalArrayValue($post, 'price1', 0);
        $price2 = self::decimalArrayValue($post, 'price2', 0);
        $costPrice = self::decimalArrayValue($post, 'cost_price', 0);
        $costSource = self::costSource($post['cost_source'] ?? 'direct');
        $directCostPrice = self::decimalValue($post['direct_cost_price'] ?? null, '0');
        $recipeCostPrice = self::decimalValue($post['recipe_cost_price'] ?? null, '0');
        $barcode = self::text($post, 'barcode');

        if (ItemUnitProfileBuilder::usesProfileForm($post)) {
            $profile = ItemUnitProfileBuilder::buildFromPost($post, $defaultUnitId);
            $profileUnits = $profile['units'];
            $preferredUnitId = (int) $profile['preferred_unit_id'];
            $purchaseUnitId = (int) $profile['purchase_unit_id'];
            $price1 = (string) $profile['price1'];
            $price2 = (string) $profile['price2'];
            $price3 = (string) $profile['price3'];
            $marketPrice = (string) $profile['market_price'];
            $costPrice = (string) $profile['cost_price'];
            $costSource = (string) ($profile['cost_source'] ?? $costSource);
            $directCostPrice = (string) ($profile['direct_cost_price'] ?? $directCostPrice);
            $recipeCostPrice = (string) ($profile['recipe_cost_price'] ?? $recipeCostPrice);
            if ($barcode === '' && ($profile['barcode'] ?? '') !== '') {
                $barcode = (string) $profile['barcode'];
            }
        }

        $payload = [
            'iname' => self::requiredText($post, 'iname'),
            'name2' => self::text($post, 'name2'),
            'barcode' => $barcode,
            'info' => self::text($post, 'info'),
            'item_type' => $itemType,
            'track_stock' => self::trackStock($post),
            'preferred_unit_id' => $preferredUnitId,
            'purchase_unit_id' => $purchaseUnitId,
            'market_price' => $marketPrice,
            'cost_price' => $costPrice,
            'cost_source' => $costSource,
            'direct_cost_price' => $directCostPrice,
            'recipe_cost_price' => $recipeCostPrice,
            'price1' => $price1,
            'price2' => $price2,
            'price3' => $price3,
            'group1' => self::intValue($post['group1'] ?? null),
            'group2' => self::intValue($post['group2'] ?? null),
            'user' => $userId > 0 ? $userId : 1,
            'units' => $profileUnits ?? self::unitRows($post, $defaultUnitId),
        ];

        if ($profileUnits === null && !self::hasVariantRows($post)) {
            self::assertUnitSellPrices($payload['units']);
        }

        return self::withoutSecondaryPrices($payload);
    }

    private static function withoutSecondaryPrices(array $payload): array
    {
        $payload['price2'] = UnitPrice::from('0')->toString();
        $payload['price3'] = UnitPrice::from('0')->toString();
        $payload['market_price'] = UnitPrice::from('0')->toString();

        foreach ($payload['units'] as $index => $unit) {
            $payload['units'][$index]['price2'] = UnitPrice::from('0')->toString();
            $payload['units'][$index]['price3'] = UnitPrice::from('0')->toString();
        }

        return $payload;
    }

    public static function resolveDefaultUnitId(mysqli $conn): int
    {
        $stmt = $conn->prepare("SELECT id FROM myunits WHERE COALESCE(isdeleted, 0) = 0 AND uname = 'قطعة' ORDER BY id LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null) {
                return (int) $row['id'];
            }
        }

        $row = $conn->query("SELECT id FROM myunits WHERE COALESCE(isdeleted, 0) = 0 ORDER BY id LIMIT 1")->fetch_assoc();
        if ($row !== null) {
            return (int) $row['id'];
        }

        $unitName = 'قطعة';
        $stmt = $conn->prepare('INSERT INTO myunits (uname) VALUES (?)');
        $stmt->bind_param('s', $unitName);
        $stmt->execute();
        $unitId = (int) $conn->insert_id;
        $stmt->close();

        return $unitId;
    }

    public static function hasVariantRows(array $post): bool
    {
        $labels = isset($post['variant_label']) && is_array($post['variant_label']) ? $post['variant_label'] : [];
        $names = isset($post['variant_name']) && is_array($post['variant_name']) ? $post['variant_name'] : [];
        $variantItemIds = isset($post['variant_item_id']) && is_array($post['variant_item_id']) ? $post['variant_item_id'] : [];
        $max = max(count($labels), count($names), count($variantItemIds));

        for ($index = 0; $index < $max; $index++) {
            $label = trim((string) ($labels[$index] ?? ''));
            $name = trim((string) ($names[$index] ?? ''));
            $variantItemId = self::intValue($variantItemIds[$index] ?? null);
            if ($label === '' && $name === '' && $variantItemId <= 0) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function assertUnitSellPrices(array $units): void
    {
        foreach ($units as $unit) {
            $price = UnitPrice::from($unit['price1'] ?? '0')->toString();
            if (FinancialDecimal::compare($price, '0', UnitPrice::SCALE) <= 0) {
                throw new InvalidArgumentException('sell_price_required');
            }
        }
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

    private static function trackStock(array $post): int
    {
        if (in_array(self::itemType($post['item_type'] ?? null), ['service', 'made'], true)) {
            return 0;
        }

        $value = $post['track_stock'] ?? '1';
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    private static function unitRows(array $post, int $defaultUnitId): array
    {
        $unitIds = isset($post['unit_id']) && is_array($post['unit_id']) ? $post['unit_id'] : [];
        if (!$unitIds && $defaultUnitId > 0) {
            $unitIds = [$defaultUnitId];
        }

        if (!$unitIds) {
            throw new InvalidArgumentException('missing unit row');
        }

        $rows = [];
        $seen = [];
        foreach ($unitIds as $index => $rawUnitId) {
            $unitId = self::intValue($rawUnitId);
            if ($unitId < 1 && $defaultUnitId > 0) {
                $unitId = $defaultUnitId;
            }

            if ($unitId < 1) {
                throw new InvalidArgumentException('invalid unit');
            }
            if (isset($seen[$unitId])) {
                throw new InvalidArgumentException('duplicate unit');
            }
            $seen[$unitId] = true;

            $uVal = self::quantityArrayValue($post, 'u_val', $index, $index === 0 ? '1' : '0');
            if (FinancialDecimal::compare($uVal, '0', 6) <= 0) {
                throw new InvalidArgumentException('invalid unit factor');
            }

            $baseBarcode = self::arrayText($post, 'unit_barcode', 0);
            $unitBarcode = self::arrayText($post, 'unit_barcode', $index);
            if ($unitBarcode === '') {
                $unitBarcode = '99' . $index . $baseBarcode;
            }

            $rows[] = [
                'unit_id' => $unitId,
                'u_val' => $uVal,
                'unit_barcode' => $unitBarcode,
                'cost_price' => self::decimalArrayValue($post, 'cost_price', $index),
                'price1' => self::decimalArrayValue($post, 'price1', $index),
                'price2' => self::decimalArrayValue($post, 'price2', $index),
                'price3' => self::decimalArrayValue(
                    $post,
                    'price3',
                    $index,
                    self::decimalArrayValue($post, 'market_price', $index)
                ),
            ];
        }

        return $rows;
    }

    private static function requiredText(array $post, string $key): string
    {
        $value = self::text($post, $key);
        if ($value === '') {
            throw new InvalidArgumentException($key . ' is required');
        }

        return $value;
    }

    private static function text(array $post, string $key): string
    {
        return trim((string) ($post[$key] ?? ''));
    }

    private static function arrayText(array $post, string $key, int $index): string
    {
        if (!isset($post[$key]) || !is_array($post[$key])) {
            return '';
        }

        return trim((string) ($post[$key][$index] ?? ''));
    }

    private static function intValue($value, int $default = 0): int
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return $default;
        }

        return (int) $value;
    }

    private static function decimalValue($value, string $default = '0'): string
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return UnitPrice::from($default)->toString();
        }

        return UnitPrice::from($value)->toString();
    }

    private static function decimalArrayValue(array $post, string $key, int $index, string $default = '0'): string
    {
        if (!isset($post[$key]) || !is_array($post[$key])) {
            return UnitPrice::from($default)->toString();
        }

        $value = $post[$key][$index] ?? null;
        if ($value === null || $value === false || trim((string) $value) === '') {
            return UnitPrice::from($default)->toString();
        }

        return UnitPrice::from($value)->toString();
    }

    private static function quantityArrayValue(
        array $post,
        string $key,
        int $index,
        string $default = '0'
    ): string {
        if (!isset($post[$key]) || !is_array($post[$key])) {
            return FinancialDecimal::normalize($default, 6);
        }

        $value = $post[$key][$index] ?? null;
        if ($value === null || $value === false || trim((string) $value) === '') {
            return FinancialDecimal::normalize($default, 6);
        }

        return FinancialDecimal::normalize($value, 6);
    }
}
