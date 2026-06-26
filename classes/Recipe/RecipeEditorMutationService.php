<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/RecipeDefinitionService.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';
require_once __DIR__ . '/Repository/RecipeVariantLineRepository.php';
require_once __DIR__ . '/RecipeEditorItemCostService.php';
require_once __DIR__ . '/../Sync/OperationalSyncRecorder.php';

class RecipeEditorMutationService
{
    private $definition;
    private ?mysqli $syncConn = null;

    public function __construct(?RecipeDefinitionService $definition = null)
    {
        $this->definition = $definition ?: new RecipeDefinitionService();
    }

    public function handle(mysqli $conn, string $action, array $input, RecipeActorContext $actor): array
    {
        $this->syncConn = $conn;
        try {
            $action = strtolower(trim($action));
            switch ($action) {
            case 'create_draft':
                return $this->createOrOpenDraft($conn, $input, $actor);

            case 'update_draft':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->updateDraft($conn, $recipeId, $this->headerUpdatePayload($input), $actor);
                $this->syncAutoItemCosts($conn, $recipeId);
                return $this->result('Recipe draft updated.', $recipeId);

            case 'add_line':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->addLine($conn, $recipeId, $this->linePayload($conn, $input), $actor);
                $this->syncAutoItemCosts($conn, $recipeId);
                return $this->result('Component added.', $recipeId);

            case 'update_line':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $lineId = $this->positiveInt($input['line_id'] ?? null, 'Component id is required.');
                $this->definition->updateLine($conn, $lineId, $this->linePayload($conn, $input), $actor);
                $this->syncAutoItemCosts($conn, $recipeId);
                return $this->result('Component updated.', $recipeId);

            case 'remove_line':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $lineId = $this->positiveInt($input['line_id'] ?? null, 'Component id is required.');
                $this->definition->removeLine($conn, $lineId, $actor);
                $this->syncAutoItemCosts($conn, $recipeId);
                return $this->result('Component removed.', $recipeId);

            case 'approve':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->approve($conn, $recipeId, $actor);
                $this->syncAutoItemCosts($conn, $recipeId);
                return $this->result('Recipe approved.', $recipeId);

            case 'activate':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->activate($conn, $recipeId, $actor);
                $this->syncAutoItemCosts($conn, $recipeId);
                return $this->result('Recipe activated.', $recipeId);

            case 'archive':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->definition->archive($conn, $recipeId, $actor);
                return $this->result('Recipe archived.', $recipeId);

            case 'clone_new_version':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $draft = $this->definition->cloneAsNewVersion($conn, $recipeId, $actor);
                $this->syncAutoItemCosts($conn, (int) $draft['id']);
                return $this->result('Recipe cloned into a new draft version.', (int) $draft['id']);

            case 'save_variations':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $mainItemId = $this->mainItemIdForRecipe($conn, $recipeId);
                (new ItemVariantService())->saveVariantsFromPost($conn, $mainItemId, $input, [
                    'user_id' => $actor->userId,
                ]);
                return $this->result('Item variations updated.', $recipeId);

            case 'save_variant_recipe':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $variantItemId = $this->positiveInt($input['variant_item_id'] ?? null, 'Variation is required.');
                $this->saveVariantRecipe($conn, $recipeId, $variantItemId, $input, $actor);
                $this->syncAutoItemCosts($conn, $recipeId);
                return $this->result('Variation recipe updated.', $recipeId);

            case 'save_item_costs':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $this->saveItemCosts($conn, $recipeId, $input);
                return $this->result('Item costs updated.', $recipeId);
        }

        throw new InvalidArgumentException('Unsupported recipe editor action.');
        } finally {
            $this->syncConn = null;
        }
    }

