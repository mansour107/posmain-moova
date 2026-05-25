<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorLookupService.php';

class RecipeEditorLookupServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_editor_lookup_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                name2 VARCHAR(255) NULL,
                barcode VARCHAR(128) NULL,
                group1 VARCHAR(128) NULL,
                item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
                track_stock TINYINT(1) NOT NULL DEFAULT 1,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query("
            INSERT INTO myitems (id, iname, barcode, group1, item_type, track_stock, cost_price) VALUES
            (61001, 'Lookup Burger', 'BURG-001', 'Food', 'sellable', 1, 99.000000),
            (61002, 'Lookup Patty', 'PAT-001', 'Raw', 'ingredient', 1, 7.000000),
            (61003, 'Lookup Box', 'BOX-001', 'Packaging', 'packaging', 1, 1.000000),
            (61004, 'Deleted Ingredient', 'DEL-001', 'Raw', 'ingredient', 1, 2.000000)
        ");
        self::$conn->query("UPDATE myitems SET isdeleted = 1 WHERE id = 61004");
        self::$conn->query("
            INSERT INTO recipe_headers
                (recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number)
            VALUES
                ('00000000-0000-4000-8000-000000061001', 0, 0, 61002, 'Lookup Sauce Base', 'sub_recipe', 'active', 1),
                ('00000000-0000-4000-8000-000000061002', 0, 0, 61003, 'Other Branch Base', 'sub_recipe', 'active', 1)
        ");
        self::$conn->query("UPDATE recipe_headers SET pos_branch = 1 WHERE recipe_uuid = '00000000-0000-4000-8000-000000061002'");
        self::$conn->query("
            INSERT INTO modifier_groups (id, name_ar, name_en, is_active) VALUES
                (62001, 'حجم', 'Size', 1)
        ");
        self::$conn->query("
            INSERT INTO modifier_options (id, group_id, name_ar, name_en, is_active) VALUES
                (63001, 62001, 'كبير', 'Large', 1)
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

    public function testItemLookupFiltersByKindAndDoesNotExposeCost(): void
    {
        $service = new RecipeEditorLookupService();

        $sellable = $service->searchItems(self::$conn, 'Burger', 'sellable');
        $components = $service->searchItems(self::$conn, 'Lookup', 'stock_component');

        $this->assertCount(1, $sellable);
        $this->assertSame(61001, $sellable[0]['id']);
        $this->assertArrayNotHasKey('cost_price', $sellable[0]);
        $this->assertSame([61002, 61003], array_values(array_sort(array_column($components, 'id'))));
    }

    public function testSubRecipeAndModifierLookupsAreScopedAndSafe(): void
    {
        $service = new RecipeEditorLookupService();

        $subRecipes = $service->searchSubRecipes(self::$conn, 'Base', 0, 0);
        $modifiers = $service->searchModifierOptions(self::$conn, 'Large');

        $this->assertCount(1, $subRecipes);
        $this->assertSame('Lookup Sauce Base v1 (active)', $subRecipes[0]['label']);
        $this->assertCount(1, $modifiers);
        $this->assertSame(63001, $modifiers[0]['id']);
        $this->assertSame(62001, $modifiers[0]['group_id']);
        $this->assertArrayNotHasKey('price_delta', $modifiers[0]);
    }
}

function array_sort(array $values): array
{
    sort($values);

    return $values;
}
