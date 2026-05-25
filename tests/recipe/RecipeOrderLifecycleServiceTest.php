<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../../classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

class RecipeOrderLifecycleServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 32000;

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
        self::$dbName = 'posmain_recipe_lifecycle_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE item_group (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                gname VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query("INSERT INTO item_group (id, gname) VALUES (7, 'PHPUnit Recipes')");
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                uuid CHAR(36) NULL,
                iname VARCHAR(255) NULL,
                name2 VARCHAR(255) NULL,
                code INT NULL,
                barcode VARCHAR(191) NULL,
                info TEXT NULL,
                itmqty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                market_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price2 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                price3 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                group1 BIGINT UNSIGNED NOT NULL DEFAULT 7,
                group2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
                item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
                track_stock TINYINT(1) NOT NULL DEFAULT 1,
                manual_price_edit TINYINT(1) NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0,
                user BIGINT UNSIGNED NULL,
                crtime DATETIME NULL,
                mdtime DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query("
            CREATE TABLE fat_details (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                fatid BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
                det_store BIGINT UNSIGNED NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createJournalTables();
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

    public function testReserveOnlyLineAddAndCancelFlowUsesLifecycleFacade(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->reserveOnlyService();
        $ctx = $this->lineContext(9001, 91, $setup['sellable_item_id'], '3.000000');

        $added = $service->onOrderLineAdded($ctx);
        $balanceAfterReserve = $this->balance($setup['ingredient_item_id']);
        $cancelled = $service->onOrderLineCancelled($ctx, 'cashier_cancelled');
        $balanceAfterCancel = $this->balance($setup['ingredient_item_id']);
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9001')[0];
        $reservation = $this->rows('stock_reservations', 'order_id = 9001')[0];

        $this->assertFalse($added['noop']);
        $this->assertCount(1, $added['writes']['recipe_order_line_usage']);
        $this->assertCount(1, $added['writes']['stock_reservations']);
        $this->assertSame('10.000000', $balanceAfterReserve['qty_on_hand']);
        $this->assertSame('3.000000', $balanceAfterReserve['qty_reserved']);
        $this->assertFalse($cancelled['noop']);
        $this->assertSame('0.000000', $balanceAfterCancel['qty_reserved']);
        $this->assertSame('released', $usage['status']);
        $this->assertSame('released', $reservation['status']);
    }

    public function testPaidOrderConsumesReservedStockOnce(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $ctx = $this->lineContext(9002, 92, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);

        $paid = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9002,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 92,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $paidAgain = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9002,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 92,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $balance = $this->balance($setup['ingredient_item_id']);
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = 9002'), 'movement_type');
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9002')[0];
        $reservation = $this->rows('stock_reservations', 'order_id = 9002')[0];

        $this->assertFalse($paid['noop']);
        $this->assertSame($paid['writes']['inventory_movements'], $paidAgain['writes']['inventory_movements']);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertSame('0.000000', $balance['qty_reserved']);
        $this->assertContains('reservation', $movementTypes);
        $this->assertContains('recipe_consumption', $movementTypes);
        $this->assertSame('consumed', $usage['status']);
        $this->assertSame('consumed', $reservation['status']);
    }

    public function testBatchPreparedPaidOrderConsumesPreparedStockNotRawInputs(): void
    {
        $setup = $this->batchPreparedRecipe('5.000000', '100.000000');
        $paid = $this->accountingService()->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9060,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
            'prepared_inventory_account_id' => 130,
            'user_id' => 77,
            'lines' => [
                [
                    'fat_detail_id' => 960,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $preparedBalance = $this->balance($setup['sellable_item_id']);
        $rawBalance = $this->balance($setup['ingredient_item_id']);
        $movements = $this->rows('inventory_movements', "order_id = 9060 AND movement_type = 'recipe_consumption'");
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9060')[0];
        $explosion = json_decode((string) $usage['explosion_json'], true);
        $journalId = $paid['writes']['accounting_journals'][0] ?? 0;
        $entries = $this->rows('journal_entries', 'journal_id = ' . (int) $journalId);

        $this->assertFalse($paid['noop']);
        $this->assertSame('3.000000', $preparedBalance['qty_on_hand']);
        $this->assertSame('100.000000', $rawBalance['qty_on_hand']);
        $this->assertCount(1, $movements);
        $this->assertSame($setup['sellable_item_id'], (int) $movements[0]['item_id']);
        $this->assertSame('2.000000', $movements[0]['qty_out']);
        $this->assertSame('4.000000', $movements[0]['unit_cost']);
        $this->assertSame('8.000000', $movements[0]['total_cost']);
        $this->assertSame('prepared_stock', $explosion['requirements'][0]['line_type'] ?? null);
        $this->assertSame($setup['sellable_item_id'], (int) ($explosion['requirements'][0]['ingredient_item_id'] ?? 0));
        $this->assertSame(510, (int) $entries[0]['account_id']);
        $this->assertSame('8.0000', $entries[0]['debit']);
        $this->assertSame(130, (int) $entries[1]['account_id']);
        $this->assertSame('8.0000', $entries[1]['credit']);

        $refunded = $this->accountingService()->onOrderRefunded([
            'conn' => self::$conn,
            'order_id' => 9060,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
            'prepared_inventory_account_id' => 130,
            'lines' => [
                [
                    'fat_detail_id' => 960,
                ],
            ],
        ], [
            'policy' => 'return_to_stock',
            'refund_uuid' => '00000000-0000-4000-8000-000000009060',
            'user_id' => 77,
        ]);
        $refundJournalId = $refunded['writes']['accounting_journals'][0] ?? 0;
        $refundEntries = $this->rows('journal_entries', 'journal_id = ' . (int) $refundJournalId);
        $preparedAfterRefund = $this->balance($setup['sellable_item_id']);
        $rawAfterRefund = $this->balance($setup['ingredient_item_id']);

        $this->assertFalse($refunded['noop']);
        $this->assertSame('5.000000', $preparedAfterRefund['qty_on_hand']);
        $this->assertSame('100.000000', $rawAfterRefund['qty_on_hand']);
        $this->assertSame(130, (int) $refundEntries[0]['account_id']);
        $this->assertSame('8.0000', $refundEntries[0]['debit']);
        $this->assertSame(510, (int) $refundEntries[1]['account_id']);
        $this->assertSame('8.0000', $refundEntries[1]['credit']);
    }

    public function testLineUpdateReplacesReservationQuantityForSameDetailId(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->reserveOnlyService();
        $oldCtx = $this->lineContext(9048, 948, $setup['sellable_item_id'], '3.000000');
        $newCtx = $this->lineContext(9048, 948, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($oldCtx);

        $updated = $service->onOrderLineUpdated($oldCtx, $newCtx);
        $balance = $this->balance($setup['ingredient_item_id']);
        $usages = $this->rows('recipe_order_line_usage', 'order_id = 9048');
        $reservations = $this->rows('stock_reservations', 'order_id = 9048');

        $this->assertFalse($updated['noop']);
        $this->assertSame('2.000000', $balance['qty_reserved']);
        $this->assertCount(2, $usages);
        $this->assertSame(['released', 'reserved'], array_column($usages, 'status'));
        $this->assertSame(['3.000000', '2.000000'], array_column($usages, 'order_qty'));
        $this->assertCount(2, $reservations);
        $this->assertSame(['released', 'reserved'], array_column($reservations, 'status'));
        $this->assertSame(['3.000000', '2.000000'], array_column($reservations, 'qty_reserved'));
    }

    public function testOrderMergedTransfersReservationThroughLifecycleFacade(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $sourceCtx = $this->lineContext(9051, 951, $setup['sellable_item_id'], '2.000000');
        $sourceCtx['channel'] = 'table';
        $sourceCtx['order_type'] = 'dine_in';
        $destinationCtx = $sourceCtx;
        $destinationCtx['order_id'] = 9052;

        $service->onOrderLineAdded($sourceCtx);
        $merged = $service->onOrderMerged([
            'conn' => self::$conn,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'reason' => 'table_merged',
            'source_lines' => [$sourceCtx],
            'destination_lines' => [$destinationCtx],
        ]);
        $balanceAfterMerge = $this->balance($setup['ingredient_item_id']);
        $sourceUsages = $this->rows('recipe_order_line_usage', 'order_id = 9051');
        $destinationUsages = $this->rows('recipe_order_line_usage', 'order_id = 9052');
        $sourceReservations = $this->rows('stock_reservations', 'order_id = 9051');
        $destinationReservations = $this->rows('stock_reservations', 'order_id = 9052');

        $this->assertSame('order_merged', $merged['action']);
        $this->assertFalse($merged['noop']);
        $this->assertSame(['released'], array_column($sourceUsages, 'status'));
        $this->assertSame(['reserved'], array_column($destinationUsages, 'status'));
        $this->assertSame(['released'], array_column($sourceReservations, 'status'));
        $this->assertSame(['reserved'], array_column($destinationReservations, 'status'));
        $this->assertSame('10.000000', $balanceAfterMerge['qty_on_hand']);
        $this->assertSame('2.000000', $balanceAfterMerge['qty_reserved']);

        $paid = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9052,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'lines' => [
                [
                    'fat_detail_id' => 951,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $balanceAfterPayment = $this->balance($setup['ingredient_item_id']);

        $this->assertFalse($paid['noop']);
        $this->assertSame('8.000000', $balanceAfterPayment['qty_on_hand']);
        $this->assertSame('0.000000', $balanceAfterPayment['qty_reserved']);
        $this->assertCount(1, $this->rows('inventory_movements', "order_id = 9052 AND movement_type = 'recipe_consumption'"));
    }

    public function testOrderSplitRebuildsOriginalReservationAndConsumesPaidChildThroughLifecycleFacade(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $oldSourceCtx = $this->lineContext(9053, 953, $setup['sellable_item_id'], '3.000000');
        $oldSourceCtx['channel'] = 'table';
        $oldSourceCtx['order_type'] = 'dine_in';
        $remainingSourceCtx = $oldSourceCtx;
        $remainingSourceCtx['quantity'] = '2.000000';
        $paidChildCtx = $this->lineContext(9054, 954, $setup['sellable_item_id'], '1.000000');
        $paidChildCtx['channel'] = 'table';
        $paidChildCtx['order_type'] = 'dine_in';

        $service->onOrderLineAdded($oldSourceCtx);
        $split = $service->onOrderSplit([
            'conn' => self::$conn,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'reason' => 'split_payment',
            'source_lines' => [$oldSourceCtx],
            'remaining_lines' => [$remainingSourceCtx],
            'paid_order_id' => 9054,
            'paid_lines' => [$paidChildCtx],
        ]);
        $balanceAfterSplit = $this->balance($setup['ingredient_item_id']);
        $sourceUsages = $this->rows('recipe_order_line_usage', 'order_id = 9053');
        $childUsages = $this->rows('recipe_order_line_usage', 'order_id = 9054');
        $sourceReservations = $this->rows('stock_reservations', 'order_id = 9053');
        $childConsumption = $this->rows('inventory_movements', "order_id = 9054 AND movement_type = 'recipe_consumption'");

        $this->assertSame('order_split', $split['action']);
        $this->assertFalse($split['noop']);
        $this->assertSame(['released', 'reserved'], array_column($sourceUsages, 'status'));
        $this->assertSame(['3.000000', '2.000000'], array_column($sourceUsages, 'order_qty'));
        $this->assertSame(['consumed'], array_column($childUsages, 'status'));
        $this->assertSame(['released', 'reserved'], array_column($sourceReservations, 'status'));
        $this->assertCount(1, $childConsumption);
        $this->assertSame('9.000000', $balanceAfterSplit['qty_on_hand']);
        $this->assertSame('2.000000', $balanceAfterSplit['qty_reserved']);

        $paidOriginal = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9053,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'lines' => [
                [
                    'fat_detail_id' => 953,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $balanceAfterFinalPayment = $this->balance($setup['ingredient_item_id']);
        $totalConsumed = self::$conn->query("
            SELECT COALESCE(SUM(qty_out), 0) AS qty
            FROM inventory_movements
            WHERE item_id = {$setup['ingredient_item_id']}
              AND movement_type = 'recipe_consumption'
              AND order_id IN (9053, 9054)
        ")->fetch_assoc();

        $this->assertFalse($paidOriginal['noop']);
        $this->assertSame('7.000000', $balanceAfterFinalPayment['qty_on_hand']);
        $this->assertSame('0.000000', $balanceAfterFinalPayment['qty_reserved']);
        $this->assertSame('3.000000', RecipeDecimal::normalize($totalConsumed['qty'] ?? '0'));
    }

    public function testDirectPaidLineDoesNotConsumeAnotherOrderReservation(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $reservedCtx = $this->lineContext(9049, 949, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($reservedCtx);

        $paid = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9050,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 950,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        $balanceAfterDirectPaid = $this->balance($setup['ingredient_item_id']);

        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9049,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'lines' => [
                [
                    'fat_detail_id' => 949,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $balanceAfterReservedPaid = $this->balance($setup['ingredient_item_id']);

        $this->assertFalse($paid['noop']);
        $this->assertSame('9.000000', $balanceAfterDirectPaid['qty_on_hand']);
        $this->assertSame('2.000000', $balanceAfterDirectPaid['qty_reserved']);
        $this->assertSame('7.000000', $balanceAfterReservedPaid['qty_on_hand']);
        $this->assertSame('0.000000', $balanceAfterReservedPaid['qty_reserved']);
    }

    public function testStrictAvailabilityBlocksOversoldReservationBeforeUsageWrites(): void
    {
        $setup = $this->recipeWithIngredient('1.000000');
        $service = $this->strictAvailabilityService();
        $blocked = false;

        try {
            $service->onOrderLineAdded($this->lineContext(9018, 918, $setup['sellable_item_id'], '2.000000'));
        } catch (RuntimeException $exception) {
            $blocked = true;
            $this->assertStringContainsString('Only 1.000000 can be made', $exception->getMessage());
        }

        $this->assertTrue($blocked, 'strict stock should block order lines above computed recipe availability');
        $this->assertSame([], $this->rows('recipe_order_line_usage', 'order_id = 9018'));
        $this->assertSame([], $this->rows('stock_reservations', 'order_id = 9018'));
        $this->assertSame('1.000000', $this->balance($setup['ingredient_item_id'])['qty_on_hand']);
    }

    public function testStrictAvailabilityBlocksDirectPaidOrderBeforeConsumptionWrites(): void
    {
        $setup = $this->recipeWithIngredient('1.000000');
        $service = $this->strictAvailabilityService();
        $blocked = false;

        try {
            $service->onOrderPaid([
                'conn' => self::$conn,
                'order_id' => 9019,
                'pos_tenant' => 0,
                'pos_branch' => 0,
                'store_id' => 0,
                'channel' => 'pos',
                'order_type' => 'takeaway',
                'lines' => [
                    [
                        'fat_detail_id' => 919,
                        'sellable_item_id' => $setup['sellable_item_id'],
                        'quantity' => '2.000000',
                    ],
                ],
            ]);
        } catch (RuntimeException $exception) {
            $blocked = true;
            $this->assertStringContainsString('Only 1.000000 can be made', $exception->getMessage());
        }

        $this->assertTrue($blocked, 'strict stock should block direct payment above computed recipe availability');
        $this->assertSame([], $this->rows('recipe_order_line_usage', 'order_id = 9019'));
        $this->assertSame([], $this->rows('inventory_movements', 'order_id = 9019'));
        $this->assertSame('1.000000', $this->balance($setup['ingredient_item_id'])['qty_on_hand']);
    }

    public function testLifecycleRefreshesAvailabilityCacheAfterReservationReleaseAndConsumption(): void
    {
        $setup = $this->recipeWithIngredient('5.000000');
        $service = $this->availabilityLifecycleService();
        $ctx = $this->lineContext(9020, 920, $setup['sellable_item_id'], '2.000000');

        $service->onOrderLineAdded($ctx);
        $reservedCache = $this->availabilityCache($setup['sellable_item_id']);
        $service->onOrderLineCancelled($ctx, 'cashier_cancelled');
        $releasedCache = $this->availabilityCache($setup['sellable_item_id']);

        $payCtx = $this->lineContext(9021, 921, $setup['sellable_item_id'], '2.000000');
        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9021,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => $payCtx['fat_detail_id'],
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $paidCache = $this->availabilityCache($setup['sellable_item_id']);

        $this->assertSame('3.000000', $reservedCache['effective_available_qty']);
        $this->assertSame('5.000000', $releasedCache['effective_available_qty']);
        $this->assertSame('3.000000', $paidCache['effective_available_qty']);
        $this->assertGreaterThan((int) $reservedCache['availability_revision'], (int) $releasedCache['availability_revision']);
        $this->assertGreaterThan((int) $releasedCache['availability_revision'], (int) $paidCache['availability_revision']);
    }

    public function testLifecycleAvailabilityRefreshEnqueuesMoovaMenuSnapshotWhenRecipeSyncEnabled(): void
    {
        $setup = $this->recipeWithIngredient('5.000000');
        $service = $this->moovaAvailabilitySyncLifecycleService();

        $service->onOrderLineAdded($this->lineContext(9022, 922, $setup['sellable_item_id'], '2.000000'));

        $moovaCache = $this->availabilityCache($setup['sellable_item_id'], 'delivery', 'moova');
        $outbox = $this->latestMenuAvailabilityOutbox($setup['sellable_item_id']);
        $payload = json_decode((string) $outbox['payload_json'], true);
        $menuItem = $payload['menu_item'] ?? [];
        $recipe = $menuItem['recipe_availability'] ?? [];

        $this->assertSame('3.000000', $moovaCache['effective_available_qty']);
        $this->assertSame('menu.item_availability_changed', $outbox['event_type']);
        $this->assertSame('recipe_lifecycle', $outbox['source_system']);
        $this->assertSame('3.000000', $recipe['effective_available_qty']);
        $this->assertTrue($recipe['effective_is_available']);
        $this->assertArrayNotHasKey('cost_price', $recipe);
        $this->assertArrayNotHasKey('cost', $recipe);
        $this->assertArrayNotHasKey('internal_cost_per_sell_unit', $recipe);
    }

    public function testExternalSourceIdentityIsStoredButLocalDetailKeepsPaymentIdempotency(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $ctx = $this->lineContext(9010, 910, $setup['sellable_item_id'], '1.000000');
        $ctx['channel'] = 'moova';
        $ctx['order_type'] = 'delivery';
        $ctx['source_order_uuid'] = 'moova-order-9010';
        $ctx['source_line_uuid'] = 'moova_pos_order_lines:77';
        $service->onOrderLineAdded($ctx);

        $paid = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9010,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'lines' => [
                [
                    'fat_detail_id' => 910,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '1.000000',
                ],
            ],
        ]);

        $usageRows = $this->rows('recipe_order_line_usage', 'order_id = 9010');
        $this->assertFalse($paid['noop']);
        $this->assertCount(1, $usageRows);
        $this->assertSame('moova-order-9010', $usageRows[0]['source_order_uuid']);
        $this->assertSame('moova_pos_order_lines:77', $usageRows[0]['source_line_uuid']);
        $this->assertSame('consumed', $usageRows[0]['status']);
    }

    public function testLegacyInvoiceBridgeLoadsFatDetailsAndPaysThroughLifecycle(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $orderId = 9011;
        $stmt = self::$conn->prepare("
            INSERT INTO fat_details (fatid, item_id, qty_in, qty_out, u_val, det_store)
            VALUES (?, ?, 0, 4.000000, 2.000000, 0)
        ");
        $stmt->bind_param('ii', $orderId, $setup['sellable_item_id']);
        $stmt->execute();
        $detailId = (int) self::$conn->insert_id;
        $stmt->close();

        $bridge = new LegacyInvoiceRecipeLifecycleBridge($this->consumeService());
        $lines = $bridge->currentLineContexts(self::$conn, $orderId, 'table', 'dine_in');
        $bridge->recordCurrentLinesAdded(self::$conn, $orderId, 'table', 'dine_in');
        $paid = $bridge->recordCurrentOrderPaid(self::$conn, $orderId, 'table', 'dine_in');

        $usage = $this->rows('recipe_order_line_usage', 'order_id = ' . $orderId)[0];
        $balance = $this->balance($setup['ingredient_item_id']);

        $this->assertCount(1, $lines);
        $this->assertSame($detailId, (int) $lines[0]['fat_detail_id']);
        $this->assertSame('2.000000', $lines[0]['quantity']);
        $this->assertFalse($paid['noop']);
        $this->assertSame('consumed', $usage['status']);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
    }

    public function testLegacyInvoiceBridgeKeepsQuantitiesAsDecimals(): void
    {
        $orderId = 90112;
        $stmt = self::$conn->prepare("
            INSERT INTO fat_details (fatid, item_id, qty_in, qty_out, u_val, det_store)
            VALUES (?, 40101, 0, 2.000000, 3.000000, 0)
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $stmt->close();

        $bridge = new LegacyInvoiceRecipeLifecycleBridge(new RecipeLifecycleBridgeSpy());
        $lines = $bridge->currentLineContexts(self::$conn, $orderId, 'table', 'dine_in');
        $externalLines = $bridge->externalLineContexts(self::$conn, 90113, 'pos', 'takeaway', 'external-order-90113', [[
            'item_id' => 40101,
            'qty' => '0.3333334',
            'fat_detail_id' => 50113,
        ]]);

        $this->assertSame('0.666667', $lines[0]['quantity']);
        $this->assertSame('0.666667', $lines[0]['qty']);
        $this->assertSame('0.333333', $externalLines[0]['quantity']);
    }

    public function testLegacyInvoiceBridgePreservesCofeExternalModifierLines(): void
    {
        $spy = new RecipeLifecycleBridgeSpy();
        $bridge = new LegacyInvoiceRecipeLifecycleBridge($spy);
        $externalLines = [
            [
                'item_id' => 40101,
                'qty' => 1,
                'fat_detail_id' => 50101,
                'det_store' => 7,
                'source_line_index' => 0,
                'source_line' => [
                    'externalLineId' => 'cofe-line-plain',
                    'itemId' => 'cofe-sku-40101',
                    'qty' => 1,
                    'modifiers' => [
                        ['option_id' => 10, 'qty' => 1],
                    ],
                ],
            ],
            [
                'item_id' => 40101,
                'qty' => 1,
                'fat_detail_id' => 50102,
                'det_store' => 7,
                'source_line_index' => 1,
                'source_line' => [
                    'externalLineId' => 'cofe-line-extra',
                    'itemId' => 'cofe-sku-40101',
                    'qty' => 1,
                    'modifiers' => [
                        ['option_id' => 11, 'qty' => 1],
                    ],
                ],
            ],
        ];

        $bridge->recordExternalLinesAdded(self::$conn, 9013, 'cofe', 'dine_in', 'cofe-order-9013', $externalLines, [
            'store_id' => 7,
            'branch_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);
        $bridge->recordExternalOrderPaid(self::$conn, 9013, 'cofe', 'dine_in', 'cofe-order-9013', $externalLines, [
            'store_id' => 7,
            'branch_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);

        $maps = self::$conn->query("
            SELECT source_channel, external_line_id, order_id, fat_detail_id, item_id,
                   modifiers_hash, modifiers_json, line_status, branch_uuid
            FROM external_order_line_map
            WHERE external_order_id = 'cofe-order-9013'
            ORDER BY external_line_id
        ")->fetch_all(MYSQLI_ASSOC);

        $this->assertCount(2, $spy->added);
        $this->assertCount(1, $spy->paid);
        $this->assertSame(['cofe:cofe-line-plain', 'cofe:cofe-line-extra'], array_column($spy->added, 'source_line_uuid'));
        $this->assertSame(['1.000000', '1.000000'], array_column($spy->added, 'quantity'));
        $this->assertSame(10, (int) $spy->added[0]['modifiers'][0]['option_id']);
        $this->assertSame(11, (int) $spy->added[1]['modifiers'][0]['option_id']);
        $this->assertSame('cofe-order-9013', $spy->paid[0]['source_order_uuid']);
        $this->assertCount(2, $spy->paid[0]['lines']);
        $this->assertCount(2, $maps);
        $this->assertSame(['cofe-line-extra', 'cofe-line-plain'], array_column($maps, 'external_line_id'));
        foreach ($maps as $map) {
            $this->assertSame('cofe', $map['source_channel']);
            $this->assertSame(9013, (int) $map['order_id']);
            $this->assertSame(40101, (int) $map['item_id']);
            $this->assertSame('active', $map['line_status']);
            $this->assertNotSame('', (string) $map['modifiers_hash']);
            $this->assertIsArray(json_decode((string) $map['modifiers_json'], true));
            $this->assertSame('11111111-1111-4111-8111-111111111111', $map['branch_uuid']);
        }
        $this->assertNotSame($maps[0]['modifiers_hash'], $maps[1]['modifiers_hash']);
    }

    public function testLegacyInvoiceBridgeCofeReplayConsumesExternalLineOnce(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'reservations' => true,
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);
        $bridge = new LegacyInvoiceRecipeLifecycleBridge(new RecipeOrderLifecycleService($flags), $flags);
        $externalLines = [
            [
                'item_id' => $setup['sellable_item_id'],
                'qty' => '2.000000',
                'fat_detail_id' => 90301,
                'det_store' => 0,
                'source_line_index' => 0,
                'source_line' => [
                    'externalLineId' => 'cofe-replay-line-1',
                    'itemId' => (string) $setup['sellable_item_id'],
                    'qty' => '2.000000',
                    'modifiers' => [
                        ['option_id' => 10, 'qty' => 1],
                    ],
                ],
            ],
        ];
        $context = [
            'store_id' => 0,
            'branch_uuid' => '22222222-2222-4222-8222-222222222222',
        ];

        $bridge->recordExternalLinesAdded(self::$conn, 9030, 'cofe', 'dine_in', 'cofe-order-replay-9030', $externalLines, $context);
        $paid = $bridge->recordExternalOrderPaid(self::$conn, 9030, 'cofe', 'dine_in', 'cofe-order-replay-9030', $externalLines, $context);
        $bridge->recordExternalLinesAdded(self::$conn, 9030, 'cofe', 'dine_in', 'cofe-order-replay-9030', $externalLines, $context);
        $paidAgain = $bridge->recordExternalOrderPaid(self::$conn, 9030, 'cofe', 'dine_in', 'cofe-order-replay-9030', $externalLines, $context);

        $usageRows = $this->rows('recipe_order_line_usage', 'order_id = 9030');
        $consumptionRows = $this->rows('inventory_movements', "order_id = 9030 AND movement_type = 'recipe_consumption'");
        $maps = self::$conn->query("
            SELECT external_line_id, source_channel, line_status
            FROM external_order_line_map
            WHERE external_order_id = 'cofe-order-replay-9030'
            ORDER BY id
        ")->fetch_all(MYSQLI_ASSOC);
        $balance = $this->balance($setup['ingredient_item_id']);

        $this->assertFalse($paid['noop']);
        $this->assertSame($paid['writes']['inventory_movements'], $paidAgain['writes']['inventory_movements']);
        $this->assertCount(1, $usageRows);
        $this->assertSame('consumed', $usageRows[0]['status']);
        $this->assertSame('cofe:cofe-replay-line-1', $usageRows[0]['source_line_uuid']);
        $this->assertCount(1, $consumptionRows);
        $this->assertCount(1, $maps);
        $this->assertSame('cofe-replay-line-1', $maps[0]['external_line_id']);
        $this->assertSame('cofe', $maps[0]['source_channel']);
        $this->assertSame('active', $maps[0]['line_status']);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
    }

    public function testLegacyInvoiceEditGuardBlocksConsumedRecipeUsageOnlyWhenEnabled(): void
    {
        $usageRepo = new RecipeOrderLineUsageRepository();
        $usageRepo->createUsage(self::$conn, [
            'usage_uuid' => '00000000-0000-4000-8000-000000009014',
            'order_id' => 9014,
            'fat_detail_id' => 914,
            'sellable_item_id' => $this->item('Guard item', '0.000000'),
            'order_qty' => '1.000000',
            'status' => 'reserved',
            'idempotency_key' => 'guard-reserved',
        ]);
        $usageRepo->createUsage(self::$conn, [
            'usage_uuid' => '00000000-0000-4000-8000-000000009015',
            'order_id' => 9015,
            'fat_detail_id' => 915,
            'sellable_item_id' => $this->item('Guard consumed item', '0.000000'),
            'order_qty' => '1.000000',
            'status' => 'consumed',
            'idempotency_key' => 'guard-consumed',
        ]);

        $enabledBridge = new LegacyInvoiceRecipeLifecycleBridge(null, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
            ],
        ]));
        $disabledBridge = new LegacyInvoiceRecipeLifecycleBridge(null, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]));

        $enabledBridge->assertLegacyEditAllowed(self::$conn, 9014);
        $disabledBridge->assertLegacyEditAllowed(self::$conn, 9015);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipe stock was already consumed');
        $enabledBridge->assertLegacyEditAllowed(self::$conn, 9015);
    }

    public function testLegacyInvoiceDeleteReleasesUnpaidRecipeReservations(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $orderId = 9016;
        $stmt = self::$conn->prepare("
            INSERT INTO fat_details (fatid, item_id, qty_in, qty_out, u_val, det_store)
            VALUES (?, ?, 0, 2.000000, 1.000000, 0)
        ");
        $stmt->bind_param('ii', $orderId, $setup['sellable_item_id']);
        $stmt->execute();
        $stmt->close();

        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'reserve_only',
                'reservations' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);
        $bridge = new LegacyInvoiceRecipeLifecycleBridge(new RecipeOrderLifecycleService($flags), $flags);
        $bridge->recordCurrentLinesAdded(self::$conn, $orderId, 'table', 'dine_in');
        $deleted = $bridge->recordCurrentOrderDeleted(self::$conn, $orderId, 'table', 'dine_in', [
            'user_id' => 77,
        ]);

        $usage = $this->rows('recipe_order_line_usage', 'order_id = ' . $orderId)[0];
        $reservation = $this->rows('stock_reservations', 'order_id = ' . $orderId)[0];
        $balance = $this->balance($setup['ingredient_item_id']);

        $this->assertIsArray($deleted);
        $this->assertSame('released', $usage['status']);
        $this->assertSame('released', $reservation['status']);
        $this->assertSame('0.000000', $balance['qty_reserved']);
        $this->assertSame('10.000000', $balance['qty_on_hand']);
    }

    public function testLegacyInvoiceDeleteReleasesPreviewedRecipeUsage(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $orderId = 9024;
        $stmt = self::$conn->prepare("
            INSERT INTO fat_details (fatid, item_id, qty_in, qty_out, u_val, det_store)
            VALUES (?, ?, 0, 1.000000, 1.000000, 0)
        ");
        $stmt->bind_param('ii', $orderId, $setup['sellable_item_id']);
        $stmt->execute();
        $stmt->close();

        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]);
        $bridge = new LegacyInvoiceRecipeLifecycleBridge(new RecipeOrderLifecycleService($flags), $flags);
        $bridge->recordCurrentLinesAdded(self::$conn, $orderId, 'pos', 'takeaway');
        $usageBefore = $this->rows('recipe_order_line_usage', 'order_id = ' . $orderId)[0];

        $deleted = $bridge->recordCurrentOrderDeleted(self::$conn, $orderId, 'pos', 'takeaway', [
            'user_id' => 77,
        ]);

        $usageAfter = $this->rows('recipe_order_line_usage', 'order_id = ' . $orderId)[0];
        $this->assertSame('previewed', $usageBefore['status']);
        $this->assertIsArray($deleted);
        $this->assertSame('released', $usageAfter['status']);
    }

    public function testLegacyInvoiceDeleteVoidsConsumedRecipeUsageWithoutReturningStockByDefault(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $orderId = 9017;
        $stmt = self::$conn->prepare("
            INSERT INTO fat_details (fatid, item_id, qty_in, qty_out, u_val, det_store)
            VALUES (?, ?, 0, 2.000000, 1.000000, 0)
        ");
        $stmt->bind_param('ii', $orderId, $setup['sellable_item_id']);
        $stmt->execute();
        $stmt->close();

        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'reservations' => true,
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);
        $settings = new RecipeSettingsService([
            'recipe' => [
                'refund_stock_policy' => 'waste',
            ],
        ]);
        $bridge = new LegacyInvoiceRecipeLifecycleBridge(new RecipeOrderLifecycleService($flags), $flags, $settings);
        $bridge->recordCurrentLinesAdded(self::$conn, $orderId, 'pos', 'takeaway');
        $bridge->recordCurrentOrderPaid(self::$conn, $orderId, 'pos', 'takeaway');
        $deleted = $bridge->recordCurrentOrderDeleted(self::$conn, $orderId, 'pos', 'takeaway', [
            'user_id' => 77,
        ]);

        $usage = $this->rows('recipe_order_line_usage', 'order_id = ' . $orderId)[0];
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = ' . $orderId), 'movement_type');
        $balance = $this->balance($setup['ingredient_item_id']);

        $this->assertIsArray($deleted);
        $this->assertSame('voided', $usage['status']);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertNotContains('refund_reversal', $movementTypes);
        $this->assertNotEmpty($deleted['warnings']);
    }

    public function testLegacyInvoiceRefundUsesManagerChoiceReturnToStockPolicy(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $orderId = 9025;
        $stmt = self::$conn->prepare("
            INSERT INTO fat_details (fatid, item_id, qty_in, qty_out, u_val, det_store)
            VALUES (?, ?, 0, 2.000000, 1.000000, 0)
        ");
        $stmt->bind_param('ii', $orderId, $setup['sellable_item_id']);
        $stmt->execute();
        $stmt->close();

        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'reservations' => true,
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);
        $settings = new RecipeSettingsService([
            'recipe' => [
                'refund_stock_policy' => 'manager_choice',
            ],
        ]);
        $bridge = new LegacyInvoiceRecipeLifecycleBridge(new RecipeOrderLifecycleService($flags), $flags, $settings);
        $bridge->recordCurrentLinesAdded(self::$conn, $orderId, 'pos', 'takeaway');
        $bridge->recordCurrentOrderPaid(self::$conn, $orderId, 'pos', 'takeaway');

        $refunded = $bridge->recordCurrentOrderRefunded(self::$conn, $orderId, 'pos', 'takeaway', [
            'user_id' => 77,
            'refund_stock_policy' => 'return_to_stock',
            'refund_uuid' => 'legacy-refund-9025',
        ]);

        $usage = $this->rows('recipe_order_line_usage', 'order_id = ' . $orderId)[0];
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = ' . $orderId), 'movement_type');
        $balance = $this->balance($setup['ingredient_item_id']);

        $this->assertIsArray($refunded);
        $this->assertSame('refunded', $usage['status']);
        $this->assertContains('refund_reversal', $movementTypes);
        $this->assertSame('10.000000', $balance['qty_on_hand']);
    }

    public function testPaidOrderConsumesPendingExternalModifierLinesWithoutGenericDuplicate(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $lineA = $this->lineContext(9012, 912, $setup['sellable_item_id'], '1.000000');
        $lineA['channel'] = 'moova';
        $lineA['order_type'] = 'delivery';
        $lineA['source_order_uuid'] = 'moova-order-9012';
        $lineA['source_line_uuid'] = 'moova:provider-line-a';
        $lineA['modifiers'] = [['option_id' => 10, 'qty' => 1]];
        $lineB = $lineA;
        $lineB['source_line_uuid'] = 'moova:provider-line-b';
        $lineB['modifiers'] = [['option_id' => 11, 'qty' => 1]];

        $service->onOrderLineAdded($lineA);
        $service->onOrderLineAdded($lineB);
        $paid = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9012,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'lines' => [
                [
                    'fat_detail_id' => 912,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $paidAgain = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9012,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'table',
            'order_type' => 'dine_in',
            'lines' => [
                [
                    'fat_detail_id' => 912,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);

        $usageRows = $this->rows('recipe_order_line_usage', 'order_id = 9012');
        $consumptionRows = $this->rows('inventory_movements', "order_id = 9012 AND movement_type = 'recipe_consumption'");
        $balance = $this->balance($setup['ingredient_item_id']);

        $this->assertFalse($paid['noop']);
        $this->assertTrue($paidAgain['noop']);
        $this->assertCount(2, $usageRows);
        $this->assertSame(['moova:provider-line-a', 'moova:provider-line-b'], array_column($usageRows, 'source_line_uuid'));
        $this->assertSame(['consumed', 'consumed'], array_column($usageRows, 'status'));
        $this->assertCount(2, $consumptionRows);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
    }

    public function testExternalSourceLineIdentityWinsOverSharedLocalOrderLineUuid(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $lineA = $this->lineContext(9055, 955, $setup['sellable_item_id'], '1.000000');
        $lineA['channel'] = 'moova';
        $lineA['order_type'] = 'delivery';
        $lineA['order_line_uuid'] = '00000000-0000-4000-8000-000000009555';
        $lineA['source_order_uuid'] = 'moova-order-9055';
        $lineA['source_line_uuid'] = 'moova:provider-line-a';
        $lineB = $lineA;
        $lineB['source_line_uuid'] = 'moova:provider-line-b';

        $service->onOrderLineAdded($lineA);
        $service->onOrderLineAdded($lineB);
        $usageRows = $this->rows('recipe_order_line_usage', 'order_id = 9055');

        $this->assertCount(2, $usageRows);
        $this->assertSame(
            ['moova:provider-line-a', 'moova:provider-line-b'],
            array_column($usageRows, 'source_line_uuid')
        );
        $this->assertSame(
            ['00000000-0000-4000-8000-000000009555', '00000000-0000-4000-8000-000000009555'],
            array_column($usageRows, 'order_line_uuid')
        );
        $this->assertNotSame($usageRows[0]['idempotency_key'], $usageRows[1]['idempotency_key']);
    }

    public function testRefundReturnToStockCreatesOneReversalAndRepeatIsNoop(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $ctx = $this->lineContext(9004, 94, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);
        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9004,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 94,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);

        $refund = $service->onOrderRefunded([
            'conn' => self::$conn,
            'order_id' => 9004,
            'lines' => [
                ['fat_detail_id' => 94],
            ],
        ], [
            'policy' => 'return_to_stock',
            'refund_uuid' => '00000000-0000-4000-8000-000000009004',
        ]);
        $refundAgain = $service->onOrderRefunded([
            'conn' => self::$conn,
            'order_id' => 9004,
            'lines' => [
                ['fat_detail_id' => 94],
            ],
        ], [
            'policy' => 'return_to_stock',
            'refund_uuid' => '00000000-0000-4000-8000-000000009004',
        ]);
        $balance = $this->balance($setup['ingredient_item_id']);
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = 9004'), 'movement_type');
        $reversals = $this->rows('inventory_movements', "order_id = 9004 AND movement_type = 'refund_reversal'");
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9004')[0];

        $this->assertFalse($refund['noop']);
        $this->assertTrue($refundAgain['noop']);
        $this->assertSame([], $refundAgain['writes']);
        $this->assertCount(1, $reversals);
        $this->assertSame('10.000000', $balance['qty_on_hand']);
        $this->assertContains('refund_reversal', $movementTypes);
        $this->assertSame('refunded', $usage['status']);
    }

    public function testLaterVoidDoesNotRewriteAlreadyRefundedRecipeUsage(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $ctx = $this->lineContext(9029, 929, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);
        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9029,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 929,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $service->onOrderRefunded([
            'conn' => self::$conn,
            'order_id' => 9029,
            'lines' => [
                ['fat_detail_id' => 929],
            ],
        ], [
            'policy' => 'return_to_stock',
            'refund_uuid' => '00000000-0000-4000-8000-000000009029',
        ]);

        $void = $service->onOrderVoided([
            'conn' => self::$conn,
            'order_id' => 9029,
            'lines' => [
                ['fat_detail_id' => 929],
            ],
        ], [
            'policy' => 'waste',
            'void_uuid' => '00000000-0000-4000-8000-000000009030',
        ]);
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9029')[0];
        $reversals = $this->rows('inventory_movements', "order_id = 9029 AND movement_type = 'refund_reversal'");
        $balance = $this->balance($setup['ingredient_item_id']);

        $this->assertTrue($void['noop']);
        $this->assertSame([], $void['writes']);
        $this->assertSame('refunded', $usage['status']);
        $this->assertCount(1, $reversals);
        $this->assertSame('10.000000', $balance['qty_on_hand']);
    }

    public function testRefundWastePolicyDoesNotReturnStock(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService();
        $ctx = $this->lineContext(9005, 95, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);
        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9005,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 95,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);

        $refund = $service->onOrderRefunded([
            'conn' => self::$conn,
            'order_id' => 9005,
            'lines' => [
                ['fat_detail_id' => 95],
            ],
        ], [
            'policy' => 'waste',
        ]);
        $balance = $this->balance($setup['ingredient_item_id']);
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = 9005'), 'movement_type');
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9005')[0];

        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertNotContains('refund_reversal', $movementTypes);
        $this->assertSame('wasted', $usage['status']);
        $this->assertStringContainsString('does not return', $refund['warnings'][0]);
    }

    public function testRefundUsesConfiguredReturnToStockPolicyWhenCallerOmitsPolicy(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService([
            'refund_stock_policy' => 'return_to_stock',
        ]);
        $ctx = $this->lineContext(9026, 926, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);
        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9026,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 926,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);

        $refund = $service->onOrderRefunded([
            'conn' => self::$conn,
            'order_id' => 9026,
            'lines' => [
                ['fat_detail_id' => 926],
            ],
        ], [
            'refund_uuid' => '00000000-0000-4000-8000-000000009026',
        ]);
        $balance = $this->balance($setup['ingredient_item_id']);
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = 9026'), 'movement_type');
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9026')[0];

        $this->assertFalse($refund['noop']);
        $this->assertSame('10.000000', $balance['qty_on_hand']);
        $this->assertContains('refund_reversal', $movementTypes);
        $this->assertSame('refunded', $usage['status']);
    }

    public function testManagerChoiceRefundWithoutSelectedPolicyFallsBackToWaste(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService([
            'refund_stock_policy' => 'manager_choice',
        ]);
        $ctx = $this->lineContext(9027, 927, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);
        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9027,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 927,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);

        $refund = $service->onOrderRefunded([
            'conn' => self::$conn,
            'order_id' => 9027,
            'lines' => [
                ['fat_detail_id' => 927],
            ],
        ], [
            'refund_uuid' => '00000000-0000-4000-8000-000000009027',
        ]);
        $balance = $this->balance($setup['ingredient_item_id']);
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = 9027'), 'movement_type');
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9027')[0];

        $this->assertFalse($refund['noop']);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertNotContains('refund_reversal', $movementTypes);
        $this->assertSame('wasted', $usage['status']);
    }

    public function testVoidUsesConfiguredWastePolicyWhenCallerOmitsPolicy(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->consumeService([
            'refund_stock_policy' => 'waste',
        ]);
        $ctx = $this->lineContext(9028, 928, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);
        $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9028,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 928,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);

        $void = $service->onOrderVoided([
            'conn' => self::$conn,
            'order_id' => 9028,
            'lines' => [
                ['fat_detail_id' => 928],
            ],
        ], [
            'void_uuid' => '00000000-0000-4000-8000-000000009028',
        ]);
        $balance = $this->balance($setup['ingredient_item_id']);
        $movementTypes = array_column($this->rows('inventory_movements', 'order_id = 9028'), 'movement_type');
        $usage = $this->rows('recipe_order_line_usage', 'order_id = 9028')[0];

        $this->assertFalse($void['noop']);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertNotContains('refund_reversal', $movementTypes);
        $this->assertSame('voided', $usage['status']);
    }

    public function testPaidOrderPostsAccountingWhenAccountingPilotEnabled(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = $this->accountingService();
        $ctx = $this->lineContext(9006, 96, $setup['sellable_item_id'], '2.000000');
        $service->onOrderLineAdded($ctx);

        $paid = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9006,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
            'user_id' => 77,
            'lines' => [
                [
                    'fat_detail_id' => 96,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $paidAgain = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9006,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
            'user_id' => 77,
            'lines' => [
                [
                    'fat_detail_id' => 96,
                    'sellable_item_id' => $setup['sellable_item_id'],
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        $journalId = $paid['writes']['accounting_journals'][0] ?? 0;
        $entries = $this->rows('journal_entries', 'journal_id = ' . (int) $journalId);
        $movements = $this->rows('inventory_movements', "order_id = 9006 AND movement_type = 'recipe_consumption'");

        $this->assertGreaterThan(0, $journalId);
        $this->assertSame($paid['writes']['accounting_journals'], $paidAgain['writes']['accounting_journals']);
        $this->assertCount(2, $entries);
        $this->assertSame(510, (int) $entries[0]['account_id']);
        $this->assertSame('8.0000', $entries[0]['debit']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
        $this->assertSame('8.0000', $entries[1]['credit']);
        $this->assertSame($journalId, (int) $movements[0]['accounting_journal_id']);
    }

    public function testReadOnlyLifecycleStillDoesNotWrite(): void
    {
        $setup = $this->recipeWithIngredient('10.000000');
        $service = new RecipeOrderLifecycleService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'read_only',
            ],
        ]));

        $result = $service->onOrderLineAdded($this->lineContext(9003, 93, $setup['sellable_item_id'], '1.000000'));

        $this->assertTrue($result['noop']);
        $this->assertSame([], $result['writes']);
        $this->assertSame([], $this->rows('recipe_order_line_usage', 'order_id = 9003'));
    }

    public function testConsumePilotCategoryScopeOnlyConsumesMatchingCategory(): void
    {
        $matching = $this->recipeWithIngredient('10.000000', 7);
        $outside = $this->recipeWithIngredient('10.000000', 9);
        $service = $this->consumeService([
            'pilot' => [
                'pos_branch' => '',
                'item_ids' => [],
                'category_ids' => [7],
            ],
        ]);

        $matched = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9040,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 940,
                    'sellable_item_id' => $matching['sellable_item_id'],
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        $outsideResult = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9041,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 941,
                    'sellable_item_id' => $outside['sellable_item_id'],
                    'quantity' => '1.000000',
                ],
            ],
        ]);

        $this->assertFalse($matched['noop']);
        $this->assertTrue($outsideResult['noop']);
        $this->assertCount(1, $this->rows('inventory_movements', "order_id = 9040 AND movement_type = 'recipe_consumption'"));
        $this->assertSame([], $this->rows('inventory_movements', "order_id = 9041 AND movement_type = 'recipe_consumption'"));
    }

    public function testConsumePilotBranchScopeUsesNormalizedLineContext(): void
    {
        $matching = $this->recipeWithIngredient('10.000000', 7, 7);
        $outside = $this->recipeWithIngredient('10.000000', 7, 8);
        $service = $this->consumeService([
            'pilot' => [
                'pos_branch' => '7',
                'item_ids' => [],
                'category_ids' => [],
            ],
        ]);

        $matched = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9042,
            'pos_tenant' => 0,
            'pos_branch' => 7,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 942,
                    'sellable_item_id' => $matching['sellable_item_id'],
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        $outsideResult = $service->onOrderPaid([
            'conn' => self::$conn,
            'order_id' => 9043,
            'pos_tenant' => 0,
            'pos_branch' => 8,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'lines' => [
                [
                    'fat_detail_id' => 943,
                    'sellable_item_id' => $outside['sellable_item_id'],
                    'quantity' => '1.000000',
                ],
            ],
        ]);

        $this->assertFalse($matched['noop']);
        $this->assertTrue($outsideResult['noop']);
        $this->assertCount(1, $this->rows('inventory_movements', "order_id = 9042 AND pos_branch = 7 AND movement_type = 'recipe_consumption'"));
        $this->assertSame([], $this->rows('inventory_movements', "order_id = 9043 AND movement_type = 'recipe_consumption'"));
    }

    public function testReserveOnlyPilotCategoryScopeSkipsOutsideCategoryBeforeUsageWrites(): void
    {
        $matching = $this->recipeWithIngredient('10.000000', 7);
        $outside = $this->recipeWithIngredient('10.000000', 9);
        $service = $this->reserveOnlyService([
            'pilot' => [
                'pos_branch' => '',
                'item_ids' => [],
                'category_ids' => [7],
            ],
        ]);

        $matched = $service->onOrderLineAdded($this->lineContext(9044, 944, $matching['sellable_item_id'], '1.000000'));
        $outsideResult = $service->onOrderLineAdded($this->lineContext(9045, 945, $outside['sellable_item_id'], '1.000000'));

        $this->assertFalse($matched['noop']);
        $this->assertTrue($outsideResult['noop']);
        $this->assertCount(1, $this->rows('recipe_order_line_usage', 'order_id = 9044'));
        $this->assertCount(1, $this->rows('stock_reservations', 'order_id = 9044'));
        $this->assertSame([], $this->rows('recipe_order_line_usage', 'order_id = 9045'));
        $this->assertSame([], $this->rows('stock_reservations', 'order_id = 9045'));
        $this->assertSame('0.000000', $this->balance($outside['ingredient_item_id'])['qty_reserved']);
    }

    public function testReserveOnlyPilotBranchScopeUsesNormalizedLineContext(): void
    {
        $matching = $this->recipeWithIngredient('10.000000', 7, 7);
        $outside = $this->recipeWithIngredient('10.000000', 7, 8);
        $service = $this->reserveOnlyService([
            'pilot' => [
                'pos_branch' => '7',
                'item_ids' => [],
                'category_ids' => [],
            ],
        ]);
        $matchedCtx = $this->lineContext(9046, 946, $matching['sellable_item_id'], '1.000000');
        $matchedCtx['pos_branch'] = 7;
        $outsideCtx = $this->lineContext(9047, 947, $outside['sellable_item_id'], '1.000000');
        $outsideCtx['pos_branch'] = 8;

        $matched = $service->onOrderLineAdded($matchedCtx);
        $outsideResult = $service->onOrderLineAdded($outsideCtx);

        $this->assertFalse($matched['noop']);
        $this->assertTrue($outsideResult['noop']);
        $this->assertCount(1, $this->rows('recipe_order_line_usage', 'order_id = 9046 AND pos_branch = 7'));
        $this->assertCount(1, $this->rows('stock_reservations', 'order_id = 9046 AND pos_branch = 7'));
        $this->assertSame([], $this->rows('recipe_order_line_usage', 'order_id = 9047'));
        $this->assertSame([], $this->rows('stock_reservations', 'order_id = 9047'));
        $this->assertSame('1.000000', $this->balance($matching['ingredient_item_id'], 7)['qty_reserved']);
        $this->assertSame('0.000000', $this->balance($outside['ingredient_item_id'], 8)['qty_reserved']);
    }

    private function recipeWithIngredient(string $stock, int $sellableCategoryId = 7, int $posBranch = 0): array
    {
        $sellableItemId = $this->item('Lifecycle item', '0.000000', $sellableCategoryId);
        $ingredientItemId = $this->item('Lifecycle ingredient', '4.000000');
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'pos_branch' => $posBranch,
            'item_id' => $ingredientItemId,
            'qty_on_hand' => $stock,
            'qty_reserved' => '0.000000',
            'qty_available' => $stock,
        ]);

        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = new RecipeActorContext(77, 0, $posBranch, null, ['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $sellableItemId,
            'recipe_name' => 'Lifecycle recipe ' . $sellableItemId,
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $ingredientItemId,
            'qty_per_yield' => '1.000000',
        ], $actor);
        $definition->activate(self::$conn, (int) $recipe['id'], $actor);

        return [
            'sellable_item_id' => $sellableItemId,
            'ingredient_item_id' => $ingredientItemId,
        ];
    }

    private function batchPreparedRecipe(string $preparedStock, string $rawStock): array
    {
        $sellableItemId = $this->item('Prepared lifecycle item', '0.000000');
        $ingredientItemId = $this->item('Prepared lifecycle raw ingredient', '4.000000');
        $this->putBalance($sellableItemId, $preparedStock);
        $this->putBalance($ingredientItemId, $rawStock);

        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = new RecipeActorContext(77, 0, 0, null, ['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $sellableItemId,
            'recipe_name' => 'Prepared lifecycle recipe ' . $sellableItemId,
            'recipe_type' => 'batch_prepared',
            'yield_qty' => '10.000000',
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $ingredientItemId,
            'qty_per_yield' => '10.000000',
        ], $actor);
        $definition->activate(self::$conn, (int) $recipe['id'], $actor);

        return [
            'sellable_item_id' => $sellableItemId,
            'ingredient_item_id' => $ingredientItemId,
        ];
    }

    private function putBalance(int $itemId, string $onHand, int $posBranch = 0): void
    {
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'pos_branch' => $posBranch,
            'item_id' => $itemId,
            'qty_on_hand' => $onHand,
            'qty_reserved' => '0.000000',
            'qty_available' => $onHand,
        ]);
    }

    private function lineContext(int $orderId, int $lineId, int $itemId, string $qty): array
    {
        return [
            'conn' => self::$conn,
            'order_id' => $orderId,
            'fat_detail_id' => $lineId,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'sellable_item_id' => $itemId,
            'quantity' => $qty,
        ];
    }

    private function reserveOnlyService(array $overrides = []): RecipeOrderLifecycleService
    {
        return new RecipeOrderLifecycleService(new RecipeFeatureFlags([
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
        ]));
    }

    private function consumeService(array $overrides = []): RecipeOrderLifecycleService
    {
        return new RecipeOrderLifecycleService(new RecipeFeatureFlags([
            'recipe' => array_replace_recursive([
                'enabled' => true,
                'mode' => 'consume_pilot',
                'reservations' => true,
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ], $overrides),
        ]));
    }

    private function accountingService(): RecipeOrderLifecycleService
    {
        return new RecipeOrderLifecycleService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'accounting_pilot',
                'reservations' => true,
                'consumption' => true,
                'accounting' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function strictAvailabilityService(): RecipeOrderLifecycleService
    {
        return new RecipeOrderLifecycleService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'reservations' => true,
                'consumption' => true,
                'availability' => true,
                'strict_stock' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function availabilityLifecycleService(): RecipeOrderLifecycleService
    {
        return new RecipeOrderLifecycleService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'reservations' => true,
                'consumption' => true,
                'availability' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function moovaAvailabilitySyncLifecycleService(): RecipeOrderLifecycleService
    {
        return new RecipeOrderLifecycleService(new RecipeFeatureFlags(posmain_app_config([
            'sync' => [
                'outbox_enabled' => true,
                'menu_sync_enabled' => true,
            ],
            'branch' => [
                'uuid' => '88888888-8888-4888-8888-888888888888',
                'name' => 'PHPUnit Recipe Lifecycle Branch',
                'pos_tenant' => 0,
                'pos_branch' => 0,
            ],
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'reservations' => true,
                'consumption' => true,
                'availability' => true,
                'moova_sync' => true,
                'cost_public_payloads' => false,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ])));
    }

    private function item(string $name, string $cost, int $categoryId = 7): int
    {
        $id = ++self::$itemCounter;
        $stmt = self::$conn->prepare('INSERT INTO myitems (id, iname, cost_price, group1) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('issi', $id, $name, $cost, $categoryId);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    private function balance(int $itemId, int $posBranch = 0): array
    {
        return (new InventoryBalanceRepository())->findBalance(self::$conn, 0, $posBranch, 0, $itemId);
    }

    private function availabilityCache(int $itemId, string $orderType = 'takeaway', string $channel = 'pos'): array
    {
        $orderType = self::$conn->real_escape_string($orderType);
        $channel = self::$conn->real_escape_string($channel);
        $row = self::$conn->query("
            SELECT *
            FROM recipe_availability_cache
            WHERE sellable_item_id = {$itemId}
              AND order_type = '{$orderType}'
              AND channel = '{$channel}'
            LIMIT 1
        ")->fetch_assoc();
        if (!$row) {
            $this->fail('Expected recipe availability cache row for item ' . $itemId);
        }

        return $row;
    }

    private function latestMenuAvailabilityOutbox(int $itemId): array
    {
        $row = self::$conn->query("
            SELECT *
            FROM sync_outbox
            WHERE entity_type = 'menu_item'
              AND entity_local_id = {$itemId}
              AND event_type = 'menu.item_availability_changed'
            ORDER BY id DESC
            LIMIT 1
        ")->fetch_assoc();
        if (!$row) {
            $this->fail('Expected menu availability outbox row for item ' . $itemId);
        }

        return $row;
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

    private static function createJournalTables(): void
    {
        self::$conn->query("
            CREATE TABLE journal_heads (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                jdate DATE NULL,
                op_id INT NULL,
                pro_tybe INT NULL,
                details VARCHAR(255) NULL,
                user INT NULL,
                op2 INT NULL,
                tenant INT NULL DEFAULT 0,
                branch INT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        self::$conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                credit DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                tybe INT NOT NULL DEFAULT 0,
                info VARCHAR(255) NULL,
                op_id INT NULL,
                op2 INT NULL,
                tenant INT NULL DEFAULT 0,
                branch INT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

class RecipeLifecycleBridgeSpy extends RecipeOrderLifecycleService
{
    public array $added = [];
    public array $paid = [];

    public function onOrderLineAdded($ctx): array
    {
        $this->added[] = (array) $ctx;

        return [
            'success' => true,
            'action' => 'order_line_added',
            'noop' => true,
            'writes' => [],
            'warnings' => [],
            'scope' => null,
        ];
    }

    public function onOrderPaid($order): array
    {
        $this->paid[] = (array) $order;

        return [
            'success' => true,
            'action' => 'order_paid',
            'noop' => true,
            'writes' => [],
            'warnings' => [],
            'scope' => null,
        ];
    }
}
