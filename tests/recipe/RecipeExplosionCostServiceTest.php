<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeCostContext.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeCostService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeExplosionService.php';

class RecipeExplosionCostServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 12000;

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
        self::$dbName = 'posmain_recipe_explosion_' . getmypid();
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

    public function testExplosionHandlesYieldWastageAndUnitConversion(): void
    {
        $itemId = $this->nextItemId();
        $recipe = $this->createActiveRecipe($itemId, [
            'recipe_name' => 'Yielded recipe',
            'yield_qty' => '2.000000',
        ], [
            [
                'ingredient_item_id' => $this->ingredient('Flour', '1.000000'),
                'qty_per_yield' => '4.000000',
                'unit_conversion_to_base' => '0.50000000',
                'wastage_percent' => '10.0000',
            ],
        ]);

        $result = $this->explosionService()->explodeOrderLine(self::$conn, new RecipeOrderLineContext([
            'sellable_item_id' => $itemId,
            'quantity' => '3.000000',
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]));

        $this->assertTrue($result->hasRecipe);
        $this->assertSame((int) $recipe['id'], $result->recipeId);
        $this->assertSame('3.300000', $result->requirements[0]->requiredQtyBase);
    }

    public function testExplosionFiltersPackagingByOrderTypeAndChannelAndModifierByOption(): void
    {
        $itemId = $this->nextItemId();
        $boxId = $this->ingredient('Delivery box', '0.250000');
        $cheeseId = $this->ingredient('Extra cheese', '1.500000');
        $this->createActiveRecipe($itemId, ['recipe_name' => 'Channel recipe'], [
            [
                'ingredient_item_id' => $boxId,
                'line_type' => 'packaging',
                'qty_per_yield' => '1.000000',
                'order_type' => 'delivery',
                'channel' => 'moova',
            ],
            [
                'ingredient_item_id' => $cheeseId,
                'line_type' => 'modifier_ingredient',
                'qty_per_yield' => '0.030000',
                'modifier_group_id' => 9,
                'modifier_option_id' => 55,
            ],
        ]);

        $takeaway = $this->explosionService()->explodeOrderLine(self::$conn, new RecipeOrderLineContext([
            'sellable_item_id' => $itemId,
            'quantity' => '1.000000',
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]));
        $delivery = $this->explosionService()->explodeOrderLine(self::$conn, new RecipeOrderLineContext([
            'sellable_item_id' => $itemId,
            'quantity' => '2.000000',
            'order_type' => 'delivery',
            'channel' => 'moova',
            'modifiers' => [
                ['modifier_group_id' => 9, 'modifier_option_id' => 55],
            ],
        ]));

        $this->assertCount(0, $takeaway->requirements);
        $this->assertCount(2, $delivery->requirements);
        $this->assertSame($boxId, $delivery->requirements[0]->ingredientItemId);
        $this->assertSame('2.000000', $delivery->requirements[0]->requiredQtyBase);
        $this->assertSame($cheeseId, $delivery->requirements[1]->ingredientItemId);
        $this->assertSame('0.060000', $delivery->requirements[1]->requiredQtyBase);
    }

    public function testModifierSubstitutionRemovesBaseIngredientAndAddsReplacement(): void
    {
        $itemId = $this->nextItemId();
        $regularMilkId = $this->ingredient('Regular milk', '2.000000');
        $oatMilkId = $this->ingredient('Oat milk', '3.000000');
        $this->createActiveRecipe($itemId, ['recipe_name' => 'Milk substitution recipe'], [
            [
                'ingredient_item_id' => $regularMilkId,
                'qty_per_yield' => '0.250000',
                'substitution_group' => 'milk',
            ],
            [
                'ingredient_item_id' => $regularMilkId,
                'line_type' => 'modifier_ingredient',
                'qty_per_yield' => '0.250000',
                'modifier_group_id' => 11,
                'modifier_option_id' => 77,
                'modifier_behavior' => 'substitution_remove',
                'substitution_group' => 'milk',
            ],
            [
                'ingredient_item_id' => $oatMilkId,
                'line_type' => 'modifier_ingredient',
                'qty_per_yield' => '0.250000',
                'modifier_group_id' => 11,
                'modifier_option_id' => 77,
                'modifier_behavior' => 'substitution_add',
                'substitution_group' => 'milk',
            ],
        ]);

        $regular = $this->explosionService()->explodeOrderLine(self::$conn, new RecipeOrderLineContext([
            'sellable_item_id' => $itemId,
            'quantity' => '2.000000',
        ]));
        $oat = $this->explosionService()->explodeOrderLine(self::$conn, new RecipeOrderLineContext([
            'sellable_item_id' => $itemId,
            'quantity' => '2.000000',
            'modifiers' => [
                ['modifier_group_id' => 11, 'modifier_option_id' => 77],
            ],
        ]));

        $this->assertCount(1, $regular->requirements);
        $this->assertSame($regularMilkId, $regular->requirements[0]->ingredientItemId);
        $this->assertSame('0.500000', $regular->requirements[0]->requiredQtyBase);
        $this->assertCount(1, $oat->requirements);
        $this->assertSame($oatMilkId, $oat->requirements[0]->ingredientItemId);
        $this->assertSame('0.500000', $oat->requirements[0]->requiredQtyBase);
        $this->assertSame([], $oat->warnings);
    }

    public function testSubRecipeExplosionMultipliesRequirementsAndRejectsCycles(): void
    {
        $sauceItem = $this->nextItemId();
        $tomatoId = $this->ingredient('Tomato', '3.000000');
        $subRecipe = $this->createActiveRecipe($sauceItem, ['recipe_name' => 'Sauce', 'recipe_type' => 'sub_recipe'], [
            [
                'ingredient_item_id' => $tomatoId,
                'qty_per_yield' => '2.000000',
            ],
        ]);

        $mealItem = $this->nextItemId();
        $meal = $this->createActiveRecipe($mealItem, ['recipe_name' => 'Meal'], [
            [
                'line_type' => 'sub_recipe',
                'sub_recipe_id' => (int) $subRecipe['id'],
                'qty_per_yield' => '3.000000',
            ],
        ]);

        $result = $this->explosionService()->explodeRecipeById(self::$conn, (int) $meal['id'], new RecipeOrderLineContext([
            'sellable_item_id' => $mealItem,
            'quantity' => '2.000000',
        ]));

        $this->assertCount(1, $result->requirements);
        $this->assertSame($tomatoId, $result->requirements[0]->ingredientItemId);
        $this->assertSame('12.000000', $result->requirements[0]->requiredQtyBase);

        $cycleItem = $this->nextItemId();
        $cycleA = $this->createDraftRecipe($cycleItem, ['recipe_name' => 'Cycle A']);
        $cycleB = $this->createDraftRecipe($this->nextItemId(), ['recipe_name' => 'Cycle B']);
        $definition = $this->definitionService();
        $actor = $this->actor();
        $definition->addLine(self::$conn, (int) $cycleA['id'], [
            'line_type' => 'sub_recipe',
            'sub_recipe_id' => (int) $cycleB['id'],
            'qty_per_yield' => '1.000000',
        ], $actor);
        $definition->addLine(self::$conn, (int) $cycleB['id'], [
            'line_type' => 'sub_recipe',
            'sub_recipe_id' => (int) $cycleA['id'],
            'qty_per_yield' => '1.000000',
        ], $actor);

        $this->expectException(RuntimeException::class);
        $this->explosionService()->explodeRecipeById(self::$conn, (int) $cycleA['id'], new RecipeOrderLineContext([
            'sellable_item_id' => $cycleItem,
            'quantity' => '1.000000',
        ]));
    }

    public function testBatchPreparedOrderExplosionConsumesPreparedStockButRecipeExplosionKeepsRawInputs(): void
    {
        $sauceItem = $this->nextItemId();
        $tomatoId = $this->ingredient('Batch tomato', '5.000000');
        $recipe = $this->createActiveRecipe($sauceItem, [
            'recipe_name' => 'Prepared sauce batch',
            'recipe_type' => 'batch_prepared',
            'yield_qty' => '10.000000',
        ], [
            [
                'ingredient_item_id' => $tomatoId,
                'qty_per_yield' => '12.000000',
            ],
        ]);

        $orderExplosion = $this->explosionService()->explodeOrderLine(self::$conn, new RecipeOrderLineContext([
            'sellable_item_id' => $sauceItem,
            'quantity' => '3.000000',
        ]));
        $productionExplosion = $this->explosionService()->explodeRecipeById(self::$conn, (int) $recipe['id'], new RecipeOrderLineContext([
            'sellable_item_id' => $sauceItem,
            'quantity' => '10.000000',
        ]), '10.000000');

        $this->assertTrue($orderExplosion->hasRecipe);
        $this->assertCount(1, $orderExplosion->requirements);
        $this->assertSame($sauceItem, $orderExplosion->requirements[0]->ingredientItemId);
        $this->assertSame('prepared_stock', $orderExplosion->requirements[0]->lineType);
        $this->assertSame('3.000000', $orderExplosion->requirements[0]->requiredQtyBase);
        $this->assertCount(1, $productionExplosion->requirements);
        $this->assertSame($tomatoId, $productionExplosion->requirements[0]->ingredientItemId);
        $this->assertSame('12.000000', $productionExplosion->requirements[0]->requiredQtyBase);
    }

    public function testCostServiceCalculatesAndPreservesImmutableSnapshots(): void
    {
        $itemId = $this->nextItemId();
        $bunId = $this->ingredient('Bun', '5.000000');
        $pattyId = $this->ingredient('Patty', '30.000000');
        $recipe = $this->createActiveRecipe($itemId, ['recipe_name' => 'Burger cost'], [
            ['ingredient_item_id' => $bunId, 'qty_per_yield' => '1.000000'],
            ['ingredient_item_id' => $pattyId, 'qty_per_yield' => '1.000000'],
        ]);
        $service = new RecipeCostService(null, null, $this->explosionService());

        $cost = $service->calculateRecipeCost(self::$conn, (int) $recipe['id'], new RecipeCostContext([
            'calculated_at' => '2026-05-23 12:00:00',
        ]));
        $snapshot = $service->createSnapshot(self::$conn, (int) $recipe['id'], new RecipeCostContext([
            'calculated_at' => '2026-05-23 12:00:00',
            'actor_user_id' => 77,
        ]));
        self::$conn->query("UPDATE myitems SET cost_price = 99.000000 WHERE id = {$pattyId}");
        $recalculated = $service->calculateRecipeCost(self::$conn, (int) $recipe['id'], new RecipeCostContext([
            'calculated_at' => '2026-05-23 12:01:00',
        ]));
        $latest = $service->getLatestSnapshot(self::$conn, (int) $recipe['id']);

        $this->assertSame('35.000000', $cost->costPerYield);
        $this->assertSame('35.000000', $snapshot['cost_per_yield']);
        $this->assertSame('104.000000', $recalculated->costPerYield);
        $this->assertSame($snapshot['id'], $latest['id']);
        $this->assertStringContainsString((string) $bunId, $snapshot['ingredient_cost_json']);
    }

    public function testCostingMethodsUseMovingAverageLastPurchaseAndManualCosts(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->ingredient('Coffee beans', '10.000000');
        $recipe = $this->createActiveRecipe($itemId, ['recipe_name' => 'Coffee cost'], [
            ['ingredient_item_id' => $ingredientId, 'qty_per_yield' => '2.000000'],
        ]);
        self::$conn->query("
            INSERT INTO inventory_item_balances
                (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
            VALUES (0, 0, 0, {$ingredientId}, 10, 0, 10, 7.500000)
        ");
        self::$conn->query("
            INSERT INTO inventory_movements
                (movement_uuid, pos_tenant, pos_branch, store_id, item_id, movement_type, source_type, qty_in, unit_cost, idempotency_key)
            VALUES ('00000000-0000-4000-8000-000000009999', 0, 0, 0, {$ingredientId}, 'purchase', 'manual', 5, 8.250000, 'purchase-cost-test')
        ");
        $service = new RecipeCostService(null, null, $this->explosionService());

        $movingAverage = $service->calculateRecipeCost(self::$conn, (int) $recipe['id'], new RecipeCostContext([
            'costing_method' => 'moving_average',
        ]));
        $lastPurchase = $service->calculateRecipeCost(self::$conn, (int) $recipe['id'], new RecipeCostContext([
            'costing_method' => 'last_purchase',
        ]));
        $manual = $service->calculateRecipeCost(self::$conn, (int) $recipe['id'], new RecipeCostContext([
            'costing_method' => 'manual_snapshot',
            'manual_costs' => [
                $ingredientId => '9.000000',
            ],
        ]));

        $this->assertSame('15.000000', $movingAverage->costPerYield);
        $this->assertSame('16.500000', $lastPurchase->costPerYield);
        $this->assertSame('18.000000', $manual->costPerYield);
    }

    public function testDisabledExplosionFallsBackWithoutWrites(): void
    {
        $service = new RecipeExplosionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]));

        $result = $service->explodeOrderLine(self::$conn, new RecipeOrderLineContext([
            'sellable_item_id' => $this->nextItemId(),
        ]));

        $this->assertFalse($result->hasRecipe);
        $this->assertSame('recipes_disabled', $result->fallbackMode);
        $this->assertCount(0, $result->requirements);
    }

    private function createActiveRecipe(int $itemId, array $header, array $lines): array
    {
        $definition = $this->definitionService();
        $actor = $this->actor();
        $recipe = $definition->createDraft(self::$conn, array_merge([
            'sellable_item_id' => $itemId,
            'recipe_name' => 'Recipe ' . $itemId,
        ], $header), $actor);
        foreach ($lines as $line) {
            $definition->addLine(self::$conn, (int) $recipe['id'], $line, $actor);
        }

        return $definition->activate(self::$conn, (int) $recipe['id'], $actor);
    }

    private function createDraftRecipe(int $itemId, array $header): array
    {
        return $this->definitionService()->createDraft(self::$conn, array_merge([
            'sellable_item_id' => $itemId,
            'recipe_name' => 'Recipe ' . $itemId,
        ], $header), $this->actor());
    }

    private function ingredient(string $name, string $cost): int
    {
        $id = $this->nextItemId();
        $stmt = self::$conn->prepare('INSERT INTO myitems (id, iname, cost_price) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $id, $name, $cost);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    private function nextItemId(): int
    {
        return ++self::$itemCounter;
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

    private function explosionService(): RecipeExplosionService
    {
        return new RecipeExplosionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'read_only',
            ],
        ]));
    }

    private function actor(): RecipeActorContext
    {
        return new RecipeActorContext(77, 0, 0, null, ['recipe.manage', 'recipe.approve']);
    }
}
