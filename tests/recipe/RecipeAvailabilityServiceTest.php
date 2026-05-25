<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeAvailabilityService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

class RecipeAvailabilityServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 18000;

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
        self::$dbName = 'posmain_recipe_availability_' . getmypid();
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

    public function testCalculatedAvailabilityUsesOnHandReservedAndSafetyStock(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($itemId, [
            [
                'ingredient_item_id' => $ingredientId,
                'qty_per_yield' => '2.000000',
            ],
        ]);
        $this->putBalance($ingredientId, '10.000000', '2.000000');

        $result = $this->service()->calculateForItem(self::$conn, $itemId, [
            'safety_stock' => '1.000000',
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);

        $this->assertTrue($result->effectiveIsAvailable);
        $this->assertSame('3.000000', $result->computedAvailableQty);
        $this->assertSame('3.000000', $result->effectiveAvailableQty);
        $this->assertNull($result->unavailableReason);
    }

    public function testManualUnavailableOverridesComputedAvailability(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $this->createActiveRecipe($itemId, [
            [
                'ingredient_item_id' => $ingredientId,
                'qty_per_yield' => '1.000000',
            ],
        ]);
        $this->putBalance($ingredientId, '50.000000', '0.000000');
        $stmt = self::$conn->prepare("
            INSERT INTO item_availability (item_id, tenant, branch, channel, is_available, unavailable_reason)
            VALUES (?, 0, 0, 'pos', 0, 'Hidden by manager')
        ");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();

        $result = $this->service()->calculateForItem(self::$conn, $itemId, [
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);

        $this->assertFalse($result->effectiveIsAvailable);
        $this->assertSame('Hidden by manager', $result->unavailableReason);
        $this->assertSame('0.000000', $result->effectiveAvailableQty);
    }

    public function testAvailabilityCacheSeparatesChannelAndOrderType(): void
    {
        $itemId = $this->nextItemId();
        $ingredientId = $this->nextItemId();
        $boxId = $this->nextItemId();
        $this->createActiveRecipe($itemId, [
            [
                'ingredient_item_id' => $ingredientId,
                'qty_per_yield' => '1.000000',
            ],
            [
                'ingredient_item_id' => $boxId,
                'line_type' => 'packaging',
                'qty_per_yield' => '1.000000',
                'order_type' => 'delivery',
                'channel' => 'moova',
            ],
        ]);
        $this->putBalance($ingredientId, '10.000000', '0.000000');
        $this->putBalance($boxId, '0.000000', '0.000000');

        $takeaway = $this->service()->calculateForItem(self::$conn, $itemId, [
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ]);
        $delivery = $this->service()->calculateForItem(self::$conn, $itemId, [
            'order_type' => 'delivery',
            'channel' => 'moova',
        ]);
        $cache = $this->service()->getCachedForMenu(self::$conn, [$itemId], [
            'order_type' => 'delivery',
            'channel' => 'moova',
        ]);

        $this->assertTrue($takeaway->effectiveIsAvailable);
        $this->assertFalse($delivery->effectiveIsAvailable);
        $this->assertSame($boxId, $delivery->blockingItemId);
        $this->assertSame('0', (string) $cache[$itemId]['effective_is_available']);
        $this->assertSame('moova', $cache[$itemId]['channel']);
    }

    public function testDisabledAvailabilityDoesNotBlockSale(): void
    {
        $service = new RecipeAvailabilityService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]));

        $result = $service->calculateForItem(self::$conn, $this->nextItemId(), []);

        $this->assertTrue($result->effectiveIsAvailable);
        $this->assertSame('0.000000', $result->computedAvailableQty);
    }

    private function createActiveRecipe(int $itemId, array $lines): array
    {
        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = new RecipeActorContext(77, 0, 0, null, ['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $itemId,
            'recipe_name' => 'Availability recipe ' . $itemId,
        ], $actor);
        foreach ($lines as $line) {
            $definition->addLine(self::$conn, (int) $recipe['id'], $line, $actor);
        }

        return $definition->activate(self::$conn, (int) $recipe['id'], $actor);
    }

    private function putBalance(int $itemId, string $onHand, string $reserved): void
    {
        $available = RecipeDecimal::subtract($onHand, $reserved);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $itemId,
            'qty_on_hand' => $onHand,
            'qty_reserved' => $reserved,
            'qty_available' => $available,
        ]);
    }

    private function service(): RecipeAvailabilityService
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);

        return new RecipeAvailabilityService($flags);
    }

    private function nextItemId(): int
    {
        return ++self::$itemCounter;
    }
}
