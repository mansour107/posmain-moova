<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorMutationService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeAuditRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeLineRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeVariantLineRepository.php';

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
        self::$conn->query("
            CREATE TABLE IF NOT EXISTS item_units (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NOT NULL,
                unit_id BIGINT UNSIGNED NOT NULL,
                u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
                unit_barcode VARCHAR(128) NULL,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price2 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price3 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query("
            CREATE TABLE IF NOT EXISTS myitems (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                iname VARCHAR(255) NULL,
                name2 VARCHAR(255) NULL,
                code VARCHAR(128) NULL,
                barcode VARCHAR(128) NULL,
                info TEXT NULL,
                market_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price2 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price3 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                group1 INT NOT NULL DEFAULT 0,
                group2 INT NOT NULL DEFAULT 0,
                user BIGINT UNSIGNED NOT NULL DEFAULT 1,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
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

    public function testEditorMutationServiceCreatesLineApprovesAndActivatesThroughDefinitionService(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);
        $sellableItemId = ++self::$itemCounter;
        self::$conn->query("
            INSERT INTO myitems (id, iname, code, barcode, user, isdeleted)
            VALUES ({$sellableItemId}, 'Managed Burger', 'mb{$sellableItemId}', 'MB-{$sellableItemId}', 1, 0)
        ");

        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => $sellableItemId,
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

    public function testCreateDraftAutoGeneratesRecipeNameFromSelectedItem(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $sellableItemId = ++self::$itemCounter;
        self::$conn->query("
            INSERT INTO myitems (id, iname, code, barcode, user, isdeleted)
            VALUES ({$sellableItemId}, 'Auto Name Crepe', 'anc{$sellableItemId}', 'ANC-{$sellableItemId}', 1, 0)
        ");

        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => $sellableItemId,
            'recipe_type' => 'make_to_order',
            'yield_qty' => '1.000000',
            'default_wastage_percent' => '0.0000',
        ], $actor);

        $recipe = (new RecipeRepository())->findHeaderById(self::$conn, (int) $created['recipe_id']);

        $this->assertSame('Auto Name Crepe - ANC-' . $sellableItemId . ' recipe', $recipe['recipe_name']);
    }

    public function testCreateDraftOpensExistingDraftForItemInsteadOfCreatingDuplicateVersion(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $sellableItemId = ++self::$itemCounter;
        self::$conn->query("
            INSERT INTO myitems (id, iname, code, barcode, user, isdeleted)
            VALUES ({$sellableItemId}, 'Existing Draft Crepe', 'edc{$sellableItemId}', 'EDC-{$sellableItemId}', 1, 0)
        ");

        $first = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => $sellableItemId,
            'recipe_type' => 'make_to_order',
            'yield_qty' => '1.000000',
            'default_wastage_percent' => '0.0000',
        ], $actor);
        $second = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => $sellableItemId,
            'recipe_type' => 'make_to_order',
            'yield_qty' => '1.000000',
            'default_wastage_percent' => '0.0000',
        ], $actor);

        $count = self::$conn->query('SELECT COUNT(*) AS c FROM recipe_headers WHERE sellable_item_id = ' . $sellableItemId)->fetch_assoc();

        $this->assertSame((int) $first['recipe_id'], (int) $second['recipe_id']);
        $this->assertSame(1, (int) $count['c']);
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

    public function testEditorMutationServiceCreatesRecipeForMainItemWhenVariationIsSelected(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $mainItemId = ++self::$itemCounter;
        $variantItemId = ++self::$itemCounter;
        self::$conn->query("
            INSERT INTO item_variants (parent_item_id, variant_item_id, variant_label, is_active, sort_order)
            VALUES ({$mainItemId}, {$variantItemId}, 'Large', 1, 1)
        ");

        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => $variantItemId,
            'recipe_name' => 'Variation Selected Recipe',
            'recipe_type' => 'make_to_order',
        ], $actor);

        $recipe = (new RecipeRepository())->findHeaderById(self::$conn, (int) $created['recipe_id']);

        $this->assertSame($mainItemId, (int) $recipe['sellable_item_id']);
    }

    public function testEditorMutationServiceSavesVariationsThroughItemVariantService(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $mainItemId = ++self::$itemCounter;
        self::$conn->query("
            INSERT INTO myitems (id, iname, code, barcode, cost_price, price1, price2, price3, market_price, user, isdeleted)
            VALUES ({$mainItemId}, 'Recipe variation parent', 'rvp', 'rvp', 0, 20, 20, 20, 20, 1, 0)
        ");
        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => $mainItemId,
            'recipe_name' => 'Variation Managed Recipe',
            'recipe_type' => 'make_to_order',
        ], $actor);

        $service->handle(self::$conn, 'save_variations', [
            'recipe_id' => (int) $created['recipe_id'],
            'variant_label' => ['Small', 'Large'],
            'variant_name' => ['Small Recipe variation parent', 'Large Recipe variation parent'],
            'variant_barcode' => ['rv-small', 'rv-large'],
            'variant_cost_price' => ['4.000', '7.000'],
            'variant_price1' => ['15.000', '25.000'],
            'variant_price2' => ['15.000', '25.000'],
            'variant_market_price' => ['15.000', '25.000'],
            'variant_active' => [1, 1],
            'variant_default' => [1, 0],
            'variant_sort' => [1, 2],
        ], $actor);

        $result = self::$conn->query('SELECT COUNT(*) AS c FROM item_variants WHERE parent_item_id = ' . $mainItemId);
        $row = $result->fetch_assoc();

        $this->assertSame(2, (int) $row['c']);
        self::$itemCounter += 100;
    }

    public function testEditorMutationServiceSavesEditableRecipeForVariation(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $mainItemId = ++self::$itemCounter;
        $variantItemId = ++self::$itemCounter;
        $flourId = ++self::$itemCounter;
        $milkId = ++self::$itemCounter;
        foreach ([$mainItemId => 'Variation recipe parent', $variantItemId => 'Large parent', $flourId => 'Flour', $milkId => 'Milk'] as $id => $name) {
            self::$conn->query("
                INSERT INTO myitems (id, iname, code, barcode, user, isdeleted)
                VALUES ({$id}, '{$name}', 'vr{$id}', 'vr{$id}', 1, 0)
            ");
        }
        self::$conn->query("
            INSERT INTO item_variants (parent_item_id, variant_item_id, variant_label, is_active, sort_order)
            VALUES ({$mainItemId}, {$variantItemId}, 'Large', 1, 1)
        ");
        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => $mainItemId,
            'recipe_name' => 'Variation Editable Recipe',
        ], $actor);

        $service->handle(self::$conn, 'save_variant_recipe', [
            'recipe_id' => (int) $created['recipe_id'],
            'variant_item_id' => $variantItemId,
            'variant_recipe_component_id' => [$flourId, $milkId],
            'variant_recipe_line_type' => ['ingredient', 'ingredient'],
            'variant_recipe_qty_per_yield' => ['150.000', '250.000'],
            'variant_recipe_unit_id' => ['', ''],
            'variant_recipe_wastage_percent' => ['0', '1.25'],
            'variant_recipe_base_line_id' => ['', ''],
            'variant_recipe_notes' => ['more flour', 'large milk'],
        ], $actor);

        $lines = (new RecipeVariantLineRepository())->findLinesForVariant(self::$conn, (int) $created['recipe_id'], $variantItemId);

        $this->assertCount(2, $lines);
        $this->assertSame($flourId, (int) $lines[0]['ingredient_item_id']);
        $this->assertSame('150.000000', $lines[0]['qty_per_yield']);
        $this->assertSame($milkId, (int) $lines[1]['ingredient_item_id']);
        $this->assertSame('1.2500', $lines[1]['wastage_percent']);
    }

    public function testEditorMutationServiceResolvesUnitConversionFromItemUnit(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $ingredientId = ++self::$itemCounter;
        $unitId = 9101;
        self::$conn->query('DELETE FROM item_units WHERE item_id = ' . $ingredientId);
        self::$conn->query("
            INSERT INTO item_units (item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3)
            VALUES ({$ingredientId}, {$unitId}, 0.250000, 'recipe-unit-test', 0, 0, 0, 0)
        ");
        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => ++self::$itemCounter,
            'recipe_name' => 'Unit Managed Recipe',
        ], $actor);

        $service->handle(self::$conn, 'add_line', [
            'recipe_id' => (int) $created['recipe_id'],
            'line_type' => 'ingredient',
            'ingredient_item_id' => $ingredientId,
            'qty_per_yield' => '2.000000',
            'unit_id' => $unitId,
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.0000',
            'is_required' => '1',
        ], $actor);

        $line = (new RecipeLineRepository())->findLinesByRecipeId(self::$conn, (int) $created['recipe_id'])[0];

        $this->assertSame($unitId, (int) $line['unit_id']);
        $this->assertSame('0.25000000', $line['unit_conversion_to_base']);
    }

    public function testEditorMutationServiceInfersModifierIngredientFromSelectedModifierOption(): void
    {
        $service = $this->service(true);
        $actor = $this->actor(['recipe.manage']);
        $created = $service->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => ++self::$itemCounter,
            'recipe_name' => 'Modifier Component Recipe',
        ], $actor);

        $service->handle(self::$conn, 'add_line', [
            'recipe_id' => (int) $created['recipe_id'],
            'line_type' => 'ingredient',
            'ingredient_item_id' => ++self::$itemCounter,
            'qty_per_yield' => '1.000000',
            'unit_conversion_to_base' => '1.00000000',
            'wastage_percent' => '0.0000',
            'is_required' => '1',
            'modifier_group_id' => 55,
            'modifier_option_id' => 66,
            'modifier_behavior' => 'substitution_add',
            'substitution_group' => 'cheese',
        ], $actor);

        $line = (new RecipeLineRepository())->findLinesByRecipeId(self::$conn, (int) $created['recipe_id'])[0];

        $this->assertSame('modifier_ingredient', $line['line_type']);
        $this->assertSame(55, (int) $line['modifier_group_id']);
        $this->assertSame(66, (int) $line['modifier_option_id']);
        $this->assertSame('substitution_add', $line['modifier_behavior']);
        $this->assertSame('cheese', $line['substitution_group']);
    }

    public function testCreateDraftMissingItemUsesNameBasedValidationMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Please choose an item by name.');

        $this->service(true)->handle(self::$conn, 'create_draft', [
            'sellable_item_id' => '',
            'recipe_name' => 'Missing Item Managed Recipe',
            'yield_qty' => '1.000000',
            'default_wastage_percent' => '0.0000',
        ], $this->actor(['recipe.manage']));
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
