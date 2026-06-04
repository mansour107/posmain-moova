<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorItemCostService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorReadService.php';

class RecipeEditorItemCostServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 59000;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';

        self::$conn = @new mysqli($host, $user, $pass, '', $port);
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$dbName = 'posmain_recipe_editor_item_cost_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                manual_cost_edit TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$conn && self::$dbName) {
            self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
            self::$conn->close();
        }
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }
    }

    public function testBuildEditorStateCalculatesMainItemCostFromComponents(): void
    {
        $sellableId = $this->insertItem('Cost burger', '0');
        $ingredientId = $this->insertItem('Cost patty', '2.500000');
        $recipe = $this->createDraftRecipe($sellableId, $ingredientId);

        $detail = (new RecipeEditorReadService())->recipeDetail(self::$conn, (int) $recipe['id']);
        $state = (new RecipeEditorItemCostService())->buildEditorState(self::$conn, $detail, [
            'costing_method' => 'item_cost_price',
        ], true);

        $this->assertTrue($state['visible']);
        $this->assertSame('5.000000', $state['items'][$sellableId]['calculated_cost']);
        $this->assertSame('0.000000', $state['items'][$sellableId]['stored_cost']);
    }

    public function testApplyAutoItemCostsUpdatesNonManualItem(): void
    {
        $sellableId = $this->insertItem('Auto cost burger', '0');
        $ingredientId = $this->insertItem('Auto cost patty', '1.000000');
        $recipe = $this->createDraftRecipe($sellableId, $ingredientId);
        $context = ['costing_method' => 'item_cost_price'];

        (new RecipeEditorItemCostService())->applyAutoItemCosts(self::$conn, (int) $recipe['id'], $context);

        $row = $this->fetchItem($sellableId);
        $this->assertSame('2.000000', $row['cost_price']);
        $this->assertSame('0', $row['manual_cost_edit']);
    }

    public function testVariationCostUsesVariationRecipeLines(): void
    {
        $mainItemId = $this->nextItemId();
        $variantItemId = $this->nextItemId();
        $flourId = $this->insertItem('Variation flour', '1.000000');
        self::$conn->query("INSERT INTO myitems (id, iname, cost_price, manual_cost_edit) VALUES ({$mainItemId}, 'Main crepe', 0, 0)");
        self::$conn->query("INSERT INTO myitems (id, iname, cost_price, manual_cost_edit) VALUES ({$variantItemId}, 'Large crepe', 0, 0)");
        self::$conn->query("
            INSERT INTO item_variants (parent_item_id, variant_item_id, variant_label, is_active, sort_order)
            VALUES ({$mainItemId}, {$variantItemId}, 'Large', 1, 1)
        ");

        $recipe = $this->createDraftRecipe($mainItemId, $flourId, '100.000000');
        self::$conn->query("
            INSERT INTO recipe_variant_lines
                (recipe_id, variant_item_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base, wastage_percent, is_required, sort_order)
            VALUES
                ({$recipe['id']}, {$variantItemId}, '00000000-0000-4000-8000-000000012999', {$flourId}, 'ingredient', 200.000000, 1.00000000, 0.0000, 1, 1)
        ");

        $detail = (new RecipeEditorReadService())->recipeDetail(self::$conn, (int) $recipe['id']);
        $state = (new RecipeEditorItemCostService())->buildEditorState(self::$conn, $detail, [
            'costing_method' => 'item_cost_price',
        ], true);

        $this->assertArrayNotHasKey($mainItemId, $state['items']);
        $this->assertSame('200.000000', $state['items'][$variantItemId]['calculated_cost']);
    }

    public function testVariationCostSumsIngredientUnitCostsForSellUnit(): void
    {
        $mainItemId = $this->nextItemId();
        $variantItemId = $this->nextItemId();
        $nutellaId = $this->insertItem('nutella', '0.000000');
        $teaId = $this->insertItem('TEMP TEST tea', '5.000000');
        self::$conn->query("INSERT INTO myitems (id, iname, cost_price, manual_cost_edit) VALUES ({$mainItemId}, 'Main dessert', 0, 0)");
        self::$conn->query("INSERT INTO myitems (id, iname, cost_price, manual_cost_edit) VALUES ({$variantItemId}, 'TEMP TEST tea variation', 0, 0)");
        self::$conn->query("
            INSERT INTO item_variants (parent_item_id, variant_item_id, variant_label, is_active, sort_order)
            VALUES ({$mainItemId}, {$variantItemId}, 'TEMP TEST tea', 1, 1)
        ");

        $recipe = $this->createDraftRecipe($mainItemId, $nutellaId, '1.000000');
        self::$conn->query("
            INSERT INTO recipe_variant_lines
                (recipe_id, variant_item_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base, wastage_percent, is_required, sort_order)
            VALUES
                ({$recipe['id']}, {$variantItemId}, '00000000-0000-4000-8000-000000012997', {$nutellaId}, 'ingredient', 1.000000, 1.00000000, 0.0000, 1, 1),
                ({$recipe['id']}, {$variantItemId}, '00000000-0000-4000-8000-000000012996', {$teaId}, 'ingredient', 1.000000, 1.00000000, 0.0000, 1, 2)
        ");

        $detail = (new RecipeEditorReadService())->recipeDetail(self::$conn, (int) $recipe['id']);
        $state = (new RecipeEditorItemCostService())->buildEditorState(self::$conn, $detail, [
            'costing_method' => 'item_cost_price',
        ], true);

        $this->assertSame('5.000000', $state['items'][$variantItemId]['calculated_cost']);
        $this->assertSame('5.000000', $state['items'][$variantItemId]['display_cost']);
    }

    public function testResolveInventoryUnitCostUsesVariantRecipeLines(): void
    {
        $mainItemId = $this->nextItemId();
        $variantItemId = $this->nextItemId();
        $flourId = $this->insertItem('Inventory flour', '1.000000');
        self::$conn->query("INSERT INTO myitems (id, iname, cost_price, manual_cost_edit) VALUES ({$mainItemId}, 'Main crepe', 0, 0)");
        self::$conn->query("INSERT INTO myitems (id, iname, cost_price, manual_cost_edit) VALUES ({$variantItemId}, 'Large crepe', 0, 0)");
        self::$conn->query("
            INSERT INTO item_variants (parent_item_id, variant_item_id, variant_label, is_active, sort_order)
            VALUES ({$mainItemId}, {$variantItemId}, 'Large', 1, 1)
        ");

        $recipe = $this->createDraftRecipe($mainItemId, $flourId, '100.000000');
        self::$conn->query("
            INSERT INTO recipe_variant_lines
                (recipe_id, variant_item_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base, wastage_percent, is_required, sort_order)
            VALUES
                ({$recipe['id']}, {$variantItemId}, '00000000-0000-4000-8000-000000012998', {$flourId}, 'ingredient', 200.000000, 1.00000000, 0.0000, 1, 1)
        ");
        $definition = new RecipeDefinitionService();
        $actor = new RecipeActorContext(1, 0, 0, null, ['recipe.manage'], null, null);
        $definition->approve(self::$conn, (int) $recipe['id'], $actor);
        $definition->activate(self::$conn, (int) $recipe['id'], $actor);

        $resolved = (new RecipeEditorItemCostService())->resolveInventoryUnitCost(self::$conn, $variantItemId, [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'costing_method' => 'item_cost_price',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('200.000000', $resolved['unit_cost']);
        $this->assertSame('recipe_calculated', $resolved['cost_source']);
    }

    private function createDraftRecipe(int $itemId, int $ingredientId, string $qty = '2.000000'): array
    {
        $definition = new RecipeDefinitionService();
        $actor = new RecipeActorContext(1, 0, 0, null, ['recipe.manage'], null, null);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $itemId,
            'recipe_name' => 'Recipe ' . $itemId,
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'line_type' => 'ingredient',
            'ingredient_item_id' => $ingredientId,
            'qty_per_yield' => $qty,
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.0000',
            'is_required' => 1,
            'order_type' => 'any',
            'channel' => 'any',
            'modifier_behavior' => 'additive',
            'sort_order' => 1,
        ], $actor);

        return $recipe;
    }

    private function insertItem(string $name, string $cost): int
    {
        $id = $this->nextItemId();
        $stmt = self::$conn->prepare('INSERT INTO myitems (id, iname, cost_price, manual_cost_edit) VALUES (?, ?, ?, 0)');
        $stmt->bind_param('iss', $id, $name, $cost);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    private function fetchItem(int $itemId): array
    {
        $stmt = self::$conn->prepare('SELECT cost_price, manual_cost_edit FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function nextItemId(): int
    {
        return ++self::$itemCounter;
    }
}
