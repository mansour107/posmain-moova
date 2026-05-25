<?php

require_once __DIR__ . '/IngredientRequirement.php';

class RecipeExplosionResult
{
    public $sellableItemId;
    public $recipeId;
    public $recipeVersion;
    public $costSnapshotId;
    public $requirements;
    public $warnings;
    public $hasRecipe;
    public $fallbackMode;

    public function __construct(array $data)
    {
        $this->sellableItemId = (int) ($data['sellable_item_id'] ?? 0);
        $this->recipeId = isset($data['recipe_id']) ? (int) $data['recipe_id'] : null;
        $this->recipeVersion = isset($data['recipe_version']) ? (int) $data['recipe_version'] : null;
        $this->costSnapshotId = isset($data['cost_snapshot_id']) ? (int) $data['cost_snapshot_id'] : null;
        $this->requirements = $data['requirements'] ?? [];
        $this->warnings = $data['warnings'] ?? [];
        $this->hasRecipe = (bool) ($data['has_recipe'] ?? false);
        $this->fallbackMode = (string) ($data['fallback_mode'] ?? 'none');
    }

    public function toArray(): array
    {
        return [
            'sellable_item_id' => $this->sellableItemId,
            'recipe_id' => $this->recipeId,
            'recipe_version' => $this->recipeVersion,
            'cost_snapshot_id' => $this->costSnapshotId,
            'requirements' => array_map(static function ($requirement) {
                return $requirement instanceof IngredientRequirement ? $requirement->toArray() : $requirement;
            }, $this->requirements),
            'warnings' => $this->warnings,
            'has_recipe' => $this->hasRecipe,
            'fallback_mode' => $this->fallbackMode,
        ];
    }
}
