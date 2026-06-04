<?php

require_once __DIR__ . '/DTO/RecipeCostContext.php';
require_once __DIR__ . '/RecipeCostService.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

class RecipeEditorItemCostService
{
    private static $itemColumnsCache;

    private $costs;

    public function __construct(?RecipeCostService $costs = null)
    {
        $this->costs = $costs ?: new RecipeCostService();
    }

    public function buildEditorState(mysqli $conn, array $recipeDetail, array $previewContext, bool $canViewCost): array
    {
        if (!$canViewCost) {
            return ['visible' => false, 'items' => [], 'line_costs' => []];
        }

        $header = $recipeDetail['header'] ?? [];
        $recipeId = (int) ($header['id'] ?? 0);
        if ($recipeId < 1) {
            return ['visible' => false, 'items' => [], 'line_costs' => []];
        }

        $this->ensureManualCostColumn($conn);
        $context = new RecipeCostContext($previewContext);
        $mainItemId = (int) ($header['main_sellable_item_id'] ?? $header['sellable_item_id'] ?? 0);
        $variants = is_array($recipeDetail['variants'] ?? null) ? $recipeDetail['variants'] : [];
        $items = [];
        $lineCosts = [];

        if (count($variants) === 0 && $mainItemId > 0) {
            $items[$mainItemId] = $this->itemCostRow($conn, $recipeId, $mainItemId, 0, $context);
            $lineCosts = $this->lineCostsForVariant($conn, $recipeId, 0, $context);
        } else {
            foreach ($variants as $variant) {
                $variantItemId = (int) ($variant['variant_item_id'] ?? 0);
                if ($variantItemId < 1) {
                    continue;
                }
                $items[$variantItemId] = $this->itemCostRow($conn, $recipeId, $variantItemId, $variantItemId, $context);
            }
        }

        return [
            'visible' => true,
            'items' => $items,
            'line_costs' => $lineCosts,
            'variant_line_costs' => $this->variantLineCosts($conn, $recipeId, $variants, $context),
        ];
    }

    public function resolveInventoryUnitCost(mysqli $conn, int $itemId, array $previewContext): ?array
    {
        $binding = $this->findActiveRecipeBinding($conn, $itemId, $previewContext);
        if (!$binding) {
            return null;
        }

        $this->ensureManualCostColumn($conn);
        $context = new RecipeCostContext($previewContext);
        $stored = $this->fetchItemCostRow($conn, $itemId);
        $calculated = $this->costs->calculateRecipeCost(
            $conn,
            (int) $binding['recipe_id'],
            $context,
            (int) $binding['variant_item_id']
        );
        $calculatedCost = $calculated->costPerSellUnit;
        $manual = (int) ($stored['manual_cost_edit'] ?? 0) === 1;
        $storedCost = RecipeDecimal::normalize($stored['cost_price'] ?? '0');
        $unitCost = $manual ? $storedCost : $calculatedCost;

        return [
            'unit_cost' => $unitCost,
            'calculated_cost' => $calculatedCost,
            'stored_cost' => $storedCost,
            'manual_cost_edit' => $manual ? 1 : 0,
            'cost_source' => $manual ? 'recipe_manual' : 'recipe_calculated',
            'recipe_id' => (int) $binding['recipe_id'],
        ];
    }

    public function findActiveRecipeBinding(mysqli $conn, int $itemId, array $scope): ?array
    {
        if ($itemId < 1) {
            return null;
        }

        $posTenant = max(0, (int) ($scope['pos_tenant'] ?? 0));
        $posBranch = max(0, (int) ($scope['pos_branch'] ?? 0));
        $recipes = new RecipeRepository();
        $recipe = $recipes->findActiveHeaderForItem($conn, $posTenant, $posBranch, $itemId);
        if ($recipe) {
            return [
                'recipe_id' => (int) $recipe['id'],
                'variant_item_id' => 0,
            ];
        }

        $parentItemId = $this->parentItemIdForVariant($conn, $itemId);
        if ($parentItemId < 1) {
            return null;
        }

        $recipe = $recipes->findActiveHeaderForItem($conn, $posTenant, $posBranch, $parentItemId);
        if (!$recipe) {
            return null;
        }

        return [
            'recipe_id' => (int) $recipe['id'],
            'variant_item_id' => $itemId,
        ];
    }

