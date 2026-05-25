<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeAvailabilityRefreshService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

class RecipeAvailabilityRefreshServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 82000;

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
        self::$dbName = 'posmain_recipe_availability_refresh_' . getmypid();
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
        self::$conn->query('DELETE FROM recipe_availability_cache');
        self::$conn->query('DELETE FROM inventory_item_balances');
        self::$conn->query('DELETE FROM recipe_lines');
        self::$conn->query('DELETE FROM recipe_headers');
    }

    public function testDryRunListsIngredientTargetsWithoutWritingCache(): void
    {
        $ingredientId = $this->nextItemId();
        $recipe = $this->createActiveRecipe($this->nextItemId(), $ingredientId);
        $this->putBalance($ingredientId, '8.000000');

        $result = $this->service()->run(self::$conn, [
            'ingredient_id' => $ingredientId,
            'store_id' => 0,
        ]);

        $this->assertFalse($result['applied']);
        $this->assertSame(1, $result['targets_count']);
        $this->assertSame((int) $recipe['id'], (int) $result['targets'][0]['recipe_id']);
        $this->assertSame(0, $this->cacheRowCount());
    }

    public function testApplyRefreshesOnlyRecipesImpactedByIngredient(): void
    {
        $ingredientId = $this->nextItemId();
        $otherIngredientId = $this->nextItemId();
        $recipe = $this->createActiveRecipe($this->nextItemId(), $ingredientId);
        $this->createActiveRecipe($this->nextItemId(), $otherIngredientId);
        $this->putBalance($ingredientId, '8.000000');
        $this->putBalance($otherIngredientId, '8.000000');

        $result = $this->service()->run(self::$conn, [
            'apply' => true,
            'ingredient_id' => $ingredientId,
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);
        $cache = $this->cacheRows();

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['refreshed_count']);
        $this->assertCount(1, $cache);
        $this->assertSame((int) $recipe['sellable_item_id'], (int) $cache[0]['sellable_item_id']);
        $this->assertSame('8.000000', $cache[0]['computed_available_qty']);
    }

    public function testIngredientRefreshFollowsSubRecipeParents(): void
    {
        $ingredientId = $this->nextItemId();
        $subRecipe = $this->createActiveRecipeWithLines($this->nextItemId(), [
            'recipe_name' => 'Refresh sauce base',
            'recipe_type' => 'sub_recipe',
        ], [
            [
                'ingredient_item_id' => $ingredientId,
                'qty_per_yield' => '2.000000',
            ],
        ]);
        $parentRecipe = $this->createActiveRecipeWithLines($this->nextItemId(), [
            'recipe_name' => 'Refresh parent item',
        ], [
            [
                'line_type' => 'sub_recipe',
                'sub_recipe_id' => (int) $subRecipe['id'],
                'qty_per_yield' => '1.000000',
            ],
        ]);
        $this->putBalance($ingredientId, '10.000000');

        $result = $this->service()->run(self::$conn, [
            'apply' => true,
            'ingredient_id' => $ingredientId,
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);
        $cache = $this->cacheRows();

        $this->assertTrue($result['applied']);
        $this->assertSame(2, $result['targets_count']);
        $this->assertSame(2, $result['refreshed_count']);
        $this->assertCount(2, $cache);
        $this->assertContains((int) $parentRecipe['sellable_item_id'], array_map('intval', array_column($cache, 'sellable_item_id')));
        foreach ($cache as $row) {
            $this->assertSame('5.000000', $row['computed_available_qty']);
        }
    }

    public function testRefreshForIngredientConvenienceMethodWritesCache(): void
    {
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($this->nextItemId(), $ingredientId);
        $this->putBalance($ingredientId, '3.000000');

        $results = $this->availability()->refreshForIngredient(self::$conn, $ingredientId, [
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('3.000000', $results[0]->computedAvailableQty);
        $this->assertSame(1, $this->cacheRowCount());
    }

    public function testRefreshForRecipeKeepsRecipeTenantAndBranchScope(): void
    {
        $ingredientId = $this->nextItemId();
        $recipe = $this->createActiveRecipeWithLines($this->nextItemId(), [
            'pos_tenant' => 7,
            'pos_branch' => 3,
        ], [
            [
                'ingredient_item_id' => $ingredientId,
                'qty_per_yield' => '1.000000',
            ],
        ]);
        $this->putBalance($ingredientId, '4.000000', 7, 3);

        $result = $this->availability('3')->refreshForRecipe(self::$conn, (int) $recipe['id'], [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);
        $cache = $this->cacheRows();

        $this->assertNotNull($result);
        $this->assertSame('4.000000', $result->computedAvailableQty);
        $this->assertCount(1, $cache);
        $this->assertSame(7, (int) $cache[0]['pos_tenant']);
        $this->assertSame(3, (int) $cache[0]['pos_branch']);
    }

    private function service(): RecipeAvailabilityRefreshService
    {
        return new RecipeAvailabilityRefreshService($this->availability());
    }

    private function availability(string $pilotBranch = '0'): RecipeAvailabilityService
    {
        return new RecipeAvailabilityService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => true,
                'pilot' => [
                    'pos_branch' => $pilotBranch,
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function createActiveRecipe(int $sellableItemId, int $ingredientId): array
    {
        return $this->createActiveRecipeWithLines($sellableItemId, [], [
            [
                'ingredient_item_id' => $ingredientId,
                'qty_per_yield' => '1.000000',
            ],
        ]);
    }

    private function createActiveRecipeWithLines(int $sellableItemId, array $recipeData, array $lines): array
    {
        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = new RecipeActorContext(77, 0, 0, null, ['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, array_merge([
            'sellable_item_id' => $sellableItemId,
            'recipe_name' => 'Refresh recipe ' . $sellableItemId,
        ], $recipeData), $actor);
        foreach ($lines as $line) {
            $definition->addLine(self::$conn, (int) $recipe['id'], $line, $actor);
        }

        return $definition->activate(self::$conn, (int) $recipe['id'], $actor);
    }

    private function putBalance(int $itemId, string $onHand, int $posTenant = 0, int $posBranch = 0): void
    {
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'pos_tenant' => $posTenant,
            'pos_branch' => $posBranch,
            'item_id' => $itemId,
            'qty_on_hand' => $onHand,
            'qty_reserved' => '0.000000',
            'qty_available' => $onHand,
        ]);
    }

    private function cacheRowCount(): int
    {
        $row = self::$conn->query('SELECT COUNT(*) AS count_rows FROM recipe_availability_cache')->fetch_assoc();

        return (int) ($row['count_rows'] ?? 0);
    }

    private function cacheRows(): array
    {
        $result = self::$conn->query('SELECT * FROM recipe_availability_cache ORDER BY id');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function nextItemId(): int
    {
        return ++self::$itemCounter;
    }
}
