<?php

class IngredientRequirement
{
    public $ingredientItemId;
    public $sourceRecipeLineId;
    public $lineType;
    public $requiredQtyBase;
    public $unitId;
    public $unitConversionToBase;
    public $wastagePercent;
    public $isRequired;
    public $modifierOptionId;
    public $orderType;
    public $channel;
    public $unitCost;
    public $totalCost;

    public function __construct(array $data)
    {
        $this->ingredientItemId = (int) ($data['ingredient_item_id'] ?? 0);
        $this->sourceRecipeLineId = (int) ($data['source_recipe_line_id'] ?? 0);
        $this->lineType = (string) ($data['line_type'] ?? 'ingredient');
        $this->requiredQtyBase = (string) ($data['required_qty_base'] ?? '0.000000');
        $this->unitId = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
        $this->unitConversionToBase = (string) ($data['unit_conversion_to_base'] ?? '1.00000000');
        $this->wastagePercent = (string) ($data['wastage_percent'] ?? '0.0000');
        $this->isRequired = (bool) ($data['is_required'] ?? true);
        $this->modifierOptionId = isset($data['modifier_option_id']) ? (int) $data['modifier_option_id'] : null;
        $this->orderType = (string) ($data['order_type'] ?? 'any');
        $this->channel = (string) ($data['channel'] ?? 'any');
        $this->unitCost = (string) ($data['unit_cost'] ?? '0.000000');
        $this->totalCost = (string) ($data['total_cost'] ?? '0.000000');
    }

    public function toArray(): array
    {
        return [
            'ingredient_item_id' => $this->ingredientItemId,
            'source_recipe_line_id' => $this->sourceRecipeLineId,
            'line_type' => $this->lineType,
            'required_qty_base' => $this->requiredQtyBase,
            'unit_id' => $this->unitId,
            'unit_conversion_to_base' => $this->unitConversionToBase,
            'wastage_percent' => $this->wastagePercent,
            'is_required' => $this->isRequired,
            'modifier_option_id' => $this->modifierOptionId,
            'order_type' => $this->orderType,
            'channel' => $this->channel,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
        ];
    }
}
