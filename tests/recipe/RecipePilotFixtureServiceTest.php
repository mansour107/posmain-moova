<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipePilotFixtureService.php';

class RecipePilotFixtureServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_pilot_fixture_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');
        self::createLegacyTables();
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

        foreach ([
            'production_batches',
            'recipe_availability_cache',
            'recipe_cost_snapshots',
            'inventory_movements',
            'inventory_item_balances',
            'recipe_lines',
            'recipe_headers',
            'item_modifier_groups',
            'modifier_options',
            'modifier_groups',
            'myitems',
        ] as $table) {
            self::$conn->query('DELETE FROM ' . $table);
        }
    }

    public function testDryRunPlansFixtureWithoutWritingRows(): void
    {
        $result = $this->service()->run(self::$conn, [
            'prefix' => 'Recipe QA Test',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['applied']);
        $this->assertSame(0, $this->rowCount('myitems'));
        $this->assertSame('<available after --apply>', $result['pilot_env']['POSMAIN_RECIPE_PILOT_ITEM_IDS']);
        $this->assertContains('customer orders', $result['does_not_write']);
        $this->assertContains('accounting journals', $result['does_not_write']);
        $this->assertContains('sync outbox rows', $result['does_not_write']);
    }

    public function testApplySeedsRepeatablePilotFixtureData(): void
    {
        $service = $this->service();
        $first = $service->run(self::$conn, [
            'apply' => true,
            'prefix' => 'Recipe QA Test',
            'barcode_prefix' => 'RQAT',
        ]);

        $this->assertTrue($first['ok']);
        $this->assertTrue($first['applied']);
        $this->assertSame(6, $this->rowCount('myitems'));
        $this->assertSame(3, $this->rowCount('recipe_headers'));
        $this->assertSame(11, $this->rowCount('recipe_lines'));
        $this->assertSame(2, $this->rowCount('recipe_cost_snapshots'));
        $this->assertSame(6, $this->rowCount('inventory_item_balances'));
        $this->assertSame(4, $this->rowCount('inventory_movements'));
        $this->assertSame(4, $this->rowCount('recipe_availability_cache'));
        $this->assertSame(1, $this->rowCount('production_batches'));
        $this->assertSame(1, $this->rowCount('modifier_groups'));
        $this->assertSame(1, $this->rowCount('modifier_options'));
        $this->assertSame(1, $this->rowCount('item_modifier_groups'));

        $latte = $this->one("SELECT id, item_type, track_stock FROM myitems WHERE barcode = 'RQAT-LATTE'");
        $this->assertSame('sellable', $latte['item_type']);
        $this->assertSame('0', (string) $latte['track_stock']);
        $this->assertStringContainsString((string) $latte['id'], $first['pilot_env']['POSMAIN_RECIPE_PILOT_ITEM_IDS']);

        $substitutionRows = $this->fetchAll("
            SELECT modifier_behavior, substitution_group
            FROM recipe_lines
            WHERE recipe_id IN (SELECT id FROM recipe_headers WHERE status = 'active')
              AND line_type = 'modifier_ingredient'
            ORDER BY id
        ");
        $this->assertSame(['substitution_remove', 'substitution_add'], array_column($substitutionRows, 'modifier_behavior'));
        $this->assertSame(['milk', 'milk'], array_column($substitutionRows, 'substitution_group'));

        $second = $service->run(self::$conn, [
            'apply' => true,
            'prefix' => 'Recipe QA Test',
            'barcode_prefix' => 'RQAT',
        ]);

        $this->assertTrue($second['ok']);
        $this->assertTrue($second['applied']);
        $this->assertSame(6, $this->rowCount('myitems'));
        $this->assertSame(3, $this->rowCount('recipe_headers'));
        $this->assertSame(11, $this->rowCount('recipe_lines'));
        $this->assertSame(2, $this->rowCount('recipe_cost_snapshots'));
        $this->assertSame(4, $this->rowCount('inventory_movements'));
        $this->assertSame(1, $this->rowCount('production_batches'));
    }

    public function testVerifyFailsBeforeApplyAndPassesAfterApply(): void
    {
        $service = $this->service();
        $before = $service->verify(self::$conn, [
            'prefix' => 'Recipe QA Verify',
            'barcode_prefix' => 'RQAV',
        ]);

        $this->assertFalse($before['ok']);
        $this->assertFalse($before['fixture_ready_for_operator_qa']);
        $this->assertTrue($before['read_only']);
        $this->assertContains('recipe_pilot_fixture_missing_item_latte', $before['blockers']);
        $this->assertSame(0, $this->rowCount('myitems'));

        $service->run(self::$conn, [
            'apply' => true,
            'prefix' => 'Recipe QA Verify',
            'barcode_prefix' => 'RQAV',
        ]);
        $after = $service->verify(self::$conn, [
            'prefix' => 'Recipe QA Verify',
            'barcode_prefix' => 'RQAV',
        ]);

        $this->assertTrue($after['ok']);
        $this->assertTrue($after['fixture_ready_for_operator_qa']);
        $this->assertTrue($after['read_only']);
        $this->assertSame([], $after['blockers']);
        $this->assertSame([
            'items' => 6,
            'modifier_groups' => 1,
            'modifier_options' => 1,
            'item_modifier_links' => 1,
            'recipes' => 2,
            'recipe_lines' => 6,
            'cost_snapshots' => 2,
            'balances' => 6,
            'opening_movements' => 4,
            'availability_cache_rows' => 4,
            'draft_recipes' => 1,
            'draft_recipe_lines' => 5,
            'draft_production_batches' => 1,
        ], $after['expected_counts']);
        $this->assertSame($after['expected_counts'], $after['counts']);
        $this->assertSame(6, $this->rowCount('myitems'));
    }

    public function testVerifyReportsMissingAvailabilityCacheRow(): void
    {
        $service = $this->service();
        $service->run(self::$conn, [
            'apply' => true,
            'prefix' => 'Recipe QA Cache',
            'barcode_prefix' => 'RQAC',
        ]);

        self::$conn->query("DELETE FROM recipe_availability_cache WHERE order_type = 'delivery' AND channel = 'moova'");
        $result = $service->verify(self::$conn, [
            'prefix' => 'Recipe QA Cache',
            'barcode_prefix' => 'RQAC',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['fixture_ready_for_operator_qa']);
        $this->assertContains('recipe_pilot_fixture_missing_availability_cache_latte_delivery_moova', $result['blockers']);
        $this->assertSame(3, $result['counts']['availability_cache_rows']);
        $this->assertSame(4, $result['expected_counts']['availability_cache_rows']);
    }

    public function testConflictBlocksApplyWhenExistingItemIdentityIsAmbiguous(): void
    {
        self::$conn->query("
            INSERT INTO myitems
              (iname, barcode, itmqty, cost_price, price1, price2, price3, group1, isdeleted, tenant, branch, item_type, track_stock)
            VALUES
              ('Recipe QA Conflict Latte', 'OTHER', 0, 0, 0, 0, 0, 0, 0, 0, 0, 'sellable', 0)
        ");

        $result = $this->service()->run(self::$conn, [
            'apply' => true,
            'prefix' => 'Recipe QA Conflict',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['applied']);
        $this->assertContains('recipe_pilot_fixture_item_row_conflict_latte', $result['blockers']);
        $this->assertSame(1, $this->rowCount('myitems'));
        $this->assertSame(0, $this->rowCount('recipe_headers'));
    }

    private static function createLegacyTables(): void
    {
        self::$conn->query("
            CREATE TABLE myitems (
              id INT NOT NULL AUTO_INCREMENT,
              iname VARCHAR(200) NOT NULL,
              barcode VARCHAR(25) DEFAULT NULL,
              itmqty DOUBLE NOT NULL DEFAULT 0,
              cost_price FLOAT NOT NULL DEFAULT 0,
              price1 FLOAT NOT NULL DEFAULT 0,
              price2 FLOAT NOT NULL DEFAULT 0,
              price3 FLOAT NOT NULL DEFAULT 0,
              group1 INT NOT NULL DEFAULT 0,
              isdeleted TINYINT(1) DEFAULT 0,
              tenant INT DEFAULT 0,
              branch INT DEFAULT 0,
              item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
              track_stock TINYINT(1) NOT NULL DEFAULT 1,
              preferred_unit_id BIGINT UNSIGNED NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_myitems_iname (iname),
              KEY idx_myitems_barcode (barcode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    private function service(): RecipePilotFixtureService
    {
        return new RecipePilotFixtureService();
    }

    private function rowCount(string $table): int
    {
        $row = self::$conn->query('SELECT COUNT(*) AS c FROM ' . $table)->fetch_assoc();

        return (int) $row['c'];
    }

    private function one(string $sql): array
    {
        $row = self::$conn->query($sql)->fetch_assoc();
        if (!$row) {
            throw new RuntimeException('Expected one row for query.');
        }

        return $row;
    }

    private function fetchAll(string $sql): array
    {
        $result = self::$conn->query($sql);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }
}
