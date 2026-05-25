<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeWasteAdjustmentService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

class RecipeWasteAdjustmentServiceTest extends TestCase
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
        self::$dbName = 'posmain_recipe_waste_adjustment_' . getmypid();
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

        foreach ([
            'recipe_audit_log',
            'inventory_movements',
            'inventory_item_balances',
            'journal_entries',
            'journal_heads',
            'document_counters',
        ] as $table) {
            self::$conn->query('DELETE FROM ' . $table);
        }
    }

    public function testWasteRecordsMovementAccountingAndAuditIdempotently(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'item_id' => 3101,
            'qty_on_hand' => '10.000000',
            'qty_reserved' => '1.000000',
            'qty_available' => '9.000000',
        ]);
        $service = new RecipeWasteAdjustmentService($this->accountingFlags());
        $input = [
            'item_id' => 3101,
            'qty' => '2.000000',
            'unit_cost' => '3.250000',
            'waste_uuid' => '00000000-0000-4000-8000-000000003101',
            'reason' => 'expired prep',
            'raw_inventory_account_id' => 120,
            'waste_expense_account_id' => 540,
        ];

        $first = $service->recordWaste(self::$conn, $input, $this->adminActor());
        $second = $service->recordWaste(self::$conn, $input, $this->adminActor());
        $balance = $balances->findBalance(self::$conn, 0, 0, 0, 3101);
        $movement = $this->rows('inventory_movements')[0];

        $this->assertFalse($first['existing']);
        $this->assertTrue($second['existing']);
        $this->assertSame($first['movement_ids'], $second['movement_ids']);
        $this->assertSame('8.000000', $balance['qty_on_hand']);
        $this->assertSame('1.000000', $balance['qty_reserved']);
        $this->assertSame('7.000000', $balance['qty_available']);
        $this->assertSame('waste', $movement['movement_type']);
        $this->assertSame('2.000000', $movement['qty_out']);
        $this->assertSame('6.500000', $movement['total_cost']);
        $this->assertGreaterThan(0, (int) $movement['accounting_journal_id']);
        $this->assertSame(1, $this->tableCount('inventory_movements'));
        $this->assertSame(1, $this->tableCount('journal_heads'));
        $this->assertSame(2, $this->tableCount('journal_entries'));
        $this->assertSame(1, $this->tableCount('recipe_audit_log'));
        $this->assertSame('record_waste', $this->rows('recipe_audit_log')[0]['action']);
    }

    public function testAdjustmentRecordsVarianceAccountingAndAudit(): void
    {
        $balances = new InventoryBalanceRepository();
        $balances->putBalance(self::$conn, [
            'item_id' => 3102,
            'qty_on_hand' => '5.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '5.000000',
        ]);
        $service = new RecipeWasteAdjustmentService($this->accountingFlags());

        $result = $service->recordAdjustment(self::$conn, [
            'item_id' => 3102,
            'direction' => 'decrease',
            'qty' => '1.500000',
            'unit_cost' => '4.000000',
            'adjustment_uuid' => '00000000-0000-4000-8000-000000003102',
            'reason' => 'cycle count correction',
            'raw_inventory_account_id' => 120,
            'production_variance_account_id' => 530,
        ], $this->adminActor());
        $balance = $balances->findBalance(self::$conn, 0, 0, 0, 3102);
        $movement = $this->rows('inventory_movements')[0];
        $entries = $this->rows('journal_entries');

        $this->assertFalse($result['noop']);
        $this->assertSame('3.500000', $balance['qty_on_hand']);
        $this->assertSame('adjustment', $movement['movement_type']);
        $this->assertSame('0.000000', $movement['qty_in']);
        $this->assertSame('1.500000', $movement['qty_out']);
        $this->assertSame('6.000000', $movement['total_cost']);
        $this->assertSame(530, (int) $entries[0]['account_id']);
        $this->assertSame('6.0000', $entries[0]['debit']);
        $this->assertSame(120, (int) $entries[1]['account_id']);
        $this->assertSame('6.0000', $entries[1]['credit']);
        $this->assertSame('record_stock_adjustment', $this->rows('recipe_audit_log')[0]['action']);
    }

    public function testBackdatedWasteRequiresApprovalPermission(): void
    {
        $service = new RecipeWasteAdjustmentService($this->movementFlags());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipe approval permission is required.');
        try {
            $service->recordWaste(self::$conn, [
                'item_id' => 3103,
                'qty' => '1.000000',
                'unit_cost' => '1.000000',
                'waste_uuid' => '00000000-0000-4000-8000-000000003103',
                'reason' => 'late entry',
                'occurred_at' => date('Y-m-d', strtotime('-1 day')),
            ], $this->inventoryActor());
        } finally {
            $this->assertSame(0, $this->tableCount('inventory_movements'));
            $this->assertSame(0, $this->tableCount('recipe_audit_log'));
        }
    }

    public function testDisabledFlagsRejectWasteWithoutWriting(): void
    {
        $service = new RecipeWasteAdjustmentService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('writes are disabled');
        try {
            $service->recordWaste(self::$conn, [
                'item_id' => 3104,
                'qty' => '1.000000',
                'unit_cost' => '1.000000',
                'waste_uuid' => '00000000-0000-4000-8000-000000003104',
                'reason' => 'disabled',
            ], $this->adminActor());
        } finally {
            $this->assertSame(0, $this->tableCount('inventory_movements'));
            $this->assertSame(0, $this->tableCount('recipe_audit_log'));
        }
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

    private function movementFlags(): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
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
        ]);
    }

    private function adminActor(): RecipeActorContext
    {
        return new RecipeActorContext(77, 0, 0, null, [
            'admin',
            'recipe.manage',
            'recipe.approve',
            'inventory.manage',
            'inventory.approve',
        ]);
    }

    private function inventoryActor(): RecipeActorContext
    {
        return new RecipeActorContext(78, 0, 0, null, [
            'recipe.manage',
            'inventory.manage',
        ]);
    }

    private function rows(string $table): array
    {
        $result = self::$conn->query("SELECT * FROM {$table} ORDER BY id");
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
