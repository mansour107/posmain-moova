<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeRolloutReadinessService.php';

class RecipeRolloutReadinessServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_rollout_readiness_' . getmypid();
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

        (new SyncSchemaManager())->apply(self::$conn);
        self::$conn->query("
            CREATE TABLE IF NOT EXISTS myitems (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->clearTables();
    }

    public function testReadOnlyModePassesWhenSchemaExistsAndDashboardIsClean(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'read_only',
        ]));

        $this->assertTrue($result['ready_for_recipe_rollout'], implode(',', $result['blockers']));
        $this->assertSame([], $result['blockers']);
        $this->assertSame('read_only', $result['mode']);
        $this->assertTrue($result['checks']['schema']['ok']);
        $this->assertContains('external_order_line_map', $result['checks']['schema']['required_tables']);
        $this->assertContains('external_order_line_map', $result['checks']['schema']['existing_tables']);
        $this->assertTrue($result['checks']['runtime_preflight']['ok']);
        $this->assertFalse($result['checks']['pilot_evidence']['required']);
    }

    public function testMissingSchemaSkipsDashboardInsteadOfThrowing(): void
    {
        self::$conn->query('DROP TABLE recipe_headers');

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'read_only',
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_schema_missing_recipe_headers', $result['blockers']);
        $this->assertTrue($result['checks']['dashboard']['skipped']);
        $this->assertSame('recipe_schema_not_ready', $result['checks']['dashboard']['reason']);
        $this->assertContains('recipe_dashboard_check_skipped_until_schema_ready', $result['warnings']);
    }

    public function testMissingExternalOrderLineMapBlocksReadinessBeforeDashboardQueries(): void
    {
        self::$conn->query('DROP TABLE external_order_line_map');

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'read_only',
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_schema_missing_external_order_line_map', $result['blockers']);
        $this->assertContains('external_order_line_map', $result['checks']['schema']['missing_tables']);
        $this->assertTrue($result['checks']['dashboard']['skipped']);
    }

    public function testActivePilotModeBlocksUnsafeOperationalSignalsAndMissingEvidence(): void
    {
        $this->seedUnsafeSignals();

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'consumption' => true,
            'availability' => true,
            'moova_sync' => true,
        ]), [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
        ]);

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_pilot_evidence_file_not_provided', $result['blockers']);
        $this->assertContains('stale_recipe_reservations', $result['blockers']);
        $this->assertContains('negative_recipe_inventory_balances', $result['blockers']);
        $this->assertContains('invalid_recipe_inventory_movements', $result['blockers']);
        $this->assertContains('active_recipes_missing_cost_snapshots', $result['blockers']);
        $this->assertContains('recipe_availability_cache_gaps', $result['blockers']);
        $this->assertContains('failed_menu_availability_sync', $result['blockers']);
        $this->assertContains('pending_menu_availability_sync', $result['blockers']);
    }

    public function testEvidenceFileCanSatisfyActivePilotEvidenceGate(): void
    {
        $evidence = tempnam(sys_get_temp_dir(), 'recipe-pilot-evidence-');
        $this->assertIsString($evidence);
        file_put_contents($evidence, implode("\n", [
            'Evidence completed at UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
            'Recipe Pilot Evidence: pass',
            'Recipe mode: availability_pilot',
            'POS branch: 0',
            'Recipe schema migrated or verified: pass',
            'Recipe runtime preflight reviewed: pass',
            'Recipe operational dashboard reviewed: pass',
            'Recipe stock reconciliation reviewed: pass',
            'POS/table recipe smoke passed: pass',
            'Recipe rollback flags documented: pass',
            'Recipe reservation lifecycle smoke passed: pass',
            'Recipe reservation lifecycle runtime proof: php tests/sync/recipe_reservation_lifecycle_runtime_test.php -> recipe-reservation-lifecycle-runtime-ok',
            'Recipe COGS accountant review: pass',
            'Recipe availability and menu sync smoke passed: pass',
            'Recipe schema evidence: tools/run_migrations.php --dry-run -> 0 pending sync schema change(s)',
            'Recipe runtime preflight evidence: tools/recipe_runtime_preflight.php --json ready_for_recipe_operator_qa=true pending_count=0',
            'Pilot fixture verification evidence: tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true',
            'Recipe operational dashboard evidence: recipe_operational_dashboard.php reviewed with issue_total=0 and zero blockers',
            'Recipe stock reconciliation evidence: recipe_stock_reconciliation.php reconciliation CSV reviewed for pilot scope',
            'POS/table smoke evidence: POS order 1001 and table order 1002 completed once',
            'Migrated runtime write smoke evidence: tools/recipe_migrated_write_smoke.php --json --apply stock_preflight ok=true idempotency_replayed=true recipe_consumption movement cost positive',
            'Recipe report export and role QA evidence: tools/recipe_report_export_smoke.php CSV export and report role checks reviewed',
            'Modifier substitution recipe evidence: recipe_manage.php modifier substitution recipe v4 activated and previewed with oat milk replacing regular milk',
            'Production batch evidence: recipe_production.php production batch 501 previewed and committed by operator QA',
            'Waste and stock adjustment evidence: inventory_adjustments.php waste movement 601 and stock adjustment 602 reviewed',
            'Paid refund/void evidence: ajax/refund_order.php paid order 1003 refund and void dialogs exercised with policy selection',
            'Recipe rollback evidence: POSMAIN_RECIPE_MODE=off rollback path documented',
            'Recipe reservation evidence: tests/sync/recipe_reservation_lifecycle_runtime_test.php -> recipe-reservation-lifecycle-runtime-ok',
            'Recipe COGS accountant evidence: accountant reviewed COGS journal sample 2001',
            'Recipe availability and menu sync evidence: menu availability revision 3001 reached Moova smoke',
            'Moova/Cofe recipe replay evidence: Moova replay event mv-3001 and Cofe replay event cf-3002 consumed once',
            '- [x] Recipe management UI smoke',
            '- [x] Modifier substitution recipe UI smoke',
            '- [x] Recipe report export and role QA smoke',
            '- [x] Production batch UI smoke',
            '- [x] Waste and stock adjustment UI smoke',
            '- [x] POS/table lifecycle smoke',
            '- [x] Migrated runtime write smoke',
            '- [x] Paid refund/void smoke',
            '- [x] Recipe reservation lifecycle smoke',
            '- [x] Recipe accounting journal review',
            '- [x] Recipe availability POS and menu sync smoke',
            '- [x] Moova/Cofe recipe replay smoke',
            'POS takeaway cashier payment runtime proof: php tests/sync/pos_takeaway_order_service_test.php -> pos-takeaway-order-service-ok',
            'POS takeaway invoice handler runtime proof: php tests/sync/pos_takeaway_invoice_handler_test.php -> pos-takeaway-invoice-handler-ok',
            'POS table save recipe endpoint runtime proof: php tests/sync/pos_table_save_recipe_endpoint_runtime_test.php -> pos-table-save-recipe-endpoint-runtime-ok',
            'POS table cancel recipe endpoint runtime proof: php tests/sync/pos_table_cancel_recipe_endpoint_runtime_test.php -> pos-table-cancel-recipe-endpoint-runtime-ok',
            'POS table payment recipe endpoint runtime proof: php tests/sync/pos_table_payment_recipe_endpoint_runtime_test.php -> pos-table-payment-recipe-endpoint-runtime-ok',
            'POS split payment recipe endpoint runtime proof: php tests/sync/pos_split_payment_recipe_endpoint_runtime_test.php -> pos-split-payment-recipe-endpoint-runtime-ok',
            'Isolated cashier browser fixture smoke proof: php tests/sync/recipe_cashier_browser_fixture_smoke_test.php -> recipe-cashier-browser-fixture-smoke-ok',
            'Modifier substitution management endpoint runtime proof: php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php -> recipe-modifier-substitution-management-endpoint-runtime-ok',
            'Production endpoint runtime proof: php tests/sync/recipe_production_endpoint_runtime_test.php -> recipe-production-endpoint-runtime-ok',
            'Waste and stock adjustment endpoint runtime proof: php tests/sync/inventory_adjustment_endpoint_runtime_test.php -> inventory-adjustment-endpoint-runtime-ok',
            'Paid refund/void endpoint runtime proof: php tests/sync/recipe_paid_reversal_endpoint_runtime_test.php -> recipe-paid-reversal-endpoint-runtime-ok',
            'POS grid availability endpoint runtime proof: php tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php -> recipe-pos-grid-availability-endpoint-runtime-ok',
            'Moova menu sync payload endpoint runtime proof: php tests/sync/recipe_moova_menu_sync_payload_endpoint_runtime_test.php -> recipe-moova-menu-sync-payload-endpoint-runtime-ok',
            'Moova/Cofe replay runtime proof: php tests/sync/recipe_moova_replay_runtime_test.php -> recipe-moova-replay-runtime-ok',
            'Legacy Cofe endpoint runtime proof: php tests/sync/recipe_cofe_create_order_endpoint_runtime_test.php -> recipe-cofe-create-order-endpoint-runtime-ok',
        ]));

        try {
            $result = $this->service()->check(self::$conn, $this->flags([
                'enabled' => true,
                'mode' => 'availability_pilot',
                'consumption' => true,
                'accounting' => true,
                'availability' => true,
                'moova_sync' => true,
                'accounts' => [
                    'cogs_account_id' => 501,
                    'raw_inventory_account_id' => 101,
                    'prepared_inventory_account_id' => 102,
                    'packaging_inventory_account_id' => 103,
                    'waste_expense_account_id' => 601,
                    'production_variance_account_id' => 602,
                ],
            ], [
                'sync' => [
                    'menu_sync_enabled' => true,
                    'outbox_enabled' => true,
                    'branch_sync_enabled' => true,
                    'worker_enabled' => true,
                    'branch_secret' => 'test-secret',
                ],
                'branch' => [
                    'uuid' => '11111111-1111-4111-8111-111111111111',
                    'cloud_base_url' => 'https://cloud.example.test',
                ],
            ]), [
                'pilot_evidence_file' => $evidence,
            ]);
        } finally {
            @unlink($evidence);
        }

        $this->assertTrue($result['checks']['pilot_evidence']['ok'], json_encode($result['checks']['pilot_evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertTrue($result['checks']['runtime_preflight']['ok']);
        $this->assertNotContains('recipe_pilot_evidence_file_not_provided', $result['blockers']);
        $this->assertNotContains('recipe_account_missing_cogs_account_id', $result['blockers']);
        $this->assertTrue($result['ready_for_recipe_rollout'], implode(',', $result['blockers']));
    }

    public function testRecipeMoovaSyncRequiresUnderlyingMenuSyncTransport(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'moova_sync' => true,
        ], [
            'sync' => [
                'menu_sync_enabled' => false,
                'outbox_enabled' => false,
                'cloud_to_branch_publish_enabled' => false,
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_moova_sync_requires_menu_sync_enabled', $result['blockers']);
        $this->assertContains('recipe_moova_sync_requires_outbox_or_cloud_publish', $result['blockers']);
    }

    public function testRecipeMoovaSyncOutboxTransportRequiresBranchWorkerConfig(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'moova_sync' => true,
        ], [
            'role' => 'branch',
            'branch' => [
                'uuid' => '',
                'cloud_base_url' => '',
            ],
            'sync' => [
                'menu_sync_enabled' => true,
                'outbox_enabled' => true,
                'branch_sync_enabled' => false,
                'worker_enabled' => false,
                'branch_secret' => '',
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_moova_sync_outbox_requires_branch_uuid', $result['blockers']);
        $this->assertContains('recipe_moova_sync_outbox_requires_cloud_base_url', $result['blockers']);
        $this->assertContains('recipe_moova_sync_outbox_requires_branch_sync_secret', $result['blockers']);
        $this->assertContains('recipe_moova_sync_outbox_requires_branch_sync_enabled', $result['blockers']);
        $this->assertContains('recipe_moova_sync_outbox_requires_sync_worker_enabled', $result['blockers']);
    }

    public function testRecipeMoovaSyncOutboxTransportRequiresBranchRole(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'moova_sync' => true,
        ], [
            'role' => 'cloud',
            'sync' => [
                'menu_sync_enabled' => true,
                'outbox_enabled' => true,
                'cloud_to_branch_publish_enabled' => false,
                'branch_sync_enabled' => true,
                'worker_enabled' => true,
                'branch_secret' => 'test-secret',
            ],
            'branch' => [
                'uuid' => '11111111-1111-4111-8111-111111111111',
                'cloud_base_url' => 'https://cloud.example.test',
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_moova_sync_outbox_requires_branch_role', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_branch_uuid', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_cloud_base_url', $result['blockers']);
    }

    public function testRecipeMoovaSyncCloudPublishTransportDoesNotRequireBranchOutboxRuntime(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'moova_sync' => true,
        ], [
            'role' => 'cloud',
            'sync' => [
                'menu_sync_enabled' => true,
                'outbox_enabled' => true,
                'cloud_to_branch_publish_enabled' => true,
                'branch_sync_enabled' => false,
                'worker_enabled' => false,
                'branch_secret' => '',
            ],
            'branch' => [
                'uuid' => '',
                'cloud_base_url' => '',
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_pilot_evidence_file_not_provided', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_branch_role', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_branch_uuid', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_cloud_base_url', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_branch_sync_secret', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_branch_sync_enabled', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_outbox_requires_sync_worker_enabled', $result['blockers']);
    }

    public function testRecipeMoovaSyncRequiresRecipeAvailabilityFlag(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => false,
            'moova_sync' => true,
        ], [
            'sync' => [
                'menu_sync_enabled' => true,
                'outbox_enabled' => true,
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_moova_sync_requires_recipe_availability', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_requires_menu_sync_enabled', $result['blockers']);
        $this->assertNotContains('recipe_moova_sync_requires_outbox_or_cloud_publish', $result['blockers']);
    }

    public function testLegacyStrictStockMapsToPermissiveProductPolicy(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'strict_stock' => true,
            'allow_negative_stock_with_approval' => true,
        ]));

        $this->assertSame('allow_with_warning', $result['checks']['configuration']['negative_stock_sale_policy']);
        $this->assertNotContains('recipe_negative_stock_approval_conflicts_with_strict_stock', $result['blockers']);
        $this->assertNotContains('strict_stock_requires_recipe_availability', $result['blockers']);
    }

    public function testLegacyStrictStockDoesNotRequireEffectiveRecipeAvailabilityMode(): void
    {
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

        $this->assertSame('allow_with_warning', $result['checks']['configuration']['negative_stock_sale_policy']);
        $this->assertNotContains('strict_stock_requires_effective_recipe_availability', $result['blockers']);
        $this->assertNotContains('strict_stock_requires_recipe_availability', $result['blockers']);
    }

    public function testLegacyNegativeStockApprovalMapsToAllowWithWarning(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => false,
            'allow_negative_stock_with_approval' => true,
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('availability_pilot_requires_recipe_availability', $result['blockers']);
        $this->assertSame('allow_with_warning', $result['checks']['configuration']['negative_stock_sale_policy']);
        $this->assertNotContains('recipe_negative_stock_approval_conflicts_with_strict_stock', $result['blockers']);
    }

    public function testHostedActivePilotEvidenceRequiresHostedRuntimeSchemaDetail(): void
    {
        $evidence = tempnam(sys_get_temp_dir(), 'recipe-pilot-hosted-evidence-');
        $this->assertIsString($evidence);
        file_put_contents($evidence, implode("\n", [
            'Evidence completed at UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
            'Recipe mode: consume_pilot',
            'POS branch: 0',
            'Recipe Pilot Evidence: pass',
            'Recipe schema migrated or verified: pass',
            'Recipe runtime preflight reviewed: pass',
            'Recipe operational dashboard reviewed: pass',
            'Recipe stock reconciliation reviewed: pass',
            'POS/table recipe smoke passed: pass',
            'Recipe rollback flags documented: pass',
            'Recipe reservation lifecycle smoke passed: pass',
            'Recipe reservation lifecycle runtime proof: php tests/sync/recipe_reservation_lifecycle_runtime_test.php -> recipe-reservation-lifecycle-runtime-ok',
            'Recipe schema evidence: local migration dry-run 0 pending at 2026-05-24T00:00:00Z',
            'Recipe runtime preflight evidence: local tools/recipe_runtime_preflight.php ready',
            'Pilot fixture verification evidence: tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true',
            'Recipe operational dashboard evidence: local dashboard reviewed with zero blockers',
            'Recipe stock reconciliation evidence: local reconciliation CSV reviewed',
            'POS/table smoke evidence: local POS order 1001 and table order 1002 completed once',
            'Migrated runtime write smoke evidence: tools/recipe_migrated_write_smoke.php --json --apply stock_preflight ok=true idempotency_replayed=true recipe_consumption movement cost positive',
            'Recipe report export and role QA evidence: local CSV export and report role checks reviewed',
            'Modifier substitution recipe evidence: modifier substitution recipe v4 activated locally',
            'Production batch evidence: prepared batch 501 previewed and committed locally',
            'Waste and stock adjustment evidence: inventory_adjustments.php waste movement 601 and stock adjustment 602 reviewed locally',
            'Paid refund/void evidence: paid order 1003 refund and void dialogs exercised locally',
            'Recipe rollback evidence: POSMAIN_RECIPE_MODE=off rollback path documented',
            'Recipe reservation evidence: tests/sync/recipe_reservation_lifecycle_runtime_test.php -> recipe-reservation-lifecycle-runtime-ok',
            '- [x] Recipe management UI smoke',
            '- [x] Modifier substitution recipe UI smoke',
            '- [x] Recipe report export and role QA smoke',
            '- [x] Production batch UI smoke',
            '- [x] Waste and stock adjustment UI smoke',
            '- [x] POS/table lifecycle smoke',
            '- [x] Migrated runtime write smoke',
            '- [x] Paid refund/void smoke',
            '- [x] Recipe reservation lifecycle smoke',
            'POS takeaway cashier payment runtime proof: php tests/sync/pos_takeaway_order_service_test.php -> pos-takeaway-order-service-ok',
            'POS takeaway invoice handler runtime proof: php tests/sync/pos_takeaway_invoice_handler_test.php -> pos-takeaway-invoice-handler-ok',
            'POS table save recipe endpoint runtime proof: php tests/sync/pos_table_save_recipe_endpoint_runtime_test.php -> pos-table-save-recipe-endpoint-runtime-ok',
            'POS table cancel recipe endpoint runtime proof: php tests/sync/pos_table_cancel_recipe_endpoint_runtime_test.php -> pos-table-cancel-recipe-endpoint-runtime-ok',
            'POS table payment recipe endpoint runtime proof: php tests/sync/pos_table_payment_recipe_endpoint_runtime_test.php -> pos-table-payment-recipe-endpoint-runtime-ok',
            'POS split payment recipe endpoint runtime proof: php tests/sync/pos_split_payment_recipe_endpoint_runtime_test.php -> pos-split-payment-recipe-endpoint-runtime-ok',
            'Isolated cashier browser fixture smoke proof: php tests/sync/recipe_cashier_browser_fixture_smoke_test.php -> recipe-cashier-browser-fixture-smoke-ok',
            'Modifier substitution management endpoint runtime proof: php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php -> recipe-modifier-substitution-management-endpoint-runtime-ok',
            'Production endpoint runtime proof: php tests/sync/recipe_production_endpoint_runtime_test.php -> recipe-production-endpoint-runtime-ok',
            'Waste and stock adjustment endpoint runtime proof: php tests/sync/inventory_adjustment_endpoint_runtime_test.php -> inventory-adjustment-endpoint-runtime-ok',
            'Paid refund/void endpoint runtime proof: php tests/sync/recipe_paid_reversal_endpoint_runtime_test.php -> recipe-paid-reversal-endpoint-runtime-ok',
        ]));

        try {
            $result = $this->service()->check(self::$conn, $this->flags([
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
            ], [
                'role' => 'cloud',
            ]), [
                'pilot_evidence_file' => $evidence,
            ]);
        } finally {
            @unlink($evidence);
        }

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_pilot_evidence_details_missing', $result['blockers'], json_encode($result['checks']['pilot_evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertContains('Hosted/cloud runtime schema evidence', $result['checks']['pilot_evidence']['missing_details']);
    }

    public function testAccountingRolloutRequiresEveryRecipeAccountMapping(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'accounting_pilot',
            'consumption' => true,
            'accounting' => true,
            'accounts' => [
                'cogs_account_id' => 501,
                'raw_inventory_account_id' => 101,
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertNotContains('recipe_account_missing_cogs_account_id', $result['blockers']);
        $this->assertNotContains('recipe_account_missing_raw_inventory_account_id', $result['blockers']);
        $this->assertContains('recipe_account_missing_prepared_inventory_account_id', $result['blockers']);
        $this->assertContains('recipe_account_missing_packaging_inventory_account_id', $result['blockers']);
        $this->assertContains('recipe_account_missing_waste_expense_account_id', $result['blockers']);
        $this->assertContains('recipe_account_missing_production_variance_account_id', $result['blockers']);
    }

    public function testAccountingRolloutAcceptsChartResolvedAccountsWhenEnvUnset(): void
    {
        self::$conn->query("
            CREATE TABLE IF NOT EXISTS acc_head (
                id INT NOT NULL PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                aname VARCHAR(255) NOT NULL,
                isdeleted TINYINT NOT NULL DEFAULT 0,
                is_basic TINYINT NOT NULL DEFAULT 0,
                is_stock TINYINT NOT NULL DEFAULT 0,
                is_fund TINYINT NOT NULL DEFAULT 0,
                parent_id INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        self::$conn->query('DELETE FROM acc_head');
        self::$conn->query("
            INSERT INTO acc_head (id, code, aname, isdeleted, is_basic) VALUES
            (15, '41', 'تكاليف المبيعات', 0, 1),
            (16, '42', 'تكلفه البضاعه المباعه', 0, 1),
            (20, '123', 'المخزون', 0, 1)
        ");

        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'accounting_pilot',
            'consumption' => true,
            'accounting' => true,
            'accounts' => [
                'cogs_account_id' => 0,
                'raw_inventory_account_id' => 0,
                'prepared_inventory_account_id' => 0,
                'packaging_inventory_account_id' => 0,
                'waste_expense_account_id' => 0,
                'production_variance_account_id' => 0,
            ],
            'pilot' => [
                'item_ids' => [101],
            ],
        ]));

        $this->assertNotContains('recipe_account_missing_cogs_account_id', $result['blockers']);
        $this->assertNotContains('recipe_account_missing_raw_inventory_account_id', $result['blockers']);
        $this->assertNotContains('recipe_account_missing_prepared_inventory_account_id', $result['blockers']);
        $this->assertNotContains('recipe_account_missing_packaging_inventory_account_id', $result['blockers']);
        $this->assertNotContains('recipe_account_missing_waste_expense_account_id', $result['blockers']);
        $this->assertNotContains('recipe_account_missing_production_variance_account_id', $result['blockers']);
    }

    public function testPostVariancePolicyRequiresActiveRecipeAccounting(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
            'accounting' => false,
            'production_variance_policy' => 'post_variance',
            'pilot' => [
                'item_ids' => [101],
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_production_variance_policy_requires_accounting', $result['blockers']);
    }

    public function testActiveModesRequireTheirMatchingRuntimeFlags(): void
    {
        $reserve = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'reserve_only',
            'reservations' => false,
        ]));
        $consume = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => false,
        ]));
        $accounting = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'accounting_pilot',
            'consumption' => true,
            'accounting' => false,
        ]));
        $availability = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'consumption' => true,
            'availability' => false,
        ]));
        $full = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'full',
            'consumption' => false,
            'accounting' => false,
            'availability' => false,
        ]), [
            'allow_full_mode' => true,
        ]);

        $this->assertContains('reserve_only_requires_recipe_reservations', $reserve['blockers']);
        $this->assertContains('consume_pilot_requires_recipe_consumption', $consume['blockers']);
        $this->assertContains('accounting_pilot_requires_recipe_accounting', $accounting['blockers']);
        $this->assertContains('availability_pilot_requires_recipe_availability', $availability['blockers']);
        $this->assertContains('full_requires_recipe_consumption', $full['blockers']);
        $this->assertContains('full_requires_recipe_accounting', $full['blockers']);
        $this->assertContains('full_requires_recipe_availability', $full['blockers']);
    }

    public function testPilotEvidenceScopeMustMatchReadinessScopeFilters(): void
    {
        $evidence = tempnam(sys_get_temp_dir(), 'recipe-pilot-evidence-scope-');
        $this->assertIsString($evidence);
        file_put_contents($evidence, implode("\n", [
            'Evidence completed at UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
            'Recipe mode: consume_pilot',
            'POS tenant: 0',
            'POS branch: 1',
            'Store: 0',
            'Recipe Pilot Evidence: pass',
            'Recipe schema migrated or verified: pass',
            'Recipe runtime preflight reviewed: pass',
            'Recipe operational dashboard reviewed: pass',
            'Recipe stock reconciliation reviewed: pass',
            'POS/table recipe smoke passed: pass',
            'Recipe rollback flags documented: pass',
            'Recipe reservation lifecycle smoke passed: pass',
            'Recipe reservation lifecycle runtime proof: php tests/sync/recipe_reservation_lifecycle_runtime_test.php -> recipe-reservation-lifecycle-runtime-ok',
            'Recipe schema evidence: migration dry-run 0 pending at 2026-05-24T00:00:00Z',
            'Recipe runtime preflight evidence: tools/recipe_runtime_preflight.php --json ready_for_recipe_operator_qa=true pending_count=0',
            'Pilot fixture verification evidence: tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true',
            'Recipe operational dashboard evidence: dashboard reviewed with zero blockers',
            'Recipe stock reconciliation evidence: reconciliation CSV reviewed for pilot scope',
            'POS/table smoke evidence: POS order 1001 and table order 1002 completed once',
            'Migrated runtime write smoke evidence: tools/recipe_migrated_write_smoke.php --json --apply stock_preflight ok=true idempotency_replayed=true recipe_consumption movement cost positive',
            'Recipe report export and role QA evidence: CSV export and report role checks reviewed',
            'Modifier substitution recipe evidence: modifier substitution recipe v4 activated and previewed with oat milk replacing regular milk',
            'Production batch evidence: prepared sauce batch 501 previewed and committed by operator QA',
            'Waste and stock adjustment evidence: inventory_adjustments.php waste movement 601 and stock adjustment 602 reviewed',
            'Paid refund/void evidence: paid order 1003 refund and void dialogs exercised with policy selection',
            'Recipe rollback evidence: POSMAIN_RECIPE_MODE=off rollback path documented',
            'Recipe reservation evidence: tests/sync/recipe_reservation_lifecycle_runtime_test.php -> recipe-reservation-lifecycle-runtime-ok',
            '- [x] Recipe management UI smoke',
            '- [x] Modifier substitution recipe UI smoke',
            '- [x] Recipe report export and role QA smoke',
            '- [x] Production batch UI smoke',
            '- [x] Waste and stock adjustment UI smoke',
            '- [x] POS/table lifecycle smoke',
            '- [x] Migrated runtime write smoke',
            '- [x] Paid refund/void smoke',
            '- [x] Recipe reservation lifecycle smoke',
        ]));

        try {
            $result = $this->service()->check(self::$conn, $this->flags([
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
            ]), [
                'pilot_evidence_file' => $evidence,
                'pos_tenant' => 0,
                'pos_branch' => 2,
                'store_id' => 0,
            ]);
        } finally {
            @unlink($evidence);
        }

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_pilot_evidence_scope_mismatch', $result['blockers']);
        $this->assertSame('2', $result['checks']['pilot_evidence']['scope_mismatches']['pos_branch']['expected']);
        $this->assertSame('1', $result['checks']['pilot_evidence']['scope_mismatches']['pos_branch']['evidence']);
    }

    public function testPilotEvidenceScopeFallsBackToConfiguredPilotBranch(): void
    {
        $evidence = tempnam(sys_get_temp_dir(), 'recipe-pilot-evidence-config-scope-');
        $this->assertIsString($evidence);
        file_put_contents($evidence, implode("\n", [
            'Evidence completed at UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
            'Recipe mode: consume_pilot',
            'POS branch: 2',
            'Recipe Pilot Evidence: pass',
        ]));

        try {
            $result = $this->service()->check(self::$conn, $this->flags([
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '3',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ]), [
                'pilot_evidence_file' => $evidence,
            ]);
        } finally {
            @unlink($evidence);
        }

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_pilot_evidence_scope_mismatch', $result['blockers']);
        $this->assertSame('3', $result['checks']['pilot_evidence']['scope_mismatches']['pos_branch']['expected']);
        $this->assertSame('2', $result['checks']['pilot_evidence']['scope_mismatches']['pos_branch']['evidence']);
    }

    public function testPilotEvidenceScopeFallsBackToBranchConfigTenantAndBranch(): void
    {
        $evidence = tempnam(sys_get_temp_dir(), 'recipe-pilot-evidence-app-scope-');
        $this->assertIsString($evidence);
        file_put_contents($evidence, implode("\n", [
            'Evidence completed at UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
            'Recipe mode: consume_pilot',
            'POS tenant: 1',
            'POS branch: 2',
            'Recipe Pilot Evidence: pass',
        ]));

        try {
            $result = $this->service()->check(self::$conn, $this->flags([
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '',
                    'item_ids' => [79001],
                    'category_ids' => [],
                ],
            ], [
                'branch' => [
                    'pos_tenant' => 7,
                    'pos_branch' => 8,
                ],
            ]), [
                'pilot_evidence_file' => $evidence,
            ]);
        } finally {
            @unlink($evidence);
        }

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('recipe_pilot_evidence_scope_mismatch', $result['blockers']);
        $this->assertSame('7', $result['checks']['pilot_evidence']['scope_mismatches']['pos_tenant']['expected']);
        $this->assertSame('1', $result['checks']['pilot_evidence']['scope_mismatches']['pos_tenant']['evidence']);
        $this->assertSame('8', $result['checks']['pilot_evidence']['scope_mismatches']['pos_branch']['expected']);
        $this->assertSame('2', $result['checks']['pilot_evidence']['scope_mismatches']['pos_branch']['evidence']);
    }

    public function testPilotModesRequireExplicitPilotScope(): void
    {
        $reserveOnly = $this->service()->check(self::$conn, new RecipeFeatureFlags([
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
        $consumePilot = $this->service()->check(self::$conn, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));

        $this->assertFalse($reserveOnly['ready_for_recipe_rollout']);
        $this->assertFalse($consumePilot['ready_for_recipe_rollout']);
        $this->assertContains('pilot_mode_without_explicit_pilot_scope', $reserveOnly['blockers']);
        $this->assertContains('pilot_mode_without_explicit_pilot_scope', $consumePilot['blockers']);
        $this->assertNotContains('pilot_mode_without_explicit_pilot_scope', $consumePilot['warnings']);
    }

    public function testPilotScopeIgnoresNonPositiveItemAndCategoryPlaceholders(): void
    {
        $result = $this->service()->check(self::$conn, new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '',
                    'item_ids' => [0, -1, ''],
                    'category_ids' => [0, -5, ''],
                ],
            ],
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertContains('pilot_mode_without_explicit_pilot_scope', $result['blockers']);
        $this->assertSame(0, $result['checks']['configuration']['pilot_scope']['item_count']);
        $this->assertSame(0, $result['checks']['configuration']['pilot_scope']['category_count']);
    }

    public function testRuntimePreflightFailureBlocksReadiness(): void
    {
        $runtimePreflight = new class extends RecipeRuntimePreflightService {
            public function check(mysqli $conn, RecipeFeatureFlags $flags, array $options = []): array
            {
                return [
                    'ok' => false,
                    'ready_for_recipe_operator_qa' => false,
                    'checked_at_utc' => '2026-05-24T00:00:00Z',
                    'mode' => $flags->mode(),
                    'checks' => [
                        'source_guards' => [
                            'ok' => false,
                            'blockers' => ['recipe_runtime_source_guards_missing'],
                            'warnings' => [],
                        ],
                    ],
                    'blockers' => ['recipe_runtime_source_guards_missing'],
                    'warnings' => ['recipe_runtime_preflight_warning_sample'],
                ];
            }
        };

        $result = (new RecipeRolloutReadinessService(null, $runtimePreflight))->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'read_only',
        ]));

        $this->assertFalse($result['ready_for_recipe_rollout']);
        $this->assertFalse($result['checks']['runtime_preflight']['ok']);
        $this->assertContains('recipe_runtime_preflight_not_ready', $result['blockers']);
        $this->assertContains('recipe_runtime_source_guards_missing', $result['blockers']);
        $this->assertContains('recipe_runtime_preflight_warning_sample', $result['warnings']);
    }

    public function testFullModeAndPublicCostsRequireExplicitOverrides(): void
    {
        $result = $this->service()->check(self::$conn, $this->flags([
            'enabled' => true,
            'mode' => 'full',
            'cost_public_payloads' => true,
        ]));

        $this->assertContains('full_mode_requires_explicit_allow_full_mode', $result['blockers']);
        $this->assertContains('public_cost_payloads_enabled', $result['blockers']);
    }

    private function flags(array $recipeOverrides, array $appOverrides = []): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
            'recipe' => array_replace_recursive([
                'enabled' => false,
                'mode' => 'off',
                'shadow_ledger' => false,
                'reservations' => false,
                'consumption' => false,
                'accounting' => false,
                'availability' => false,
                'moova_sync' => false,
                'strict_stock' => false,
                'cost_public_payloads' => false,
                'refund_stock_policy' => 'waste',
                'allow_negative_stock_with_approval' => false,
                'default_reservation_minutes' => 90,
                'accounts' => [],
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [79001],
                    'category_ids' => [],
                ],
            ], $recipeOverrides),
        ] + $appOverrides);
    }

    private function service(): RecipeRolloutReadinessService
    {
        $runtimePreflight = new class extends RecipeRuntimePreflightService {
            public function check(mysqli $conn, RecipeFeatureFlags $flags, array $options = []): array
            {
                return [
                    'ok' => true,
                    'ready_for_recipe_operator_qa' => true,
                    'checked_at_utc' => '2026-05-24T00:00:00Z',
                    'mode' => $flags->mode(),
                    'checks' => [],
                    'blockers' => [],
                    'warnings' => [],
                ];
            }
        };

        return new RecipeRolloutReadinessService(null, $runtimePreflight);
    }

    private function clearTables(): void
    {
        foreach ([
            'sync_outbox',
            'inventory_movements',
            'inventory_item_balances',
            'stock_reservations',
            'recipe_availability_cache',
            'external_order_line_map',
            'recipe_order_line_usage',
            'recipe_cost_snapshots',
            'recipe_lines',
            'recipe_headers',
            'myitems',
        ] as $table) {
            self::$conn->query('DELETE FROM ' . $table);
        }
    }

    private function seedUnsafeSignals(): void
    {
        self::$conn->query("
            INSERT INTO myitems (id, iname, cost_price) VALUES
                (79001, 'Unsafe burger', 0.000000),
                (79002, 'Unsafe bun', 5.000000),
                (79003, 'Unsafe ledger ingredient', 1.000000)
        ");
        self::$conn->query("
            INSERT INTO recipe_headers
                (id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number, yield_qty, approved_at, updated_at)
            VALUES
                (99001, '00000000-0000-4000-8000-000000990001', 0, 0, 79001, 'Unsafe recipe', 'make_to_order', 'active', 1, 1.000000, '2026-05-24 10:00:00', '2026-05-24 10:00:00')
        ");
        self::$conn->query("
            INSERT INTO recipe_lines
                (recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base, is_required)
            VALUES
                (99001, '00000000-0000-4000-8000-000000990002', 79002, 'ingredient', 1.000000, 1.00000000, 1)
        ");
        self::$conn->query("
            INSERT INTO stock_reservations
                (reservation_uuid, pos_tenant, pos_branch, store_id, order_id, fat_detail_id, sellable_item_id, recipe_id, ingredient_item_id, qty_reserved, status, expires_at, idempotency_key)
            VALUES
                ('00000000-0000-4000-8000-000000990003', 0, 0, 0, 98001, 97001, 79001, 99001, 79002, 1.000000, 'reserved', '2000-01-01 00:00:00', 'unsafe-stale-reservation')
        ");
        self::$conn->query("
            INSERT INTO inventory_item_balances
                (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost, updated_at)
            VALUES
                (0, 0, 0, 79002, -1.000000, 0.000000, -1.000000, 5.000000, '2026-05-24 11:00:00')
        ");
        self::$conn->query("
            INSERT INTO inventory_movements
                (movement_uuid, pos_tenant, pos_branch, store_id, item_id, movement_type, source_type, qty_in, qty_out, unit_cost, total_cost, idempotency_key, created_at)
            VALUES
                ('00000000-0000-4000-8000-000000990005', 0, 0, 0, 79003, 'adjustment', 'manual', 1.000000, 1.000000, 1.000000, 1.000000, '', '2026-05-24 11:15:00')
        ");
        self::$conn->query("
            INSERT INTO sync_outbox
                (event_uuid, branch_uuid, pos_tenant, pos_branch, aggregate_type, aggregate_uuid, aggregate_local_id, aggregate_id, entity_type, entity_uuid, entity_local_id, event_type, event_version, source_system, idempotency_key, payload_json, payload_hash, status, attempts, last_error, updated_at)
            VALUES
                ('00000000-0000-4000-8000-000000990004', '00000000-0000-4000-8000-000000000001', 0, 0, 'menu_item', '00000000-0000-4000-8000-000000000101', 79001, 'myitems:79001', 'menu_item', '00000000-0000-4000-8000-000000000101', 79001, 'menu.item_availability_changed', 1, 'pos', 'unsafe-failed-menu-sync', '{}', 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 'dead', 3, 'sync dead', '2026-05-24 11:30:00')
        ");
        self::$conn->query("
            INSERT INTO sync_outbox
                (event_uuid, branch_uuid, pos_tenant, pos_branch, aggregate_type, aggregate_uuid, aggregate_local_id, aggregate_id, entity_type, entity_uuid, entity_local_id, event_type, event_version, source_system, idempotency_key, payload_json, payload_hash, status, attempts, last_error, updated_at)
            VALUES
                ('00000000-0000-4000-8000-000000990006', '00000000-0000-4000-8000-000000000001', 0, 0, 'menu_item', '00000000-0000-4000-8000-000000000101', 79001, 'myitems:79001', 'menu_item', '00000000-0000-4000-8000-000000000101', 79001, 'menu.item_availability_changed', 1, 'pos', 'unsafe-pending-menu-sync', '{}', 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd', 'pending', 0, NULL, '2026-05-24 11:31:00')
        ");
    }
}
