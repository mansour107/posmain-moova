<?php

class RecipeAvailabilityResult
{
    public $sellableItemId;
    public $recipeId;
    public $computedAvailableQty;
    public $effectiveAvailableQty;
    public $effectiveIsAvailable;
    public $blockingItemId;
    public $unavailableReason;
    public $availabilityRevision;

    public function __construct(array $data)
    {
        $this->sellableItemId = (int) ($data['sellable_item_id'] ?? 0);
        $this->recipeId = isset($data['recipe_id']) ? (int) $data['recipe_id'] : null;
        $this->computedAvailableQty = (string) ($data['computed_available_qty'] ?? '0.000000');
        $this->effectiveAvailableQty = (string) ($data['effective_available_qty'] ?? '0.000000');
        $this->effectiveIsAvailable = (bool) ($data['effective_is_available'] ?? false);
        $this->blockingItemId = isset($data['blocking_item_id']) ? (int) $data['blocking_item_id'] : null;
        $this->unavailableReason = $data['unavailable_reason'] ?? null;
        $this->availabilityRevision = (int) ($data['availability_revision'] ?? 1);
    }

    public function toArray(): array
    {
        return [
            'sellable_item_id' => $this->sellableItemId,
            'recipe_id' => $this->recipeId,
            'computed_available_qty' => $this->computedAvailableQty,
            'effective_available_qty' => $this->effectiveAvailableQty,
            'effective_is_available' => $this->effectiveIsAvailable,
            'blocking_item_id' => $this->blockingItemId,
            'unavailable_reason' => $this->unavailableReason,
            'availability_revision' => $this->availabilityRevision,
        ];
    }
}
