<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorMutationService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeAuditRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeLineRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeRepository.php';

class RecipeEditorMutationServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 57000;

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
        self::$dbName = 'posmain_recipe_editor_mutation_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
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

    public function testEditorMutationServiceCreatesLineApprovesAndActivatesThroughDefinitionService(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);

        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => ++self::$itemCounter,
            'recipe_name' => 'Managed Burger',
            'recipe_type' => 'make_to_order',
            'yield_qty' => '1.000000',
            'default_wastage_percent' => '0.0000',
        ], $actor);
        $recipeId = (int) $created['recipe_id'];

        $service->handle(self::$conn, 'add_line', [
            'recipe_id' => $recipeId,
            'line_type' => 'ingredient',
            'ingredient_item_id' => ++self::$itemCounter,
            'qty_per_yield' => '2.000000',
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '5.0000',
            'is_required' => '1',
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ], $actor);
        $lines = (new RecipeLineRepository())->findLinesByRecipeId(self::$conn, $recipeId);
        $lineId = (int) $lines[0]['id'];

        $service->handle(self::$conn, 'update_draft', [
            'recipe_id' => $recipeId,
            'recipe_name' => 'Managed Burger v1',
            'recipe_type' => 'hybrid',
            'yield_qty' => '2.000000',
            'default_wastage_percent' => '1.5000',
            'costing_method' => 'moving_average',
            'requires_recipe_for_sale' => '1',
        ], $actor);
        $service->handle(self::$conn, 'update_line', [
            'recipe_id' => $recipeId,
            'line_id' => $lineId,
            'line_type' => 'packaging',
            'ingredient_item_id' => ++self::$itemCounter,
            'qty_per_yield' => '3.000000',
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.5000',
            'is_required' => '1',
            'order_type' => 'delivery',
            'channel' => 'moova',
        ], $actor);
        $service->handle(self::$conn, 'add_line', [
            'recipe_id' => $recipeId,
            'line_type' => 'modifier_ingredient',
            'ingredient_item_id' => ++self::$itemCounter,
            'qty_per_yield' => '0.250000',
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.0000',
            'is_required' => '1',
            'modifier_group_id' => 12,
            'modifier_option_id' => 34,
            'modifier_behavior' => 'substitution_add',
            'substitution_group' => 'milk',
            'order_type' => 'any',
            'channel' => 'pos',
        ], $actor);
        $service->handle(self::$conn, 'approve', ['recipe_id' => $recipeId], $actor);
        $service->handle(self::$conn, 'activate', ['recipe_id' => $recipeId], $actor);

        $recipe = (new RecipeRepository())->findHeaderById(self::$conn, $recipeId);
        $lines = (new RecipeLineRepository())->findLinesByRecipeId(self::$conn, $recipeId);
        $auditRows = (new RecipeAuditRepository())->findForRecipe(self::$conn, 0, 0, $recipeId);

        $this->assertSame('active', $recipe['status']);
        $this->assertSame('Managed Burger v1', $recipe['recipe_name']);
        $this->assertSame('hybrid', $recipe['recipe_type']);
        $this->assertSame('moving_average', $recipe['costing_method']);
        $this->assertSame('2.000000', $recipe['yield_qty']);
        $this->assertSame('1.5000', $recipe['default_wastage_percent']);
        $this->assertCount(2, $lines);
        $this->assertSame('delivery', $lines[0]['order_type']);
        $this->assertSame('moova', $lines[0]['channel']);
        $this->assertSame('packaging', $lines[0]['line_type']);
        $this->assertSame('3.000000', $lines[0]['qty_per_yield']);
        $this->assertSame('1.00000000', $lines[0]['unit_conversion_to_base']);
        $this->assertSame('0.5000', $lines[0]['wastage_percent']);
        $this->assertSame('modifier_ingredient', $lines[1]['line_type']);
        $this->assertSame('substitution_add', $lines[1]['modifier_behavior']);
        $this->assertSame('milk', $lines[1]['substitution_group']);
        $this->assertGreaterThanOrEqual(5, count($auditRows));
    }

    public function testEditorMutationServiceNormalizesDecimalInputs(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => ++self::$itemCounter,
            'recipe_name' => 'Decimal Managed Recipe',
            'yield_qty' => '1.2345674',
            'default_wastage_percent' => '0.55555',
        ], $actor);

        $service->handle(self::$conn, 'add_line', [
            'recipe_id' => (int) $created['recipe_id'],
            'line_type' => 'ingredient',
            'ingredient_item_id' => ++self::$itemCounter,
            'qty_per_yield' => '0.3333335',
            'unit_conversion_to_base' => '1.12345678',
            'wastage_percent' => '0.33335',
            'is_required' => '1',
        ], $actor);

        $recipe = (new RecipeRepository())->findHeaderById(self::$conn, (int) $created['recipe_id']);
        $line = (new RecipeLineRepository())->findLinesByRecipeId(self::$conn, (int) $created['recipe_id'])[0];

        $this->assertSame('1.234567', $recipe['yield_qty']);
        $this->assertSame('0.5556', $recipe['default_wastage_percent']);
        $this->assertSame('0.333334', $line['qty_per_yield']);
        $this->assertSame('1.12345678', $line['unit_conversion_to_base']);
        $this->assertSame('0.3334', $line['wastage_percent']);
    }

    public function testEditorMutationServiceRejectsWritesWhenRecipeFlagsAreOff(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service(false)->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => ++self::$itemCounter,
            'recipe_name' => 'Disabled managed recipe',
        ], $this->actor(['recipe.manage']));
    }

    private function service(bool $enabled): RecipeEditorMutationService
    {
        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => $enabled,
                'mode' => $enabled ? 'shadow' : 'off',
            ],
        ]));

        return new RecipeEditorMutationService($definition);
    }

    private function actor(array $permissions): RecipeActorContext
    {
        return new RecipeActorContext(88, 0, 0, null, $permissions, '127.0.0.1', 'phpunit');
    }
}
