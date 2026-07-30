<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeRuntimePreflightService.php';

class RecipeRuntimePreflightServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_runtime_preflight_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');
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

    public function testPreflightPassesWhenSchemaAndSourceSurfacesAreReady(): void
    {
        (new SyncSchemaManager())->apply(self::$conn);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'read_only',
        ]));

        $this->assertTrue($result['ready_for_recipe_operator_qa'], implode(',', $result['blockers']));
        $this->assertSame([], $result['blockers']);
        $this->assertSame(0, $result['checks']['schema']['pending_count']);
        $this->assertTrue($result['checks']['runtime_dependencies']['ok']);
        $this->assertSame(function_exists('bcadd'), $result['checks']['runtime_dependencies']['extensions']['bcmath']);
        $this->assertTrue($result['checks']['source_guards']['ok']);
        $this->assertTrue($result['checks']['operator_tools']['ok']);
    }

    public function testPreflightBlocksWhenRuntimeSchemaIsNotMigrated(): void
    {
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'read_only',
        ]));

        $this->assertFalse($result['ready_for_recipe_operator_qa']);
        $this->assertContains('recipe_runtime_schema_missing_tables', $result['blockers']);
        $this->assertContains('recipe_runtime_schema_pending_migrations', $result['blockers']);
        $this->assertGreaterThan(0, $result['checks']['schema']['pending_count']);
    }

    public function testActiveModeWarnsToUsePilotEvidenceGate(): void
    {
        (new SyncSchemaManager())->apply(self::$conn);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'consumption' => true,
            'availability' => true,
        ]));

        $this->assertContains('recipe_runtime_preflight_active_mode_use_pilot_evidence_gate', $result['warnings']);

        $reserveResult = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'reserve_only',
            'reservations' => true,
            'pilot' => [
                'pos_branch' => '1',
            ],
        ]));

        $this->assertContains('recipe_runtime_preflight_active_mode_use_pilot_evidence_gate', $reserveResult['warnings']);
    }

    public function testPreflightBlocksActiveModesWhenMatchingRuntimeFlagsAreDisabled(): void
    {
        (new SyncSchemaManager())->apply(self::$conn);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'full',
            'consumption' => false,
            'accounting' => false,
            'availability' => false,
        ]));

        $this->assertFalse($result['ready_for_recipe_operator_qa']);
        $this->assertContains('recipe_runtime_full_requires_recipe_reservations', $result['blockers']);
        $this->assertContains('recipe_runtime_full_requires_recipe_consumption', $result['blockers']);
        $this->assertContains('recipe_runtime_full_requires_recipe_accounting', $result['blockers']);
        $this->assertContains('recipe_runtime_full_requires_recipe_availability', $result['blockers']);
        $this->assertSame([
            'recipe_runtime_full_requires_recipe_reservations',
            'recipe_runtime_full_requires_recipe_consumption',
            'recipe_runtime_full_requires_recipe_accounting',
            'recipe_runtime_full_requires_recipe_availability',
        ], $result['checks']['feature_flags']['blockers']);
    }

    public function testPreflightBlocksPilotModesWithoutExplicitScope(): void
    {
        (new SyncSchemaManager())->apply(self::$conn);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'reserve_only',
            'reservations' => true,
            'pilot' => [
                'pos_branch' => '',
                'item_ids' => [],
                'category_ids' => [],
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_operator_qa']);
        $this->assertContains('recipe_runtime_pilot_mode_without_explicit_pilot_scope', $result['blockers']);
        $this->assertContains('recipe_runtime_pilot_mode_without_explicit_pilot_scope', $result['checks']['feature_flags']['blockers']);
    }

    public function testPreflightBlocksActiveRecipeModeWithoutQuantityTracking(): void
    {
        (new SyncSchemaManager())->apply(self::$conn);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
            'pilot' => [
                'pos_branch' => '1',
            ],
        ], false));

        $this->assertFalse($result['ready_for_recipe_operator_qa']);
        $this->assertFalse($result['checks']['feature_flags']['inventory_quantity_tracking_enabled']);
        $this->assertContains(
            'recipe_runtime_active_mode_requires_inventory_quantity_tracking',
            $result['checks']['feature_flags']['blockers']
        );
    }

    public function testPreflightResolvesLegacyStockFlagsToOnePolicy(): void
    {
        (new SyncSchemaManager())->apply(self::$conn);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'consumption' => true,
            'availability' => false,
            'strict_stock' => true,
            'allow_negative_stock_with_approval' => true,
        ]));

        $this->assertFalse($result['ready_for_recipe_operator_qa']);
        $this->assertContains('recipe_runtime_availability_pilot_requires_recipe_availability', $result['blockers']);
        $this->assertSame('allow_with_warning', $result['checks']['feature_flags']['negative_stock_sale_policy']);
        $this->assertNotContains('recipe_runtime_negative_stock_approval_conflicts_with_strict_stock', $result['blockers']);
    }

    public function testPreflightIgnoresLegacyStrictStockAsSaleBlocker(): void
    {
        (new SyncSchemaManager())->apply(self::$conn);

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
            'availability' => true,
            'strict_stock' => true,
            'pilot' => [
                'pos_branch' => '1',
            ],
        ]));

        $this->assertTrue($result['ready_for_recipe_operator_qa'], implode(',', $result['blockers']));
        $this->assertSame('allow_with_warning', $result['checks']['feature_flags']['negative_stock_sale_policy']);
        $this->assertNotContains('recipe_runtime_strict_stock_requires_effective_recipe_availability', $result['blockers']);
        $this->assertNotContains('recipe_runtime_strict_stock_requires_recipe_availability', $result['blockers']);
    }

    private function service(): RecipeRuntimePreflightService
    {
        return new RecipeRuntimePreflightService(dirname(__DIR__, 2));
    }

    private function flags(array $recipeOverrides, bool $quantityTracking = true): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
            'inventory' => [
                'ledger_mode' => $quantityTracking ? 'live' : 'off',
                'quantity_tracking' => $quantityTracking,
            ],
            'recipe' => array_replace_recursive([
                'enabled' => false,
                'mode' => 'off',
                'reservations' => false,
                'consumption' => false,
                'availability' => false,
                'accounting' => false,
                'moova_sync' => false,
                'strict_stock' => false,
                'allow_negative_stock_with_approval' => false,
                'cost_public_payloads' => false,
            ], $recipeOverrides),
        ]);
    }
}
