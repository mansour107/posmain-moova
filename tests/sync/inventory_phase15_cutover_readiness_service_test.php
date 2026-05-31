<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryCutoverReadinessService.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';

inventoryPhase15ReadinessAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase15-cutover-readiness-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase15_cutover_readiness_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase15ReadinessCreateLegacyTables($conn);
    inventoryPhase15ReadinessCreateJournalTables($conn);

    $service = new InventoryCutoverReadinessService();
    $clean = $service->review($conn, [], [
        'flags' => new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]),
    ]);
    inventoryPhase15ReadinessAssert($clean['ready_for_cutover'] === true, 'empty migrated fixture should be ready for cutover');
    inventoryPhase15ReadinessAssert($clean['ready_for_legacy_retirement'] === true, 'clean migrated fixture should be legacy-retirement ready after source-level legacy stock endpoints are retired');
    inventoryPhase15ReadinessAssert($clean['legacy_retirement_blockers'] === [], 'clean migrated fixture should not keep stale source-level legacy retirement blockers');
    inventoryPhase15ReadinessAssert(in_array('inventory_ledger_mode_not_live_yet', $clean['warnings'], true), 'readiness should warn when live mode is not enabled yet instead of blocking rehearsal');

    $conn->query("
        INSERT INTO myitems (id, iname, itmqty, cost_price, item_type, track_stock)
        VALUES (15101, 'Ambiguous opening row', 0.000000, 2.000000, 'ingredient', 1)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, pro_id, pro_tybe, item_id, u_val, qty_in, qty_out, det_store, cost_price, isdeleted, tenant, branch, crtime)
        VALUES (1510101, 0, 0, 14, 15101, 1.000000, 5.000000, 0.000000, 3, 2.000000, 0, 0, 0, '2026-05-30 10:00:00')
    ");
    (new InventoryLedgerService(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']])))->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 15101,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => 'phase15-readiness-cost-difference',
        'qty_in' => '5.000000',
        'unit_cost' => '2.000000',
        'total_cost' => '10.000000',
        'idempotency_key' => 'phase15-readiness:cost-difference',
    ], ['id' => 15101, 'item_type' => 'ingredient', 'track_stock' => 1]);

    $blocked = $service->review($conn, [], [
        'flags' => new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]),
    ]);
    inventoryPhase15ReadinessAssert($blocked['ready_for_cutover'] === false, 'ambiguous legacy rows should block cutover');
    inventoryPhase15ReadinessAssert(in_array('ambiguous_legacy_rows_require_review', $blocked['blockers'], true), 'ambiguous migration row should be named as a blocker');
    inventoryPhase15ReadinessAssert(in_array('inventory_reconciliation_has_differences', $blocked['blockers'], true), 'unreconciled quantity difference should block cutover');
    $reviewedAmbiguous = $service->review($conn, [], [
        'flags' => new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]),
        'reviewed_decisions' => [
            ['fat_detail_id' => 1510101, 'action' => 'skip', 'reason' => 'already represented by reviewed opening-balance ledger movement'],
        ],
    ]);
    inventoryPhase15ReadinessAssert((int) $reviewedAmbiguous['migration']['summary']['ambiguous_count'] === 0, 'reviewed decisions should clear ambiguous migration rows in cutover readiness');
    inventoryPhase15ReadinessAssert(!in_array('ambiguous_legacy_rows_require_review', $reviewedAmbiguous['blockers'], true), 'reviewed decisions should clear ambiguous blocker without clearing unrelated reconciliation blockers');
    inventoryPhase15ReadinessAssert(in_array('inventory_reconciliation_has_differences', $reviewedAmbiguous['blockers'], true), 'reviewed decisions should not mask unrelated reconciliation blockers');
    $scoped = $service->review($conn, ['item_id' => 15101], [
        'flags' => new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]),
        'require_accounting' => false,
    ]);
    inventoryPhase15ReadinessAssert(($scoped['filters']['item_ids'] ?? []) === [15101], 'item-scoped readiness should pass item_ids to reconciliation');
    inventoryPhase15ReadinessAssert((int) $scoped['reconciliation']['difference_count'] === 1, 'item-scoped readiness should not report unrelated reconciliation rows');

    $conn->query("UPDATE inventory_item_balances SET moving_average_cost = 9.000000 WHERE item_id = 15101 AND store_id = 3");
    $costOnly = $service->review($conn, ['item_id' => 15101], [
        'flags' => new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]),
        'skip_accounting_gate' => true,
        'require_accounting' => false,
    ]);
    inventoryPhase15ReadinessAssert(in_array('inventory_rebuild_has_cost_differences', $costOnly['blockers'], true), 'unaccepted rebuild cost difference should block cutover');
    $candidate = $costOnly['migration']['sample_unaccepted_rebuild_rows'][0] ?? [];
    $acceptanceFile = tempnam(sys_get_temp_dir(), 'posmain-rebuild-acceptance-');
    file_put_contents($acceptanceFile, json_encode([
        'accepted_balance_rebuild_differences' => [
            $candidate + [
                'accepted_by' => 'inventory-accountant',
                'accepted_at_utc' => '2026-05-30T17:00:00Z',
                'reason' => 'Fixture cost-only rebuild difference was reviewed.',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    try {
        $acceptedWithoutAllow = $service->review($conn, ['item_id' => 15101], [
            'flags' => new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]),
            'require_accounting' => false,
            'rebuild_acceptance_file' => $acceptanceFile,
        ]);
        inventoryPhase15ReadinessAssert(in_array('accepted_balance_rebuild_differences_require_explicit_allow_flag', $acceptedWithoutAllow['blockers'], true), 'accepted rebuild differences should require explicit allow flag');

        $acceptedWithAllow = $service->review($conn, ['item_id' => 15101], [
            'flags' => new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]),
            'require_accounting' => false,
            'rebuild_acceptance_file' => $acceptanceFile,
            'allow_accepted_rebuild_differences' => true,
        ]);
        inventoryPhase15ReadinessAssert(!in_array('inventory_rebuild_has_cost_differences', $acceptedWithAllow['blockers'], true), 'accepted rebuild cost difference should clear the cost blocker when explicitly allowed');
        inventoryPhase15ReadinessAssert((int) $acceptedWithAllow['migration']['summary']['accepted_rebuild_candidate_count'] === 1, 'accepted rebuild candidate should be counted');
    } finally {
        @unlink($acceptanceFile);
    }

    echo "inventory-phase15-cutover-readiness-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase15ReadinessAssertSourceContracts(string $root): void
{
    $readinessSource = inventoryPhase15ReadinessSource($root . '/classes/Inventory/InventoryCutoverReadinessService.php');
    $legacyReadinessSource = inventoryPhase15ReadinessSource($root . '/classes/Inventory/InventoryLegacyRetirementReadinessService.php');
    $readinessTool = inventoryPhase15ReadinessSource($root . '/tools/inventory_cutover_readiness.php');
    foreach ([
        'ready_for_cutover',
        'ready_for_legacy_retirement',
        'InventoryBalanceRebuildAcceptanceService',
        'InventoryLegacyRetirementReadinessService',
        'ambiguous_legacy_rows_require_review',
        'inventory_rebuild_has_cost_differences',
        'accepted_balance_rebuild_differences_require_explicit_allow_flag',
        'inventory_accounting_reconciliation_not_ready',
        'inventory_ledger_mode_not_live_yet',
        'legacy_retirement_blockers',
        'unsafe_legacy_stock_endpoint_still_present',
        'fat_details_stock_triggers_still_defined_in_db_schema',
        'reviewed_decisions',
        '--decisions-file',
    ] as $needle) {
        inventoryPhase15ReadinessAssert(strpos($readinessSource . $legacyReadinessSource . $readinessTool, $needle) !== false, 'cutover readiness should preserve gate: ' . $needle);
    }
    inventoryPhase15ReadinessAssert(
        !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[^A-Za-z_]|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $readinessTool),
        'cutover readiness tool must remain read-only'
    );

    $docs = inventoryPhase15ReadinessSource($root . '/docs/inventory/phase15_cutover_contracts.md');
    foreach (['`php tools/inventory_cutover_readiness.php --json`', '`--decisions-file=/absolute/path/to/reviewed-decisions.json`', '`ready_for_cutover` can pass before live mode', '`ready_for_legacy_retirement` is stricter'] as $needle) {
        inventoryPhase15ReadinessAssert(strpos($docs, $needle) !== false, 'cutover docs should preserve readiness gate: ' . $needle);
    }
}

function inventoryPhase15ReadinessCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'ingredient',
  track_stock TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE fat_details (
  id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  fatid BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pro_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pro_tybe INT NOT NULL DEFAULT 0,
  item_id BIGINT UNSIGNED NULL,
  u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  det_store BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  crtime DATETIME NULL,
  mdtime DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase15ReadinessCreateJournalTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE journal_heads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  journal_id INT NOT NULL,
  op_id BIGINT UNSIGNED NULL,
  total DECIMAL(18,6) NOT NULL DEFAULT 0,
  jdate DATE NULL,
  details VARCHAR(255) NULL,
  user BIGINT UNSIGNED NULL,
  tenant INT NULL,
  branch INT NULL
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
  tenant INT NULL,
  branch INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase15ReadinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryPhase15ReadinessSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}
