<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/ProductionBatchService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

final class FailingProductionBatchSyncEventService extends OperationalSyncEventService
{
    public function recordProductionBatchSnapshot(mysqli $conn, int $batchId, array $options = []): ?array
    {
        throw new RuntimeException('PRODUCTION_BATCH_SYNC_CAPTURE_FAILED');
    }
}

class ProductionBatchServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 25000;

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
        self::$dbName = 'posmain_recipe_production_' . getmypid();
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
        self::createJournalTables();
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

    public function testCreateDraftAndPreviewProductionRequirements(): void
    {
        $setup = $this->productionRecipe();
        $batch = $this->service()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        $preview = $this->service()->preview(self::$conn, (int) $batch['id']);

        $this->assertSame('draft', $batch['status']);
        $this->assertCount(2, $preview['requirements']);
        $this->assertSame('12.000000', $preview['requirements'][0]['required_qty_base']);
        $this->assertSame('100.000000', $preview['total_input_cost']);
    }

    public function testCommitConsumesInputsCreatesOutputAndLocksBatch(): void
    {
        $setup = $this->productionRecipe();
        $batch = $this->service()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        $result = $this->service()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '10.000000',
        ], $this->actor(['recipe.approve']));

        $tomatoBalance = $this->balance($setup['tomato_id']);
        $oilBalance = $this->balance($setup['oil_id']);
        $outputBalance = $this->balance($setup['output_item_id']);
        $movementTypes = array_column($this->rows('inventory_movements', 'production_batch_id = ' . (int) $batch['id']), 'movement_type');

        $this->assertSame('committed', $result['batch']['status']);
        $this->assertSame('8.000000', $tomatoBalance['qty_on_hand']);
        $this->assertSame('4.000000', $oilBalance['qty_on_hand']);
        $this->assertSame('10.000000', $outputBalance['qty_on_hand']);
        $this->assertSame('10.000000', $outputBalance['moving_average_cost']);
        $this->assertContains('production_input', $movementTypes);
        $this->assertContains('production_output', $movementTypes);
        $this->assertCount(3, $result['lines']);
        $this->assertSame([], $result['availability_refreshes']);

        $this->expectException(RuntimeException::class);
        $this->service()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '10.000000',
        ], $this->actor(['recipe.approve']));
    }

    public function testCommitRefreshesAvailabilityForProductionInputsAndOutputDependencies(): void
    {
        self::$conn->query('DELETE FROM recipe_availability_cache');
        $setup = $this->productionRecipe();
        $tomatoMenuItemId = $this->item('Tomato sandwich', '0.000000');
        $sauceMenuItemId = $this->item('Pasta with prepared sauce', '0.000000');
        $this->activeRecipeWithLines($tomatoMenuItemId, [], [
            [
                'ingredient_item_id' => $setup['tomato_id'],
                'qty_per_yield' => '1.000000',
            ],
        ]);
        $this->activeRecipeWithLines($sauceMenuItemId, [], [
            [
                'ingredient_item_id' => $setup['output_item_id'],
                'qty_per_yield' => '2.000000',
            ],
        ]);
        $batch = $this->availabilityProductionService()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        $result = $this->availabilityProductionService()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '10.000000',
        ], $this->actor(['recipe.approve']));
        $refreshedItems = array_map('intval', array_column($result['availability_refreshes'], 'sellable_item_id'));
        $outputCache = $this->availabilityCacheRow($setup['output_item_id']);
        $tomatoMenuCache = $this->availabilityCacheRow($tomatoMenuItemId);
        $sauceMenuCache = $this->availabilityCacheRow($sauceMenuItemId);

        $this->assertContains($setup['output_item_id'], $refreshedItems);
        $this->assertContains($tomatoMenuItemId, $refreshedItems);
        $this->assertContains($sauceMenuItemId, $refreshedItems);
        $this->assertSame('10.000000', $outputCache['computed_available_qty']);
        $this->assertSame('8.000000', $tomatoMenuCache['computed_available_qty']);
        $this->assertSame('5.000000', $sauceMenuCache['computed_available_qty']);
    }

    public function testVarianceReasonRequiredWhenActualOutputDiffers(): void
    {
        $setup = $this->productionRecipe();
        $batch = $this->service()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        try {
            $this->service()->commit(self::$conn, (int) $batch['id'], [
                'actual_output_qty' => '9.000000',
            ], $this->actor(['recipe.approve']));
            $this->fail('Expected missing variance reason to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Variance reason', $exception->getMessage());
        }

        $result = $this->service()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '9.000000',
            'variance_reason' => 'evaporation',
        ], $this->actor(['recipe.approve']));

        $this->assertSame('committed', $result['batch']['status']);
        $this->assertSame('evaporation', $result['batch']['variance_reason']);
    }

    public function testStrictStockBlocksInsufficientInputs(): void
    {
        $setup = $this->productionRecipe('1.000000', '5.000000');
        $batch = $this->strictService()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        $this->expectException(RuntimeException::class);
        $this->strictService()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '10.000000',
        ], $this->actor(['recipe.approve']));
    }

    public function testCommitPostsProductionAccountingWhenAccountingEnabled(): void
    {
        $setup = $this->productionRecipe();
        $batch = $this->accountingService()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        $result = $this->accountingService()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '10.000000',
        ], $this->actor(['recipe.approve']));
        $journalId = (int) ($result['accounting']['journal_head_id'] ?? 0);
        $entries = $this->journalEntries($journalId);
        $linkedMovementIds = array_merge($result['input_movement_ids'], $result['output_movement_ids']);

        $this->assertGreaterThan(0, $journalId);
        $this->assertSame(2, $result['accounting']['entry_count']);
        $this->assertSame(130, (int) $entries[0]['account_id']);
        $this->assertSame('100.0000', $entries[0]['debit']);
        $this->assertSame('0.0000', $entries[0]['credit']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
        $this->assertSame('0.0000', $entries[1]['debit']);
        $this->assertSame('100.0000', $entries[1]['credit']);
        foreach ($linkedMovementIds as $movementId) {
            $movement = $this->rows('inventory_movements', 'id = ' . (int) $movementId)[0];
            $this->assertSame($journalId, (int) $movement['accounting_journal_id']);
        }
    }

    public function testCommitCanPostExplicitProductionVarianceWhenPolicyEnabled(): void
    {
        $setup = $this->productionRecipe();
        $batch = $this->varianceAccountingService()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        $result = $this->varianceAccountingService()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '9.000000',
            'variance_reason' => 'yield loss',
        ], $this->actor(['recipe.approve']));
        $journalId = (int) ($result['accounting']['journal_head_id'] ?? 0);
        $entries = $this->journalEntries($journalId);
        $outputMovement = $this->rows('inventory_movements', 'id = ' . (int) $result['output_movement_ids'][0])[0];
        $batchLines = $this->rows('production_batch_lines', 'batch_id = ' . (int) $batch['id']);
        $lineTypes = array_column($batchLines, 'line_type');
        $varianceLine = $batchLines[array_search('variance', $lineTypes, true)];
        $outputBalance = $this->balance($setup['output_item_id']);

        $this->assertSame('90.000000', $result['output_total_cost']);
        $this->assertSame('10.000000', $result['variance_cost']);
        $this->assertSame('90.000000', $outputMovement['total_cost']);
        $this->assertSame('10.000000', $outputBalance['moving_average_cost']);
        $this->assertContains('variance', $lineTypes);
        $this->assertSame('10.000000', $varianceLine['total_cost']);
        $this->assertSame(3, $result['accounting']['entry_count']);
        $this->assertSame(130, (int) $entries[0]['account_id']);
        $this->assertSame('90.0000', $entries[0]['debit']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
        $this->assertSame('100.0000', $entries[1]['credit']);
        $this->assertSame(530, (int) $entries[2]['account_id']);
        $this->assertSame('10.0000', $entries[2]['debit']);
    }

    public function testAccountingFailureRollsBackProductionMovementsAndLeavesBatchDraft(): void
    {
        $setup = $this->productionRecipe();
        $batch = $this->incompleteAccountingService()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        try {
            $this->incompleteAccountingService()->commit(self::$conn, (int) $batch['id'], [
                'actual_output_qty' => '10.000000',
            ], $this->actor(['recipe.approve']));
            $this->fail('Expected missing production accounting account to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('raw_inventory_account_id', $exception->getMessage());
        }

        $batchAfterFailure = $this->rows('production_batches', 'id = ' . (int) $batch['id'])[0];
        $movements = $this->rows('inventory_movements', 'production_batch_id = ' . (int) $batch['id']);
        $tomatoBalance = $this->balance($setup['tomato_id']);
        $oilBalance = $this->balance($setup['oil_id']);

        $this->assertSame('draft', $batchAfterFailure['status']);
        $this->assertSame([], $movements);
        $this->assertSame('20.000000', $tomatoBalance['qty_on_hand']);
        $this->assertSame('5.000000', $oilBalance['qty_on_hand']);
    }

    public function testCancelOnlyAllowsDraftBatches(): void
    {
        $setup = $this->productionRecipe();
        $batch = $this->service()->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));

        $this->service()->cancel(self::$conn, (int) $batch['id'], 'operator cancelled', $this->actor(['recipe.manage']));
        $cancelled = $this->rows('production_batches', 'id = ' . (int) $batch['id'])[0];

        $this->assertSame('cancelled', $cancelled['status']);
        $this->expectException(RuntimeException::class);
        $this->service()->commit(self::$conn, (int) $batch['id'], [
            'actual_output_qty' => '10.000000',
        ], $this->actor(['recipe.approve']));
    }

    public function testDisabledProductionWritesAreRejected(): void
    {
        $service = new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $service->createDraft(self::$conn, [
            'recipe_id' => 1,
            'output_item_id' => 2,
            'planned_output_qty' => '1.000000',
        ], $this->actor(['recipe.manage']));
    }

    public function testProductionBatchSyncIsStrictlyVersionedOrderedAndAtomic(): void
    {
        $setup = $this->productionRecipe();
        $config = $this->productionSyncConfig('branch');
        (new SyncBranchIdentity())->ensure(self::$conn, $config);
        $service = $this->syncProductionService($config);
        self::$conn->query('DELETE FROM sync_outbox');

        $draft = $service->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));
        $batchId = (int) $draft['id'];
        $this->assertSame(1, (int) $draft['sync_revision']);
        $draftEvent = $this->productionSyncEvent($batchId, 1);
        $this->assertSame('production_batch_bundle', $draftEvent['payload']['snapshot_type']);
        $this->assertSame('draft', $draftEvent['payload']['production_batch']['status']);
        $this->assertSame([], $draftEvent['payload']['production_batch_lines']);

        $beforeCommitOutbox = (int) self::$conn->query('SELECT COALESCE(MAX(id), 0) AS id FROM sync_outbox')->fetch_assoc()['id'];
        $committed = $service->commit(self::$conn, $batchId, [
            'actual_output_qty' => '10.000000',
        ], $this->actor(['recipe.approve']));
        $this->assertSame(2, (int) $committed['batch']['sync_revision']);
        $commitEvent = $this->productionSyncEvent($batchId, 2);
        $this->assertSame('committed', $commitEvent['payload']['production_batch']['status']);
        $this->assertCount(3, $commitEvent['payload']['production_batch_lines']);
        $this->assertContains('input', array_column($commitEvent['payload']['production_batch_lines'], 'line_type'));
        $this->assertContains('output', array_column($commitEvent['payload']['production_batch_lines'], 'line_type'));
        $afterCommit = self::$conn->query("SELECT aggregate_type FROM sync_outbox WHERE id > {$beforeCommitOutbox} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $this->assertNotEmpty($afterCommit);
        $this->assertSame('production_batch', (string) end($afterCommit)['aggregate_type']);

        $cancelDraft = $service->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '2.000000',
        ], $this->actor(['recipe.manage']));
        $cancelId = (int) $cancelDraft['id'];
        $service->cancel(self::$conn, $cancelId, 'Operator cancelled', $this->actor(['recipe.manage']));
        $cancelEvent = $this->productionSyncEvent($cancelId, 2);
        $this->assertSame('cancelled', $cancelEvent['payload']['production_batch']['status']);
        $this->assertSame([], $cancelEvent['payload']['production_batch_lines']);

        $failureDraft = $service->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '3.000000',
        ], $this->actor(['recipe.manage']));
        $failureId = (int) $failureDraft['id'];
        self::$conn->query('DELETE FROM sync_outbox');
        $failing = $this->syncProductionService($config, new FailingProductionBatchSyncEventService());
        try {
            $failing->commit(self::$conn, $failureId, [
                'actual_output_qty' => '3.000000',
            ], $this->actor(['recipe.approve']));
            $this->fail('Expected production sync capture failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PRODUCTION_BATCH_SYNC_CAPTURE_FAILED', $exception->getMessage());
        }
        $failedBatch = $this->rows('production_batches', 'id = ' . $failureId)[0];
        $this->assertSame('draft', $failedBatch['status']);
        $this->assertSame(1, (int) $failedBatch['sync_revision']);
        $this->assertSame([], $this->rows('production_batch_lines', 'batch_id = ' . $failureId));
        $this->assertSame([], $this->rows('inventory_movements', 'production_batch_id = ' . $failureId));
        $this->assertSame(0, (int) self::$conn->query('SELECT COUNT(*) AS c FROM sync_outbox')->fetch_assoc()['c']);

        $hostedConfig = $this->productionSyncConfig('cloud');
        $hosted = $this->syncProductionService($hostedConfig);
        $hostedDraft = $hosted->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '1.000000',
        ], $this->actor(['recipe.manage']));
        $this->assertSame(1, (int) $hostedDraft['sync_revision']);
        $this->assertSame(0, (int) self::$conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'production_batch'")->fetch_assoc()['c']);
    }

    public function testShadowModeDoesNotAllowProductionStockWrites(): void
    {
        $setup = $this->productionRecipe();
        $service = new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
                'consumption' => false,
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $service->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));
    }

    public function testProductionWritesRespectPilotBranchScope(): void
    {
        $setup = $this->productionRecipe();
        $service = new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '2',
                    'item_ids' => [$setup['output_item_id']],
                    'category_ids' => [],
                ],
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $service->createDraft(self::$conn, [
            'recipe_id' => $setup['recipe_id'],
            'output_item_id' => $setup['output_item_id'],
            'planned_output_qty' => '10.000000',
        ], $this->actor(['recipe.manage']));
    }

    private function productionRecipe(string $tomatoStock = '20.000000', string $oilStock = '5.000000'): array
    {
        $outputItemId = $this->item('Tomato sauce', '0.000000');
        $tomatoId = $this->item('Tomato', '5.000000');
        $oilId = $this->item('Oil', '40.000000');
        $this->putBalance($tomatoId, $tomatoStock);
        $this->putBalance($oilId, $oilStock);

        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, [
            'sellable_item_id' => $outputItemId,
            'recipe_name' => 'Tomato sauce batch',
            'recipe_type' => 'batch_prepared',
            'yield_qty' => '10.000000',
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $tomatoId,
            'qty_per_yield' => '12.000000',
        ], $actor);
        $definition->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => $oilId,
            'qty_per_yield' => '1.000000',
        ], $actor);
        $active = $definition->activate(self::$conn, (int) $recipe['id'], $actor);

        return [
            'recipe_id' => (int) $active['id'],
            'output_item_id' => $outputItemId,
            'tomato_id' => $tomatoId,
            'oil_id' => $oilId,
        ];
    }

    private function activeRecipeWithLines(int $sellableItemId, array $recipeData, array $lines): array
    {
        $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);
        $recipe = $definition->createDraft(self::$conn, array_merge([
            'sellable_item_id' => $sellableItemId,
            'recipe_name' => 'Availability recipe ' . $sellableItemId,
        ], $recipeData), $actor);
        foreach ($lines as $line) {
            $definition->addLine(self::$conn, (int) $recipe['id'], $line, $actor);
        }

        return $definition->activate(self::$conn, (int) $recipe['id'], $actor);
    }

    private function putBalance(int $itemId, string $onHand): void
    {
        (new InventoryBalanceRepository())->putBalance(self::$conn, [
            'item_id' => $itemId,
            'qty_on_hand' => $onHand,
            'qty_reserved' => '0.000000',
            'qty_available' => $onHand,
        ]);
    }

    private function balance(int $itemId): array
    {
        return (new InventoryBalanceRepository())->findBalance(self::$conn, 0, 0, 0, $itemId);
    }

    private function item(string $name, string $cost): int
    {
        $id = ++self::$itemCounter;
        $stmt = self::$conn->prepare('INSERT INTO myitems (id, iname, cost_price) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $id, $name, $cost);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    private function service(): ProductionBatchService
    {
        return new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function syncProductionService(
        array $config,
        ?OperationalSyncEventService $syncEvents = null
    ): ProductionBatchService {
        $recipeFlags = new RecipeFeatureFlags($config);
        $inventoryFlags = new InventoryFeatureFlags($config);
        $movements = new RecipeInventoryMovementService(
            $recipeFlags,
            null,
            null,
            $inventoryFlags,
            new InventoryLedgerService($inventoryFlags)
        );

        return new ProductionBatchService(
            $recipeFlags,
            null,
            null,
            null,
            $movements,
            null,
            null,
            null,
            null,
            null,
            null,
            $syncEvents
        );
    }

    private function productionSyncConfig(string $role): array
    {
        return [
            'role' => $role,
            'branch' => [
                'uuid' => '96969696-9696-4696-8696-969696969696',
                'name' => 'Production Sync Test',
                'pos_tenant' => 0,
                'pos_branch' => 0,
            ],
            'sync' => [
                'outbox_enabled' => true,
                'branch_sync_enabled' => true,
                'operational_sync_enabled' => true,
                'cloud_to_branch_publish_enabled' => true,
            ],
            'inventory' => [
                'ledger_mode' => 'live',
                'sync' => true,
            ],
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ];
    }

    private function productionSyncEvent(int $batchId, int $revision): array
    {
        $row = self::$conn->query("SELECT * FROM sync_outbox
            WHERE aggregate_type = 'production_batch'
              AND aggregate_local_id = {$batchId}
              AND event_version = {$revision}
            LIMIT 1")->fetch_assoc();
        $this->assertIsArray($row);

        return [
            'row' => $row,
            'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function strictService(): ProductionBatchService
    {
        return new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'strict_stock' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function accountingService(): ProductionBatchService
    {
        return new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'accounting_pilot',
                'consumption' => true,
                'accounting' => true,
                'accounts' => [
                    'raw_inventory_account_id' => 120,
                    'prepared_inventory_account_id' => 130,
                    'production_variance_account_id' => 530,
                    'cogs_account_id' => 500,
                    'packaging_inventory_account_id' => 140,
                    'waste_expense_account_id' => 540,
                ],
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function availabilityProductionService(): ProductionBatchService
    {
        return new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'consumption' => true,
                'availability' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function incompleteAccountingService(): ProductionBatchService
    {
        return new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'accounting_pilot',
                'consumption' => true,
                'accounting' => true,
                'accounts' => [
                    'prepared_inventory_account_id' => 130,
                    'production_variance_account_id' => 530,
                ],
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function varianceAccountingService(): ProductionBatchService
    {
        return new ProductionBatchService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'accounting_pilot',
                'consumption' => true,
                'accounting' => true,
                'production_variance_policy' => 'post_variance',
                'accounts' => [
                    'raw_inventory_account_id' => 120,
                    'prepared_inventory_account_id' => 130,
                    'production_variance_account_id' => 530,
                    'cogs_account_id' => 500,
                    'packaging_inventory_account_id' => 140,
                    'waste_expense_account_id' => 540,
                ],
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]));
    }

    private function actor(array $permissions): RecipeActorContext
    {
        return new RecipeActorContext(77, 0, 0, null, $permissions, '127.0.0.1', 'phpunit');
    }

    private function rows(string $table, string $where = '1=1'): array
    {
        $result = self::$conn->query("SELECT * FROM {$table} WHERE {$where} ORDER BY id");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function journalEntries(int $journalHeadId): array
    {
        $result = self::$conn->query("SELECT * FROM journal_entries WHERE journal_id = {$journalHeadId} ORDER BY id");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function availabilityCacheRow(int $sellableItemId): array
    {
        $stmt = self::$conn->prepare("
SELECT *
FROM recipe_availability_cache
WHERE sellable_item_id = ?
  AND order_type = 'takeaway'
  AND channel = 'pos'
ORDER BY id DESC
LIMIT 1");
        $stmt->bind_param('i', $sellableItemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertIsArray($row, 'Missing availability cache row for item ' . $sellableItemId);

        return $row;
    }

    private static function createJournalTables(): void
    {
        self::$conn->query("
            CREATE TABLE journal_heads (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                jdate DATE NULL,
                op_id INT NULL,
                pro_tybe INT NULL,
                details VARCHAR(255) NULL,
                user INT NULL,
                op2 INT NULL,
                tenant INT NULL DEFAULT 0,
                branch INT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        self::$conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                credit DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                tybe INT NOT NULL DEFAULT 0,
                info VARCHAR(255) NULL,
                op_id INT NULL,
                op2 INT NULL,
                tenant INT NULL DEFAULT 0,
                branch INT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}