    public function applyAutoItemCosts(mysqli $conn, int $recipeId, array $previewContext): void
    {
        $recipe = $this->fetchRecipeDetail($conn, $recipeId);
        if (!$recipe) {
            return;
        }

        $state = $this->buildEditorState($conn, $recipe, $previewContext, true);
        foreach ($state['items'] as $row) {
            if (!empty($row['manual_cost_edit'])) {
                continue;
            }
            $this->updateItemCost($conn, (int) $row['item_id'], (string) $row['calculated_cost'], false);
        }
    }

    public function saveItemCostsFromInput(mysqli $conn, int $recipeId, array $input, array $previewContext): void
    {
        $recipe = $this->fetchRecipeDetail($conn, $recipeId);
        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        $this->ensureManualCostColumn($conn);
        $state = $this->buildEditorState($conn, $recipe, $previewContext, true);
        $itemIds = $this->arrayInput($input, 'item_cost_item_id');
        $costs = $this->arrayInput($input, 'item_cost_price');
        $manualFlags = $this->arrayInput($input, 'item_cost_manual_edit');
        $resetFlags = $this->arrayInput($input, 'item_cost_reset_auto');

        $max = max(count($itemIds), count($costs), count($manualFlags), count($resetFlags));
        for ($index = 0; $index < $max; $index++) {
            $itemId = (int) ($itemIds[$index] ?? 0);
            if ($itemId < 1 || !isset($state['items'][$itemId])) {
                continue;
            }

            $row = $state['items'][$itemId];
            $resetAuto = !empty($resetFlags[$index]);
            if ($resetAuto) {
                $this->updateItemCost($conn, $itemId, (string) $row['calculated_cost'], false);
                continue;
            }

            $manual = !empty($manualFlags[$index]);
            $submitted = trim((string) ($costs[$index] ?? ''));
            if ($submitted === '') {
                continue;
            }

            $normalized = $this->normalizeCost($submitted);
            $calculated = (string) $row['calculated_cost'];
            $isManual = $manual || RecipeDecimal::compare($normalized, $calculated) !== 0;
            $value = $isManual ? $normalized : $calculated;
            $this->updateItemCost($conn, $itemId, $value, $isManual);
        }
    }

    private function itemCostRow(mysqli $conn, int $recipeId, int $itemId, int $variantItemId, RecipeCostContext $context): array
    {
        $stored = $this->fetchItemCostRow($conn, $itemId);
        $calculated = $this->costs->calculateRecipeCost($conn, $recipeId, $context, $variantItemId);
        $calculatedCost = $calculated->costPerSellUnit;
        $manual = (int) ($stored['manual_cost_edit'] ?? 0) === 1;
        $storedCost = RecipeDecimal::normalize($stored['cost_price'] ?? '0');
        $displayCost = $manual ? $storedCost : $calculatedCost;

        return [
            'item_id' => $itemId,
            'variant_item_id' => $variantItemId,
            'calculated_cost' => $calculatedCost,
            'stored_cost' => $storedCost,
            'display_cost' => $displayCost,
            'manual_cost_edit' => $manual ? 1 : 0,
        ];
    }

    private function lineCostsForVariant(mysqli $conn, int $recipeId, int $variantItemId, RecipeCostContext $context): array
    {
        $result = $this->costs->calculateRecipeCost($conn, $recipeId, $context, $variantItemId);
        $mapped = [];
        foreach ($result->ingredientCosts as $row) {
            $lineId = (int) ($row['source_recipe_line_id'] ?? 0);
            if ($lineId < 1) {
                continue;
            }
            $mapped[$lineId] = [
                'unit_cost' => (string) ($row['unit_cost'] ?? '0'),
                'total_cost' => (string) ($row['total_cost'] ?? '0'),
            ];
        }

        return $mapped;
    }

