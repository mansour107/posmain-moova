<?php

final class ItemFormInput
{
    public static function normalizeAddPayload(array $post, int $userId, int $defaultUnitId = 0): array
    {
        $marketPrice = self::decimalArrayValue($post, 'market_price', 0);
        $price3 = self::decimalArrayValue($post, 'price3', 0, $marketPrice);

        return [
            'iname' => self::requiredText($post, 'iname'),
            'name2' => self::text($post, 'name2'),
            'code' => self::intValue($post['code'] ?? null),
            'barcode' => self::text($post, 'barcode'),
            'info' => self::text($post, 'info'),
            'item_type' => self::itemType($post['item_type'] ?? null),
            'track_stock' => self::trackStock($post),
            'preferred_unit_id' => self::intValue($post['preferred_unit_id'] ?? null),
            'market_price' => $marketPrice,
            'cost_price' => self::decimalArrayValue($post, 'cost_price', 0),
            'price1' => self::decimalArrayValue($post, 'price1', 0),
            'price2' => self::decimalArrayValue($post, 'price2', 0),
            'price3' => $price3,
            'group1' => self::intValue($post['group1'] ?? null),
            'group2' => self::intValue($post['group2'] ?? null),
            'user' => $userId > 0 ? $userId : 1,
            'units' => self::unitRows($post, $defaultUnitId),
        ];
    }

    private static function itemType($value): string
    {
        $type = strtolower(trim((string) $value));

        return in_array($type, ['sellable', 'ingredient', 'packaging', 'service'], true) ? $type : 'sellable';
    }

    private static function trackStock(array $post): int
    {
        if (self::itemType($post['item_type'] ?? null) === 'service') {
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

            $uVal = self::decimalArrayValue($post, 'u_val', $index, $index === 0 ? 1.0 : 0.0);
            if ($uVal <= 0) {
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

    private static function decimalArrayValue(array $post, string $key, int $index, float $default = 0.0): float
    {
        if (!isset($post[$key]) || !is_array($post[$key])) {
            return $default;
        }

        $value = $post[$key][$index] ?? null;
        if ($value === null || $value === false || trim((string) $value) === '') {
            return $default;
        }

        return (float) $value;
    }
}
