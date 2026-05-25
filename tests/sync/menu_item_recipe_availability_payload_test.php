<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncOutboxEventService.php';

class MenuItemRecipeAvailabilityPayloadTest extends TestCase
{
    private const BRANCH_UUID = '77777777-7777-4777-8777-777777777777';

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
        self::$dbName = 'posmain_menu_recipe_payload_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::createCatalogTables();
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

        $this->truncateRecipeAndSyncRows();
    }

    public function testMenuItemOutboxSnapshotIncludesSafeRecipeAvailabilityWhenEnabled(): void
    {
        $this->seedMenuItem(7001);
        $recipeId = $this->seedActiveRecipe(7001, 5);
        $this->seedAvailability(7001, $recipeId, 'delivery', 'moova', '1.000000', 0, 'Only 1 can be made');

        $result = (new SyncOutboxEventService())->recordMenuItemSnapshot(self::$conn, 7001, [
            'event_type' => 'menu.item_saved',
            'source_system' => 'item_form',
            'config' => $this->recipeMenuConfig(true),
        ]);

        $this->assertIsArray($result);
        $outbox = $this->fetchOutbox((int) $result['outbox_id']);
        $payload = json_decode((string) $outbox['payload_json'], true);
        $menuItem = $payload['menu_item'] ?? [];
        $recipe = $menuItem['recipe_availability'] ?? [];

        $this->assertSame('pos_menu_item', $payload['snapshot_type']);
        $this->assertSame(7001, (int) $menuItem['item_id']);
        $this->assertTrue($menuItem['recipe_enabled']);
        $this->assertSame(5, $menuItem['active_recipe_version']);
        $this->assertSame('1.000000', $menuItem['computed_available_qty']);
        $this->assertFalse($menuItem['effective_is_available']);
        $this->assertSame('Only 1 can be made', $menuItem['unavailable_reason']);
        $this->assertFalse($menuItem['available_online']);
        $this->assertFalse($menuItem['is_orderable']);

        $this->assertSame(7001, $recipe['item_id']);
        $this->assertArrayNotHasKey('cost_price', $recipe);
        $this->assertArrayNotHasKey('cost', $recipe);
        $this->assertArrayNotHasKey('internal_cost_per_sell_unit', $recipe);
    }

    public function testMenuItemOutboxSnapshotRemainsLegacyShapeWhenRecipeMoovaSyncIsDisabled(): void
    {
        $this->seedMenuItem(7002);
        $recipeId = $this->seedActiveRecipe(7002, 1);
        $this->seedAvailability(7002, $recipeId, 'delivery', 'moova', '0.000000', 0, 'ingredient out');

        $result = (new SyncOutboxEventService())->recordMenuItemSnapshot(self::$conn, 7002, [
            'event_type' => 'menu.item_saved',
            'source_system' => 'item_form',
            'config' => $this->recipeMenuConfig(false),
        ]);

        $this->assertIsArray($result);
        $outbox = $this->fetchOutbox((int) $result['outbox_id']);
        $payload = json_decode((string) $outbox['payload_json'], true);
        $menuItem = $payload['menu_item'] ?? [];

        $this->assertArrayNotHasKey('recipe_availability', $menuItem);
        $this->assertArrayNotHasKey('recipe_enabled', $menuItem);
        $this->assertTrue($menuItem['available_online']);
        $this->assertTrue($menuItem['is_orderable']);
    }

    private static function createCatalogTables(): void
    {
        self::$conn->query("
            CREATE TABLE item_group (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                gname VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
                group1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
        self::$conn->query("INSERT INTO item_group (id, gname) VALUES (7, 'PHPUnit Drinks')");
    }

    private function truncateRecipeAndSyncRows(): void
    {
        foreach ([
            'sync_outbox',
            'sync_branch_identity',
            'recipe_availability_cache',
            'recipe_headers',
            'myitems',
        ] as $table) {
            self::$conn->query('DELETE FROM ' . $table);
        }
    }

    private function seedMenuItem(int $itemId): void
    {
        $stmt = self::$conn->prepare("
            INSERT INTO myitems (
                id, uuid, iname, code, barcode, itmqty, cost_price, market_price,
                price1, price2, price3, group1, item_type, track_stock, isdeleted, mdtime
            ) VALUES (?, ?, ?, ?, ?, 10.000000, 44.000000, 0.000000,
                15.000000, 15.000000, 15.000000, 7, 'sellable', 1, 0, '2026-05-23 12:00:00')
        ");
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $itemId);
        $name = 'PHPUnit Recipe Item ' . $itemId;
        $code = $itemId;
        $barcode = 'RCP' . $itemId;
        $stmt->bind_param('issis', $itemId, $uuid, $name, $code, $barcode);
        $stmt->execute();
        $stmt->close();
    }

    private function seedActiveRecipe(int $itemId, int $version): int
    {
        $stmt = self::$conn->prepare("
            INSERT INTO recipe_headers (
                recipe_uuid, pos_tenant, pos_branch, branch_uuid, sellable_item_id,
                recipe_name, status, version_number, approved_at
            ) VALUES (?, 0, 0, ?, ?, ?, 'active', ?, CURRENT_TIMESTAMP)
        ");
        $recipeUuid = sprintf('00000000-0000-4000-8001-%012d', $itemId);
        $name = 'Recipe ' . $itemId;
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('ssisi', $recipeUuid, $branchUuid, $itemId, $name, $version);
        $stmt->execute();
        $recipeId = (int) self::$conn->insert_id;
        $stmt->close();

        return $recipeId;
    }

    private function seedAvailability(
        int $itemId,
        int $recipeId,
        string $orderType,
        string $channel,
        string $qty,
        int $available,
        string $reason
    ): void {
        $stmt = self::$conn->prepare("
            INSERT INTO recipe_availability_cache (
                pos_tenant, pos_branch, branch_uuid, store_id, sellable_item_id, recipe_id,
                order_type, channel, computed_available_qty, effective_available_qty,
                effective_is_available, unavailable_reason, availability_revision, calculated_at
            ) VALUES (0, 0, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, 19, CURRENT_TIMESTAMP)
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param(
            'siissssis',
            $branchUuid,
            $itemId,
            $recipeId,
            $orderType,
            $channel,
            $qty,
            $qty,
            $available,
            $reason
        );
        $stmt->execute();
        $stmt->close();
    }

    private function recipeMenuConfig(bool $moovaSyncEnabled): array
    {
        return posmain_app_config([
            'sync' => [
                'outbox_enabled' => true,
                'menu_sync_enabled' => true,
            ],
            'branch' => [
                'uuid' => self::BRANCH_UUID,
                'name' => 'PHPUnit Recipe Menu Branch',
                'pos_tenant' => 0,
                'pos_branch' => 0,
            ],
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => true,
                'moova_sync' => $moovaSyncEnabled,
                'cost_public_payloads' => false,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);
    }

    private function fetchOutbox(int $id): array
    {
        $stmt = self::$conn->prepare('SELECT * FROM sync_outbox WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }
}

class menu_item_recipe_availability_payload_test extends MenuItemRecipeAvailabilityPayloadTest
{
}
