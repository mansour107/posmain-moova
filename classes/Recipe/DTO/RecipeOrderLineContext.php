<?php

class RecipeOrderLineContext
{
    public $posTenant;
    public $posBranch;
    public $branchUuid;
    public $storeId;
    public $orderId;
    public $fatDetailId;
    public $orderLineUuid;
    public $sourceOrderUuid;
    public $sourceLineUuid;
    public $sourceEventUuid;
    public $sellableItemId;
    public $itemCategoryId;
    public $quantity;
    public $unitId;
    public $variantId;
    public $modifiers;
    public $preparationValues;
    public $orderType;
    public $channel;
    public $requestedAt;

    public function __construct(array $data)
    {
        $this->posTenant = max(0, (int) ($data['pos_tenant'] ?? $data['tenant'] ?? 0));
        $this->posBranch = max(0, (int) ($data['pos_branch'] ?? $data['branch'] ?? 0));
        $this->branchUuid = $this->nullableString($data['branch_uuid'] ?? null);
        $this->storeId = max(0, (int) ($data['store_id'] ?? $data['det_store'] ?? 0));
        $this->orderId = isset($data['order_id']) ? (int) $data['order_id'] : null;
        $this->fatDetailId = isset($data['fat_detail_id']) ? (int) $data['fat_detail_id'] : null;
        $this->orderLineUuid = $this->nullableString($data['order_line_uuid'] ?? null);
        $this->sourceOrderUuid = $this->nullableString($data['source_order_uuid'] ?? $data['source_order_id'] ?? null);
        $this->sourceLineUuid = $this->nullableString($data['source_line_uuid'] ?? $data['source_line_id'] ?? null);
        $this->sourceEventUuid = $this->nullableString($data['source_event_uuid'] ?? $data['event_uuid'] ?? null);
        $this->sellableItemId = (int) ($data['sellable_item_id'] ?? $data['item_id'] ?? 0);
        $this->itemCategoryId = $this->nullablePositiveInt(
            $data['item_category_id']
            ?? $data['sellable_item_category_id']
            ?? $data['category_id']
            ?? $data['group1']
            ?? $data['sellable_item_group']
            ?? null
        );
        $this->quantity = (string) ($data['quantity'] ?? $data['qty'] ?? '1.000000');
        $this->unitId = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
        $this->variantId = isset($data['variant_id']) ? (int) $data['variant_id'] : null;
        $this->modifiers = $this->normalizeModifiers($data['modifiers'] ?? []);
        $this->preparationValues = $this->normalizePreparationValues($data['preparation_values'] ?? $data['preparations'] ?? []);
        $this->orderType = $this->token($data['order_type'] ?? 'takeaway', ['dine_in', 'takeaway', 'delivery'], 'takeaway');
        $this->channel = $this->token($data['channel'] ?? 'pos', ['pos', 'table', 'moova', 'cofe', 'api'], 'pos');
        $this->requestedAt = $data['requested_at'] ?? date('Y-m-d H:i:s');
    }

    public function hasModifierOption(int $modifierOptionId): bool
    {
        foreach ($this->modifiers as $modifier) {
            if ((int) ($modifier['modifier_option_id'] ?? $modifier['option_id'] ?? 0) === $modifierOptionId) {
                return true;
            }
        }

        return false;
    }

    private function normalizeModifiers($modifiers): array
    {
        if (!is_array($modifiers)) {
            return [];
        }

        $normalized = [];
        foreach ($modifiers as $modifier) {
            if (!is_array($modifier)) {
                continue;
            }
            $normalized[] = $modifier;
        }

        return $normalized;
    }

    private function normalizePreparationValues($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_array($value) || trim((string) ($value['code'] ?? $value['field_code'] ?? '')) === '') {
                continue;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function nullableString($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function token($value, array $allowed, string $default): string
    {
        $token = strtolower(trim((string) $value));
        $token = str_replace(['-', ' '], '_', $token);

        return in_array($token, $allowed, true) ? $token : $default;
    }
}
