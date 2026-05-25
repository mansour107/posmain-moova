<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeOperationalDashboardService.php';

class RecipeOperationalDashboardServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_operational_dashboard_' . getmypid();
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

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE IF NOT EXISTS myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function testDashboardSurfacesRolloutHealthSignalsWithoutWrites(): void
    {
        $this->seedDashboardFixture();

        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'shadow_ledger' => true,
                'reservations' => true,
                'consumption' => true,
                'accounting' => false,
                'availability' => true,
                'moova_sync' => true,
                'strict_stock' => false,
                'cost_public_payloads' => true,
                'refund_stock_policy' => 'waste',
                'allow_negative_stock_with_approval' => true,
                'default_reservation_minutes' => 90,
                'production_variance_policy' => 'post_variance',
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [78001],
                    'category_ids' => [],
                ],
            ],
        ]);

        $dashboard = (new RecipeOperationalDashboardService())->dashboard(self::$conn, $flags, [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'limit' => 20,
        ]);

        $this->assertSame('availability_pilot', $dashboard['config']['mode']);
        $this->assertTrue($dashboard['config']['enabled_effective']);
        $this->assertTrue($dashboard['config']['reservations']);
        $this->assertTrue($dashboard['config']['moova_sync']);
        $this->assertSame(PHP_SAPI, $dashboard['config']['php_sapi']);
        $this->assertSame(PHP_VERSION, $dashboard['config']['php_version']);
        $this->assertSame(function_exists('bcadd'), $dashboard['config']['bcmath_loaded']);
        $this->assertSame(function_exists('bcadd') ? 0 : 1, $dashboard['summary']['runtime_bcmath_missing']);
        $this->assertTrue($dashboard['config']['cost_public_payloads']);
        $this->assertSame(1, $dashboard['summary']['public_cost_payloads_enabled']);
        $this->assertSame('enabled', $this->healthRow($dashboard, 'public_cost_payloads')['status']);
        $this->assertSame('post_variance', $dashboard['config']['production_variance_policy']);
        $this->assertSame(1, $dashboard['summary']['production_variance_policy_requires_accounting']);
        $this->assertSame('requires_accounting', $this->healthRow($dashboard, 'production_variance_policy')['status']);
        $this->assertSame(0, $dashboard['summary']['active_mode_flag_mismatches']);
        $this->assertSame('ok', $this->healthRow($dashboard, 'active_mode_flags')['status']);
        $this->assertSame(0, $dashboard['summary']['stock_policy_mismatches']);
        $this->assertSame('ok', $this->healthRow($dashboard, 'stock_policy_flags')['status']);
        $this->assertSame(1, $dashboard['summary']['stale_reservations']);
        $this->assertSame(1, $dashboard['summary']['negative_balances']);
        $this->assertSame(1, $dashboard['summary']['invalid_inventory_movements']);
        $this->assertSame(2, $dashboard['summary']['missing_cost_snapshots']);
        $this->assertGreaterThanOrEqual(3, $dashboard['summary']['recipe_setup_issues']);
        $this->assertSame(1, $dashboard['summary']['movement_write_gaps']);
        $this->assertSame(4, $dashboard['summary']['availability_cache_gaps']);
        $this->assertSame(1, $dashboard['summary']['menu_sync_outbox_issues']);
        $this->assertSame(1, $dashboard['summary']['pending_menu_sync_outbox']);
        $this->assertSame('runtime_bcmath', $this->healthRow($dashboard, 'runtime_bcmath')['key']);

        $this->assertSame('Old reservation item', $dashboard['sections']['stale_reservations']['rows'][0]['sellable_item_name']);
        $this->assertSame('Negative ingredient', $dashboard['sections']['negative_balances']['rows'][0]['item_name']);
        $this->assertSame('Bad ledger ingredient', $dashboard['sections']['invalid_inventory_movements']['rows'][0]['item_name']);
        $this->assertStringContainsString('both_qty_in_and_qty_out', $dashboard['sections']['invalid_inventory_movements']['rows'][0]['issue_type']);
        $this->assertStringContainsString('blank_idempotency_key', $dashboard['sections']['invalid_inventory_movements']['rows'][0]['issue_type']);
        $this->assertSame('No cost recipe', $this->firstSectionRow($dashboard, 'missing_cost_snapshots', 'recipe_name', 'No cost recipe')['recipe_name']);
        $this->assertSame('missing_unit_conversion', $this->firstSectionRow($dashboard, 'recipe_setup_issues', 'issue_type', 'missing_unit_conversion')['issue_type']);
        $this->assertSame('Movement gap item', $dashboard['sections']['movement_write_gaps']['rows'][0]['sellable_item_name']);
        $this->assertSame('stale_cache', $dashboard['sections']['availability_cache_gaps']['rows'][0]['issue_type']);
        $this->assertSame('failed', $dashboard['sections']['menu_sync_outbox_issues']['rows'][0]['status']);
        $this->assertSame(2, $dashboard['last_reconciliation']['inventory_movement_rows']);
        $this->assertSame(1, $dashboard['last_reconciliation']['inventory_balance_rows']);
    }

    public function testDashboardReturnsMissingSchemaSectionsInsteadOfFailing(): void
    {
        self::$conn->query('DROP TABLE IF EXISTS stock_reservations');

        $dashboard = (new RecipeOperationalDashboardService())->dashboard(self::$conn, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]));

        $this->assertSame('off', $dashboard['config']['mode']);
        $this->assertSame('missing_schema', $dashboard['sections']['stale_reservations']['status']);
    }

    public function testDashboardSurfacesActiveModeFlagMismatches(): void
    {
        $dashboard = (new RecipeOperationalDashboardService())->dashboard(self::$conn, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'full',
                'consumption' => false,
                'accounting' => false,
                'availability' => false,
            ],
        ]));

        $this->assertSame(4, $dashboard['summary']['active_mode_flag_mismatches']);
        $this->assertSame('mismatch', $this->healthRow($dashboard, 'active_mode_flags')['status']);
        $this->assertStringContainsString('full requires reservations', $this->healthRow($dashboard, 'active_mode_flags')['detail']);
        $this->assertStringContainsString('full requires consumption', $this->healthRow($dashboard, 'active_mode_flags')['detail']);
        $this->assertStringContainsString('full requires accounting', $this->healthRow($dashboard, 'active_mode_flags')['detail']);
        $this->assertStringContainsString('full requires availability', $this->healthRow($dashboard, 'active_mode_flags')['detail']);
    }

    public function testDashboardSurfacesPilotModeMissingScopeMismatch(): void
    {
        $dashboard = (new RecipeOperationalDashboardService())->dashboard(self::$conn, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'reserve_only',
                'reservations' => true,
                'pilot' => [
                    'pos_branch' => '',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));

        $this->assertSame(1, $dashboard['summary']['active_mode_flag_mismatches']);
        $this->assertSame('mismatch', $this->healthRow($dashboard, 'active_mode_flags')['status']);
        $this->assertStringContainsString('reserve_only requires explicit pilot branch, item, or category scope', $this->healthRow($dashboard, 'active_mode_flags')['detail']);
    }

    public function testDashboardSurfacesStockPolicyMismatches(): void
    {
        $dashboard = (new RecipeOperationalDashboardService())->dashboard(self::$conn, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => false,
                'strict_stock' => true,
                'allow_negative_stock_with_approval' => true,
            ],
        ]));

        $this->assertSame(3, $dashboard['summary']['stock_policy_mismatches']);
        $this->assertSame('mismatch', $this->healthRow($dashboard, 'stock_policy_flags')['status']);
        $this->assertStringContainsString('strict stock requires recipe availability', $this->healthRow($dashboard, 'stock_policy_flags')['detail']);
        $this->assertStringContainsString('strict stock conflicts with manager negative-stock approval', $this->healthRow($dashboard, 'stock_policy_flags')['detail']);
        $this->assertStringContainsString('manager negative-stock approval requires recipe availability', $this->healthRow($dashboard, 'stock_policy_flags')['detail']);
    }

    public function testDashboardSurfacesStrictStockWhenAvailabilityFlagIsNotEffectiveForMode(): void
    {
        $dashboard = (new RecipeOperationalDashboardService())->dashboard(self::$conn, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'availability' => true,
                'strict_stock' => true,
            ],
        ]));

        $this->assertSame(1, $dashboard['summary']['stock_policy_mismatches']);
        $this->assertFalse($dashboard['config']['availability_effective']);
        $this->assertSame('mismatch', $this->healthRow($dashboard, 'stock_policy_flags')['status']);
        $this->assertStringContainsString('strict stock requires effective recipe availability mode', $this->healthRow($dashboard, 'stock_policy_flags')['detail']);
    }

    private function seedDashboardFixture(): void
    {
        self::$conn->query('DELETE FROM sync_outbox');
        self::$conn->query('DELETE FROM inventory_movements');
        self::$conn->query('DELETE FROM inventory_item_balances');
        self::$conn->query('DELETE FROM stock_reservations');
        self::$conn->query('DELETE FROM recipe_availability_cache');
        self::$conn->query('DELETE FROM recipe_order_line_usage');
        self::$conn->query('DELETE FROM recipe_cost_snapshots');
        self::$conn->query('DELETE FROM recipe_lines');
        self::$conn->query('DELETE FROM recipe_headers');
        self::$conn->query('DELETE FROM myitems');

        self::$conn->query("
            INSERT INTO myitems (id, iname, cost_price) VALUES
                (78001, 'Old reservation item', 0.000000),
                (78002, 'Bun ingredient', 5.000000),
                (78003, 'Negative ingredient', 2.000000),
                (78004, 'No cost recipe', 0.000000),
                (78005, 'Setup issue item', 0.000000),
                (78006, 'Movement gap item', 0.000000),
                (78007, 'Stale cache item', 0.000000),
                (78008, 'Bad ledger ingredient', 1.000000)
        ");

        self::$conn->query("
            INSERT INTO recipe_headers
                (id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number, yield_qty, approved_at, updated_at)
            VALUES
                (91001, '00000000-0000-4000-8000-000000910001', 0, 0, 78004, 'No cost recipe', 'make_to_order', 'active', 1, 1.000000, '2026-05-24 10:00:00', '2026-05-24 10:00:00'),
                (91002, '00000000-0000-4000-8000-000000910002', 0, 0, 78005, 'Setup issue recipe', 'make_to_order', 'active', 1, 0.000000, '2026-05-24 10:05:00', '2026-05-24 10:05:00'),
                (91003, '00000000-0000-4000-8000-000000910003', 0, 0, 78006, 'Movement gap recipe', 'make_to_order', 'active', 1, 1.000000, '2026-05-24 10:10:00', '2026-05-24 10:10:00'),
                (91004, '00000000-0000-4000-8000-000000910004', 0, 0, 78007, 'Stale cache recipe', 'make_to_order', 'active', 1, 1.000000, '2026-05-24 10:15:00', '2026-05-24 12:00:00')
        ");

        self::$conn->query("
            INSERT INTO recipe_lines
                (recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base, is_required)
            VALUES
                (91001, '00000000-0000-4000-8000-000000920001', 78002, 'ingredient', 1.000000, 1.00000000, 1),
                (91002, '00000000-0000-4000-8000-000000920002', 78002, 'ingredient', 0.000000, 0.00000000, 1),
                (91003, '00000000-0000-4000-8000-000000920003', 78002, 'ingredient', 1.000000, 1.00000000, 1),
                (91004, '00000000-0000-4000-8000-000000920004', 78002, 'ingredient', 1.000000, 1.00000000, 1)
        ");

        self::$conn->query("
            INSERT INTO recipe_cost_snapshots
                (snapshot_uuid, pos_tenant, pos_branch, recipe_id, sellable_item_id, version_number, cost_per_yield, cost_per_sell_unit, calculated_at)
            VALUES
                ('00000000-0000-4000-8000-000000930003', 0, 0, 91003, 78006, 1, 5.000000, 5.000000, '2026-05-24 11:00:00'),
                ('00000000-0000-4000-8000-000000930004', 0, 0, 91004, 78007, 1, 5.000000, 5.000000, '2026-05-24 11:05:00')
        ");

        self::$conn->query("
            INSERT INTO stock_reservations
                (reservation_uuid, pos_tenant, pos_branch, store_id, order_id, fat_detail_id, sellable_item_id, recipe_id, ingredient_item_id, qty_reserved, status, expires_at, idempotency_key)
            VALUES
                ('00000000-0000-4000-8000-000000940001', 0, 0, 0, 88001, 99001, 78001, 91001, 78002, 1.000000, 'reserved', '2000-01-01 00:00:00', 'stale-reservation-1')
        ");

        self::$conn->query("
            INSERT INTO inventory_item_balances
                (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost, updated_at)
            VALUES
                (0, 0, 0, 78003, -1.000000, 0.000000, -1.000000, 2.000000, '2026-05-24 11:30:00')
        ");

        self::$conn->query("
            INSERT INTO recipe_order_line_usage
                (usage_uuid, pos_tenant, pos_branch, store_id, order_id, fat_detail_id, sellable_item_id, order_qty, recipe_id, recipe_version_number, cost_total, status, idempotency_key, updated_at)
            VALUES
                ('00000000-0000-4000-8000-000000950001', 0, 0, 0, 88002, 99002, 78006, 1.000000, 91003, 1, 5.000000, 'consumed', 'usage-gap-1', '2026-05-24 11:35:00')
        ");

        self::$conn->query("
            INSERT INTO inventory_movements
                (movement_uuid, pos_tenant, pos_branch, store_id, item_id, movement_type, source_type, qty_out, unit_cost, total_cost, idempotency_key, created_at)
            VALUES
                ('00000000-0000-4000-8000-000000960001', 0, 0, 0, 78002, 'recipe_consumption', 'recipe', 1.000000, 5.000000, 5.000000, 'unlinked-movement-1', '2026-05-24 11:40:00')
        ");
        self::$conn->query("
            INSERT INTO inventory_movements
                (movement_uuid, pos_tenant, pos_branch, store_id, item_id, movement_type, source_type, qty_in, qty_out, unit_cost, total_cost, idempotency_key, created_at)
            VALUES
                ('00000000-0000-4000-8000-000000960002', 0, 0, 0, 78008, 'adjustment', 'manual', 1.000000, 1.000000, 1.000000, 1.000000, '', '2026-05-24 11:41:00')
        ");

        self::$conn->query("
            INSERT INTO recipe_availability_cache
                (pos_tenant, pos_branch, store_id, sellable_item_id, recipe_id, computed_available_qty, effective_available_qty, effective_is_available, availability_revision, calculated_at, updated_at)
            VALUES
                (0, 0, 0, 78007, 91004, 5.000000, 5.000000, 1, 1, '2026-05-24 10:30:00', '2026-05-24 10:30:00')
        ");

        self::$conn->query("
            INSERT INTO sync_outbox
                (event_uuid, branch_uuid, pos_tenant, pos_branch, aggregate_type, aggregate_uuid, aggregate_local_id, aggregate_id, entity_type, entity_uuid, entity_local_id, event_type, event_version, source_system, idempotency_key, payload_json, payload_hash, status, attempts, last_error, updated_at)
            VALUES
                ('00000000-0000-4000-8000-000000970001', '00000000-0000-4000-8000-000000000001', 0, 0, 'menu_item', '00000000-0000-4000-8000-000000000101', 78007, 'myitems:78007', 'menu_item', '00000000-0000-4000-8000-000000000101', 78007, 'menu.item_availability_changed', 1, 'pos', 'failed-menu-availability-1', '{}', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'failed', 3, 'cloud unavailable', '2026-05-24 11:45:00'),
                ('00000000-0000-4000-8000-000000970002', '00000000-0000-4000-8000-000000000001', 0, 0, 'menu_item', '00000000-0000-4000-8000-000000000102', 78006, 'myitems:78006', 'menu_item', '00000000-0000-4000-8000-000000000102', 78006, 'menu.item_availability_changed', 1, 'pos', 'pending-menu-availability-1', '{}', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'pending', 0, NULL, '2026-05-24 11:50:00')
        ");
    }

    private function firstSectionRow(array $dashboard, string $section, string $key, string $value): array
    {
        foreach (($dashboard['sections'][$section]['rows'] ?? []) as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        $this->fail('Missing section row ' . $section . ' where ' . $key . '=' . $value);
    }

    private function healthRow(array $dashboard, string $key): array
    {
        foreach (($dashboard['health'] ?? []) as $row) {
            if (($row['key'] ?? null) === $key) {
                return $row;
            }
        }

        $this->fail('Missing dashboard health row ' . $key);
    }
}
