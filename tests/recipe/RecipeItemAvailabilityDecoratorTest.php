<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ItemAvailabilityService.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

class RecipeItemAvailabilityDecoratorTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 43000;

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
        self::$dbName = 'posmain_recipe_item_availability_' . getmypid();
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

    public function testNoActiveRecipeKeepsManualDefaultAvailable(): void
    {
        $itemId = $this->nextItemId();
        $service = new ItemAvailabilityService($this->availabilityFlags());

        $availability = $service->availabilityForItem(self::$conn, $itemId, [
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        $this->assertTrue($availability['is_available']);
        $this->assertArrayNotHasKey('recipe_enabled', $availability);
    }

    public function testRecipeComputedShortageDecoratesRecipeBackedItemWithoutBlockingSale(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($itemId, $ingredientId);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $ingredientId,
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '0.000000',
        ]);
        $service = new ItemAvailabilityService($this->availabilityFlags());

        $availability = $service->availabilityForItem(self::$conn, $itemId, [
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        $this->assertTrue($availability['is_available']);
        $this->assertTrue($availability['manual_is_available']);
        $this->assertTrue($availability['availability_can_add']);
        $this->assertTrue($availability['availability_warn_only']);
        $this->assertSame('recipe_shortage', $availability['availability_status']);
        $this->assertTrue($availability['recipe_enabled']);
        $this->assertSame('0.000000', $availability['recipe_effective_available_qty']);
        $this->assertSame('0', $availability['recipe_cashier_available_qty']);
        $this->assertSame('Required ingredient out of stock.', $availability['unavailable_reason']);
    }

    public function testLegacyNegativeApprovalFlagStillProducesPermissiveShortagePresentation(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($itemId, $ingredientId);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $ingredientId,
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '0.000000',
        ]);
        $flags = $this->availabilityFlags([
            'allow_negative_stock_with_approval' => true,
        ]);
        $service = new ItemAvailabilityService($flags);

        $availability = $service->availabilityForItem(self::$conn, $itemId, [
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        $this->assertTrue($availability['is_available']);
        $this->assertTrue($availability['availability_can_add']);
        $this->assertFalse($availability['availability_requires_manager_override']);
        $this->assertTrue($availability['availability_warn_only']);
        $this->assertSame('recipe_shortage', $availability['availability_status']);
        $this->assertSame('0', $availability['recipe_cashier_available_qty']);
    }

    public function testLegacyStrictStockFlagCannotBlockCashierSale(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($itemId, $ingredientId);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $ingredientId,
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '0.000000',
        ]);
        $flags = $this->availabilityFlags([
            'allow_negative_stock_with_approval' => true,
            'strict_stock' => true,
        ]);
        $service = new ItemAvailabilityService($flags);

        $availability = $service->availabilityForItem(self::$conn, $itemId, [
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        $this->assertTrue($availability['is_available']);
        $this->assertTrue($availability['availability_can_add']);
        $this->assertTrue($availability['availability_warn_only']);
        $this->assertSame('recipe_shortage', $availability['availability_status']);
        $this->assertSame('0', $availability['recipe_cashier_available_qty']);
        $this->assertFalse($availability['availability_requires_manager_override']);
        $this->assertFalse($availability['availability_override_allowed']);
    }

    public function testManualUnavailableStillWinsOverComputedAvailability(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($itemId, $ingredientId);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $ingredientId,
            'qty_on_hand' => '20.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '20.000000',
        ]);
        $service = new ItemAvailabilityService($this->availabilityFlags());
        $service->setAvailability(self::$conn, $itemId, false, [
            'tenant' => 0,
            'branch' => 0,
            'channel' => 'pos',
        ], 'Hidden by manager', 77);

        $availability = $service->availabilityForItem(self::$conn, $itemId, [
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        $this->assertFalse($availability['is_available']);
        $this->assertFalse($availability['manual_is_available']);
        $this->assertSame('manual_unavailable', $availability['availability_status']);
        $this->assertSame('Hidden by manager', $availability['unavailable_reason']);
        $this->assertArrayNotHasKey('recipe_enabled', $availability);
    }

    public function testDecorateItemsAddsRecipeAvailabilityFieldsForRecipeItemsOnly(): void
    {
        $recipeItemId = $this->nextItemId();
        $plainItemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($recipeItemId, $ingredientId);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $ingredientId,
            'qty_on_hand' => '5.000000',
            'qty_reserved' => '1.000000',
            'qty_available' => '4.000000',
        ]);
        $service = new ItemAvailabilityService($this->availabilityFlags());

        $decorated = $service->decorateItems(self::$conn, [
            ['id' => $recipeItemId, 'iname' => 'Recipe item'],
            ['id' => $plainItemId, 'iname' => 'Plain item'],
        ], [
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        $this->assertSame(1, (int) $decorated[0]['is_available']);
        $this->assertSame('recipe_low', $decorated[0]['availability_status']);
        $this->assertTrue($decorated[0]['availability_low_stock']);
        $this->assertArrayHasKey('availability_override_permission', $decorated[0]);
        $this->assertSame('', $decorated[0]['availability_override_permission']);
        $this->assertSame('4.000000', $decorated[0]['recipe_effective_available_qty']);
        $this->assertSame(1, (int) $decorated[1]['is_available']);
        $this->assertArrayHasKey('availability_override_permission', $decorated[1]);
        $this->assertSame('', $decorated[1]['availability_override_permission']);
        $this->assertArrayNotHasKey('recipe_enabled', $decorated[1]);
    }

    public function testTrackedNonRecipeItemUsesSavedShopPolicyAndManualDisableStillWins(): void
    {
        $itemId = $this->nextItemId();
        self::$conn->query("CREATE TABLE IF NOT EXISTS settings (
            id INT NOT NULL PRIMARY KEY,
            negative_stock_sale_policy ENUM('block','allow_with_warning') NULL
        ) ENGINE=InnoDB");
        self::$conn->query("INSERT INTO settings (id, negative_stock_sale_policy)
            VALUES (1, 'block')
            ON DUPLICATE KEY UPDATE negative_stock_sale_policy = VALUES(negative_stock_sale_policy)");
        self::$conn->query("CREATE TABLE IF NOT EXISTS myitems (
            id INT NOT NULL PRIMARY KEY,
            track_stock TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");
        self::$conn->query("INSERT INTO myitems (id, track_stock) VALUES ({$itemId}, 1)
            ON DUPLICATE KEY UPDATE track_stock = VALUES(track_stock)");
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'pos_tenant' => 2,
            'pos_branch' => 3,
            'store_id' => 9,
            'item_id' => $itemId,
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '0.000000',
        ]);

        try {
            $service = new ItemAvailabilityService(new RecipeFeatureFlags([
                'recipe' => ['enabled' => false],
                'inventory' => [
                    'ledger_mode' => 'live',
                    'strict_stock' => false,
                ],
            ]));
            $scope = [
                'tenant' => 2,
                'branch' => 3,
                'store_id' => 9,
                'channel' => 'pos',
                'order_type' => 'takeaway',
            ];

            $legacyBlock = $service->availabilityForItem(self::$conn, $itemId, $scope);
            $this->assertTrue($legacyBlock['availability_can_add']);
            $this->assertTrue($legacyBlock['availability_warn_only']);
            $this->assertSame('inventory_shortage', $legacyBlock['availability_status']);
            $this->assertTrue($legacyBlock['inventory_stock_tracked']);
            $this->assertSame('0.000000', $legacyBlock['inventory_qty_available']);
            $this->assertSame('0', $legacyBlock['inventory_cashier_qty_available']);

            self::$conn->query("UPDATE settings SET negative_stock_sale_policy = 'allow_with_warning' WHERE id = 1");
            $warned = $service->availabilityForItem(self::$conn, $itemId, $scope);
            $this->assertTrue($warned['availability_can_add']);
            $this->assertTrue($warned['availability_warn_only']);
            $this->assertSame('inventory_shortage', $warned['availability_status']);

            $service->setAvailability(self::$conn, $itemId, false, [
                'tenant' => 2,
                'branch' => 3,
                'channel' => 'pos',
            ], 'Disabled by manager', 77);
            $manualBlock = $service->availabilityForItem(self::$conn, $itemId, $scope);
            $this->assertFalse($manualBlock['availability_can_add']);
            $this->assertSame('manual_unavailable', $manualBlock['availability_status']);
            $this->assertSame('Disabled by manager', $manualBlock['unavailable_reason']);
        } finally {
            self::$conn->query('DROP TABLE IF EXISTS settings');
            self::$conn->query("DELETE FROM myitems WHERE id = {$itemId}");
        }
    }

    private function createActiveRecipe(int $sellableItemId, int $ingredientItemId): void
    {
        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = new RecipeActorContext(77, 0, 0, null, ['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $sellableItemId,
            'recipe_name' => 'Item availability recipe ' . $sellableItemId,
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $ingredientItemId,
            'qty_per_yield' => '1.000000',
        ], $actor);
        $definition->activate(self::$conn, (int) $recipe['id'], $actor);
    }

    private function availabilityFlags(array $overrides = []): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => true,
                'strict_stock' => (bool) ($overrides['strict_stock'] ?? false),
                'allow_negative_stock_with_approval' => (bool) ($overrides['allow_negative_stock_with_approval'] ?? false),
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);
    }

    private function nextItemId(): int
    {
        return ++self::$itemCounter;
    }
}
