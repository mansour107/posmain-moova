<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorReadService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeCostSnapshotRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeAvailabilityCacheRepository.php';

class RecipeEditorReadServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 56000;

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
        self::$dbName = 'posmain_recipe_editor_read_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                barcode VARCHAR(128) NULL,
                group1 VARCHAR(128) NULL,
                item_type VARCHAR(64) NULL,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
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

    public function testReadModelListsRecipesAndLoadsDetailWithoutWrites(): void
    {
        $sellableId = $this->insertItem('Read model burger', 'RB-001', 'Burgers', 'sellable');
        $ingredientId = $this->insertItem('Read model patty', 'P-001', 'Ingredients', 'ingredient');

        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = new RecipeActorContext(77, 0, 0, null, ['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $sellableId,
            'recipe_name' => 'Read Model Burger',
            'recipe_type' => 'make_to_order',
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $ingredientId,
            'qty_per_yield' => '2.000000',
            'order_type' => 'takeaway',
            'channel' => 'pos',
        ], $actor);
        $definition->activate(self::$conn, (int) $recipe['id'], $actor);

        (new RecipeCostSnapshotRepository())->createSnapshot(self::$conn, [
            'snapshot_uuid' => '00000000-0000-4000-8000-000000056001',
            'recipe_id' => (int) $recipe['id'],
            'sellable_item_id' => $sellableId,
            'version_number' => 1,
            'cost_per_yield' => '44.000000',
            'cost_per_sell_unit' => '44.000000',
            'calculated_at' => '2026-05-24 12:00:00',
        ]);
        (new RecipeAvailabilityCacheRepository())->putAvailability(self::$conn, [
            'sellable_item_id' => $sellableId,
            'recipe_id' => (int) $recipe['id'],
            'order_type' => 'takeaway',
            'channel' => 'pos',
            'computed_available_qty' => '3.000000',
            'effective_available_qty' => '3.000000',
            'effective_is_available' => 1,
            'availability_revision' => 9,
            'calculated_at' => '2026-05-24 12:05:00',
        ]);

        $service = new RecipeEditorReadService();
        $beforeAuditCount = $this->countRows('recipe_audit_log');
        $rows = $service->listRecipes(self::$conn, [
            'status' => 'active',
            'q' => 'Burger',
            'pos_tenant' => 0,
            'pos_branch' => 0,
        ]);
        $detail = $service->recipeDetail(self::$conn, (int) $recipe['id']);
        $afterAuditCount = $this->countRows('recipe_audit_log');

        $this->assertCount(1, $rows);
        $this->assertSame('Read Model Burger', $rows[0]['recipe_name']);
        $this->assertSame('Read model burger', $rows[0]['sellable_item_name']);
        $this->assertSame('44.000000', $rows[0]['latest_cost_per_sell_unit']);
        $this->assertSame('3.000000', $rows[0]['cached_effective_available_qty']);
        $this->assertSame($beforeAuditCount, $afterAuditCount, 'read model should not write audit rows');

        $this->assertIsArray($detail);
        $this->assertSame('Read Model Burger', $detail['header']['recipe_name']);
        $this->assertSame('Read model patty', $detail['lines'][0]['ingredient_item_name']);
        $this->assertSame('44.000000', $detail['latest_cost']['cost_per_sell_unit']);
        $this->assertSame('3.000000', $detail['availability'][0]['effective_available_qty']);
        $this->assertSame('Read Model Burger', $detail['versions'][0]['recipe_name']);
        $this->assertNotEmpty($detail['audit']);
    }

    private function insertItem(string $name, string $barcode, string $group, string $type): int
    {
        $id = ++self::$itemCounter;
        $stmt = self::$conn->prepare('INSERT INTO myitems (id, iname, barcode, group1, item_type) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $id, $name, $barcode, $group, $type);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    private function countRows(string $table): int
    {
        $result = self::$conn->query('SELECT COUNT(*) AS row_count FROM `' . $table . '`');
        $row = $result->fetch_assoc();

        return (int) ($row['row_count'] ?? 0);
    }
}
