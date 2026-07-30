<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryAccountingReconciliationAcceptanceService.php';
require_once $root . '/classes/Inventory/InventoryAccountingReconciliationService.php';
require_once $root . '/classes/Inventory/InventoryAccountingService.php';
require_once $root . '/classes/Inventory/InventoryAdjustmentService.php';
require_once $root . '/classes/Inventory/InventoryCountService.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryPurchaseReceivingService.php';

inventoryPhase12AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase12-accounting-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase12_accounting_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase12CreateLegacyTables($conn);
    inventoryPhase12CreateJournalTables($conn);
    $conn->query("
        INSERT INTO myitems (id, iname, itmqty, cost_price, item_type, track_stock)
        VALUES
            (12001, 'Accounting purchase item', 0.000000, 0.000000, 'ingredient', 1),
            (12002, 'Accounting waste item', 5.000000, 4.000000, 'ingredient', 1),
            (12003, 'Accounting failed item', 5.000000, 4.000000, 'ingredient', 1),
            (12004, 'Accounting count gain item', 0.000000, 0.000000, 'ingredient', 1),
            (12005, 'Accounting count loss item', 0.000000, 0.000000, 'ingredient', 1)
    ");

    $flags = new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
            'accounting' => '1',
            'accounts' => [
                'inventory_asset_account_id' => 1100,
                'purchase_clearing_account_id' => 2100,
                'cogs_account_id' => 5100,
                'waste_expense_account_id' => 5200,
                'adjustment_gain_loss_account_id' => 5300,
            ],
        ],
    ]);

    $receiving = new InventoryPurchaseReceivingService($flags);
    $receipt = $receiving->receive($conn, [
        'purchase_receipt_uuid' => '12121212-1212-4212-8212-121212121212',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['item_id' => 12001, 'qty' => '4.000000', 'unit_cost' => '6.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase12Assert(empty($receipt['accounting']['noop']), 'purchase receipt should post accounting when inventory accounting is enabled');
    inventoryPhase12Assert((int) $receipt['accounting']['entry_count'] === 2, 'purchase receipt should post two journal entries');
    $purchaseMovement = inventoryPhase12One($conn, 'SELECT * FROM inventory_movements WHERE id = ' . (int) $receipt['movement_ids'][0]);
    inventoryPhase12Assert((int) $purchaseMovement['accounting_journal_id'] === (int) $receipt['accounting']['journal_head_id'], 'purchase movement should be linked to journal');
    inventoryPhase12Assert(inventoryPhase12EntriesMatch($conn, (int) $receipt['accounting']['journal_head_id'], [
        [1100, '24.000000', '0.000000'],
        [2101, '0.000000', '24.000000'],
    ]), 'purchase receipt journal should debit inventory and credit supplier');

    $purchaseReturn = $receiving->returnItems($conn, [
        'purchase_receipt_uuid' => '12121212-1212-4212-8212-343434343434',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['item_id' => 12001, 'qty' => '1.000000', 'unit_cost' => '6.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase12Assert(empty($purchaseReturn['accounting']['noop']), 'purchase return should post accounting when inventory accounting is enabled');
    $purchaseReturnMovement = inventoryPhase12One($conn, 'SELECT * FROM inventory_movements WHERE id = ' . (int) $purchaseReturn['movement_ids'][0]);
    inventoryPhase12Assert($purchaseReturnMovement['movement_type'] === 'purchase_return', 'purchase return accounting should use dedicated purchase_return movements');
    inventoryPhase12Assert((int) $purchaseReturnMovement['accounting_journal_id'] === (int) $purchaseReturn['accounting']['journal_head_id'], 'purchase return movement should be linked to journal');
    inventoryPhase12Assert(inventoryPhase12EntriesMatch($conn, (int) $purchaseReturn['accounting']['journal_head_id'], [
        [2101, '6.000000', '0.000000'],
        [1100, '0.000000', '6.000000'],
    ]), 'purchase return journal should debit supplier and credit inventory');

    $ledger = new InventoryLedgerService($flags);
    inventoryPhase12SeedStock($conn, $ledger, 12002, '5.000000', '4.000000', 'waste-item-opening');
    inventoryPhase12SeedStock($conn, $ledger, 12003, '5.000000', '4.000000', 'failed-item-opening');

    $adjustments = new InventoryAdjustmentService($flags);
    $waste = $adjustments->recordWaste($conn, [
        'waste_uuid' => '34343434-3434-4434-8434-343434343434',
        'store_id' => 3,
        'item_id' => 12002,
        'qty' => '2.000000',
        'unit_cost' => '4.000000',
        'reason' => 'phase 12 waste proof',
    ], ['user_id' => 7]);
    inventoryPhase12Assert(empty($waste['accounting']['noop']), 'waste should post accounting when enabled');
    inventoryPhase12Assert(inventoryPhase12EntriesMatch($conn, (int) $waste['accounting']['journal_head_id'], [
        [5200, '8.000000', '0.000000'],
        [1100, '0.000000', '8.000000'],
    ]), 'waste journal should debit waste expense and credit inventory');

    $increase = $adjustments->recordAdjustment($conn, [
        'adjustment_uuid' => '56565656-5656-4656-8656-565656565656',
        'store_id' => 3,
        'item_id' => 12002,
        'qty' => '1.000000',
        'unit_cost' => '4.000000',
        'direction' => 'increase',
        'reason' => 'phase 12 gain proof',
    ], ['user_id' => 7]);
    inventoryPhase12Assert(inventoryPhase12EntriesMatch($conn, (int) $increase['accounting']['journal_head_id'], [
        [1100, '4.000000', '0.000000'],
        [5300, '0.000000', '4.000000'],
    ]), 'positive adjustment should debit inventory and credit gain/loss');

    inventoryPhase12SeedStock($conn, $ledger, 12004, '10.000000', '2.000000', 'count-gain');
    inventoryPhase12SeedStock($conn, $ledger, 12005, '10.000000', '3.000000', 'count-loss');
    $countService = new InventoryCountService($flags, $ledger);
    $count = $countService->createDraft($conn, [
        'count_uuid' => '78787878-7878-4878-8878-787878787878',
        'store_id' => 3,
        'lines' => [
            ['item_id' => 12004, 'counted_qty' => '12.000000'],
            ['item_id' => 12005, 'counted_qty' => '8.000000'],
        ],
    ], ['user_id' => 7]);
    $countService->submit($conn, (int) $count['count_id'], ['user_id' => 8]);
    $countService->approve($conn, (int) $count['count_id'], ['user_id' => 9]);
    $closedCount = $countService->close($conn, (int) $count['count_id'], ['user_id' => 10]);
    inventoryPhase12Assert(!empty($closedCount['accounting']['grouped']), 'mixed count variances should split accounting by direction');
    inventoryPhase12Assert((int) $closedCount['accounting']['journal_count'] === 2, 'mixed count variances should create two balanced journals');
    $countJournalLinks = $conn->query("
        SELECT COUNT(DISTINCT accounting_journal_id) AS journal_count
        FROM inventory_movements
        WHERE source_type = 'inventory_count'
          AND item_id IN (12004, 12005)
    ")->fetch_assoc();
    inventoryPhase12Assert((int) ($countJournalLinks['journal_count'] ?? 0) === 2, 'mixed count movements should link to separate direction journals');

    $reconciliation = (new InventoryAccountingReconciliationService())->review($conn);
    inventoryPhase12Assert($reconciliation['ok'] === true, 'accountant reconciliation report should be ready');
    inventoryPhase12Assert((int) $reconciliation['problem_count'] === 0, 'posted movements should reconcile cleanly');

    $movement = (new InventoryLedgerService(new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
        ],
    ])))->recordMovement($conn, [
        'scope' => ['store_id' => 3],
        'item_id' => 12003,
        'movement_type' => 'waste',
        'source_type' => 'adjustment',
        'source_uuid' => 'phase12-failed-journal',
        'qty_out' => '1.000000',
        'unit_cost' => '4.000000',
        'total_cost' => '4.000000',
        'idempotency_key' => 'phase12:failed-journal:movement',
        'created_by' => 7,
    ]);
    try {
        (new InventoryAccountingService(new InventoryFeatureFlags([
            'inventory' => [
                'ledger_mode' => 'bridge',
                'accounting' => '1',
                'accounts' => [
                    'inventory_asset_account_id' => 1100,
                    'waste_expense_account_id' => 9999,
                ],
            ],
        ])))->postWaste($conn, ['user_id' => 7], [(int) $movement['movement_id']]);
        inventoryPhase12Assert(false, 'inactive or missing configured account should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase12Assert(
            strpos($exception->getMessage(), 'missing or inactive: 9999') !== false,
            'active-account enforcement should identify the invalid configured account'
        );
    }
    try {
        (new InventoryAccountingService(new InventoryFeatureFlags([
            'inventory' => [
                'ledger_mode' => 'bridge',
                'accounting' => '1',
                'accounts' => [
                    'inventory_asset_account_id' => 1100,
                ],
            ],
        ])))->postWaste($conn, ['user_id' => 7], [(int) $movement['movement_id']]);
        inventoryPhase12Assert(false, 'missing expense account should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase12Assert(strpos($exception->getMessage(), 'waste_expense_account_id') !== false, 'failed journal policy should name missing account');
    }
    $failedMovement = inventoryPhase12One($conn, 'SELECT accounting_journal_id FROM inventory_movements WHERE id = ' . (int) $movement['movement_id']);
    inventoryPhase12Assert($failedMovement['accounting_journal_id'] === null, 'failed journal should leave successful movement unlinked for accountant review');
    $failedReview = (new InventoryAccountingReconciliationService())->review($conn);
    inventoryPhase12Assert($failedReview['ok'] === false && $failedReview['status'] === 'problems_found', 'unlinked movement should make accountant reconciliation not ready');
    inventoryPhase12Assert((int) $failedReview['problem_count'] >= 1, 'unlinked movement should appear in accountant reconciliation');
    $failedProblemRows = array_values(array_filter($failedReview['rows'], static fn(array $row): bool => (string) ($row['reconciliation_status'] ?? '') !== 'balanced'));
    $acceptedReview = (new InventoryAccountingReconciliationAcceptanceService())->evaluate($failedReview['rows'], [$failedProblemRows[0] + [
        'accepted_by' => 'chief-accountant',
        'accepted_at_utc' => '2026-05-30T12:00:00Z',
        'reason' => 'Phase 12 fixture proves historical missing journals stay audited.',
    ]]);
    inventoryPhase12Assert((int) $acceptedReview['summary']['accepted_problem_count'] === 1, 'exact accounting acceptance should mark the matching problem');
    inventoryPhase12Assert(!empty($acceptedReview['rows'][array_search($failedProblemRows[0]['review_key'], array_column($acceptedReview['rows'], 'review_key'), true)]['accepted_accounting_reconciliation']), 'accepted accounting reconciliation row should remain visible');
    echo "inventory-phase12-accounting-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase12CreateLegacyTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id INT NOT NULL PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        aname VARCHAR(100) NOT NULL,
        is_stock TINYINT(1) NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock, isdeleted) VALUES
        (3, '1303', 'Phase 12 operational store', 1, 0),
        (1100, '1100', 'Inventory asset', 0, 0),
        (2100, '2100', 'Purchase clearing', 0, 0),
        (2101, '2101', 'Supplier', 0, 0),
        (5100, '5100', 'COGS', 0, 0),
        (5200, '5200', 'Waste expense', 0, 0),
        (5300, '5300', 'Adjustment gain loss', 0, 0)");
    $conn->query("CREATE TABLE settings (
        id INT NOT NULL PRIMARY KEY,
        def_pos_store INT NULL
    ) ENGINE=InnoDB");
    $conn->query('INSERT INTO settings (id, def_pos_store) VALUES (1, 3)');
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase12CreateJournalTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE journal_heads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  journal_id INT NOT NULL,
  op_id BIGINT UNSIGNED NULL,
  total DECIMAL(18,6) NOT NULL DEFAULT 0,
  jdate DATE NULL,
  pro_tybe INT NULL,
  details VARCHAR(255) NULL,
  op2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  user BIGINT UNSIGNED NULL,
  tenant INT NULL,
  branch INT NULL,
  source_type VARCHAR(64) NULL,
  source_id BIGINT UNSIGNED NULL,
  posting_kind VARCHAR(64) NULL,
  idempotency_key VARCHAR(191) NULL,
  reversal_of_journal_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_phase12_journal_idempotency (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE journal_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  journal_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  debit DECIMAL(18,6) NOT NULL DEFAULT 0,
  credit DECIMAL(18,6) NOT NULL DEFAULT 0,
  tybe INT NOT NULL DEFAULT 0,
  info VARCHAR(255) NULL,
  op_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  op2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  tenant INT NULL,
  branch INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase12AssertSourceContracts(string $root): void
{
    $phase12Doc = file_get_contents($root . '/docs/inventory/phase12_accounting_contracts.md');
    inventoryPhase12Assert(is_string($phase12Doc), 'phase12 docs should be readable');
    inventoryPhase12Assert(strpos($phase12Doc, 'status: problems_found') !== false, 'phase12 docs should state that reconciliation problems are not ready');
    inventoryPhase12Assert(strpos($phase12Doc, '--acceptance-file=/absolute/path/to/accepted-accounting.json') !== false, 'phase12 docs should document accounting acceptance file');
    inventoryPhase12Assert(strpos($phase12Doc, 'InventoryInvoiceBridge` posts sale-direct COGS and refund-reversal journals') !== false, 'phase12 docs should capture invoice bridge COGS posting');

    $invoiceBridge = file_get_contents($root . '/classes/Inventory/InventoryInvoiceBridge.php');
    inventoryPhase12Assert(is_string($invoiceBridge), 'invoice bridge source should be readable');
    foreach ([
        'InventoryAccountingService.php',
        'postAccountingForMovements',
        'postSaleCogs',
        'postRefundReversal',
        'inventory ledger mode is not accounting-authoritative',
        "movementIdsByType(\$movements, 'sale_direct')",
        "movementIdsByType(\$movements, 'refund_reversal')",
    ] as $needle) {
        inventoryPhase12Assert(strpos($invoiceBridge, $needle) !== false, 'invoice bridge should expose Phase 12 accounting contract: ' . $needle);
    }
}

function inventoryPhase12SeedStock(mysqli $conn, InventoryLedgerService $ledger, int $itemId, string $qty, string $unitCost, string $key): void
{
    $conn->begin_transaction();
    $ledger->recordMovement($conn, [
        'scope' => [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 3,
        ],
        'item_id' => $itemId,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => 'phase12:' . $key,
        'qty_in' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
        'idempotency_key' => 'phase12-accounting:' . $key,
        'metadata' => ['source' => 'phase12_accounting_test'],
        'created_by' => 7,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryPhase12EntriesMatch(mysqli $conn, int $journalHeadId, array $expected): bool
{
    $rows = $conn->query('SELECT account_id, debit, credit FROM journal_entries WHERE journal_id = ' . $journalHeadId . ' ORDER BY tybe ASC')->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== count($expected)) {
        return false;
    }
    foreach ($expected as $index => $entry) {
        if ((int) $rows[$index]['account_id'] !== (int) $entry[0]) {
            return false;
        }
        if (number_format((float) $rows[$index]['debit'], 6, '.', '') !== $entry[1]) {
            return false;
        }
        if (number_format((float) $rows[$index]['credit'], 6, '.', '') !== $entry[2]) {
            return false;
        }
    }

    return true;
}

function inventoryPhase12One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase12Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase12Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
