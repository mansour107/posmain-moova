<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeOperationsReportService.php';

class RecipeOperationsReportServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 76000;

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
        self::$dbName = 'posmain_recipe_operations_report_' . getmypid();
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

    public function testOperationsReportsAggregateLedgerProductionAvailabilityAndCosts(): void
    {
        $fixture = $this->seedOperationsFixture();
        $service = new RecipeOperationsReportService();

        $costHistory = $service->costHistory(self::$conn, [
            'recipe_id' => $fixture['recipe_id'],
            'store_id' => 0,
        ]);
        $ingredientConsumption = $service->ingredientConsumption(self::$conn, ['ingredient_item_id' => $fixture['ingredient_id']]);
        $recipeCogs = $service->recipeCogsByItem(self::$conn, ['recipe_id' => $fixture['recipe_id']]);
        $productionVariance = $service->productionVariance(self::$conn, ['variance_only' => true]);
        $lowStock = $service->lowStockAffectedItems(self::$conn, ['low_stock_threshold' => '5']);
        $cogsReconciliation = $service->cogsJournalReconciliation(self::$conn, ['recipe_id' => $fixture['recipe_id']]);
        $expectedVsActual = $service->expectedVsActualUsage(self::$conn, ['recipe_id' => $fixture['recipe_id']]);
        $modifierRevenueCost = $service->modifierRevenueCost(self::$conn, ['recipe_id' => $fixture['recipe_id']]);

        $this->assertSame('Burger recipe', $costHistory[0]['recipe_name']);
        $this->assertSame('12.500000', $costHistory[0]['cost_per_sell_unit']);
        $this->assertSame($fixture['ingredient_id'], (int) $ingredientConsumption[0]['item_id']);
        $this->assertSame('2.000000', $ingredientConsumption[0]['qty_consumed']);
        $this->assertSame('10.000000', $ingredientConsumption[0]['total_cost']);
        $this->assertSame('10.000000', $recipeCogs[0]['recipe_cogs']);
        $this->assertSame('2.000000', $productionVariance[0]['variance_qty']);
        $this->assertSame('low buns', $lowStock[0]['unavailable_reason']);
        $this->assertSame('Bun', $lowStock[0]['blocking_item_name']);
        $this->assertSame($fixture['journal_id'], (int) $cogsReconciliation[0]['accounting_journal_id']);
        $this->assertSame('10.000000', $cogsReconciliation[0]['movement_total']);
        $this->assertSame('10.000000', $cogsReconciliation[0]['journal_debit_total']);
        $this->assertSame('10.000000', $cogsReconciliation[0]['journal_credit_total']);
        $this->assertSame('balanced', $cogsReconciliation[0]['reconciliation_status']);
        $missingUsage = $this->firstReportRow($expectedVsActual, 'order_id', 102);
        $matchedUsage = $this->firstReportRow($expectedVsActual, 'order_id', 101);
        $this->assertSame('missing_consumption', $missingUsage['reconciliation_status']);
        $this->assertSame('1.000000', $missingUsage['expected_qty']);
        $this->assertSame('0.000000', $missingUsage['actual_qty']);
        $this->assertSame('matched', $matchedUsage['reconciliation_status']);
        $this->assertSame('2.000000', $matchedUsage['expected_qty']);
        $this->assertSame('2.000000', $matchedUsage['actual_qty']);
        $this->assertSame($fixture['modifier_option_id'], (int) $modifierRevenueCost[0]['modifier_option_id']);
        $this->assertSame('Extra cheese', $modifierRevenueCost[0]['modifier_option_name']);
        $this->assertSame('7.500000', $modifierRevenueCost[0]['modifier_revenue']);
        $this->assertSame('2.000000', $modifierRevenueCost[0]['modifier_ingredient_cost']);
        $this->assertSame('5.500000', $modifierRevenueCost[0]['modifier_margin']);
        $this->assertSame('Cheese', $modifierRevenueCost[0]['ingredient_item_names']);
    }

    public function testGenericReportDispatchRejectsUnknownReports(): void
    {
        $service = new RecipeOperationsReportService();
        $this->assertIsArray($service->report(self::$conn, 'ingredient_consumption', []));

        $this->expectException(InvalidArgumentException::class);
        $service->report(self::$conn, 'unknown_report', []);
    }

    private function seedOperationsFixture(): array
    {
        $sellableId = $this->item('Burger', '0.000000');
        $ingredientId = $this->item('Bun', '5.000000');
        $modifierIngredientId = $this->item('Cheese', '4.000000');
        $outputId = $this->item('Prepared sauce', '0.000000');
        $recipeId = $this->recipe($sellableId, 'Burger recipe', 'make_to_order');
        $batchRecipeId = $this->recipe($outputId, 'Sauce batch', 'batch_prepared');
        $journalId = $this->journal('Recipe COGS for order 101', '10.000000');
        $usageId = $this->usage($recipeId, $sellableId, $ingredientId, 101, '2.000000', '10.000000');
        $this->usage($recipeId, $sellableId, $ingredientId, 102, '1.000000', '5.000000');
        $modifierOptionId = $this->modifierOption('Extras', 'Extra cheese', '7.500');
        $this->usage($recipeId, $sellableId, $modifierIngredientId, 103, '0.500000', '2.000000', 5003, $modifierOptionId);
        self::$conn->query("
            INSERT INTO order_line_modifiers
                (order_id, detail_id, modifier_group_id, modifier_option_id, qty, price_delta, created_at)
            VALUES
                (103, 5003, 91001, {$modifierOptionId}, 1.000, 7.500, '2026-05-24 11:10:00')
        ");

        self::$conn->query("
            INSERT INTO recipe_cost_snapshots
                (snapshot_uuid, recipe_id, sellable_item_id, version_number, pos_tenant, pos_branch, cost_per_yield, cost_per_sell_unit, calculated_at)
            VALUES
                ('00000000-0000-4000-8000-000000760001', {$recipeId}, {$sellableId}, 1, 0, 0, 12.500000, 12.500000, '2026-05-24 10:00:00')
        ");
        self::$conn->query("
            INSERT INTO inventory_movements
                (movement_uuid, pos_tenant, pos_branch, store_id, item_id, movement_type, source_type, source_id, order_id, recipe_order_line_usage_id, recipe_id, qty_out, unit_cost, total_cost, accounting_journal_id, idempotency_key, created_at)
            VALUES
                ('00000000-0000-4000-8000-000000760002', 0, 0, 0, {$ingredientId}, 'recipe_consumption', 'recipe_order_line_usage', {$usageId}, 101, {$usageId}, {$recipeId}, 2.000000, 5.000000, 10.000000, {$journalId}, 'ops-consume-1', '2026-05-24 11:00:00')
        ");
        self::$conn->query("
            INSERT INTO production_batches
                (batch_uuid, pos_tenant, pos_branch, store_id, recipe_id, output_item_id, planned_output_qty, actual_output_qty, status, committed_at, variance_reason)
            VALUES
                ('00000000-0000-4000-8000-000000760003', 0, 0, 0, {$batchRecipeId}, {$outputId}, 10.000000, 12.000000, 'committed', '2026-05-24 12:00:00', 'extra yield')
        ");
        $batchId = (int) self::$conn->insert_id;
        self::$conn->query("
            INSERT INTO production_batch_lines
                (batch_id, line_type, item_id, planned_qty, actual_qty, unit_cost, total_cost)
            VALUES
                ({$batchId}, 'input', {$ingredientId}, 5.000000, 5.000000, 5.000000, 25.000000),
                ({$batchId}, 'output', {$outputId}, 10.000000, 12.000000, 2.083333, 25.000000)
        ");
        self::$conn->query("
            INSERT INTO recipe_availability_cache
                (pos_tenant, pos_branch, store_id, sellable_item_id, recipe_id, computed_available_qty, effective_available_qty, effective_is_available, blocking_item_id, unavailable_reason, calculated_at)
            VALUES
                (0, 0, 0, {$sellableId}, {$recipeId}, 0.000000, 0.000000, 0, {$ingredientId}, 'low buns', '2026-05-24 13:00:00')
        ");

        return [
            'recipe_id' => $recipeId,
            'batch_recipe_id' => $batchRecipeId,
            'sellable_id' => $sellableId,
            'ingredient_id' => $ingredientId,
            'modifier_ingredient_id' => $modifierIngredientId,
            'modifier_option_id' => $modifierOptionId,
            'output_id' => $outputId,
            'journal_id' => $journalId,
        ];
    }

    private static function createJournalTables(): void
    {
        self::$conn->query("
            CREATE TABLE journal_heads (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                jdate DATE NULL,
                details VARCHAR(255) NULL,
                tenant INT NULL DEFAULT 0,
                branch INT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        self::$conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                credit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                tybe INT NOT NULL DEFAULT 0,
                info VARCHAR(255) NULL,
                tenant INT NULL DEFAULT 0,
                branch INT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    private function journal(string $details, string $total): int
    {
        $stmt = self::$conn->prepare('INSERT INTO journal_heads (journal_id, total, jdate, details, tenant, branch) VALUES (?, ?, ?, ?, 0, 0)');
        $documentNumber = ++self::$itemCounter;
        $date = '2026-05-24';
        $stmt->bind_param('isss', $documentNumber, $total, $date, $details);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        $stmt = self::$conn->prepare("
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, info, tenant, branch)
            VALUES
                (?, 510, ?, '0.000000', 0, 'Recipe COGS', 0, 0),
                (?, 120, '0.000000', ?, 1, 'Recipe inventory credit', 0, 0)
        ");
        $stmt->bind_param('isis', $id, $total, $id, $total);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    private function modifierOption(string $groupName, string $optionName, string $priceDelta): int
    {
        self::$conn->query("
            INSERT IGNORE INTO modifier_groups
                (id, name_ar, name_en, tenant, branch, is_active)
            VALUES
                (91001, '{$groupName}', '{$groupName}', 0, 0, 1)
        ");
        $stmt = self::$conn->prepare("
            INSERT INTO modifier_options
                (group_id, name_ar, name_en, price_delta, is_active)
            VALUES
                (91001, ?, ?, ?, 1)
        ");
        $stmt->bind_param('sss', $optionName, $optionName, $priceDelta);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function usage(
        int $recipeId,
        int $sellableId,
        int $ingredientId,
        int $orderId,
        string $qty,
        string $cost,
        ?int $fatDetailId = null,
        ?int $modifierOptionId = null
    ): int
    {
        $uuidTail = str_pad((string) (++self::$itemCounter), 12, '0', STR_PAD_LEFT);
        $explosion = json_encode([
            'sellable_item_id' => $sellableId,
            'recipe_id' => $recipeId,
            'recipe_version' => 1,
            'cost_snapshot_id' => null,
            'requirements' => [[
                'ingredient_item_id' => $ingredientId,
                'source_recipe_line_id' => 1,
                'line_type' => $modifierOptionId ? 'modifier_ingredient' : 'ingredient',
                'required_qty_base' => $qty,
                'unit_id' => null,
                'unit_conversion_to_base' => '1.00000000',
                'wastage_percent' => '0.0000',
                'is_required' => true,
                'modifier_option_id' => $modifierOptionId,
                'order_type' => 'takeaway',
                'channel' => 'pos',
                'unit_cost' => '5.000000',
                'total_cost' => $cost,
            ]],
            'warnings' => [],
            'has_recipe' => true,
            'fallback_mode' => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $usageUuid = '00000000-0000-4000-8000-' . $uuidTail;
        $idempotency = 'ops-usage-' . $orderId;
        $stmt = self::$conn->prepare("
            INSERT INTO recipe_order_line_usage
                (usage_uuid, pos_tenant, pos_branch, store_id, order_id, fat_detail_id, source_channel, sellable_item_id, order_qty, recipe_id, recipe_version_number, explosion_json, cost_total, status, consumed_at, idempotency_key)
            VALUES
                (?, 0, 0, 0, ?, ?, 'pos', ?, 1.000000, ?, 1, ?, ?, 'consumed', '2026-05-24 11:00:00', ?)
        ");
        $stmt->bind_param('siiiisss', $usageUuid, $orderId, $fatDetailId, $sellableId, $recipeId, $explosion, $cost, $idempotency);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function recipe(int $sellableItemId, string $name, string $type): int
    {
        $uuidTail = str_pad((string) (++self::$itemCounter), 12, '0', STR_PAD_LEFT);
        $stmt = self::$conn->prepare("
            INSERT INTO recipe_headers
                (recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number, yield_qty)
            VALUES (?, 0, 0, ?, ?, ?, 'active', 1, 1.000000)
        ");
        $uuid = '00000000-0000-4000-8000-' . $uuidTail;
        $stmt->bind_param('siss', $uuid, $sellableItemId, $name, $type);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
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

    private function firstReportRow(array $rows, string $column, $value): array
    {
        foreach ($rows as $row) {
            if ((string) ($row[$column] ?? '') === (string) $value) {
                return $row;
            }
        }

        $this->fail('Missing report row for ' . $column . '=' . (string) $value);
    }
}