    private function headerPayload(mysqli $conn, array $input): array
    {
        $sellableItemId = $this->mainItemIdForSellable($conn, $this->positiveInt($input['sellable_item_id'] ?? null, 'Please choose an item by name.'));

        return [
            'sellable_item_id' => $sellableItemId,
            'recipe_name' => $this->recipeNameForItem($conn, $sellableItemId),
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

    private function createOrOpenDraft(mysqli $conn, array $input, RecipeActorContext $actor): array
    {
        $payload = $this->headerPayload($conn, $input);
        $itemId = (int) $payload['sellable_item_id'];

        $draft = $this->findRecipeForItemByStatus($conn, $actor->posTenant, $actor->posBranch, $itemId, 'draft');
        if ($draft) {
            return $this->result('Recipe draft opened.', (int) $draft['id']);
        }

        $recipes = new RecipeRepository();
        $active = $recipes->findActiveHeaderForItem($conn, $actor->posTenant, $actor->posBranch, $itemId);
        if ($active) {
            $draft = $this->definition->cloneAsNewVersion($conn, (int) $active['id'], $actor);
            return $this->result('Recipe draft created.', (int) $draft['id']);
        }

        $maxVersion = $recipes->maxVersionForItem($conn, $actor->posTenant, $actor->posBranch, $itemId);
        if ($maxVersion > 0) {
            $payload['version_number'] = $maxVersion + 1;
        }

        $recipe = $this->definition->createDraft($conn, $payload, $actor);
        return $this->result('Recipe draft created.', (int) $recipe['id']);
    }

    private function findRecipeForItemByStatus(mysqli $conn, int $posTenant, int $posBranch, int $itemId, string $status): ?array
    {
        $stmt = $conn->prepare("
SELECT *
FROM recipe_headers
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND sellable_item_id = ?
  AND status = ?
ORDER BY version_number DESC, id DESC
LIMIT 1
");
        $stmt->bind_param('iiis', $posTenant, $posBranch, $itemId, $status);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function recipeNameForItem(mysqli $conn, int $itemId): string
    {
        $stmt = $conn->prepare('SELECT iname, barcode FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $name = $row ? trim((string) ($row['iname'] ?? '')) : '';
        $barcode = $row ? trim((string) ($row['barcode'] ?? '')) : '';
        $label = trim($name . ($barcode !== '' ? ' - ' . $barcode : ''));
        if ($label === '') {
            $label = 'Item ' . $itemId;
        }

        return $this->requiredText($label . ' recipe', 'Recipe name is required.');
    }

    private function headerUpdatePayload(array $input): array
    {
        return [
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

    private function mainItemIdForSellable(mysqli $conn, int $itemId): int
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'item_variants')) {
            return $itemId;
        }

        $stmt = $conn->prepare('SELECT parent_item_id FROM item_variants WHERE variant_item_id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) ($row['parent_item_id'] ?? 0) > 0 ? (int) $row['parent_item_id'] : $itemId;
    }

    private function mainItemIdForRecipe(mysqli $conn, int $recipeId): int
    {
        if ($recipeId < 1) {
            throw new InvalidArgumentException('Recipe id is required.');
        }

        $stmt = $conn->prepare('SELECT sellable_item_id FROM recipe_headers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int) ($row['sellable_item_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('Recipe item is required.');
        }

        return $this->mainItemIdForSellable($conn, (int) $row['sellable_item_id']);
    }

    private function linePayload(mysqli $conn, array $input): array
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
            'qty_per_yield' => $this->decimal($input['qty_per_yield'] ?? '1', 'Component amount is required.'),
            'unit_id' => $this->nullablePositiveInt($input['unit_id'] ?? null),
            'unit_conversion_to_base' => $this->decimal($input['unit_conversion_to_base'] ?? '1', 'Component unit conversion is invalid.', 8),
            'wastage_percent' => $this->decimal($input['wastage_percent'] ?? '0', 'Component wastage is invalid.', 4),
            'is_required' => !empty($input['is_required']) ? 1 : 0,
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

        if ($lineType === 'ingredient' && $payload['modifier_option_id'] !== null) {
            $lineType = 'modifier_ingredient';
            $payload['line_type'] = $lineType;
        }

        if ($lineType !== 'modifier_ingredient') {
            $payload['modifier_behavior'] = 'additive';
        }

        if ($lineType === 'sub_recipe') {
            $payload['sub_recipe_id'] = $this->positiveInt($input['sub_recipe_id'] ?? null, 'Component is required.');
        } elseif ($lineType !== 'labor_placeholder') {
            $payload['ingredient_item_id'] = $this->positiveInt($input['ingredient_item_id'] ?? null, 'Component is required.');
        }
        if ($payload['unit_id'] !== null && $payload['ingredient_item_id'] !== null) {
            $payload['unit_conversion_to_base'] = $this->resolveUnitConversion(
                $conn,
                (int) $payload['ingredient_item_id'],
                (int) $payload['unit_id'],
                (string) $payload['unit_conversion_to_base']
            );
        }

        return $payload;
    }

    private function saveVariantRecipe(mysqli $conn, int $recipeId, int $variantItemId, array $input, RecipeActorContext $actor): void
    {
        $this->assertCanEditVariationRecipe($conn, $recipeId, $variantItemId, $actor);
        $rows = $this->variantRecipeRows($conn, $input);
        (new RecipeVariantLineRepository())->replaceLinesForVariant($conn, $recipeId, $variantItemId, $rows);
    }

    private function saveItemCosts(mysqli $conn, int $recipeId, array $input): void
    {
        (new RecipeEditorItemCostService())->saveItemCostsFromInput(
            $conn,
            $recipeId,
            $input,
            $this->costPreviewContextForRecipe($conn, $recipeId)
        );
    }

    private function syncAutoItemCosts(mysqli $conn, int $recipeId): void
    {
        (new RecipeEditorItemCostService())->applyAutoItemCosts(
            $conn,
            $recipeId,
            $this->costPreviewContextForRecipe($conn, $recipeId)
        );
    }

    private function costPreviewContextForRecipe(mysqli $conn, int $recipeId): array
    {
        $recipe = (new RecipeRepository())->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            return [];
        }

        return [
            'pos_tenant' => (int) ($recipe['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($recipe['pos_branch'] ?? 0),
            'branch_uuid' => $recipe['branch_uuid'] ?? null,
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
            'costing_method' => (string) ($recipe['costing_method'] ?? 'item_cost_price'),
        ];
    }

    private function assertCanEditVariationRecipe(mysqli $conn, int $recipeId, int $variantItemId, RecipeActorContext $actor): void
    {
        if (!in_array('recipe.manage', $actor->permissions, true) && !in_array('inventory.manage', $actor->permissions, true) && !in_array('*', $actor->permissions, true)) {
            throw new RuntimeException('Recipe edit permission is required.');
        }

        $recipe = (new RecipeRepository())->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }
        if (($recipe['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Only editing recipes can change variation recipes.');
        }

        $mainItemId = $this->mainItemIdForRecipe($conn, $recipeId);
        if (!$this->tableExists($conn, 'item_variants')) {
            throw new RuntimeException('Item variations are not configured.');
        }
        $stmt = $conn->prepare('SELECT 1 FROM item_variants WHERE parent_item_id = ? AND variant_item_id = ? LIMIT 1');
        $stmt->bind_param('ii', $mainItemId, $variantItemId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$ok) {
            throw new RuntimeException('Variation does not belong to this recipe item.');
        }
    }

    private function variantRecipeRows(mysqli $conn, array $input): array
    {
        $components = $this->arrayInput($input, 'variant_recipe_component_id');
        $lineTypes = $this->arrayInput($input, 'variant_recipe_line_type');
        $amounts = $this->arrayInput($input, 'variant_recipe_qty_per_yield');
        $unitIds = $this->arrayInput($input, 'variant_recipe_unit_id');
        $wastages = $this->arrayInput($input, 'variant_recipe_wastage_percent');
        $baseLineIds = $this->arrayInput($input, 'variant_recipe_base_line_id');
        $notes = $this->arrayInput($input, 'variant_recipe_notes');
        $max = max(count($components), count($amounts), count($lineTypes));
        $rows = [];

        for ($index = 0; $index < $max; $index++) {
            $componentId = (int) ($components[$index] ?? 0);
            $amount = trim((string) ($amounts[$index] ?? ''));
            if ($componentId < 1 && $amount === '') {
                continue;
            }

            $lineType = $this->enum($lineTypes[$index] ?? 'ingredient', [
                'ingredient',
                'packaging',
                'sub_recipe',
                'labor_placeholder',
            ], 'Invalid variation component type.');
            $row = [
                'line_uuid' => $this->uuid(),
                'base_line_id' => $this->nullablePositiveInt($baseLineIds[$index] ?? null),
                'line_type' => $lineType,
                'ingredient_item_id' => null,
                'sub_recipe_id' => null,
                'qty_per_yield' => $this->decimal($amount === '' ? '1' : $amount, 'Variation amount is required.'),
                'unit_id' => $this->nullablePositiveInt($unitIds[$index] ?? null),
                'unit_conversion_to_base' => '1.00000000',
                'wastage_percent' => $this->decimal($wastages[$index] ?? '0', 'Variation waste is invalid.', 4),
                'is_required' => 1,
                'order_type' => 'any',
                'channel' => 'any',
                'sort_order' => $index + 1,
                'notes' => $this->nullableText($notes[$index] ?? null),
            ];

            if ($lineType === 'sub_recipe') {
                $row['sub_recipe_id'] = $this->positiveInt($componentId, 'Variation component is required.');
            } elseif ($lineType !== 'labor_placeholder') {
                $row['ingredient_item_id'] = $this->positiveInt($componentId, 'Variation component is required.');
            }
            if ($row['unit_id'] !== null && $row['ingredient_item_id'] !== null) {
                $row['unit_conversion_to_base'] = $this->resolveUnitConversion(
                    $conn,
                    (int) $row['ingredient_item_id'],
                    (int) $row['unit_id'],
                    (string) $row['unit_conversion_to_base']
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function arrayInput(array $input, string $key): array
    {
        return isset($input[$key]) && is_array($input[$key]) ? $input[$key] : [];
    }

    private function resolveUnitConversion(mysqli $conn, int $itemId, int $unitId, string $fallback): string
    {
        if (!$this->tableExists($conn, 'item_units')) {
            return $fallback;
        }

        $stmt = $conn->prepare('SELECT u_val FROM item_units WHERE item_id = ? AND unit_id = ? AND COALESCE(isdeleted, 0) = 0 ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('Unit conversion is not configured for this component.');
        }

        return RecipeDecimal::normalize((string) ($row['u_val'] ?? '1'), 8);
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

    private function result(string $message, int $recipeId): array
    {
        if ($this->syncConn && $recipeId > 0) {
            posmain_record_recipe_sync($this->syncConn, $recipeId, 'recipe_editor');
        }

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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
