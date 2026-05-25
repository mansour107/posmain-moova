<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeReconciliationService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryMovementRepository.php';

class RecipeReconciliationSyncPayloadServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_reconcile_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                uuid CHAR(36) NULL,
                code VARCHAR(64) NOT NULL DEFAULT '',
                iname VARCHAR(255) NOT NULL DEFAULT '',
                item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
                track_stock TINYINT(1) NOT NULL DEFAULT 1,
                itmqty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query("
            CREATE TABLE fat_details (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NULL,
                tenant INT NOT NULL DEFAULT 0,
                branch INT NOT NULL DEFAULT 0,
                det_store BIGINT UNSIGNED NOT NULL DEFAULT 0,
                qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                crtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

    public function testReconciliationComparesLegacyFatLedgerAndBalanceQuantities(): void
    {
        self::$conn->query("
            INSERT INTO myitems (id, uuid, itmqty, cost_price)
            VALUES (3001, '00000000-0000-4000-8000-000000003001', 10.000000, 2.000000)
        ");
        self::$conn->query("
            INSERT INTO fat_details (item_id, tenant, branch, det_store, qty_in, qty_out)
            VALUES (3001, 0, 0, 0, 12.000000, 4.000000)
        ");
        (new InventoryMovementRepository())->createMovement(self::$conn, [
            'movement_uuid' => '00000000-0000-4000-8000-000000003101',
            'item_id' => 3001,
            'movement_type' => 'opening_balance',
            'source_type' => 'manual',
            'qty_in' => '7.000000',
            'idempotency_key' => 'reconcile-opening-3001',
        ]);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => 3001,
            'qty_on_hand' => '7.000000',
            'qty_reserved' => '1.000000',
            'qty_available' => '6.000000',
        ]);

        $row = (new RecipeReconciliationService())->compareItem(self::$conn, 0, 0, 0, 3001);

        $this->assertSame('10.000000', $row['legacy_qty']);
        $this->assertSame('8.000000', $row['fat_details_qty']);
        $this->assertSame('7.000000', $row['ledger_qty']);
        $this->assertSame('7.000000', $row['balance_qty']);
        $this->assertSame('2.000000', $row['legacy_vs_fat_difference']);
        $this->assertSame('0.000000', $row['ledger_vs_balance_difference']);
        $this->assertSame('3.000000', $row['legacy_vs_ledger_difference']);
        $this->assertTrue($row['has_difference']);
        $this->assertSame('Reconcile legacy stock with recipe ledger before expanding pilot items.', $row['recommended_action']);
    }

    public function testReconciliationReportSupportsDateMovementAndSourceFilters(): void
    {
        self::$conn->query("
            INSERT INTO myitems (id, uuid, code, iname, itmqty, cost_price)
            VALUES (3002, '00000000-0000-4000-8000-000000003002', 'ING-3002', 'Filtered Ingredient', 8.000000, 2.000000)
        ");
        self::$conn->query("
            INSERT INTO fat_details (item_id, tenant, branch, det_store, qty_in, qty_out, crtime)
            VALUES
                (3002, 0, 0, 0, 5.000000, 1.000000, '2026-05-20 10:00:00'),
                (3002, 0, 0, 0, 9.000000, 0.000000, '2026-05-01 10:00:00')
        ");
        (new InventoryMovementRepository())->createMovement(self::$conn, [
            'movement_uuid' => '00000000-0000-4000-8000-000000003201',
            'item_id' => 3002,
            'movement_type' => 'purchase',
            'source_type' => 'purchase_invoice',
            'qty_in' => '3.000000',
            'idempotency_key' => 'reconcile-purchase-3002',
            'created_at' => '2026-05-20 11:00:00',
        ]);
        (new InventoryMovementRepository())->createMovement(self::$conn, [
            'movement_uuid' => '00000000-0000-4000-8000-000000003202',
            'item_id' => 3002,
            'movement_type' => 'adjustment',
            'source_type' => 'manual',
            'qty_in' => '4.000000',
            'idempotency_key' => 'reconcile-adjustment-3002',
            'created_at' => '2026-05-20 12:00:00',
        ]);
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => 3002,
            'qty_on_hand' => '3.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '3.000000',
        ]);

        $rows = (new RecipeReconciliationService())->report(self::$conn, [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'item_ids' => [3002],
            'date_from' => '2026-05-15',
            'date_to' => '2026-05-24',
            'movement_type' => 'purchase',
            'source_type' => 'purchase_invoice',
            'differences_only' => true,
            'limit' => 10,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('ING-3002', $rows[0]['item_code']);
        $this->assertSame('Filtered Ingredient', $rows[0]['item_name']);
        $this->assertSame('4.000000', $rows[0]['fat_details_qty']);
        $this->assertSame('3.000000', $rows[0]['ledger_qty']);
        $this->assertSame('3.000000', $rows[0]['balance_qty']);
        $this->assertSame('5.000000', $rows[0]['legacy_vs_ledger_difference']);
    }

    public function testSyncPayloadOnlyExposesSafeAvailabilityFieldsByDefault(): void
    {
        $payload = (new RecipeSyncPayloadService())->menuAvailabilityPayload(
            [
                'id' => 5001,
                'uuid' => '00000000-0000-4000-8000-000000005001',
                'item_type' => 'sellable',
                'track_stock' => 1,
                'cost_price' => '99.000000',
            ],
            [
                'computed_available_qty' => '4.000000',
                'effective_is_available' => 1,
                'unavailable_reason' => null,
                'availability_revision' => 12,
                'updated_at' => '2026-05-23 13:00:00',
            ],
            [
                'version_number' => 3,
                'cost_per_sell_unit' => '55.000000',
            ]
        );

        $this->assertSame(5001, $payload['item_id']);
        $this->assertTrue($payload['recipe_enabled']);
        $this->assertSame(3, $payload['active_recipe_version']);
        $this->assertSame('4.000000', $payload['computed_available_qty']);
        $this->assertTrue($payload['effective_is_available']);
        $this->assertArrayNotHasKey('cost_price', $payload);
        $this->assertArrayNotHasKey('cost', $payload);
        $this->assertArrayNotHasKey('internal_cost_per_sell_unit', $payload);
    }

    public function testInternalCostPayloadRequiresExplicitFlag(): void
    {
        $service = new RecipeSyncPayloadService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'full',
                'cost_public_payloads' => true,
            ],
        ]));

        $payload = $service->menuAvailabilityPayload(
            ['id' => 5002],
            ['effective_is_available' => 1],
            ['version_number' => 1, 'cost_per_sell_unit' => '12.340000']
        );

        $this->assertSame('12.340000', $payload['internal_cost_per_sell_unit']);
    }

    public function testMenuItemSnapshotPayloadUsesCachedMoovaAvailabilityWithoutCostLeak(): void
    {
        self::$conn->query("
            INSERT INTO myitems (id, uuid, itmqty, cost_price)
            VALUES (5003, '00000000-0000-4000-8000-000000005003', 10.000000, 99.000000)
        ");
        self::$conn->query("
            INSERT INTO recipe_headers (
                recipe_uuid, pos_tenant, pos_branch, branch_uuid, sellable_item_id,
                recipe_name, status, version_number, approved_at
            ) VALUES (
                '00000000-0000-4000-8000-000000006003', 0, 0, NULL, 5003,
                'Moova Payload Recipe', 'active', 4, CURRENT_TIMESTAMP
            )
        ");
        self::$conn->query("
            INSERT INTO recipe_availability_cache (
                pos_tenant, pos_branch, store_id, sellable_item_id, recipe_id,
                order_type, channel, computed_available_qty, effective_available_qty,
                effective_is_available, unavailable_reason, availability_revision, calculated_at
            ) VALUES (
                0, 0, 0, 5003, LAST_INSERT_ID(),
                'any', 'any', 9.000000, 9.000000,
                1, NULL, 3, CURRENT_TIMESTAMP
            )
        ");
        self::$conn->query("
            INSERT INTO recipe_availability_cache (
                pos_tenant, pos_branch, store_id, sellable_item_id, recipe_id,
                order_type, channel, computed_available_qty, effective_available_qty,
                effective_is_available, unavailable_reason, availability_revision, calculated_at
            )
            SELECT
                0, 0, 0, 5003, id,
                'delivery', 'moova', 2.000000, 2.000000,
                0, 'delivery packaging is out of stock', 8, CURRENT_TIMESTAMP
            FROM recipe_headers
            WHERE sellable_item_id = 5003
            LIMIT 1
        ");

        $service = new RecipeSyncPayloadService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => true,
                'moova_sync' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));

        $payload = $service->menuItemSnapshotPayload(
            self::$conn,
            new RecipeScope(0, 0, null, 0, 'moova', 'delivery', 'pos'),
            [
                'id' => 5003,
                'uuid' => '00000000-0000-4000-8000-000000005003',
                'item_type' => 'sellable',
                'track_stock' => 1,
                'cost_price' => '99.000000',
            ],
            'delivery',
            'moova'
        );

        $this->assertIsArray($payload);
        $this->assertSame(5003, $payload['item_id']);
        $this->assertTrue($payload['recipe_enabled']);
        $this->assertSame(4, $payload['active_recipe_version']);
        $this->assertSame('2.000000', $payload['computed_available_qty']);
        $this->assertSame('2.000000', $payload['effective_available_qty']);
        $this->assertFalse($payload['effective_is_available']);
        $this->assertSame('delivery packaging is out of stock', $payload['unavailable_reason']);
        $this->assertSame(8, $payload['availability_revision']);
        $this->assertArrayNotHasKey('cost_price', $payload);
        $this->assertArrayNotHasKey('cost', $payload);
        $this->assertArrayNotHasKey('internal_cost_per_sell_unit', $payload);
    }

    public function testMenuItemSnapshotPayloadIsDisabledWhenMoovaRecipeSyncIsOff(): void
    {
        $service = new RecipeSyncPayloadService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => true,
                'moova_sync' => false,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));

        $payload = $service->menuItemSnapshotPayload(
            self::$conn,
            new RecipeScope(0, 0, null, 0, 'moova', 'delivery', 'pos'),
            ['id' => 5004],
            'delivery',
            'moova'
        );

        $this->assertNull($payload);
    }
}
