<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/IngredientRequirement.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeExplosionResult.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeInventoryMovementService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeReservationService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryMovementRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/StockReservationRepository.php';

class RecipeInventoryReservationServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;

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
        self::$dbName = 'posmain_recipe_inventory_' . getmypid();
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

    public function testReservationServiceChangesReservedQuantityWithoutReducingOnHand(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'item_id' => 3001,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '10.000000',
        ]);
        $service = new RecipeReservationService($this->reservationFlags());
        $explosion = $this->explosion(1001, 5001, 1, 3001, '3.000000');
        $context = $this->orderContext(7001, 71);

        $first = $service->reserveExplosion(self::$conn, $explosion, $context);
        $second = $service->reserveExplosion(self::$conn, $explosion, $context);
        $balance = $balances->findBalance(self::$conn, 0, 0, 0, 3001);
        $movements = $this->rows('inventory_movements');
        $reservations = $this->rows('stock_reservations');

        $this->assertCount(1, $first->movementIds);
        $this->assertSame($first->movementIds, $second->movementIds);
        $this->assertSame('10.000000', $balance['qty_on_hand']);
        $this->assertSame('3.000000', $balance['qty_reserved']);
        $this->assertSame('7.000000', $balance['qty_available']);
        $this->assertCount(1, $movements);
        $this->assertSame('reservation', $movements[0]['movement_type']);
        $this->assertSame('0.000000', $movements[0]['qty_in']);
        $this->assertSame('0.000000', $movements[0]['qty_out']);
        $this->assertCount(1, $reservations);
    }

    public function testReservationReleaseIsIdempotentAndRestoresAvailableQuantity(): void
    {
        $balances = new InventoryBalanceRepository();
        $service = new RecipeReservationService($this->reservationFlags());
        $explosion = $this->explosion(1002, 5002, 1, 3002, '2.000000');
        $context = $this->orderContext(7002, 72);

        $service->reserveExplosion(self::$conn, $explosion, $context);
        $release = $service->releaseForOrderLine(self::$conn, 7002, 72, null, 'cancel');
        $secondRelease = $service->releaseForOrderLine(self::$conn, 7002, 72, null, 'cancel');
        $balance = $balances->findBalance(self::$conn, 0, 0, 0, 3002);

        $this->assertCount(1, $release->movementIds);
        $this->assertTrue($secondRelease->noop);
        $this->assertSame('0.000000', $balance['qty_reserved']);
        $this->assertSame('0.000000', $balance['qty_on_hand']);
        $this->assertSame('0.000000', $balance['qty_available']);
        $this->assertSame('released', $this->rows('stock_reservations', 'ingredient_item_id = 3002')[0]['status']);
    }

    public function testReservationServiceHonorsPilotCategoryScopeForDirectCalls(): void
    {
        $flags = $this->reservationFlags([
            'pilot' => [
                'pos_branch' => '',
                'item_ids' => [],
                'category_ids' => [44],
            ],
        ]);
        $service = new RecipeReservationService($flags);
        $matchingContext = array_merge($this->orderContext(7013, 83), [
            'item_category_id' => 44,
        ]);
        $outsideContext = array_merge($this->orderContext(7014, 84), [
            'item_category_id' => 45,
        ]);

        $matching = $service->reserveExplosion(self::$conn, $this->explosion(1013, 5013, 1, 3013, '1.000000'), $matchingContext);
        $outside = $service->reserveExplosion(self::$conn, $this->explosion(1014, 5014, 1, 3014, '1.000000'), $outsideContext);

        $this->assertFalse($matching->noop);
        $this->assertTrue($outside->noop);
        $this->assertCount(1, $this->rows('stock_reservations', 'order_id = 7013'));
        $this->assertSame([], $this->rows('stock_reservations', 'order_id = 7014'));
        $this->assertSame([], $this->rows('inventory_movements', 'order_id = 7014'));
    }

    public function testReservationMovementServiceHonorsPilotCategoryScopeForDirectCalls(): void
    {
        $flags = $this->reservationFlags([
            'pilot' => [
                'pos_branch' => '',
                'item_ids' => [],
                'category_ids' => [44],
            ],
        ]);
        $service = new RecipeInventoryMovementService($flags);
        $matchingContext = array_merge($this->orderContext(7015, 85), [
            'item_category_id' => 44,
        ]);
        $outsideContext = array_merge($this->orderContext(7016, 86), [
            'item_category_id' => 45,
        ]);

        $matching = $service->recordReservationMovement(self::$conn, $this->explosion(1015, 5015, 1, 3015, '1.000000'), $matchingContext);
        $outside = $service->recordReservationMovement(self::$conn, $this->explosion(1016, 5016, 1, 3016, '1.000000'), $outsideContext);

        $this->assertFalse($matching->noop);
        $this->assertTrue($outside->noop);
        $this->assertCount(1, $this->rows('inventory_movements', "order_id = 7015 AND movement_type = 'reservation'"));
        $this->assertSame([], $this->rows('inventory_movements', 'order_id = 7016'));
    }

    public function testExpiredReservationsReleaseReservedQuantityAndRequireFreshAvailabilityCheck(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'item_id' => 3007,
            'qty_on_hand' => '5.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '5.000000',
        ]);
        $balances->putBalance(self::$conn, [
            'item_id' => 3008,
            'qty_on_hand' => '5.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '5.000000',
        ]);
        $balances->putBalance(self::$conn, [
            'item_id' => 3009,
            'qty_on_hand' => '5.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '5.000000',
        ]);

        $service = new RecipeReservationService($this->reservationFlags());
        $usageRepo = new RecipeOrderLineUsageRepository();
        $expiredUsageId = $usageRepo->createUsage(self::$conn, [
            'usage_uuid' => '00000000-0000-4000-8000-000000007007',
            'order_id' => 7007,
            'fat_detail_id' => 77,
            'sellable_item_id' => 1007,
            'order_qty' => '1.000000',
            'recipe_id' => 5007,
            'recipe_version_number' => 1,
            'status' => 'reserved',
            'idempotency_key' => 'usage:7007:77',
        ]);
        $futureUsageId = $usageRepo->createUsage(self::$conn, [
            'usage_uuid' => '00000000-0000-4000-8000-000000007008',
            'order_id' => 7008,
            'fat_detail_id' => 78,
            'sellable_item_id' => 1008,
            'order_qty' => '1.000000',
            'recipe_id' => 5008,
            'recipe_version_number' => 1,
            'status' => 'reserved',
            'idempotency_key' => 'usage:7008:78',
        ]);
        $consumedUsageId = $usageRepo->createUsage(self::$conn, [
            'usage_uuid' => '00000000-0000-4000-8000-000000007009',
            'order_id' => 7009,
            'fat_detail_id' => 79,
            'sellable_item_id' => 1009,
            'order_qty' => '1.000000',
            'recipe_id' => 5009,
            'recipe_version_number' => 1,
            'status' => 'consumed',
            'idempotency_key' => 'usage:7009:79',
        ]);
        $expiredContext = array_merge($this->orderContext(7007, 77), [
            'recipe_order_line_usage_id' => $expiredUsageId,
            'expires_at' => '2026-05-24 09:00:00',
        ]);
        $futureContext = array_merge($this->orderContext(7008, 78), [
            'recipe_order_line_usage_id' => $futureUsageId,
            'expires_at' => '2026-05-24 13:00:00',
        ]);
        $consumedContext = array_merge($this->orderContext(7009, 79), [
            'recipe_order_line_usage_id' => $consumedUsageId,
            'expires_at' => '2026-05-24 09:30:00',
        ]);

        $service->reserveExplosion(self::$conn, $this->explosion(1007, 5007, 1, 3007, '2.000000'), $expiredContext);
        $service->reserveExplosion(self::$conn, $this->explosion(1008, 5008, 1, 3008, '1.000000'), $futureContext);
        $service->reserveExplosion(self::$conn, $this->explosion(1009, 5009, 1, 3009, '1.000000'), $consumedContext);

        $first = $service->expireReservations(self::$conn, new DateTimeImmutable('2026-05-24 12:00:00'));
        $second = $service->expireReservations(self::$conn, new DateTimeImmutable('2026-05-24 12:00:00'));
        $expiredBalance = $balances->findBalance(self::$conn, 0, 0, 0, 3007);
        $futureBalance = $balances->findBalance(self::$conn, 0, 0, 0, 3008);
        $expiredReservation = $this->rows('stock_reservations', 'ingredient_item_id = 3007')[0];
        $futureReservation = $this->rows('stock_reservations', 'ingredient_item_id = 3008')[0];
        $expiredUsage = $this->rows('recipe_order_line_usage', 'id = ' . (int) $expiredReservation['recipe_order_line_usage_id'])[0];
        $consumedUsage = $this->rows('recipe_order_line_usage', 'id = ' . $consumedUsageId)[0];
        $releaseMovements = $this->rows('inventory_movements', "item_id = 3007 AND movement_type = 'reservation_release'");

        $this->assertCount(2, $first->movementIds);
        $this->assertCount(2, $first->reservationIds);
        $this->assertTrue($second->noop);
        $this->assertSame('expired', $expiredReservation['status']);
        $this->assertSame('reserved', $futureReservation['status']);
        $this->assertSame('previewed', $expiredUsage['status']);
        $this->assertSame('consumed', $consumedUsage['status']);
        $this->assertSame('5.000000', $expiredBalance['qty_on_hand']);
        $this->assertSame('0.000000', $expiredBalance['qty_reserved']);
        $this->assertSame('5.000000', $expiredBalance['qty_available']);
        $this->assertSame('1.000000', $futureBalance['qty_reserved']);
        $this->assertCount(1, $releaseMovements);
        $this->assertStringContainsString(':expired', $releaseMovements[0]['idempotency_key']);
    }

    public function testConsumptionReducesOnHandOnceAndStoreScopesIdempotency(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'store_id' => 0,
            'item_id' => 3003,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '1.000000',
            'qty_available' => '9.000000',
        ]);
        $balances->putBalance(self::$conn, [
            'store_id' => 1,
            'item_id' => 3003,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '10.000000',
        ]);
        $service = new RecipeInventoryMovementService($this->consumptionFlags());
        $explosion = $this->explosion(1003, 5003, 4, 3003, '2.000000');

        $first = $service->recordRecipeConsumption(self::$conn, $explosion, $this->orderContext(7003, 73, 0));
        $second = $service->recordRecipeConsumption(self::$conn, $explosion, $this->orderContext(7003, 73, 0));
        $storeOne = $service->recordRecipeConsumption(self::$conn, $explosion, $this->orderContext(7003, 73, 1));
        $storeZeroBalance = $balances->findBalance(self::$conn, 0, 0, 0, 3003);
        $storeOneBalance = $balances->findBalance(self::$conn, 0, 0, 1, 3003);

        $this->assertSame($first->movementIds, $second->movementIds);
        $this->assertNotSame($first->movementIds, $storeOne->movementIds);
        $this->assertSame('8.000000', $storeZeroBalance['qty_on_hand']);
        $this->assertSame('1.000000', $storeZeroBalance['qty_reserved']);
        $this->assertSame('7.000000', $storeZeroBalance['qty_available']);
        $this->assertSame('8.000000', $storeOneBalance['qty_on_hand']);
        $this->assertSame('8.000000', $storeOneBalance['qty_available']);
    }

    public function testConsumptionAgainstReservedQuantityIsReplaySafe(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'item_id' => 3010,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '10.000000',
        ]);
        $reservationService = new RecipeReservationService($this->reservationFlags());
        $movementService = new RecipeInventoryMovementService($this->consumptionFlags());
        $explosion = $this->explosion(1010, 5010, 1, 3010, '2.000000');
        $context = $this->orderContext(7010, 80);

        $reservationService->reserveExplosion(self::$conn, $explosion, $context);
        $consumeContext = array_merge($context, [
            'consume_reserved' => true,
        ]);
        $first = $movementService->recordRecipeConsumption(self::$conn, $explosion, $consumeContext);
        $second = $movementService->recordRecipeConsumption(self::$conn, $explosion, $consumeContext);
        $reservationService->consumeForOrderLine(self::$conn, 7010, 80, null);
        $secondStatusUpdate = $reservationService->consumeForOrderLine(self::$conn, 7010, 80, null);
        $balance = $balances->findBalance(self::$conn, 0, 0, 0, 3010);
        $movements = $this->rows('inventory_movements', 'item_id = 3010');
        $reservation = $this->rows('stock_reservations', 'ingredient_item_id = 3010')[0];

        $this->assertSame($first->movementIds, $second->movementIds);
        $this->assertTrue($secondStatusUpdate->noop);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertSame('0.000000', $balance['qty_reserved']);
        $this->assertSame('8.000000', $balance['qty_available']);
        $this->assertSame('consumed', $reservation['status']);
        $this->assertSame(['reservation', 'recipe_consumption'], array_column($movements, 'movement_type'));
    }

    public function testMultiIngredientConsumptionLocksBalancesDeterministically(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'item_id' => 3012,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '10.000000',
        ]);
        $balances->putBalance(self::$conn, [
            'item_id' => 3011,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '10.000000',
        ]);
        $service = new RecipeInventoryMovementService($this->consumptionFlags());
        $explosion = new RecipeExplosionResult([
            'sellable_item_id' => 1011,
            'recipe_id' => 5011,
            'recipe_version' => 1,
            'requirements' => [
                new IngredientRequirement([
                    'ingredient_item_id' => 3012,
                    'source_recipe_line_id' => 1,
                    'line_type' => 'ingredient',
                    'required_qty_base' => '2.000000',
                    'unit_cost' => '4.000000',
                    'total_cost' => '8.000000',
                ]),
                new IngredientRequirement([
                    'ingredient_item_id' => 3011,
                    'source_recipe_line_id' => 2,
                    'line_type' => 'ingredient',
                    'required_qty_base' => '1.000000',
                    'unit_cost' => '3.000000',
                    'total_cost' => '3.000000',
                ]),
            ],
            'has_recipe' => true,
        ]);

        $result = $service->recordRecipeConsumption(self::$conn, $explosion, $this->orderContext(7011, 81));
        $balanceA = $balances->findBalance(self::$conn, 0, 0, 0, 3011);
        $balanceB = $balances->findBalance(self::$conn, 0, 0, 0, 3012);
        $movements = $this->rows('inventory_movements', 'item_id IN (3011, 3012)');

        $this->assertCount(2, $result->movementIds);
        $this->assertSame('9.000000', $balanceA['qty_on_hand']);
        $this->assertSame('8.000000', $balanceB['qty_on_hand']);
        $movementItemIds = array_map('intval', array_column($movements, 'item_id'));
        sort($movementItemIds, SORT_NUMERIC);
        $this->assertSame([3011, 3012], $movementItemIds);
    }

    public function testWasteMovementReducesOnHandIdempotently(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'item_id' => 3006,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '1.000000',
            'qty_available' => '9.000000',
        ]);
        $service = new RecipeInventoryMovementService($this->consumptionFlags());

        $first = $service->recordWaste(self::$conn, [
            'item_id' => 3006,
            'qty' => '2.000000',
            'unit_cost' => '3.500000',
            'waste_uuid' => '00000000-0000-4000-8000-000000003006',
            'created_by' => 77,
        ]);
        $second = $service->recordWaste(self::$conn, [
            'item_id' => 3006,
            'qty' => '2.000000',
            'unit_cost' => '3.500000',
            'waste_uuid' => '00000000-0000-4000-8000-000000003006',
            'created_by' => 77,
        ]);
        $balance = $balances->findBalance(self::$conn, 0, 0, 0, 3006);
        $movement = $this->rows('inventory_movements', "item_id = 3006 AND movement_type = 'waste'")[0];

        $this->assertSame($first->movementIds, $second->movementIds);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertSame('1.000000', $balance['qty_reserved']);
        $this->assertSame('7.000000', $balance['qty_available']);
        $this->assertSame('2.000000', $movement['qty_out']);
        $this->assertSame('7.000000', $movement['total_cost']);
    }

    public function testDisabledMovementServicesReturnNoop(): void
    {
        $explosion = $this->explosion(1004, 5004, 1, 3004, '1.000000');
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]);

        $reservation = (new RecipeReservationService($flags))->reserveExplosion(self::$conn, $explosion, $this->orderContext(7004, 74));
        $consumption = (new RecipeInventoryMovementService($flags))->recordRecipeConsumption(self::$conn, $explosion, $this->orderContext(7004, 74));

        $this->assertTrue($reservation->noop);
        $this->assertTrue($consumption->noop);
    }

    private function explosion(int $sellableItemId, int $recipeId, int $recipeVersion, int $ingredientId, string $qty): RecipeExplosionResult
    {
        return new RecipeExplosionResult([
            'sellable_item_id' => $sellableItemId,
            'recipe_id' => $recipeId,
            'recipe_version' => $recipeVersion,
            'requirements' => [
                new IngredientRequirement([
                    'ingredient_item_id' => $ingredientId,
                    'source_recipe_line_id' => 1,
                    'line_type' => 'ingredient',
                    'required_qty_base' => $qty,
                    'unit_cost' => '4.000000',
                    'total_cost' => RecipeDecimal::multiply($qty, '4.000000'),
                ]),
            ],
            'has_recipe' => true,
        ]);
    }

    private function orderContext(int $orderId, int $lineId, int $storeId = 0): array
    {
        return [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => $storeId,
            'order_id' => $orderId,
            'fat_detail_id' => $lineId,
            'recipe_order_line_usage_id' => $orderId + $lineId,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'created_by' => 77,
        ];
    }

    private function reservationFlags(array $overrides = []): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
            'recipe' => array_replace_recursive([
                'enabled' => true,
                'mode' => 'reserve_only',
                'reservations' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ], $overrides),
        ]);
    }

    private function consumptionFlags(): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
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
        ]);
    }

    private function rows(string $table, string $where = '1=1'): array
    {
        $result = self::$conn->query("SELECT * FROM {$table} WHERE {$where} ORDER BY id");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }
}
