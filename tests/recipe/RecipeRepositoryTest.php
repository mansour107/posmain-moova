<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeLineRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeCostSnapshotRepository.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeAuditService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeOrderLineUsageRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryMovementRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/StockReservationRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/ProductionBatchRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeAuditRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeAvailabilityCacheRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/ExternalOrderLineMapRepository.php';

class RecipeRepositoryTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $uuidCounter = 1;

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
        self::$dbName = 'posmain_recipe_repo_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        $manager = new SyncSchemaManager();
        $manager->apply(self::$conn);
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

    public function testDefinitionCostAndAuditRepositoriesRoundTrip(): void
    {
        $recipes = new RecipeRepository();
        $lines = new RecipeLineRepository();
        $costs = new RecipeCostSnapshotRepository();
        $audit = new RecipeAuditRepository();

        $recipeId = $recipes->createHeader(self::$conn, [
            'recipe_uuid' => $this->uuid(),
            'sellable_item_id' => 1001,
            'recipe_name' => 'Burger v1',
        ]);
        $lineId = $lines->createLine(self::$conn, [
            'recipe_id' => $recipeId,
            'line_uuid' => $this->uuid(),
            'ingredient_item_id' => 2001,
            'qty_per_yield' => '2.500000',
            'sort_order' => 10,
        ]);
        $recipes->updateStatus(self::$conn, $recipeId, 'active', 42);

        $snapshotId = $costs->createSnapshot(self::$conn, [
            'snapshot_uuid' => $this->uuid(),
            'recipe_id' => $recipeId,
            'sellable_item_id' => 1001,
            'version_number' => 1,
            'cost_per_yield' => '12.000000',
            'cost_per_sell_unit' => '12.000000',
            'ingredient_cost_json' => '{"ingredient":2001}',
            'calculated_at' => '2026-05-23 12:00:00',
        ]);
        $auditId = $audit->log(self::$conn, [
            'recipe_id' => $recipeId,
            'entity_type' => 'recipe_header',
            'entity_id' => $recipeId,
            'action' => 'activate',
            'actor_user_id' => 42,
            'after_json' => '{"status":"active"}',
        ]);

        $active = $recipes->findActiveHeaderForItem(self::$conn, 0, 0, 1001);
        $storedLines = $lines->findLinesByRecipeId(self::$conn, $recipeId);
        $latestCost = $costs->latestForRecipe(self::$conn, $recipeId);
        $auditRows = $audit->findForRecipe(self::$conn, 0, 0, $recipeId);

        $this->assertSame($recipeId, (int) $active['id']);
        $this->assertSame($lineId, (int) $storedLines[0]['id']);
        $this->assertSame($snapshotId, (int) $latestCost['id']);
        $this->assertSame($auditId, (int) $auditRows[0]['id']);
    }

    public function testRecipeHeaderRepositoryRejectsUnsafeDefinitionRows(): void
    {
        $recipes = new RecipeRepository();
        $base = [
            'recipe_uuid' => $this->uuid(),
            'sellable_item_id' => 1101,
            'recipe_name' => 'Guarded Header',
            'recipe_type' => 'make_to_order',
            'yield_qty' => '1.2345674',
            'default_wastage_percent' => '0.55555',
        ];

        $recipeId = $recipes->createHeader(self::$conn, $base);
        $stored = $recipes->findHeaderById(self::$conn, $recipeId);
        $this->assertSame('1.234567', $stored['yield_qty']);
        $this->assertSame('0.5556', $stored['default_wastage_percent']);

        $this->assertInvalidArgument(function () use ($recipes, $base): void {
            $recipes->createHeader(self::$conn, array_merge($base, [
                'recipe_uuid' => '',
            ]));
        }, 'UUID is required');

        $this->assertInvalidArgument(function () use ($recipes, $base): void {
            $recipes->createHeader(self::$conn, array_merge($base, [
                'recipe_uuid' => $this->uuid(),
                'sellable_item_id' => 0,
            ]));
        }, 'sellable_item_id must be positive');

        $this->assertInvalidArgument(function () use ($recipes, $base): void {
            $recipes->createHeader(self::$conn, array_merge($base, [
                'recipe_uuid' => $this->uuid(),
                'recipe_type' => 'combo',
            ]));
        }, 'recipe_type is invalid');

        $this->assertInvalidArgument(function () use ($recipes, $base): void {
            $recipes->createHeader(self::$conn, array_merge($base, [
                'recipe_uuid' => $this->uuid(),
                'yield_qty' => '0',
            ]));
        }, 'yield_qty must be positive');

        $this->assertInvalidArgument(function () use ($recipes, $base): void {
            $recipes->createHeader(self::$conn, array_merge($base, [
                'recipe_uuid' => $this->uuid(),
                'default_wastage_percent' => '-0.0001',
            ]));
        }, 'default_wastage_percent cannot be negative');

        $this->assertInvalidArgument(function () use ($recipes): void {
            $recipes->updateDraft(self::$conn, 0, ['recipe_name' => 'bad']);
        }, 'id must be positive');

        $this->assertInvalidArgument(function () use ($recipes, $recipeId): void {
            $recipes->updateDraft(self::$conn, $recipeId, ['costing_method' => 'supplier_guess']);
        }, 'costing_method is invalid');

        $this->assertInvalidArgument(function () use ($recipes, $recipeId): void {
            $recipes->updateStatus(self::$conn, $recipeId, 'published');
        }, 'status is invalid');
    }

    public function testRecipeLineRepositoryRejectsUnsafeDefinitionRows(): void
    {
        $recipes = new RecipeRepository();
        $lines = new RecipeLineRepository();
        $recipeId = $recipes->createHeader(self::$conn, [
            'recipe_uuid' => $this->uuid(),
            'sellable_item_id' => 1102,
            'recipe_name' => 'Guarded Lines',
        ]);
        $base = [
            'recipe_id' => $recipeId,
            'line_uuid' => $this->uuid(),
            'line_type' => 'ingredient',
            'ingredient_item_id' => 2102,
            'qty_per_yield' => '0.3333335',
            'unit_conversion_to_base' => '1.123456785',
            'wastage_percent' => '0.33335',
        ];

        $lineId = $lines->createLine(self::$conn, $base);
        $stored = $lines->findLineById(self::$conn, $lineId);
        $this->assertSame('0.333334', $stored['qty_per_yield']);
        $this->assertSame('1.12345679', $stored['unit_conversion_to_base']);
        $this->assertSame('0.3334', $stored['wastage_percent']);

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => '',
            ]));
        }, 'UUID is required');

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => $this->uuid(),
                'line_type' => 'combo_component',
            ]));
        }, 'line_type is invalid');

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => $this->uuid(),
                'ingredient_item_id' => null,
            ]));
        }, 'ingredient_item_id must be positive');

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => $this->uuid(),
                'line_type' => 'sub_recipe',
                'ingredient_item_id' => null,
                'sub_recipe_id' => null,
            ]));
        }, 'sub_recipe_id must be positive');

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => $this->uuid(),
                'qty_per_yield' => '0',
            ]));
        }, 'qty_per_yield must be positive');

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => $this->uuid(),
                'unit_conversion_to_base' => '0',
            ]));
        }, 'unit_conversion_to_base must be positive');

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => $this->uuid(),
                'wastage_percent' => '-0.0001',
            ]));
        }, 'wastage_percent cannot be negative');

        $this->assertInvalidArgument(function () use ($lines, $base): void {
            $lines->createLine(self::$conn, array_merge($base, [
                'line_uuid' => $this->uuid(),
                'modifier_behavior' => 'replace',
            ]));
        }, 'modifier_behavior is invalid');

        $this->assertInvalidArgument(function () use ($lines, $lineId): void {
            $lines->updateLine(self::$conn, $lineId, ['channel' => 'kiosk']);
        }, 'channel is invalid');

        $this->assertInvalidArgument(function () use ($lines): void {
            $lines->removeLine(self::$conn, 0);
        }, 'id must be positive');
    }

    public function testAuditRepositorySupportsFilteredReadOnlyReports(): void
    {
        $audit = new RecipeAuditRepository();
        $audit->log(self::$conn, [
            'pos_tenant' => 2,
            'pos_branch' => 3,
            'recipe_id' => 9001,
            'entity_type' => 'recipe_header',
            'entity_id' => 9001,
            'action' => 'activate',
            'actor_user_id' => 55,
            'before_json' => '{"status":"draft"}',
            'after_json' => '{"status":"active"}',
            'ip_address' => '127.0.0.55',
        ]);
        $oldId = $audit->log(self::$conn, [
            'pos_tenant' => 2,
            'pos_branch' => 3,
            'recipe_id' => 9001,
            'entity_type' => 'recipe_header',
            'entity_id' => 9001,
            'action' => 'archive',
            'actor_user_id' => 55,
        ]);
        self::$conn->query("UPDATE recipe_audit_log SET created_at = '2026-05-01 09:00:00' WHERE id = " . (int) $oldId);

        $service = new RecipeAuditService($audit);
        $rows = $service->report(self::$conn, [
            'pos_tenant' => 2,
            'pos_branch' => 3,
            'recipe_id' => 9001,
            'actor_user_id' => 55,
            'action' => 'activate',
            'entity_type' => 'recipe_header',
            'date_from' => date('Y-m-d'),
            'limit' => 10,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('activate', $rows[0]['action']);
        $this->assertSame('recipe_header', $rows[0]['entity_type']);
        $this->assertSame(9001, (int) $rows[0]['recipe_id']);
        $this->assertContains('activate', $service->actionOptions(self::$conn));
        $this->assertContains('recipe_header', $service->entityTypeOptions(self::$conn));
    }

    public function testAuditRepositoryRejectsUnsafeAuditRows(): void
    {
        $audit = new RecipeAuditRepository();
        $base = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'recipe_id' => 9401,
            'entity_type' => 'recipe_header',
            'entity_id' => 9401,
            'action' => 'activate',
            'actor_user_id' => 42,
            'before_json' => '{"status":"draft"}',
            'after_json' => '{"status":"active"}',
        ];

        $auditId = $audit->log(self::$conn, $base);
        $this->assertGreaterThan(0, $auditId);

        $this->assertInvalidArgument(function () use ($audit, $base): void {
            $audit->log(self::$conn, array_merge($base, [
                'action' => '',
            ]));
        }, 'action is required');

        $this->assertInvalidArgument(function () use ($audit, $base): void {
            $audit->log(self::$conn, array_merge($base, [
                'entity_type' => 'recipe header',
            ]));
        }, 'entity_type contains invalid characters');

        $this->assertInvalidArgument(function () use ($audit, $base): void {
            $audit->log(self::$conn, array_merge($base, [
                'pos_branch' => -1,
            ]));
        }, 'pos_branch cannot be negative');

        $this->assertInvalidArgument(function () use ($audit, $base): void {
            $audit->log(self::$conn, array_merge($base, [
                'recipe_id' => 0,
            ]));
        }, 'recipe_id must be positive when provided');

        $this->assertInvalidArgument(function () use ($audit, $base): void {
            $audit->log(self::$conn, array_merge($base, [
                'after_json' => '{"status":',
            ]));
        }, 'after_json must be valid JSON');
    }

    public function testAuditServiceRejectsUnencodablePayloads(): void
    {
        $service = new RecipeAuditService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode recipe audit payload');

        $service->record(
            self::$conn,
            new RecipeActorContext(42, 0, 0, null, ['recipe.manage'], '127.0.0.1', 'phpunit'),
            'activate',
            'recipe_header',
            9402,
            9402,
            ['bad' => NAN],
            null
        );
    }

    public function testCostSnapshotRepositoryRejectsUnsafeHistoricalCostRows(): void
    {
        $costs = new RecipeCostSnapshotRepository();
        $base = [
            'snapshot_uuid' => $this->uuid(),
            'recipe_id' => 9301,
            'sellable_item_id' => 1301,
            'version_number' => 1,
            'cost_per_yield' => '12.000000',
            'cost_per_sell_unit' => '6.000000',
            'ingredient_cost_json' => '{"ingredient":2301,"cost":"6.000000"}',
            'calculated_at' => '2026-05-24 12:30:00',
        ];

        $snapshotId = $costs->createSnapshot(self::$conn, $base);
        $this->assertGreaterThan(0, $snapshotId);

        $this->assertInvalidArgument(function () use ($costs, $base): void {
            $costs->createSnapshot(self::$conn, array_merge($base, [
                'snapshot_uuid' => '',
            ]));
        }, 'UUID is required');

        $this->assertInvalidArgument(function () use ($costs, $base): void {
            $costs->createSnapshot(self::$conn, array_merge($base, [
                'snapshot_uuid' => $this->uuid(),
                'recipe_id' => 0,
            ]));
        }, 'recipe_id must be positive');

        $this->assertInvalidArgument(function () use ($costs, $base): void {
            $costs->createSnapshot(self::$conn, array_merge($base, [
                'snapshot_uuid' => $this->uuid(),
                'cost_per_yield' => '-1.000000',
            ]));
        }, 'cost_per_yield cannot be negative');

        $this->assertInvalidArgument(function () use ($costs, $base): void {
            $costs->createSnapshot(self::$conn, array_merge($base, [
                'snapshot_uuid' => $this->uuid(),
                'cost_per_sell_unit' => 'abc',
            ]));
        }, 'cost_per_sell_unit must be a decimal value');

        $this->assertInvalidArgument(function () use ($costs, $base): void {
            $costs->createSnapshot(self::$conn, array_merge($base, [
                'snapshot_uuid' => $this->uuid(),
                'ingredient_cost_json' => '{"ingredient":',
            ]));
        }, 'ingredient_cost_json must be valid JSON');

        $this->assertInvalidArgument(function () use ($costs, $base): void {
            $costs->createSnapshot(self::$conn, array_merge($base, [
                'snapshot_uuid' => $this->uuid(),
                'calculated_at' => '',
            ]));
        }, 'calculated_at is required');
    }

    public function testInventoryUsageAndReservationRepositoriesKeepStoreScopedIdempotency(): void
    {
        $usageRepo = new RecipeOrderLineUsageRepository();
        $movementRepo = new InventoryMovementRepository();
        $balanceRepo = new InventoryBalanceRepository();
        $reservationRepo = new StockReservationRepository();

        $usageId = $usageRepo->createUsage(self::$conn, [
            'usage_uuid' => $this->uuid(),
            'order_id' => 9001,
            'fat_detail_id' => 91,
            'order_line_uuid' => $this->uuid(),
            'sellable_item_id' => 1001,
            'order_qty' => '1.000000',
            'recipe_id' => null,
            'idempotency_key' => 'usage:9001:91',
        ]);

        $movementRepo->createMovement(self::$conn, [
            'movement_uuid' => $this->uuid(),
            'item_id' => 2001,
            'movement_type' => 'reservation',
            'source_type' => 'recipe_order_line_usage',
            'source_id' => $usageId,
            'recipe_order_line_usage_id' => $usageId,
            'qty_in' => '0.000000',
            'qty_out' => '0.000000',
            'idempotency_key' => 'reserve:9001:91:2001',
        ]);
        $this->assertDuplicate(function () use ($movementRepo, $usageId): void {
            $movementRepo->createMovement(self::$conn, [
                'movement_uuid' => $this->uuid(),
                'item_id' => 2001,
                'movement_type' => 'reservation',
                'source_type' => 'recipe_order_line_usage',
                'source_id' => $usageId,
                'idempotency_key' => 'reserve:9001:91:2001',
            ]);
        });
        $movementRepo->createMovement(self::$conn, [
            'movement_uuid' => $this->uuid(),
            'store_id' => 1,
            'item_id' => 2001,
            'movement_type' => 'reservation',
            'source_type' => 'recipe_order_line_usage',
            'source_id' => $usageId,
            'idempotency_key' => 'reserve:9001:91:2001',
        ]);

        $balanceRepo->putBalance(self::$conn, [
            'item_id' => 2001,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '2.000000',
            'qty_available' => '8.000000',
        ]);
        $reservationRepo->createReservation(self::$conn, [
            'reservation_uuid' => $this->uuid(),
            'order_id' => 9001,
            'fat_detail_id' => 91,
            'order_line_uuid' => $this->uuid(),
            'recipe_order_line_usage_id' => $usageId,
            'sellable_item_id' => 1001,
            'ingredient_item_id' => 2001,
            'qty_reserved' => '2.000000',
            'idempotency_key' => 'reservation:9001:91:2001',
        ]);

        $balance = $balanceRepo->findBalance(self::$conn, 0, 0, 0, 2001);
        $reservations = $reservationRepo->findActiveForOrderLine(self::$conn, 9001, 91, null);

        $this->assertSame('10.000000', $balance['qty_on_hand']);
        $this->assertSame('2.000000', $balance['qty_reserved']);
        $this->assertSame('2.000000', $reservations[0]['qty_reserved']);
    }

    public function testInventoryMovementRepositoryRejectsUnsafeLedgerRows(): void
    {
        $movementRepo = new InventoryMovementRepository();
        $base = [
            'movement_uuid' => $this->uuid(),
            'item_id' => 2901,
            'movement_type' => 'adjustment',
            'source_type' => 'manual',
            'qty_in' => '1.000000',
            'idempotency_key' => 'repo-safe-ledger-valid-' . $this->uuid(),
        ];

        $movementId = $movementRepo->createMovement(self::$conn, $base);
        $this->assertGreaterThan(0, $movementId);

        $this->assertInvalidArgument(function () use ($movementRepo, $base): void {
            $movementRepo->createMovement(self::$conn, array_merge($base, [
                'movement_uuid' => $this->uuid(),
                'idempotency_key' => '',
            ]));
        }, 'idempotency key is required');

        $this->assertInvalidArgument(function () use ($movementRepo, $base): void {
            $movementRepo->createMovement(self::$conn, array_merge($base, [
                'movement_uuid' => $this->uuid(),
                'movement_type' => 'recipe_consumpton',
                'idempotency_key' => 'repo-safe-ledger-type-' . $this->uuid(),
            ]));
        }, 'movement_type is invalid');

        $this->assertInvalidArgument(function () use ($movementRepo, $base): void {
            $movementRepo->createMovement(self::$conn, array_merge($base, [
                'movement_uuid' => $this->uuid(),
                'source_type' => 'manual_entry',
                'idempotency_key' => 'repo-safe-ledger-source-' . $this->uuid(),
            ]));
        }, 'source_type is invalid');

        $this->assertInvalidArgument(function () use ($movementRepo, $base): void {
            $movementRepo->createMovement(self::$conn, array_merge($base, [
                'movement_uuid' => $this->uuid(),
                'qty_in' => '-1.000000',
                'idempotency_key' => 'repo-safe-ledger-negative-' . $this->uuid(),
            ]));
        }, 'qty_in cannot be negative');

        $this->assertInvalidArgument(function () use ($movementRepo, $base): void {
            $movementRepo->createMovement(self::$conn, array_merge($base, [
                'movement_uuid' => $this->uuid(),
                'qty_in' => 'abc',
                'idempotency_key' => 'repo-safe-ledger-nonnumeric-' . $this->uuid(),
            ]));
        }, 'qty_in must be a decimal value');

        $this->assertInvalidArgument(function () use ($movementRepo, $base): void {
            $movementRepo->createMovement(self::$conn, array_merge($base, [
                'movement_uuid' => $this->uuid(),
                'unit_conversion_to_base' => '0',
                'idempotency_key' => 'repo-safe-ledger-conversion-' . $this->uuid(),
            ]));
        }, 'unit conversion must be positive');

        $this->assertInvalidArgument(function () use ($movementRepo, $base): void {
            $movementRepo->createMovement(self::$conn, array_merge($base, [
                'movement_uuid' => $this->uuid(),
                'qty_out' => '1.000000',
                'idempotency_key' => 'repo-safe-ledger-both-' . $this->uuid(),
            ]));
        }, 'cannot have both qty_in and qty_out positive');
    }

    public function testRecipeOrderLineUsageRepositoryRejectsUnsafeLifecycleRows(): void
    {
        $usageRepo = new RecipeOrderLineUsageRepository();
        $base = [
            'usage_uuid' => $this->uuid(),
            'order_id' => 9201,
            'fat_detail_id' => 201,
            'source_channel' => 'moova',
            'sellable_item_id' => 1201,
            'order_qty' => '1.500000',
            'recipe_id' => 7201,
            'recipe_version_number' => 1,
            'status' => 'reserved',
            'idempotency_key' => 'usage-safe-valid-' . $this->uuid(),
        ];

        $usageId = $usageRepo->createUsage(self::$conn, $base);
        $this->assertGreaterThan(0, $usageId);
        $this->assertSame(1, $usageRepo->updateUsage(self::$conn, $usageId, [
            'status' => 'consumed',
            'cost_total' => '4.500000',
            'consumed_at' => '2026-05-24 12:00:00',
        ]));

        $this->assertInvalidArgument(function () use ($usageRepo, $base): void {
            $usageRepo->createUsage(self::$conn, array_merge($base, [
                'usage_uuid' => $this->uuid(),
                'idempotency_key' => '',
            ]));
        }, 'idempotency key is required');

        $this->assertInvalidArgument(function () use ($usageRepo, $base): void {
            $usageRepo->createUsage(self::$conn, array_merge($base, [
                'usage_uuid' => $this->uuid(),
                'source_channel' => 'kiosk',
                'idempotency_key' => 'usage-safe-channel-' . $this->uuid(),
            ]));
        }, 'source_channel is invalid');

        $this->assertInvalidArgument(function () use ($usageRepo, $base): void {
            $usageRepo->createUsage(self::$conn, array_merge($base, [
                'usage_uuid' => $this->uuid(),
                'status' => 'held',
                'idempotency_key' => 'usage-safe-status-' . $this->uuid(),
            ]));
        }, 'status is invalid');

        $this->assertInvalidArgument(function () use ($usageRepo, $base): void {
            $usageRepo->createUsage(self::$conn, array_merge($base, [
                'usage_uuid' => $this->uuid(),
                'order_qty' => '0',
                'idempotency_key' => 'usage-safe-zero-' . $this->uuid(),
            ]));
        }, 'order_qty must be positive');

        $this->assertInvalidArgument(function () use ($usageRepo, $base): void {
            $usageRepo->createUsage(self::$conn, array_merge($base, [
                'usage_uuid' => $this->uuid(),
                'cost_total' => '-1.000000',
                'idempotency_key' => 'usage-safe-cost-' . $this->uuid(),
            ]));
        }, 'cost_total cannot be negative');

        $this->assertInvalidArgument(function () use ($usageRepo): void {
            $usageRepo->updateUsage(self::$conn, 0, ['status' => 'released']);
        }, 'id must be positive');

        $this->assertInvalidArgument(function () use ($usageRepo, $usageId): void {
            $usageRepo->updateUsage(self::$conn, $usageId, ['status' => 'missing']);
        }, 'status is invalid');

        $this->assertInvalidArgument(function () use ($usageRepo, $usageId): void {
            $usageRepo->updateUsage(self::$conn, $usageId, ['recipe_cost_snapshot_id' => 0]);
        }, 'recipe_cost_snapshot_id must be positive');
    }

    public function testStockReservationRepositoryRejectsUnsafeRowsAndStatuses(): void
    {
        $reservationRepo = new StockReservationRepository();
        $base = [
            'reservation_uuid' => $this->uuid(),
            'order_id' => 9101,
            'fat_detail_id' => 191,
            'sellable_item_id' => 1101,
            'recipe_id' => 7101,
            'ingredient_item_id' => 2101,
            'qty_reserved' => '2.500000',
            'idempotency_key' => 'reservation-safe-valid-' . $this->uuid(),
        ];

        $reservationId = $reservationRepo->createReservation(self::$conn, $base);
        $this->assertGreaterThan(0, $reservationId);
        $this->assertSame(1, $reservationRepo->updateStatus(self::$conn, $reservationId, 'consumed'));

        $this->assertInvalidArgument(function () use ($reservationRepo, $base): void {
            $reservationRepo->createReservation(self::$conn, array_merge($base, [
                'reservation_uuid' => $this->uuid(),
                'idempotency_key' => '',
            ]));
        }, 'idempotency key is required');

        $this->assertInvalidArgument(function () use ($reservationRepo, $base): void {
            $reservationRepo->createReservation(self::$conn, array_merge($base, [
                'reservation_uuid' => $this->uuid(),
                'status' => 'held',
                'idempotency_key' => 'reservation-safe-status-' . $this->uuid(),
            ]));
        }, 'status is invalid');

        $this->assertInvalidArgument(function () use ($reservationRepo, $base): void {
            $reservationRepo->createReservation(self::$conn, array_merge($base, [
                'reservation_uuid' => $this->uuid(),
                'qty_reserved' => '0',
                'idempotency_key' => 'reservation-safe-zero-' . $this->uuid(),
            ]));
        }, 'qty_reserved must be positive');

        $this->assertInvalidArgument(function () use ($reservationRepo, $base): void {
            $reservationRepo->createReservation(self::$conn, array_merge($base, [
                'reservation_uuid' => $this->uuid(),
                'qty_reserved' => 'abc',
                'idempotency_key' => 'reservation-safe-nonnumeric-' . $this->uuid(),
            ]));
        }, 'qty_reserved must be a decimal value');

        $this->assertInvalidArgument(function () use ($reservationRepo, $base): void {
            $reservationRepo->createReservation(self::$conn, array_merge($base, [
                'reservation_uuid' => $this->uuid(),
                'order_id' => 0,
                'idempotency_key' => 'reservation-safe-order-' . $this->uuid(),
            ]));
        }, 'order_id must be positive');

        $this->assertInvalidArgument(function () use ($reservationRepo): void {
            $reservationRepo->updateStatus(self::$conn, 0, 'released');
        }, 'id must be positive');

        $this->assertInvalidArgument(function () use ($reservationRepo, $reservationId): void {
            $reservationRepo->updateStatus(self::$conn, $reservationId, 'missing');
        }, 'status is invalid');
    }

    public function testAvailabilityCacheSeparatesOrderTypeAndChannel(): void
    {
        $cache = new RecipeAvailabilityCacheRepository();

        $cache->putAvailability(self::$conn, [
            'sellable_item_id' => 1001,
            'order_type' => 'takeaway',
            'channel' => 'pos',
            'computed_available_qty' => '5.000000',
            'effective_available_qty' => '5.000000',
            'availability_revision' => 10,
            'calculated_at' => '2026-05-23 12:00:00',
        ]);
        $cache->putAvailability(self::$conn, [
            'sellable_item_id' => 1001,
            'order_type' => 'delivery',
            'channel' => 'moova',
            'computed_available_qty' => '0.000000',
            'effective_available_qty' => '0.000000',
            'effective_is_available' => 0,
            'unavailable_reason' => 'delivery packaging is out of stock',
            'availability_revision' => 11,
            'calculated_at' => '2026-05-23 12:01:00',
        ]);

        $takeaway = $cache->findForItem(self::$conn, 0, 0, 0, 1001, 'takeaway', 'pos');
        $delivery = $cache->findForItem(self::$conn, 0, 0, 0, 1001, 'delivery', 'moova');

        $this->assertSame('5.000000', $takeaway['effective_available_qty']);
        $this->assertSame('0', (string) $delivery['effective_is_available']);
        $this->assertSame('delivery packaging is out of stock', $delivery['unavailable_reason']);
    }

    public function testAvailabilityCacheRepositoryRejectsUnsafeRows(): void
    {
        $cache = new RecipeAvailabilityCacheRepository();
        $base = [
            'sellable_item_id' => 1401,
            'recipe_id' => 7401,
            'order_type' => 'takeaway',
            'channel' => 'pos',
            'computed_available_qty' => '5.000000',
            'effective_available_qty' => '4.000000',
            'effective_is_available' => 1,
            'availability_revision' => 12,
            'calculated_at' => '2026-05-24 13:00:00',
        ];

        $cache->putAvailability(self::$conn, $base);
        $stored = $cache->findForItem(self::$conn, 0, 0, 0, 1401, 'takeaway', 'pos');
        $this->assertSame('4.000000', $stored['effective_available_qty']);

        $this->assertInvalidArgument(function () use ($cache, $base): void {
            $cache->putAvailability(self::$conn, array_merge($base, [
                'sellable_item_id' => 0,
            ]));
        }, 'sellable_item_id must be positive');

        $this->assertInvalidArgument(function () use ($cache, $base): void {
            $cache->putAvailability(self::$conn, array_merge($base, [
                'order_type' => 'curbside',
            ]));
        }, 'order_type is invalid');

        $this->assertInvalidArgument(function () use ($cache, $base): void {
            $cache->putAvailability(self::$conn, array_merge($base, [
                'channel' => 'kiosk',
            ]));
        }, 'channel is invalid');

        $this->assertInvalidArgument(function () use ($cache, $base): void {
            $cache->putAvailability(self::$conn, array_merge($base, [
                'computed_available_qty' => '-1.000000',
            ]));
        }, 'computed_available_qty cannot be negative');

        $this->assertInvalidArgument(function () use ($cache, $base): void {
            $cache->putAvailability(self::$conn, array_merge($base, [
                'effective_is_available' => 'yes',
            ]));
        }, 'effective_is_available must be 0 or 1');

        $this->assertInvalidArgument(function () use ($cache, $base): void {
            $cache->putAvailability(self::$conn, array_merge($base, [
                'availability_revision' => 0,
            ]));
        }, 'availability_revision must be positive');

        $this->assertInvalidArgument(function () use ($cache, $base): void {
            $cache->putAvailability(self::$conn, array_merge($base, [
                'calculated_at' => '',
            ]));
        }, 'calculated_at is required');
    }

    public function testExternalOrderLineMapIsScopedByBranch(): void
    {
        $map = new ExternalOrderLineMapRepository();

        $map->createMapping(self::$conn, [
            'source_channel' => 'moova',
            'external_order_id' => 'MOOVA-1',
            'external_line_id' => 'LINE-1',
            'item_id' => 1001,
            'idempotency_key' => 'moova:0:line-1',
        ]);
        $map->createMapping(self::$conn, [
            'pos_branch' => 2,
            'source_channel' => 'moova',
            'external_order_id' => 'MOOVA-1',
            'external_line_id' => 'LINE-1',
            'item_id' => 1001,
            'idempotency_key' => 'moova:2:line-1',
        ]);

        $this->assertDuplicate(function () use ($map): void {
            $map->createMapping(self::$conn, [
                'source_channel' => 'moova',
                'external_order_id' => 'MOOVA-1',
                'external_line_id' => 'LINE-1',
                'item_id' => 1001,
                'idempotency_key' => 'moova:0:line-1-duplicate',
            ]);
        });

        $branchTwo = $map->findMapping(self::$conn, 0, 2, 'moova', 'MOOVA-1', 'LINE-1');
        $this->assertSame('2', (string) $branchTwo['pos_branch']);
    }

    public function testExternalOrderLineMapRejectsUnsafeRows(): void
    {
        $map = new ExternalOrderLineMapRepository();
        $base = [
            'source_channel' => 'moova',
            'external_order_id' => 'MOOVA-2',
            'external_line_id' => 'LINE-2',
            'item_id' => 1002,
            'idempotency_key' => 'moova:0:line-2',
            'modifiers_hash' => strtoupper(hash('sha256', 'mods')),
            'modifiers_json' => '{"options":[10]}',
        ];

        $mappingId = $map->createMapping(self::$conn, $base);
        $this->assertGreaterThan(0, $mappingId);

        $this->assertInvalidArgument(function () use ($map, $base): void {
            $map->createMapping(self::$conn, array_merge($base, [
                'external_order_id' => 'MOOVA-2-BAD-CHANNEL',
                'source_channel' => 'widget',
                'idempotency_key' => 'moova:0:bad-channel',
            ]));
        }, 'source_channel is invalid');

        $this->assertInvalidArgument(function () use ($map, $base): void {
            $map->createMapping(self::$conn, array_merge($base, [
                'external_order_id' => '',
                'idempotency_key' => 'moova:0:blank-order',
            ]));
        }, 'external_order_id is required');

        $this->assertInvalidArgument(function () use ($map, $base): void {
            $map->createMapping(self::$conn, array_merge($base, [
                'external_order_id' => 'MOOVA-2-BAD-ITEM',
                'item_id' => 0,
                'idempotency_key' => 'moova:0:bad-item',
            ]));
        }, 'item_id must be positive');

        $this->assertInvalidArgument(function () use ($map, $base): void {
            $map->createMapping(self::$conn, array_merge($base, [
                'external_order_id' => 'MOOVA-2-BAD-STATUS',
                'line_status' => 'paid',
                'idempotency_key' => 'moova:0:bad-status',
            ]));
        }, 'line_status is invalid');

        $this->assertInvalidArgument(function () use ($map, $base): void {
            $map->createMapping(self::$conn, array_merge($base, [
                'external_order_id' => 'MOOVA-2-BAD-JSON',
                'modifiers_json' => '{"options":',
                'idempotency_key' => 'moova:0:bad-json',
            ]));
        }, 'modifiers_json must be valid JSON');

        $this->assertInvalidArgument(function () use ($map, $base): void {
            $map->upsertMapping(self::$conn, array_merge($base, [
                'external_order_id' => 'MOOVA-2-BAD-HASH',
                'modifiers_hash' => 'not-a-sha',
                'idempotency_key' => 'moova:0:bad-hash',
            ]));
        }, 'modifiers_hash must be a sha256 hex digest');
    }

    public function testProductionBatchRepositoryRoundTrip(): void
    {
        $repo = new ProductionBatchRepository();
        $batchId = $repo->createBatch(self::$conn, [
            'batch_uuid' => $this->uuid(),
            'recipe_id' => 7001,
            'output_item_id' => 3001,
            'planned_output_qty' => '10.000000',
        ]);
        $lineId = $repo->createBatchLine(self::$conn, [
            'batch_id' => $batchId,
            'line_type' => 'input',
            'item_id' => 2001,
            'planned_qty' => '12.000000',
        ]);

        $batch = $repo->findBatchById(self::$conn, $batchId);

        $this->assertSame($batchId, (int) $batch['id']);
        $this->assertGreaterThan(0, $lineId);
    }

    public function testProductionBatchRepositoryRejectsUnsafeRowsAndStatuses(): void
    {
        $repo = new ProductionBatchRepository();
        $baseBatch = [
            'batch_uuid' => $this->uuid(),
            'recipe_id' => 7101,
            'output_item_id' => 3101,
            'planned_output_qty' => '10.000000',
        ];
        $batchId = $repo->createBatch(self::$conn, $baseBatch);
        $this->assertGreaterThan(0, $batchId);

        $lineId = $repo->createBatchLine(self::$conn, [
            'batch_id' => $batchId,
            'line_type' => 'output',
            'item_id' => 3101,
            'planned_qty' => '10.000000',
            'actual_qty' => '9.000000',
            'unit_cost' => '2.000000',
            'total_cost' => '18.000000',
        ]);
        $this->assertGreaterThan(0, $lineId);
        $this->assertSame(1, $repo->updateCommitted(self::$conn, $batchId, '9.000000', 77, 'evaporation'));

        $this->assertInvalidArgument(function () use ($repo, $baseBatch): void {
            $repo->createBatch(self::$conn, array_merge($baseBatch, [
                'batch_uuid' => $this->uuid(),
                'status' => 'started',
            ]));
        }, 'status is invalid');

        $this->assertInvalidArgument(function () use ($repo, $baseBatch): void {
            $repo->createBatch(self::$conn, array_merge($baseBatch, [
                'batch_uuid' => $this->uuid(),
                'planned_output_qty' => '0',
            ]));
        }, 'planned_output_qty must be positive');

        $this->assertInvalidArgument(function () use ($repo, $baseBatch): void {
            $repo->createBatch(self::$conn, array_merge($baseBatch, [
                'batch_uuid' => $this->uuid(),
                'recipe_id' => 0,
            ]));
        }, 'recipe_id must be positive');

        $this->assertInvalidArgument(function () use ($repo, $batchId): void {
            $repo->createBatchLine(self::$conn, [
                'batch_id' => $batchId,
                'line_type' => 'ingredient',
                'item_id' => 3101,
            ]);
        }, 'line_type is invalid');

        $this->assertInvalidArgument(function () use ($repo, $batchId): void {
            $repo->createBatchLine(self::$conn, [
                'batch_id' => $batchId,
                'line_type' => 'input',
                'item_id' => 3101,
                'actual_qty' => '-1.000000',
            ]);
        }, 'actual_qty cannot be negative');

        $this->assertInvalidArgument(function () use ($repo): void {
            $repo->updateCommitted(self::$conn, 0, '1.000000', 77);
        }, 'id must be positive');

        $this->assertInvalidArgument(function () use ($repo, $batchId): void {
            $repo->updateCommitted(self::$conn, $batchId, '0', 77);
        }, 'actual_output_qty must be positive');

        $this->assertInvalidArgument(function () use ($repo): void {
            $repo->cancel(self::$conn, 0, 'cancel');
        }, 'id must be positive');
    }

    private function assertDuplicate(callable $callback): void
    {
        try {
            $callback();
        } catch (mysqli_sql_exception $exception) {
            $this->assertStringContainsString('Duplicate', $exception->getMessage());
            return;
        }

        $this->fail('Expected duplicate-key exception.');
    }

    private function assertInvalidArgument(callable $callback, string $messageFragment): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
            return;
        }

        $this->fail('Expected invalid-argument exception.');
    }

    private function uuid(): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', self::$uuidCounter++);
    }
}
