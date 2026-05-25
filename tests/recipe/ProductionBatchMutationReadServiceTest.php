<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/ProductionBatchMutationService.php';
require_once __DIR__ . '/../../classes/Recipe/ProductionBatchReadService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

class ProductionBatchMutationReadServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 62000;

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
        self::$dbName = 'posmain_recipe_production_ui_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000
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

    public function testMutationAdapterCreatesDraftAndReadServiceListsIt(): void
    {
        $setup = $this->productionRecipe();
        $result = $this->mutationService()->handle(self::$conn, 'create_draft', [
            'recipe_id' => $setup['recipe_id'],
            'planned_output_qty' => '5.000000',
            'store_id' => '2',
            'notes' => 'morning prep',
        ], $this->actor(['recipe.manage']));

        $detail = (new ProductionBatchReadService())->batchDetail(self::$conn, (int) $result['batch_id']);
        $recipes = (new ProductionBatchReadService())->activeProductionRecipes(self::$conn);
        $rows = (new ProductionBatchReadService())->listBatches(self::$conn, [
            'status' => 'draft',
            'store_id' => 2,
        ]);

        $this->assertSame('Production batch draft created.', $result['message']);
        $this->assertSame('draft', $detail['batch']['status']);
        $this->assertSame('5.000000', $detail['batch']['planned_output_qty']);
        $this->assertSame('morning prep', $detail['batch']['notes']);
        $this->assertSame('Prepared sauce', $detail['batch']['output_item_name']);
        $this->assertNotEmpty($recipes);
        $this->assertNotEmpty($rows);
    }

    public function testMutationAdapterNormalizesProductionQuantitiesAsDecimals(): void
    {
        $setup = $this->productionRecipe();
        $created = $this->mutationService()->handle(self::$conn, 'create_draft', [
            'recipe_id' => $setup['recipe_id'],
            'planned_output_qty' => '2.3333334',
        ], $this->actor(['recipe.manage']));

        $this->mutationService()->handle(self::$conn, 'commit', [
            'batch_id' => $created['batch_id'],
            'actual_output_qty' => '2.3333335',
            'variance_reason' => 'rounding check',
        ], $this->actor(['recipe.approve']));

        $detail = (new ProductionBatchReadService())->batchDetail(self::$conn, (int) $created['batch_id']);

        $this->assertSame('2.333333', $detail['batch']['planned_output_qty']);
        $this->assertSame('2.333334', $detail['batch']['actual_output_qty']);
    }

    public function testMutationAdapterCommitsAndCancelsThroughProductionService(): void
    {
        $setup = $this->productionRecipe();
        $created = $this->mutationService()->handle(self::$conn, 'create_draft', [
            'recipe_id' => $setup['recipe_id'],
            'planned_output_qty' => '5.000000',
        ], $this->actor(['recipe.manage']));

        $committed = $this->mutationService()->handle(self::$conn, 'commit', [
            'batch_id' => $created['batch_id'],
            'actual_output_qty' => '5.000000',
        ], $this->actor(['recipe.approve']));
        $detail = (new ProductionBatchReadService())->batchDetail(self::$conn, (int) $created['batch_id']);

        $cancelSetup = $this->productionRecipe();
        $cancelCreated = $this->mutationService()->handle(self::$conn, 'create_draft', [
            'recipe_id' => $cancelSetup['recipe_id'],
            'planned_output_qty' => '3.000000',
        ], $this->actor(['recipe.manage']));
        $cancelled = $this->mutationService()->handle(self::$conn, 'cancel', [
            'batch_id' => $cancelCreated['batch_id'],
            'cancel_reason' => 'prep changed',
        ], $this->actor(['recipe.manage']));
        $cancelDetail = (new ProductionBatchReadService())->batchDetail(self::$conn, (int) $cancelCreated['batch_id']);

        $this->assertSame('Production batch committed.', $committed['message']);
        $this->assertSame('committed', $detail['batch']['status']);
        $this->assertCount(2, $detail['lines']);
        $this->assertSame('Production batch cancelled.', $cancelled['message']);
        $this->assertSame('cancelled', $cancelDetail['batch']['status']);
        $this->assertSame('prep changed', $cancelDetail['batch']['variance_reason']);
    }

    public function testMutationAdapterRejectsNonProductionRecipeTypes(): void
    {
        $itemId = $this->item('Sellable sandwich', '0.000000');
        $ingredientId = $this->item('Bread', '1.000000');
        $definition = $this->definitionService();
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $itemId,
            'recipe_name' => 'Sandwich',
            'recipe_type' => 'make_to_order',
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $ingredientId,
            'qty_per_yield' => '1.000000',
        ], $actor);
        $active = $definition->activate(self::$conn, (int) $recipe['id'], $actor);

        $this->expectException(InvalidArgumentException::class);
        $this->mutationService()->handle(self::$conn, 'create_draft', [
            'recipe_id' => (int) $active['id'],
            'planned_output_qty' => '1.000000',
        ], $this->actor(['recipe.manage']));
    }

    private function productionRecipe(): array
    {
        $outputItemId = $this->item('Prepared sauce', '0.000000');
        $inputItemId = $this->item('Tomato', '4.000000');
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $inputItemId,
            'qty_on_hand' => '50.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '50.000000',
        ]);

        $definition = $this->definitionService();
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $outputItemId,
            'recipe_name' => 'Prepared sauce batch',
            'recipe_type' => 'batch_prepared',
            'yield_qty' => '10.000000',
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $inputItemId,
            'qty_per_yield' => '20.000000',
        ], $actor);
        $active = $definition->activate(self::$conn, (int) $recipe['id'], $actor);

        return [
            'recipe_id' => (int) $active['id'],
            'output_item_id' => $outputItemId,
            'input_item_id' => $inputItemId,
        ];
    }

    private function mutationService(): ProductionBatchMutationService
    {
        return new ProductionBatchMutationService(new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ])));
    }

    private function definitionService(): RecipeDefinitionService
    {
        return new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
    }

    private function actor(array $permissions): RecipeActorContext
    {
        return new RecipeActorContext(77, 0, 0, null, $permissions, '127.0.0.1', 'phpunit');
    }

    private function item(string $name, string $cost): int
    {
        $id = ++self::$itemCounter;
        $stmt = self::$conn->prepare('INSERT INTO myitems (id, iname, cost_price) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $id, $name, $cost);
        $stmt->execute();
        $stmt->close();

        return $id;
    }
}
