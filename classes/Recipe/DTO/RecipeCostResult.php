<?php

class RecipeCostResult
{
    public $recipeId;
    public $sellableItemId;
    public $versionNumber;
    public $costPerYield;
    public $costPerSellUnit;
    public $ingredientCosts;

    public function __construct(array $data)
    {
        $this->recipeId = (int) ($data['recipe_id'] ?? 0);
        $this->sellableItemId = (int) ($data['sellable_item_id'] ?? 0);
        $this->versionNumber = (int) ($data['version_number'] ?? 1);
        $this->costPerYield = (string) ($data['cost_per_yield'] ?? '0.000000');
        $this->costPerSellUnit = (string) ($data['cost_per_sell_unit'] ?? '0.000000');
        $this->ingredientCosts = $data['ingredient_costs'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'recipe_id' => $this->recipeId,
            'sellable_item_id' => $this->sellableItemId,
            'version_number' => $this->versionNumber,
            'cost_per_yield' => $this->costPerYield,
            'cost_per_sell_unit' => $this->costPerSellUnit,
            'ingredient_costs' => $this->ingredientCosts,
        ];
    }
}
