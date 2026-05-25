<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeEditorPreviewService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeExplosionService.php';

class RecipeEditorPreviewServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 58000;

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
        self::$dbName = 'posmain_recipe_editor_preview_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000
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

    public function testPreviewCalculatesCostAndAvailabilityWithoutWritingCache(): void
    {
        $sellableId = $this->insertItem('Preview burger', '0.000000');
        $ingredientId = $this->insertItem('Preview patty', '3.000000');
        $recipe = $this->createDraftRecipe($sellableId, $ingredientId);
        self::$conn->query("
            INSERT INTO inventory_item_balances
                (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
            VALUES (0, 0, 0, {$ingredientId}, 11.000000, 1.000000, 10.000000, 3.000000)
        ");

        $beforeCacheRows = $this->countRows('recipe_availability_cache');
        $preview = $this->previewService()->preview(self::$conn, (int) $recipe['id'], [
            'order_type' => 'takeaway',
            'channel' => 'pos',
            'safety_stock' => '0',
            'costing_method' => 'item_cost_price',
        ]);
        $afterCacheRows = $this->countRows('recipe_availability_cache');

        $this->assertSame('6.000000', $preview['cost']['cost_per_yield']);
        $this->assertSame('6.000000', $preview['cost']['cost_per_sell_unit']);
        $this->assertSame('5.000000', $preview['availability']['effective_available_qty']);
        $this->assertTrue($preview['availability']['effective_is_available']);
        $this->assertSame($beforeCacheRows, $afterCacheRows, 'editor preview must not refresh/write availability cache');
    }

    public function testPreviewCanSuppressCostForUnauthorizedViewers(): void
    {
        $sellableId = $this->insertItem('No-cost preview item', '0.000000');
        $ingredientId = $this->insertItem('No-cost preview ingredient', '5.000000');
        $recipe = $this->createDraftRecipe($sellableId, $ingredientId);

        $preview = $this->previewService()->preview(self::$conn, (int) $recipe['id'], [], false);

        $this->assertNull($preview['cost']);
        $this->assertArrayHasKey('effective_available_qty', $preview['availability']);
    }

    private function createDraftRecipe(int $sellableId, int $ingredientId): array
    {
        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = new RecipeActorContext(88, 0, 0, null, ['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $sellableId,
            'recipe_name' => 'Preview Recipe ' . $sellableId,
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $ingredientId,
            'qty_per_yield' => '2.000000',
        ], $actor);

        return $recipe;
    }

    private function previewService(): RecipeEditorPreviewService
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'read_only',
            ],
        ]);
        $explosion = new RecipeExplosionService($flags);

        return new RecipeEditorPreviewService(
            new RecipeCostService(null, null, $explosion),
            new RecipeAvailabilityService($flags, new RecipeExplosionService($flags))
        );
    }

    private function insertItem(string $name, string $cost): int
    {
        $id = ++self::$itemCounter;
        $stmt = self::$conn->prepare('INSERT INTO myitems (id, iname, cost_price) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $id, $name, $cost);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    private function countRows(string $table): int
    {
        $row = self::$conn->query('SELECT COUNT(*) AS row_count FROM `' . $table . '`')->fetch_assoc();

        return (int) ($row['row_count'] ?? 0);
    }
}
