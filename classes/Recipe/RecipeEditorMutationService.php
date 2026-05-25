<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/RecipeDefinitionService.php';
require_once __DIR__ . '/RecipeDecimal.php';

class RecipeEditorMutationService
{
    private $definition;

    public function __construct(?RecipeDefinitionService $definition = null)
    {
        $this->definition = $definition ?: new RecipeDefinitionService();
    }

    public function handle(mysqli $conn, string $action, array $input, RecipeActorContext $actor): array
    {
        $action = strtolower(trim($action));
        switch ($action) {
            case 'create_draft':
                $recipe = $this->definition->createDraft($conn, $this->headerPayload($input), $actor);
                return $this->result('Recipe draft created.', (int) $recipe['id']);

            case 'update_draft':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->updateDraft($conn, $recipeId, $this->headerUpdatePayload($input), $actor);
                return $this->result('Recipe draft updated.', $recipeId);

            case 'add_line':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->addLine($conn, $recipeId, $this->linePayload($input), $actor);
                return $this->result('Recipe line added.', $recipeId);

            case 'update_line':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $lineId = $this->positiveInt($input['line_id'] ?? null, 'Recipe line id is required.');
                $this->definition->updateLine($conn, $lineId, $this->linePayload($input), $actor);
                return $this->result('Recipe line updated.', $recipeId);

            case 'remove_line':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $lineId = $this->positiveInt($input['line_id'] ?? null, 'Recipe line id is required.');
                $this->definition->removeLine($conn, $lineId, $actor);
                return $this->result('Recipe line removed.', $recipeId);

            case 'approve':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->approve($conn, $recipeId, $actor);
                return $this->result('Recipe approved.', $recipeId);

            case 'activate':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->activate($conn, $recipeId, $actor);
                return $this->result('Recipe activated.', $recipeId);

            case 'archive':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->archive($conn, $recipeId, $actor);
                return $this->result('Recipe archived.', $recipeId);

            case 'clone_new_version':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $draft = $this->definition->cloneAsNewVersion($conn, $recipeId, $actor);
                return $this->result('Recipe cloned into a new draft version.', (int) $draft['id']);
        }

        throw new InvalidArgumentException('Unsupported recipe editor action.');
    }

    private function headerPayload(array $input): array
    {
        return [
            'sellable_item_id' => $this->positiveInt($input['sellable_item_id'] ?? null, 'Sellable item id is required.'),
            'recipe_name' => $this->requiredText($input['recipe_name'] ?? '', 'Recipe name is required.'),
            'recipe_type' => $this->enum($input['recipe_type'] ?? 'make_to_order', [
                'make_to_order',
                'batch_prepared',
                'hybrid',
                'packaging_bundle',
                'modifier_only',
                'sub_recipe',
            ], 'Invalid recipe type.'),
            'yield_qty' => $this->decimal($input['yield_qty'] ?? '1', 'Yield quantity is required.'),
            'default_wastage_percent' => $this->decimal($input['default_wastage_percent'] ?? '0', 'Default wastage is invalid.', 4),
            'costing_method' => $this->enum($input['costing_method'] ?? 'item_cost_price', [
                'item_cost_price',
                'moving_average',
                'last_purchase',
                'manual_snapshot',
            ], 'Invalid costing method.'),
            'requires_recipe_for_sale' => !empty($input['requires_recipe_for_sale']) ? 1 : 0,
            'allow_sale_without_stock' => !empty($input['allow_sale_without_stock']) ? 1 : 0,
        ];
    }

    private function headerUpdatePayload(array $input): array
    {
        $payload = $this->headerPayload(array_merge(['sellable_item_id' => 1], $input));
        unset($payload['sellable_item_id']);

        return $payload;
    }

    private function linePayload(array $input): array
    {
        $lineType = $this->enum($input['line_type'] ?? 'ingredient', [
            'ingredient',
            'packaging',
            'sub_recipe',
            'modifier_ingredient',
            'labor_placeholder',
        ], 'Invalid recipe line type.');

        $payload = [
            'line_type' => $lineType,
            'ingredient_item_id' => null,
            'sub_recipe_id' => null,
            'qty_per_yield' => $this->decimal($input['qty_per_yield'] ?? '1', 'Line quantity is required.'),
            'unit_conversion_to_base' => $this->decimal($input['unit_conversion_to_base'] ?? '1', 'Line unit conversion is invalid.', 8),
            'wastage_percent' => $this->decimal($input['wastage_percent'] ?? '0', 'Line wastage is invalid.', 4),
            'is_required' => isset($input['is_required']) ? 1 : 0,
            'order_type' => $this->enum($input['order_type'] ?? 'any', ['any', 'dine_in', 'takeaway', 'delivery'], 'Invalid order type.'),
            'channel' => $this->enum($input['channel'] ?? 'any', ['any', 'pos', 'table', 'moova', 'cofe', 'api'], 'Invalid channel.'),
            'modifier_group_id' => $this->nullablePositiveInt($input['modifier_group_id'] ?? null),
            'modifier_option_id' => $this->nullablePositiveInt($input['modifier_option_id'] ?? null),
            'modifier_behavior' => $this->enum($input['modifier_behavior'] ?? 'additive', [
                'additive',
                'substitution_remove',
                'substitution_add',
            ], 'Invalid modifier behavior.'),
            'substitution_group' => $this->substitutionGroup($input['substitution_group'] ?? null),
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'notes' => $this->nullableText($input['notes'] ?? null),
        ];

        if ($lineType !== 'modifier_ingredient') {
            $payload['modifier_behavior'] = 'additive';
        }

        if ($lineType === 'sub_recipe') {
            $payload['sub_recipe_id'] = $this->positiveInt($input['sub_recipe_id'] ?? null, 'Sub-recipe id is required.');
        } elseif ($lineType !== 'labor_placeholder') {
            $payload['ingredient_item_id'] = $this->positiveInt($input['ingredient_item_id'] ?? null, 'Ingredient item id is required.');
        }

        return $payload;
    }

    private function result(string $message, int $recipeId): array
    {
        return [
            'success' => true,
            'message' => $message,
            'recipe_id' => $recipeId,
        ];
    }

    private function positiveInt($value, string $message): int
    {
        $int = (int) $value;
        if ($int > 0) {
            return $int;
        }

        throw new InvalidArgumentException($message);
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function requiredText($value, string $message): string
    {
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }

        throw new InvalidArgumentException($message);
    }

    private function nullableText($value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function substitutionGroup($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > 64) {
            throw new InvalidArgumentException('Substitution group is too long.');
        }

        return $text;
    }

    private function decimal($value, string $message, int $scale = 6): string
    {
        $text = trim((string) $value);
        if (!preg_match('/^\d+(\.\d{1,8})?$/', $text)) {
            throw new InvalidArgumentException($message);
        }

        $decimal = RecipeDecimal::normalize($text, $scale);
        if (RecipeDecimal::compare($decimal, '0', $scale) >= 0) {
            return $decimal;
        }

        throw new InvalidArgumentException($message);
    }

    private function enum($value, array $allowed, string $message): string
    {
        $value = strtolower(trim((string) $value));
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        throw new InvalidArgumentException($message);
    }
}
