<?php

class InventoryItemPolicyService
{
    public function policyForItem(array $item, array $scope = []): array
    {
        $itemType = $this->normalizeItemType($item['item_type'] ?? $item['type'] ?? $item['itm_type'] ?? 'sellable');
        $trackStock = $this->trackStockFlag($item, $itemType);

        return [
            'item_id' => $this->positiveInt($item['item_id'] ?? $item['id'] ?? $item['itmid'] ?? $item['itm_id'] ?? 0),
            'item_type' => $itemType,
            'track_stock' => $trackStock,
            'is_stock_tracked' => $trackStock,
            'base_unit_id' => $this->nonNegativeInt($item['base_unit_id'] ?? $item['unit_id'] ?? $item['unit'] ?? 0),
            'stock_behavior' => $trackStock ? $this->stockBehavior($item, $itemType) : 'non_stock',
            'reason' => $trackStock ? 'stock_tracked' : $this->nonStockReason($itemType, $item),
            'scope' => $scope,
        ];
    }

    private function normalizeItemType($value): string
    {
        $type = strtolower(trim((string) $value));
        $type = str_replace(['-', ' '], '_', $type);

        if (in_array($type, ['ingredient', 'packaging', 'service', 'sellable'], true)) {
            return $type;
        }

        if (in_array($type, ['raw', 'raw_material'], true)) {
            return 'ingredient';
        }

        return 'sellable';
    }

    private function trackStockFlag(array $item, string $itemType): bool
    {
        if ($itemType === 'service') {
            return false;
        }

        if (array_key_exists('track_stock', $item)) {
            return $this->boolValue($item['track_stock']);
        }

        if (array_key_exists('is_stock_tracked', $item)) {
            return $this->boolValue($item['is_stock_tracked']);
        }

        if (array_key_exists('stock_tracked', $item)) {
            return $this->boolValue($item['stock_tracked']);
        }

        return in_array($itemType, ['ingredient', 'packaging'], true);
    }

    private function stockBehavior(array $item, string $itemType): string
    {
        $behavior = strtolower(trim((string) ($item['stock_behavior'] ?? '')));
        $behavior = str_replace(['-', ' '], '_', $behavior);
        if (in_array($behavior, ['direct_stock', 'make_to_order', 'batch_prepared', 'packaging_bundle'], true)) {
            return $behavior;
        }

        if (in_array($itemType, ['ingredient', 'packaging'], true)) {
            return 'direct_stock';
        }

        return 'direct_stock';
    }

    private function nonStockReason(string $itemType, array $item): string
    {
        if ($itemType === 'service') {
            return 'service_item_non_stock';
        }

        return 'track_stock_disabled';
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function positiveInt($value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function nonNegativeInt($value): int
    {
        $int = (int) $value;

        return $int < 0 ? 0 : $int;
    }
}
