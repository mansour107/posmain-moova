<?php

require_once __DIR__ . '/DTO/IngredientRequirement.php';
require_once __DIR__ . '/DTO/RecipeExplosionResult.php';
require_once __DIR__ . '/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/Repository/RecipeLineRepository.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';
require_once __DIR__ . '/Repository/RecipeVariantLineRepository.php';

class RecipeExplosionService
{
    private $flags;
    private $recipes;
    private $lines;
    private $variantLines;
    private $warnings = [];

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?RecipeRepository $recipes = null,
        ?RecipeLineRepository $lines = null,
        ?RecipeVariantLineRepository $variantLines = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->recipes = $recipes ?: new RecipeRepository();
        $this->lines = $lines ?: new RecipeLineRepository();
        $this->variantLines = $variantLines ?: new RecipeVariantLineRepository();
    }

    public function explodeOrderLine(mysqli $conn, RecipeOrderLineContext $context): RecipeExplosionResult
    {
        $this->warnings = [];
        if (!$this->canCalculate()) {
            return $this->fallback($context, 'recipes_disabled');
        }

        $sellableItemId = $context->sellableItemId;
        $variantItemId = (int) ($context->variantId ?? 0);
        $recipe = $this->recipes->findActiveHeaderForItem(
            $conn,
            $context->posTenant,
            $context->posBranch,
            $sellableItemId
        );
        if (!$recipe) {
            $parent = $this->variantParentForChild($conn, $sellableItemId);
            if ($parent) {
                $variantItemId = (int) $parent['variant_item_id'];
                $recipe = $this->recipes->findActiveHeaderForItem(
                    $conn,
                    $context->posTenant,
                    $context->posBranch,
                    (int) $parent['parent_item_id']
                );
            }
        }
        if (!$recipe) {
            return $this->fallback($context, 'no_active_recipe');
        }

        $orderQty = RecipeDecimal::normalize($context->quantity);
        $requirements = (string) ($recipe['recipe_type'] ?? '') === 'batch_prepared'
            ? [$this->preparedStockRequirement($recipe, $orderQty)]
            : $this->explodeRecipe($conn, $recipe, $orderQty, $context, [], $variantItemId);

        return new RecipeExplosionResult([
            'sellable_item_id' => $context->sellableItemId,
            'recipe_id' => (int) $recipe['id'],
            'recipe_version' => (int) $recipe['version_number'],
            'requirements' => $requirements,
            'warnings' => $this->warnings,
            'has_recipe' => true,
            'fallback_mode' => 'none',
        ]);
    }

    public function explodeRecipeById(mysqli $conn, int $recipeId, RecipeOrderLineContext $context, ?string $orderQty = null): RecipeExplosionResult
    {
        $this->warnings = [];
        if (!$this->canCalculate()) {
            return $this->fallback($context, 'recipes_disabled');
        }

        return $this->buildRecipeExplosion($conn, $recipeId, $context, $orderQty);
    }

    public function explodeRecipeByIdForCosting(mysqli $conn, int $recipeId, RecipeOrderLineContext $context, ?string $orderQty = null): RecipeExplosionResult
    {
        $this->warnings = [];

        return $this->buildRecipeExplosion($conn, $recipeId, $context, $orderQty);
    }

    private function buildRecipeExplosion(mysqli $conn, int $recipeId, RecipeOrderLineContext $context, ?string $orderQty): RecipeExplosionResult
    {
        $recipe = $this->recipes->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        $requirements = $this->explodeRecipe(
            $conn,
            $recipe,
            RecipeDecimal::normalize($orderQty ?? $context->quantity),
            $context,
            [],
            (int) ($context->variantId ?? 0)
        );

        return new RecipeExplosionResult([
            'sellable_item_id' => (int) $recipe['sellable_item_id'],
            'recipe_id' => (int) $recipe['id'],
            'recipe_version' => (int) $recipe['version_number'],
            'requirements' => $requirements,
            'warnings' => $this->warnings,
            'has_recipe' => true,
            'fallback_mode' => 'none',
        ]);
    }

    private function preparedStockRequirement(array $recipe, string $orderQty): IngredientRequirement
    {
        return new IngredientRequirement([
            'ingredient_item_id' => (int) $recipe['sellable_item_id'],
            'source_recipe_line_id' => 0,
            'line_type' => 'prepared_stock',
            'required_qty_base' => $orderQty,
            'unit_id' => isset($recipe['yield_unit_id']) ? (int) $recipe['yield_unit_id'] : null,
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.0000',
            'is_required' => true,
            'order_type' => 'any',
            'channel' => 'any',
        ]);
    }

    private function canCalculate(): bool
    {
        return $this->flags->isEnabled() && $this->flags->mode() !== 'schema_only';
    }

    private function fallback(RecipeOrderLineContext $context, string $mode): RecipeExplosionResult
    {
        return new RecipeExplosionResult([
            'sellable_item_id' => $context->sellableItemId,
            'requirements' => [],
            'warnings' => [],
            'has_recipe' => false,
            'fallback_mode' => $mode,
        ]);
    }

    private function explodeRecipe(
        mysqli $conn,
        array $recipe,
        string $orderQty,
        RecipeOrderLineContext $context,
        array $visitedRecipeIds,
        int $variantItemId = 0
    ): array {
        $recipeId = (int) $recipe['id'];
        if (isset($visitedRecipeIds[$recipeId])) {
            throw new RuntimeException('Recursive recipe reference detected.');
        }
        $visitedRecipeIds[$recipeId] = true;

        $requirements = [];
        $yieldQty = RecipeDecimal::normalize($recipe['yield_qty'] ?? '1');
        if (!RecipeDecimal::isPositive($yieldQty)) {
            throw new RuntimeException('Recipe yield quantity must be positive.');
        }

        $recipeLines = $variantItemId > 0
            ? $this->variantLinesForRecipe($conn, $recipeId, $variantItemId)
            : $this->lines->findLinesByRecipeId($conn, $recipeId);
        $substitutionRemovals = $this->selectedSubstitutionRemovals($recipeLines, $context);

        foreach ($recipeLines as $line) {
            if (!$this->lineApplies($line, $context)) {
                continue;
            }

            if (($line['line_type'] ?? '') === 'labor_placeholder') {
                continue;
            }

            if ($this->isRemovedBySelectedSubstitution($line, $substitutionRemovals)) {
                continue;
            }

            if (($line['line_type'] ?? '') === 'modifier_ingredient') {
                $optionId = (int) ($line['modifier_option_id'] ?? 0);
                if ($optionId <= 0 || !$context->hasModifierOption($optionId)) {
                    continue;
                }
                $modifierBehavior = $this->modifierBehavior($line);
                if ($modifierBehavior === 'substitution_remove') {
                    continue;
                }
                if (!in_array($modifierBehavior, ['additive', 'substitution_add'], true)) {
                    $this->warnings[] = 'Unsupported modifier behavior for recipe line ' . (int) $line['id'];
                    continue;
                }
            }

            $requiredQty = $this->lineRequiredQty($orderQty, $yieldQty, $line);
            if (($line['line_type'] ?? '') === 'sub_recipe') {
                $subRecipeId = (int) ($line['sub_recipe_id'] ?? 0);
                $subRecipe = $this->recipes->findHeaderById($conn, $subRecipeId);
                if (!$subRecipe) {
                    throw new RuntimeException('Sub-recipe not found for recipe line ' . (int) $line['id']);
                }
                foreach ($this->explodeRecipe($conn, $subRecipe, $requiredQty, $context, $visitedRecipeIds) as $requirement) {
                    $requirements[] = $requirement;
                }
                continue;
            }

            $requirements[] = new IngredientRequirement([
                'ingredient_item_id' => (int) ($line['ingredient_item_id'] ?? 0),
                'source_recipe_line_id' => (int) $line['id'],
                'line_type' => (string) $line['line_type'],
                'required_qty_base' => $requiredQty,
                'unit_id' => isset($line['unit_id']) ? (int) $line['unit_id'] : null,
                'unit_conversion_to_base' => (string) $line['unit_conversion_to_base'],
                'wastage_percent' => (string) $line['wastage_percent'],
                'is_required' => (int) $line['is_required'] === 1,
                'modifier_option_id' => isset($line['modifier_option_id']) ? (int) $line['modifier_option_id'] : null,
                'order_type' => (string) $line['order_type'],
                'channel' => (string) $line['channel'],
            ]);
        }

        return $requirements;
    }

    private function variantLinesForRecipe(mysqli $conn, int $recipeId, int $variantItemId): array
    {
        $lines = $this->variantLines->findLinesForVariant($conn, $recipeId, $variantItemId);
        return $lines ?: $this->lines->findLinesByRecipeId($conn, $recipeId);
    }

    private function variantParentForChild(mysqli $conn, int $itemId): ?array
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'item_variants')) {
            return null;
        }

        $stmt = $conn->prepare('SELECT parent_item_id, variant_item_id FROM item_variants WHERE variant_item_id = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function selectedSubstitutionRemovals(array $recipeLines, RecipeOrderLineContext $context): array
    {
        $removals = [
            'groups' => [],
            'ingredient_ids' => [],
        ];

        foreach ($recipeLines as $line) {
            if (($line['line_type'] ?? '') !== 'modifier_ingredient') {
                continue;
            }
            if (!$this->lineApplies($line, $context)) {
                continue;
            }
            if ($this->modifierBehavior($line) !== 'substitution_remove') {
                continue;
            }

            $optionId = (int) ($line['modifier_option_id'] ?? 0);
            if ($optionId <= 0 || !$context->hasModifierOption($optionId)) {
                continue;
            }

            $group = $this->substitutionGroup($line);
            if ($group !== '') {
                $removals['groups'][$group] = true;
                continue;
            }

            $ingredientId = (int) ($line['ingredient_item_id'] ?? 0);
            if ($ingredientId > 0) {
                $removals['ingredient_ids'][$ingredientId] = true;
                continue;
            }

            $this->warnings[] = 'Modifier substitution removal has no group or ingredient for recipe line ' . (int) $line['id'];
        }

        return $removals;
    }

    private function isRemovedBySelectedSubstitution(array $line, array $substitutionRemovals): bool
    {
        if (($line['line_type'] ?? '') === 'modifier_ingredient') {
            return false;
        }

        $group = $this->substitutionGroup($line);
        if ($group !== '' && isset($substitutionRemovals['groups'][$group])) {
            return true;
        }

        $ingredientId = (int) ($line['ingredient_item_id'] ?? 0);
        return $group === ''
            && $ingredientId > 0
            && isset($substitutionRemovals['ingredient_ids'][$ingredientId]);
    }

    private function modifierBehavior(array $line): string
    {
        $behavior = strtolower(trim((string) ($line['modifier_behavior'] ?? 'additive')));
        $aliases = [
            'substitute_remove' => 'substitution_remove',
            'substitute_add' => 'substitution_add',
        ];

        return $aliases[$behavior] ?? $behavior;
    }

    private function substitutionGroup(array $line): string
    {
        return trim((string) ($line['substitution_group'] ?? ''));
    }

    private function lineApplies(array $line, RecipeOrderLineContext $context): bool
    {
        $orderType = (string) ($line['order_type'] ?? 'any');
        if ($orderType !== 'any' && $orderType !== $context->orderType) {
            return false;
        }

        $channel = (string) ($line['channel'] ?? 'any');
        if ($channel !== 'any' && $channel !== $context->channel) {
            return false;
        }

        return true;
    }

    private function lineRequiredQty(string $orderQty, string $yieldQty, array $line): string
    {
        $qtyPerYield = RecipeDecimal::normalize($line['qty_per_yield'] ?? '0');
        $unitConversion = RecipeDecimal::normalize($line['unit_conversion_to_base'] ?? '1', 8);
        $wastagePercent = RecipeDecimal::normalize($line['wastage_percent'] ?? '0', 4);

        $qty = RecipeDecimal::multiply($orderQty, RecipeDecimal::divide($qtyPerYield, $yieldQty, 8), 8);
        $qty = RecipeDecimal::multiply($qty, $unitConversion, 8);
        $qty = RecipeDecimal::applyPercent($qty, $wastagePercent, 8);

        return RecipeDecimal::normalize($qty, 6);
    }
}