    private function variantLineCosts(mysqli $conn, int $recipeId, array $variants, RecipeCostContext $context): array
    {
        $mapped = [];
        foreach ($variants as $variant) {
            $variantItemId = (int) ($variant['variant_item_id'] ?? 0);
            if ($variantItemId < 1) {
                continue;
            }
            $mapped[$variantItemId] = $this->lineCostsForVariant($conn, $recipeId, $variantItemId, $context);
        }

        return $mapped;
    }

    private function parentItemIdForVariant(mysqli $conn, int $variantItemId): int
    {
        if (!$this->tableExists($conn, 'item_variants')) {
            return 0;
        }

        $stmt = $conn->prepare('SELECT parent_item_id FROM item_variants WHERE variant_item_id = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('i', $variantItemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['parent_item_id'] ?? 0);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $safeTable = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");

        return (bool) ($result && $result->num_rows > 0);
    }

    private function fetchRecipeDetail(mysqli $conn, int $recipeId): ?array
    {
        require_once __DIR__ . '/RecipeEditorReadService.php';

        return (new RecipeEditorReadService())->recipeDetail($conn, $recipeId);
    }

    private function fetchItemCostRow(mysqli $conn, int $itemId): array
    {
        $columns = $this->itemColumns($conn);
        $select = ['cost_price'];
        if (isset($columns['manual_cost_edit'])) {
            $select[] = 'manual_cost_edit';
        }

        $stmt = $conn->prepare('SELECT ' . implode(', ', $select) . ' FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if (!isset($row['manual_cost_edit'])) {
            $row['manual_cost_edit'] = 0;
        }

        return $row;
    }

    private function updateItemCost(mysqli $conn, int $itemId, string $cost, bool $manual): void
    {
        $columns = $this->itemColumns($conn);
        if (isset($columns['manual_cost_edit'])) {
            $manualFlag = $manual ? 1 : 0;
            $stmt = $conn->prepare('UPDATE myitems SET cost_price = ?, manual_cost_edit = ? WHERE id = ?');
            $stmt->bind_param('sii', $cost, $manualFlag, $itemId);
            $stmt->execute();
            $stmt->close();

            return;
        }

        $stmt = $conn->prepare('UPDATE myitems SET cost_price = ? WHERE id = ?');
        $stmt->bind_param('si', $cost, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    private function ensureManualCostColumn(mysqli $conn): void
    {
        if ($this->columnExistsOnTable($conn, 'myitems', 'manual_cost_edit')) {
            return;
        }

        $conn->query('ALTER TABLE myitems ADD COLUMN manual_cost_edit TINYINT(1) NOT NULL DEFAULT 0');
        $this->resetItemColumnsCache();
    }

    private function itemColumns(mysqli $conn): array
    {
        if (is_array(self::$itemColumnsCache)) {
            return self::$itemColumnsCache;
        }

        self::$itemColumnsCache = $this->loadItemColumns($conn);

        return self::$itemColumnsCache;
    }

    private function loadItemColumns(mysqli $conn): array
    {
        $result = $conn->query('SHOW COLUMNS FROM myitems');
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['Field']] = true;
        }

        return $columns;
    }

    private function columnExistsOnTable(mysqli $conn, string $table, string $column): bool
    {
        $safeTable = $conn->real_escape_string($table);
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");

        return (bool) ($result && $result->num_rows > 0);
    }

    private function resetItemColumnsCache(): void
    {
        self::$itemColumnsCache = null;
    }

    private function normalizeCost(string $value): string
    {
        $text = trim($value);
        if (!preg_match('/^\d+(\.\d{1,6})?$/', $text)) {
            throw new InvalidArgumentException('Item cost must be a positive number.');
        }

        return RecipeDecimal::normalize($text, 6);
    }

    private function arrayInput(array $input, string $key): array
    {
        return isset($input[$key]) && is_array($input[$key]) ? $input[$key] : [];
    }
}
