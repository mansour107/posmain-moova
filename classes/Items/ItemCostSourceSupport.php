<?php

require_once __DIR__ . '/../Recipe/RecipeEditorItemCostService.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

final class ItemCostSourceSupport
{
    public const COLUMN = 'item_cost_source';

    public static function ensureColumn(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $result = $conn->query("SHOW COLUMNS FROM myitems LIKE '" . self::COLUMN . "'");
        if ($result !== false && $result->num_rows > 0) {
            return;
        }

        $conn->query("ALTER TABLE myitems ADD COLUMN " . self::COLUMN . " VARCHAR(16) NOT NULL DEFAULT 'direct' AFTER cost_price");
    }

    public static function normalize(?string $value): string
    {
        $source = strtolower(trim((string) $value));

        return in_array($source, ['direct', 'purchase', 'recipe'], true) ? $source : 'direct';
    }

    public static function readForItem(mysqli $conn, int $itemId): string
    {
        if ($itemId < 1) {
            return 'direct';
        }

        self::ensureColumn($conn);
        $column = self::COLUMN;
        $stmt = $conn->prepare("SELECT {$column} FROM myitems WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return 'direct';
        }

        return self::normalize($row[$column] ?? 'direct');
    }

    public static function saveForItem(mysqli $conn, int $itemId, string $source): void
    {
        if ($itemId < 1) {
            return;
        }

        self::ensureColumn($conn);
        $normalized = self::normalize($source);
        $column = self::COLUMN;
        $stmt = $conn->prepare("UPDATE myitems SET {$column} = ? WHERE id = ?");
        $stmt->bind_param('si', $normalized, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array{
     *   recipe_available: bool,
     *   recipe_has_cost: bool,
     *   recipe_cost: float,
     *   stored_cost_source: string
     * }
     */
    public static function editorMeta(mysqli $conn, int $itemId): array
    {
        $stored = self::readForItem($conn, $itemId);
        $recipeCost = self::activeRecipeCostForItem($conn, $itemId);
        $recipeAvailable = $recipeCost !== null;
        $recipeValue = $recipeAvailable ? (float) ($recipeCost['unit_cost'] ?? 0) : 0.0;
        $recipeHasCost = $recipeAvailable && RecipeDecimal::isPositive((string) $recipeValue);

        return [
            'recipe_available' => $recipeAvailable,
            'recipe_has_cost' => $recipeHasCost,
            'recipe_cost' => $recipeHasCost ? $recipeValue : 0.0,
            'stored_cost_source' => $stored,
        ];
    }

    public static function applyRecipeCostSourceForRecipe(mysqli $conn, int $recipeId, array $previewContext): void
    {
        require_once __DIR__ . '/../Recipe/RecipeEditorReadService.php';

        $detail = (new RecipeEditorReadService())->recipeDetail($conn, $recipeId);
        if (!$detail) {
            return;
        }

        $header = $detail['header'] ?? [];
        $mainItemId = (int) ($header['main_sellable_item_id'] ?? $header['sellable_item_id'] ?? 0);
        if ($mainItemId < 1) {
            return;
        }

        $costService = new RecipeEditorItemCostService();
        $state = $costService->buildEditorState($conn, $detail, $previewContext, true);
        $row = $state['items'][$mainItemId] ?? null;
        if ($row === null) {
            foreach ($state['items'] as $candidate) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            return;
        }

        $calculated = (string) ($row['calculated_cost'] ?? '0');
        if (!RecipeDecimal::isPositive($calculated)) {
            return;
        }

        self::saveForItem($conn, $mainItemId, 'recipe');
        $costService->applyAutoItemCosts($conn, $recipeId, $previewContext);
    }

    private static function activeRecipeCostForItem(mysqli $conn, int $itemId): ?array
    {
        $scope = ['pos_tenant' => 0, 'pos_branch' => 0];
        if (function_exists('posmain_app_config')) {
            $branch = posmain_app_config()['branch'] ?? [];
            if (is_array($branch)) {
                $scope['pos_tenant'] = (int) ($branch['pos_tenant'] ?? 0);
                $scope['pos_branch'] = (int) ($branch['pos_branch'] ?? 0);
                $scope['branch_uuid'] = $branch['uuid'] ?? null;
            }
        }

        return (new RecipeEditorItemCostService())->resolveInventoryUnitCost($conn, $itemId, $scope);
    }
}
