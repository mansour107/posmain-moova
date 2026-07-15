<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeAccountingService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryMovementRepository.php';

class RecipeAccountingServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_accounting_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
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

        self::$conn->query('DELETE FROM journal_entries');
        self::$conn->query('DELETE FROM journal_heads');
        self::$conn->query('DELETE FROM inventory_movements');
        self::$conn->query('DELETE FROM document_counters');
    }

    public function testSaleCogsPostsBalancedJournalAndLinksMovementsIdempotently(): void
    {
        $repo = new InventoryMovementRepository();
        $firstMovementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000101',
            'item_id' => 3001,
            'movement_type' => 'recipe_consumption',
            'source_type' => 'recipe_order_line_usage',
            'order_id' => 7001,
            'total_cost' => '5.250000',
            'idempotency_key' => 'acct-sale-1',
        ]);
        $secondMovementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000102',
            'item_id' => 3002,
            'movement_type' => 'recipe_consumption',
            'source_type' => 'recipe_order_line_usage',
            'order_id' => 7001,
            'total_cost' => '6.750000',
            'idempotency_key' => 'acct-sale-2',
        ]);
        $service = new RecipeAccountingService($this->accountingFlags());

        $posted = $service->postSaleCogs(self::$conn, [
            'order_id' => 7001,
            'sellable_item_id' => 1001,
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
            'user_id' => 77,
        ], [$firstMovementId, $secondMovementId]);
        $postedAgain = $service->postSaleCogs(self::$conn, [
            'order_id' => 7001,
            'sellable_item_id' => 1001,
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
            'user_id' => 77,
        ], [$firstMovementId, $secondMovementId]);
        $entries = $this->journalEntries($posted['journal_head_id']);
        $linked = $repo->findByIds(self::$conn, [$firstMovementId, $secondMovementId]);

        $this->assertFalse($posted['noop']);
        $this->assertSame($posted['journal_head_id'], $postedAgain['journal_head_id']);
        $this->assertSame(1, $this->tableCount('journal_heads'));
        $this->assertSame(2, $posted['entry_count']);
        $this->assertSame(510, (int) $entries[0]['account_id']);
        $this->assertSame('12.0000', $entries[0]['debit']);
        $this->assertSame('0.0000', $entries[0]['credit']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
        $this->assertSame('0.0000', $entries[1]['debit']);
        $this->assertSame('12.0000', $entries[1]['credit']);
        $this->assertSame($posted['journal_head_id'], (int) $linked[0]['accounting_journal_id']);
        $this->assertSame($posted['journal_head_id'], (int) $linked[1]['accounting_journal_id']);
    }

    public function testSaleCogsUsesConfiguredAccountDefaults(): void
    {
        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000111',
            'item_id' => 3001,
            'movement_type' => 'recipe_consumption',
            'source_type' => 'recipe_order_line_usage',
            'order_id' => 7011,
            'total_cost' => '12.000000',
            'idempotency_key' => 'acct-sale-defaults',
        ]);
        $service = new RecipeAccountingService(
            $this->accountingFlags(),
            null,
            $repo,
            new RecipeSettingsService([
                'recipe' => [
                    'accounts' => [
                        'cogs_account_id' => 510,
                        'raw_inventory_account_id' => 120,
                    ],
                ],
            ])
        );

        $posted = $service->postSaleCogs(self::$conn, [
            'order_id' => 7011,
            'sellable_item_id' => 1001,
        ], [$movementId]);
        $entries = $this->journalEntries($posted['journal_head_id']);

        $this->assertFalse($posted['noop']);
        $this->assertSame(510, (int) $entries[0]['account_id']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
    }

    public function testSaleCogsResolvesMissingAccountsFromChartOfAccounts(): void
    {
        $this->seedRecipeChartAccounts();
        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000112',
            'item_id' => 3002,
            'movement_type' => 'recipe_consumption',
            'source_type' => 'recipe_order_line_usage',
            'order_id' => 7012,
            'total_cost' => '8.500000',
            'idempotency_key' => 'acct-sale-chart-defaults',
        ]);
        $service = new RecipeAccountingService(
            $this->accountingFlags(),
            null,
            $repo,
            new RecipeSettingsService([
                'recipe' => [
                    'accounts' => [
                        'cogs_account_id' => 0,
                        'raw_inventory_account_id' => 0,
                        'prepared_inventory_account_id' => 0,
                        'packaging_inventory_account_id' => 0,
                    ],
                ],
            ])
        );

        $posted = $service->postSaleCogs(self::$conn, [
            'order_id' => 7012,
            'sellable_item_id' => 1001,
        ], [$movementId]);
        $entries = $this->journalEntries($posted['journal_head_id']);

        $this->assertFalse($posted['noop']);
        $this->assertSame(16, (int) $entries[0]['account_id']);
        $this->assertSame(20, (int) $entries[1]['account_id']);
    }

    public function testProductionBatchPostsPreparedInventoryAndVariance(): void
    {
        $repo = new InventoryMovementRepository();
        $inputId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000201',
            'item_id' => 4001,
            'movement_type' => 'production_input',
            'source_type' => 'production_batch',
            'production_batch_id' => 22,
            'total_cost' => '120.000000',
            'idempotency_key' => 'acct-prod-in',
        ]);
        $outputId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000202',
            'item_id' => 5001,
            'movement_type' => 'production_output',
            'source_type' => 'production_batch',
            'production_batch_id' => 22,
            'total_cost' => '100.000000',
            'idempotency_key' => 'acct-prod-out',
        ]);
        $service = new RecipeAccountingService($this->accountingFlags());

        $posted = $service->postProductionBatch(self::$conn, [
            'batch_id' => 22,
            'output_item_id' => 5001,
            'raw_inventory_account_id' => 120,
            'prepared_inventory_account_id' => 130,
            'production_variance_account_id' => 530,
            'user_id' => 77,
        ], [$inputId], [$outputId]);
        $entries = $this->journalEntries($posted['journal_head_id']);

        $this->assertSame(3, $posted['entry_count']);
        $this->assertSame(130, (int) $entries[0]['account_id']);
        $this->assertSame('100.0000', $entries[0]['debit']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
        $this->assertSame('120.0000', $entries[1]['credit']);
        $this->assertSame(530, (int) $entries[2]['account_id']);
        $this->assertSame('20.0000', $entries[2]['debit']);
    }

    public function testRefundReversalDebitsInventoryAndCreditsCogs(): void
    {
        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000301',
            'item_id' => 3001,
            'movement_type' => 'refund_reversal',
            'source_type' => 'order_line',
            'order_id' => 7003,
            'qty_in' => '2.000000',
            'qty_out' => '0.000000',
            'total_cost' => '12.000000',
            'idempotency_key' => 'acct-refund',
        ]);
        $service = new RecipeAccountingService($this->accountingFlags());

        $posted = $service->postRefundReversal(self::$conn, [
            'order_id' => 7003,
            'sellable_item_id' => 1001,
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
        ], [$movementId]);
        $entries = $this->journalEntries($posted['journal_head_id']);

        $this->assertSame(2, $posted['entry_count']);
        $this->assertSame(120, (int) $entries[0]['account_id']);
        $this->assertSame('12.0000', $entries[0]['debit']);
        $this->assertSame(510, (int) $entries[1]['account_id']);
        $this->assertSame('12.0000', $entries[1]['credit']);
    }

    public function testRefundReversalCanDebitPreparedInventoryAccount(): void
    {
        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000302',
            'item_id' => 3002,
            'movement_type' => 'refund_reversal',
            'source_type' => 'order_line',
            'order_id' => 7004,
            'qty_in' => '2.000000',
            'qty_out' => '0.000000',
            'total_cost' => '12.000000',
            'idempotency_key' => 'acct-refund-prepared',
        ]);
        $service = new RecipeAccountingService($this->accountingFlags());

        $posted = $service->postRefundReversal(self::$conn, [
            'order_id' => 7004,
            'sellable_item_id' => 1002,
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
            'prepared_inventory_account_id' => 130,
            'recipe_inventory_account_type' => 'prepared',
        ], [$movementId]);
        $entries = $this->journalEntries($posted['journal_head_id']);

        $this->assertSame(2, $posted['entry_count']);
        $this->assertSame(130, (int) $entries[0]['account_id']);
        $this->assertSame('12.0000', $entries[0]['debit']);
        $this->assertSame(510, (int) $entries[1]['account_id']);
        $this->assertSame('12.0000', $entries[1]['credit']);
    }

    public function testWastePostsWasteExpenseAndInventoryCredit(): void
    {
        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000351',
            'item_id' => 3001,
            'movement_type' => 'waste',
            'source_type' => 'manual',
            'qty_out' => '2.000000',
            'total_cost' => '9.500000',
            'idempotency_key' => 'acct-waste',
        ]);
        $service = new RecipeAccountingService($this->accountingFlags());

        $posted = $service->postWaste(self::$conn, [
            'item_id' => 3001,
            'waste_expense_account_id' => 540,
            'raw_inventory_account_id' => 120,
        ], [$movementId]);
        $entries = $this->journalEntries($posted['journal_head_id']);

        $this->assertSame(2, $posted['entry_count']);
        $this->assertSame(540, (int) $entries[0]['account_id']);
        $this->assertSame('9.5000', $entries[0]['debit']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
        $this->assertSame('9.5000', $entries[1]['credit']);
    }

    public function testDisabledAccountingDoesNotWriteJournalsOrLinkMovements(): void
    {
        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000401',
            'item_id' => 3001,
            'movement_type' => 'recipe_consumption',
            'source_type' => 'recipe_order_line_usage',
            'order_id' => 7004,
            'total_cost' => '12.000000',
            'idempotency_key' => 'acct-disabled',
        ]);
        $service = new RecipeAccountingService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
                'accounting' => false,
            ],
        ]));

        $posted = $service->postSaleCogs(self::$conn, [
            'order_id' => 7004,
            'sellable_item_id' => 1001,
            'cogs_account_id' => 510,
            'raw_inventory_account_id' => 120,
        ], [$movementId]);
        $movement = $repo->findByIds(self::$conn, [$movementId])[0];

        $this->assertTrue($posted['noop']);
        $this->assertSame(0, $this->tableCount('journal_heads'));
        $this->assertNull($movement['accounting_journal_id']);
    }

    public function testAccountingRefusesMissingMovementIdsWithoutPartialJournal(): void
    {
        $service = new RecipeAccountingService($this->accountingFlags());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipe accounting movement id is missing.');
        try {
            $service->postSaleCogs(self::$conn, [
                'order_id' => 7006,
                'sellable_item_id' => 1001,
                'cogs_account_id' => 510,
                'raw_inventory_account_id' => 120,
            ], [999999]);
        } finally {
            $this->assertSame(0, $this->tableCount('journal_heads'));
            $this->assertSame(0, $this->tableCount('journal_entries'));
        }
    }

    public function testAccountingRefusesUnexpectedMovementTypesWithoutPartialJournal(): void
    {
        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000601',
            'item_id' => 3001,
            'movement_type' => 'adjustment',
            'source_type' => 'adjustment',
            'source_id' => 9001,
            'total_cost' => '12.000000',
            'idempotency_key' => 'acct-wrong-type',
        ]);
        $service = new RecipeAccountingService($this->accountingFlags());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipe accounting movement type is invalid for this posting.');
        try {
            $service->postSaleCogs(self::$conn, [
                'order_id' => 7007,
                'sellable_item_id' => 1001,
                'cogs_account_id' => 510,
                'raw_inventory_account_id' => 120,
            ], [$movementId]);
        } finally {
            $movement = $repo->findByIds(self::$conn, [$movementId])[0];
            $this->assertSame(0, $this->tableCount('journal_heads'));
            $this->assertNull($movement['accounting_journal_id']);
        }
    }

    public function testAccountingRefusesIntegerJournalEntryColumns(): void
    {
        self::$conn->query('DROP TABLE journal_entries');
        self::$conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit INT NOT NULL DEFAULT 0,
                credit INT NOT NULL DEFAULT 0,
                tybe INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $repo = new InventoryMovementRepository();
        $movementId = $this->movement($repo, [
            'movement_uuid' => '00000000-0000-4000-8000-000000000501',
            'item_id' => 3001,
            'movement_type' => 'recipe_consumption',
            'source_type' => 'recipe_order_line_usage',
            'order_id' => 7005,
            'total_cost' => '12.250000',
            'idempotency_key' => 'acct-integer-guard',
        ]);
        $service = new RecipeAccountingService($this->accountingFlags());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decimal-safe journal entry columns');
        try {
            $service->postSaleCogs(self::$conn, [
                'order_id' => 7005,
                'sellable_item_id' => 1001,
                'cogs_account_id' => 510,
                'raw_inventory_account_id' => 120,
            ], [$movementId]);
        } finally {
            self::$conn->query('DROP TABLE journal_entries');
            self::createJournalEntriesTable();
        }
    }

    private function movement(InventoryMovementRepository $repo, array $data): int
    {
        return $repo->createMovement(self::$conn, array_merge([
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'qty_in' => '0.000000',
            'qty_out' => '1.000000',
            'unit_cost' => '1.000000',
            'total_cost' => '1.000000',
            'idempotency_key' => 'acct-' . uniqid('', true),
        ], $data));
    }

    private function accountingFlags(): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'accounting_pilot',
                'accounting' => true,
                'pilot' => [
                    'pos_branch' => '0',
                    'item_ids' => [],
                    'category_ids' => [],
                ],
            ],
        ]);
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

    private function tableCount(string $table): int
    {
        $row = self::$conn->query("SELECT COUNT(*) AS c FROM {$table}")->fetch_assoc();

        return (int) $row['c'];
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
        self::createJournalEntriesTable();
    }

    private static function createJournalEntriesTable(): void
    {
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

    private function seedRecipeChartAccounts(): void
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
    }
}
