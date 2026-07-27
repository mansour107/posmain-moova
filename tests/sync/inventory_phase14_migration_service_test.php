<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryHistoricalMigrationService.php';

inventoryPhase14AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase14-migration-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase14_migration_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase14CreateLegacyTables($conn);
    inventoryPhase14SeedFixtures($conn);

    $ledger = new InventoryLedgerService(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]));
    inventoryPhase14SeedMigratedMovement($conn, $ledger);

    $service = new InventoryHistoricalMigrationService();
    $filters = ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3, 'limit' => 50, 'sample_limit' => 10];

    $backfill = $service->fatDetailsBackfillPlan($conn, $filters);
    inventoryPhase14Assert((int) $backfill['summary']['legacy_rows_scanned'] === 6, 'active store rows should be scanned');
    inventoryPhase14Assert((int) $backfill['summary']['safe_candidate_count'] === 2, 'purchase and sale rows should be safe candidates');
    inventoryPhase14Assert((int) $backfill['summary']['skipped_count'] === 1, 'non-stock legacy rows should be skipped instead of no-op applied');
    inventoryPhase14Assert((int) $backfill['summary']['ambiguous_count'] === 2, 'type 14 and missing item rows should be ambiguous');
    inventoryPhase14Assert((int) $backfill['summary']['already_migrated_count'] === 1, 'existing idempotency key should be detected');
    inventoryPhase14Assert(
        $service->countUnmigratedLegacyFatRows($conn, $filters) === 4,
        'unmigrated counter should exclude already-migrated and explicitly non-stock legacy rows'
    );

    $plannedById = [];
    foreach ($backfill['planned_movements'] as $movement) {
        $plannedById[(int) $movement['fat_detail_id']] = $movement;
    }
    inventoryPhase14Assert($plannedById[1]['movement_type'] === 'purchase', 'legacy purchase should map to purchase movement');
    inventoryPhase14Assert($plannedById[2]['movement_type'] === 'reservation', 'unpaid legacy sale should map to a neutral reservation');
    inventoryPhase14Assert($plannedById[2]['qty_reserved'] === '2.000000', 'unpaid legacy sale should preserve its quantity as reserved');
    inventoryPhase14Assert($plannedById[2]['qty_out'] === '0.000000', 'unpaid legacy sale must not deplete on-hand stock');
    inventoryPhase14Assert($plannedById[2]['total_cost'] === '0.000000', 'unpaid legacy reservation must not create COGS');
    inventoryPhase14Assert($plannedById[1]['idempotency_key'] === 'migration:fat_details:1:v1', 'candidate should expose deterministic idempotency key');
    inventoryPhase14Assert($plannedById[1]['source_type'] === 'fat_details', 'candidate should preserve source type');
    inventoryPhase14Assert($plannedById[1]['metadata']['legacy_u_val'] === '12.000000', 'candidate should preserve legacy unit value in metadata');
    inventoryPhase14Assert($plannedById[1]['unit_conversion_to_base'] === '12.00000000', 'candidate should preserve legacy unit conversion on movement payload');

    $ambiguousReasons = [];
    foreach ($backfill['ambiguous_rows'] as $row) {
        $ambiguousReasons[(int) $row['fat_detail_id']] = $row['reasons'];
    }
    inventoryPhase14Assert(in_array('pro_tybe_14_opening_balance_offer_collision', $ambiguousReasons[3] ?? [], true), 'type 14 rows should require manual decision');
    inventoryPhase14Assert(in_array('missing_item_id', $ambiguousReasons[4] ?? [], true), 'missing item rows should require manual decision');

    $withDeleted = $service->fatDetailsBackfillPlan($conn, $filters + ['include_deleted' => true]);
    $deletedReasons = [];
    foreach ($withDeleted['ambiguous_rows'] as $row) {
        $deletedReasons[(int) $row['fat_detail_id']] = $row['reasons'];
    }
    inventoryPhase14Assert(in_array('deleted_legacy_row', $deletedReasons[5] ?? [], true), 'deleted rows should be visible only when requested');

    $rebuild = $service->rebuildBalancesPlan($conn, $filters);
    inventoryPhase14Assert((int) $rebuild['summary']['derived_balance_rows'] >= 1, 'rebuild dry-run should derive rows from movements');
    inventoryPhase14Assert((int) $rebuild['summary']['difference_count'] === 0, 'fresh ledger balance should match derived movement balance');
    $negativeRows = array_values(array_filter($rebuild['rows'], static fn(array $row): bool => (int) ($row['item_id'] ?? 0) === 14004));
    inventoryPhase14Assert($negativeRows !== [], 'rebuild dry-run should include negative-stock movement rows');
    inventoryPhase14Assert($negativeRows[0]['derived_qty_on_hand'] === '-2.000000', 'negative-stock fixture should remain negative for quantity proof');
    inventoryPhase14Assert($negativeRows[0]['derived_moving_average_cost'] === '4.000000', 'negative-stock derived cost should preserve the sale-time cost instead of zero');
    $outboundCostRows = array_values(array_filter($rebuild['rows'], static fn(array $row): bool => (int) ($row['item_id'] ?? 0) === 14005));
    inventoryPhase14Assert($outboundCostRows !== [], 'rebuild dry-run should include the outbound-cost divergence fixture');
    inventoryPhase14Assert($outboundCostRows[0]['derived_qty_on_hand'] === '1.000000', 'outbound-cost fixture should retain one unit');
    inventoryPhase14Assert($outboundCostRows[0]['derived_moving_average_cost'] === '2.000000', 'outbound movement unit cost must not distort moving average cost');

    $conn->query("UPDATE inventory_item_balances SET qty_on_hand = qty_on_hand + 1 WHERE item_id = 14001 AND store_id = 3");
    $rebuildWithDifference = $service->rebuildBalancesPlan($conn, $filters);
    inventoryPhase14Assert((int) $rebuildWithDifference['summary']['difference_count'] >= 1, 'rebuild dry-run should flag balance differences');
    inventoryPhase14Assert((int) $rebuildWithDifference['summary']['rebuild_candidate_count'] >= 1, 'rebuild dry-run should expose rebuild candidates');

    $rebuildRehearsal = $service->rehearseBalanceRebuild($conn, $filters);
    inventoryPhase14Assert(!empty($rebuildRehearsal['ok']), 'balance rebuild rehearsal should run through the balance repository');
    inventoryPhase14Assert((int) $rebuildRehearsal['summary']['rehearsed_count'] >= 1, 'balance rebuild rehearsal should count affected balances');
    $stillDifferentAfterRehearsal = $service->rebuildBalancesPlan($conn, $filters);
    inventoryPhase14Assert((int) $stillDifferentAfterRehearsal['summary']['difference_count'] >= 1, 'balance rebuild rehearsal should roll back balance changes');

    $rebuildApply = $service->applyBalanceRebuild($conn, $filters);
    inventoryPhase14Assert(!empty($rebuildApply['ok']), 'balance rebuild apply should repair scoped balance rows');
    inventoryPhase14Assert((int) $rebuildApply['summary']['rebuilt_count'] >= 1, 'balance rebuild apply should report repaired balance rows');
    $rebuildAfterApply = $service->rebuildBalancesPlan($conn, $filters);
    inventoryPhase14Assert((int) $rebuildAfterApply['summary']['difference_count'] === 0, 'balance rebuild apply should remove quantity differences');

    $plan = $service->migrationPlan($conn, $filters);
    inventoryPhase14Assert(!empty($plan['ok']), 'plan should be reviewable when required tables exist');
    inventoryPhase14Assert(in_array('database_backup', $plan['required_before_apply'], true), 'plan should require backup before any apply path');
    inventoryPhase14Assert((int) $plan['snapshot']['fat_details']['deleted_row_count'] === 1, 'snapshot should count deleted legacy detail rows');

    $blockedApply = $service->applyFatDetailsBackfill($conn, $filters);
    inventoryPhase14Assert(empty($blockedApply['ok']), 'backfill apply should block when ambiguous rows are in scope');
    inventoryPhase14Assert(in_array('ambiguous_legacy_rows_require_review', $blockedApply['blockers'], true), 'backfill apply should name ambiguous-row blocker');

    $reviewedPlan = $service->fatDetailsBackfillPlan($conn, $filters + [
        'reviewed_decisions' => [
            ['fat_detail_id' => 3, 'action' => 'movement', 'movement_type' => 'opening_balance', 'reason' => 'reviewed as opening balance'],
            ['fat_detail_id' => 4, 'action' => 'skip', 'reason' => 'missing item cannot be safely mapped'],
        ],
    ]);
    inventoryPhase14Assert((int) $reviewedPlan['summary']['safe_candidate_count'] === 2, 'reviewed decisions should not inflate safe candidate count');
    inventoryPhase14Assert((int) $reviewedPlan['summary']['reviewed_candidate_count'] === 1, 'reviewed movement decisions should be counted separately');
    inventoryPhase14Assert((int) $reviewedPlan['summary']['reviewed_skip_count'] === 1, 'reviewed skip decisions should be counted separately');
    inventoryPhase14Assert((int) $reviewedPlan['summary']['ambiguous_count'] === 0, 'reviewed decisions should clear the scoped ambiguous rows');
    inventoryPhase14Assert($reviewedPlan['reviewed_movements'][0]['movement_type'] === 'opening_balance', 'reviewed type 14 row should become explicit opening balance movement');
    inventoryPhase14Assert($reviewedPlan['reviewed_movements'][0]['idempotency_key'] === 'migration:fat_details:3:reviewed:v1', 'reviewed movement should use reviewed deterministic idempotency key');
    inventoryPhase14Assert($reviewedPlan['reviewed_movements'][0]['unit_conversion_to_base'] === '6.00000000', 'reviewed movement should preserve legacy unit conversion');

    $reviewedPurchaseReturnPlan = $service->fatDetailsBackfillPlan($conn, $filters + [
        'reviewed_decisions' => [
            [
                'fat_detail_id' => 3,
                'action' => 'movement',
                'movement_type' => 'purchase_return',
                'qty_in' => '0.000000',
                'qty_out' => '2.000000',
                'reason' => 'reviewed as supplier return',
            ],
            ['fat_detail_id' => 4, 'action' => 'skip', 'reason' => 'missing item cannot be safely mapped'],
        ],
    ]);
    inventoryPhase14Assert($reviewedPurchaseReturnPlan['reviewed_movements'][0]['movement_type'] === 'purchase_return', 'reviewed ambiguous rows should allow purchase_return when qty_out is provided');

    $rehearsal = $service->rehearseFatDetailsBackfill($conn, $filters + ['item_id' => 14001]);
    inventoryPhase14Assert(!empty($rehearsal['ok']), 'backfill rehearsal should execute safe legacy rows through the ledger');
    inventoryPhase14Assert($rehearsal['mode'] === 'rehearse', 'backfill rehearsal should report rehearse mode');
    inventoryPhase14Assert((int) $rehearsal['summary']['rehearsed_count'] === 2, 'backfill rehearsal should count safe historical movements');
    inventoryPhase14Assert((int) $rehearsal['summary']['applied_count'] === 0, 'backfill rehearsal should not report persistent applies');
    inventoryPhase14Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE idempotency_key IN ('migration:fat_details:1:v1', 'migration:fat_details:2:v1')")->fetch_assoc()['c'] === 0, 'backfill rehearsal should roll back movement writes');

    $safeApply = $service->applyFatDetailsBackfill($conn, $filters + ['item_id' => 14001]);
    inventoryPhase14Assert(!empty($safeApply['ok']), 'backfill apply should insert safe legacy rows when ambiguous rows are out of scope');
    inventoryPhase14Assert((int) $safeApply['summary']['applied_count'] === 2, 'backfill apply should insert two safe historical movements');
    inventoryPhase14Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE idempotency_key IN ('migration:fat_details:1:v1', 'migration:fat_details:2:v1')")->fetch_assoc()['c'] === 2, 'backfill apply should write deterministic migration keys');
    inventoryPhase14Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_item_balances WHERE item_id = 14001 AND store_id = 3")->fetch_assoc()['c'] === 1, 'backfill apply should update the scoped balance');

    $reapply = $service->applyFatDetailsBackfill($conn, $filters + ['item_id' => 14001]);
    inventoryPhase14Assert(!empty($reapply['ok']), 'backfill reapply should remain idempotent');
    inventoryPhase14Assert((int) $reapply['summary']['applied_count'] === 0, 'backfill reapply should not insert duplicate movements');

    $reviewedFilters = $filters + [
        'reviewed_decisions' => [
            ['fat_detail_id' => 3, 'action' => 'movement', 'movement_type' => 'opening_balance', 'reason' => 'reviewed as opening balance'],
            ['fat_detail_id' => 4, 'action' => 'skip', 'reason' => 'missing item cannot be safely mapped'],
        ],
    ];
    $reviewedApply = $service->applyFatDetailsBackfill($conn, $reviewedFilters);
    inventoryPhase14Assert(!empty($reviewedApply['ok']), 'reviewed movement and skip decisions should be applicable after safe rows');
    inventoryPhase14Assert((int) $reviewedApply['summary']['applied_count'] === 1, 'reviewed movement should be written exactly once');
    inventoryPhase14Assert(
        $service->countUnmigratedLegacyFatRows($conn, $reviewedFilters) === 0,
        'cutover counter should recognize reviewed movement keys and reviewed skips'
    );

    $conn->query("
        INSERT INTO fat_details (id, fatid, pro_id, pro_tybe, item_id, u_val, qty_in, qty_out, det_store, cost_price, tenant, branch, isdeleted, crtime)
        VALUES (9, 1009, 1009, 4, 14004, 1.000000, 1.000000, 0.000000, 3, -4.000000, 0, 0, 0, '2026-01-09 08:00:00')
    ");
    $negativeCostPlan = $service->fatDetailsBackfillPlan($conn, $filters + ['min_fat_detail_id' => 8]);
    inventoryPhase14Assert((int) $negativeCostPlan['summary']['ambiguous_count'] === 1, 'negative historical unit cost should require an explicit reviewed decision');
    inventoryPhase14Assert(
        in_array('negative_unit_cost_requires_review', $negativeCostPlan['ambiguous_rows'][0]['reasons'] ?? [], true),
        'negative historical unit cost should expose a specific review reason'
    );

    $conn->query("
        INSERT INTO fat_details (id, fatid, pro_id, pro_tybe, item_id, u_val, qty_in, qty_out, det_store, cost_price, tenant, branch, isdeleted, crtime)
        VALUES (10, 1010, 1010, 4, 14001, 1.000000, 1.000000, 0.000000, 3, 2.000000, 0, 0, 0, '2026-01-10 08:00:00')
    ");
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 4],
        'item_id' => 14001,
        'movement_type' => 'purchase',
        'source_type' => 'invoice',
        'source_id' => 1010,
        'source_uuid' => 'invoice-bridge:1010',
        'fat_detail_id' => 10,
        'qty_in' => '1.000000',
        'unit_cost' => '2.000000',
        'total_cost' => '2.000000',
        'idempotency_key' => 'inventory-invoice-bridge:fat-detail:10:v1',
        'metadata' => ['source' => 'phase14_existing_canonical_bridge_test'],
    ], ['id' => 14001, 'item_type' => 'ingredient', 'track_stock' => 1]);
    $canonicalBridgeScope = $filters + ['min_fat_detail_id' => 9];
    $canonicalBridgePlan = $service->fatDetailsBackfillPlan($conn, $canonicalBridgeScope);
    inventoryPhase14Assert(
        (int) $canonicalBridgePlan['summary']['already_migrated_count'] === 1,
        'a canonical bridge movement with the same fat-detail quantity should prevent a second historical replay'
    );
    inventoryPhase14Assert(
        (int) $canonicalBridgePlan['summary']['safe_candidate_count'] === 0,
        'canonical bridge evidence in another store scope must not be planned again under the legacy store'
    );
    inventoryPhase14Assert(
        $service->countUnmigratedLegacyFatRows($conn, $canonicalBridgeScope) === 0,
        'unmigrated counter should recognize matching canonical bridge evidence regardless of idempotency-key family'
    );

    echo "inventory-phase14-migration-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase14AssertSourceContracts(string $root): void
{
    $serviceSource = inventoryPhase14Source($root . '/classes/Inventory/InventoryHistoricalMigrationService.php');
    foreach ([
        'fatDetailsBackfillPlan',
        'rehearseFatDetailsBackfill',
        'applyFatDetailsBackfill',
        'rebuildBalancesPlan',
        'rehearseBalanceRebuild',
        'applyBalanceRebuild',
        'reviewAmbiguousFatDetailsRow',
        'migration:fat_details:',
        'reviewed:v1',
        'pro_tybe_14_opening_balance_offer_collision',
        'non_stock_item_not_migrated_to_inventory_ledger',
        'deleted_legacy_row',
        'already_migrated',
        'ambiguous_legacy_rows_require_review',
        'negative_unit_cost_requires_review',
        'InventoryMovingAverageCostCalculator',
        'nextAverageCost',
    ] as $needle) {
        inventoryPhase14Assert(strpos($serviceSource, $needle) !== false, 'migration service should preserve phase14 behavior: ' . $needle);
    }

    foreach (["return 'purchase'", "return 'sale_direct'", "return 'purchase_return'", "'source_type' => 'fat_details'"] as $needle) {
        inventoryPhase14Assert(strpos($serviceSource, $needle) !== false, 'migration service should preserve historical movement mapping: ' . $needle);
    }

    $docs = inventoryPhase14Source($root . '/docs/inventory/phase14_migration_contracts.md');
    foreach ([
        'Snapshot legacy `myitems.itmqty`',
        '`pro_tybe=14`',
        'Reviewed ambiguous rows',
        '--rehearse',
        '--apply --backup-file',
        'branch, store, and item-category signoff',
        'No runtime page switches',
    ] as $needle) {
        inventoryPhase14Assert(strpos($docs, $needle) !== false, 'phase14 docs should preserve migration guardrail: ' . $needle);
    }
}

function inventoryPhase14CreateLegacyTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  code VARCHAR(64) NULL,
  iname VARCHAR(200) NOT NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'ingredient',
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE fat_details (
  id INT NOT NULL PRIMARY KEY,
  fatid INT NOT NULL DEFAULT 0,
  pro_id INT NOT NULL DEFAULT 0,
  pro_tybe INT NOT NULL DEFAULT 0,
  item_id INT NULL,
  u_val DECIMAL(18,6) NOT NULL DEFAULT 1,
  qty_in DECIMAL(18,6) NOT NULL DEFAULT 0,
  qty_out DECIMAL(18,6) NOT NULL DEFAULT 0,
  det_store INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  crtime DATETIME NULL,
  mdtime DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE ot_head (
  id INT NOT NULL PRIMARY KEY,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
  invoice_status VARCHAR(20) NOT NULL DEFAULT 'draft',
  order_status VARCHAR(20) NOT NULL DEFAULT 'active',
  closed INT NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE order_payments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  amount DECIMAL(19,2) NOT NULL DEFAULT 0,
  is_voided TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase14SeedFixtures(mysqli $conn): void
{
    $conn->query("INSERT INTO ot_head (id, payment_status) VALUES (1002, 'unpaid')");
    $conn->query("
        INSERT INTO myitems (id, code, iname, itmqty, cost_price, item_type, track_stock)
        VALUES
            (14001, 'M-14001', 'Migration flour', 3.000000, 2.000000, 'ingredient', 1),
            (14002, 'M-14002', 'Migration cheese', 8.000000, 1.000000, 'ingredient', 1),
            (14003, 'M-14003', 'Migration service', 0.000000, 0.000000, 'service', 0),
            (14004, 'M-14004', 'Negative migration stock', -2.000000, 0.000000, 'ingredient', 1),
            (14005, 'M-14005', 'Outbound cost divergence', 1.000000, 2.000000, 'ingredient', 1)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, pro_id, pro_tybe, item_id, u_val, qty_in, qty_out, det_store, cost_price, tenant, branch, isdeleted, crtime)
        VALUES
            (1, 1001, 1001, 4, 14001, 12.000000, 5.000000, 0.000000, 3, 2.000000, 0, 0, 0, '2026-01-01 08:00:00'),
            (2, 1002, 1002, 9, 14001, 1.000000, 0.000000, 2.000000, 3, 2.000000, 0, 0, 0, '2026-01-02 08:00:00'),
            (3, 1003, 1003, 14, 14002, 6.000000, 8.000000, 0.000000, 3, 1.000000, 0, 0, 0, '2026-01-03 08:00:00'),
            (4, 1004, 1004, 4, NULL, 1.000000, 1.000000, 0.000000, 3, 1.000000, 0, 0, 0, '2026-01-04 08:00:00'),
            (5, 1005, 1005, 4, 14001, 1.000000, 1.000000, 0.000000, 3, 2.000000, 0, 0, 1, '2026-01-05 08:00:00'),
            (6, 1006, 1006, 4, 14001, 1.000000, 4.000000, 0.000000, 3, 2.000000, 0, 0, 0, '2026-01-06 08:00:00'),
            (7, 1007, 1007, 4, 14001, 1.000000, 3.000000, 0.000000, 4, 2.000000, 0, 0, 0, '2026-01-07 08:00:00'),
            (8, 1008, 1008, 9, 14003, 1.000000, 0.000000, 1.000000, 3, 0.000000, 0, 0, 0, '2026-01-08 08:00:00')
    ");
}

function inventoryPhase14SeedMigratedMovement(mysqli $conn, InventoryLedgerService $ledger): void
{
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 14001,
        'movement_type' => 'purchase',
        'source_type' => 'fat_details',
        'source_id' => 6,
        'source_uuid' => 'legacy-fat-details:6',
        'fat_detail_id' => 6,
        'qty_in' => '4.000000',
        'unit_cost' => '2.000000',
        'total_cost' => '8.000000',
        'idempotency_key' => 'migration:fat_details:6:v1',
        'metadata' => ['source' => 'phase14_test_existing'],
    ], ['id' => 14001, 'item_type' => 'ingredient', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 14004,
        'movement_type' => 'sale_direct',
        'source_type' => 'manual',
        'source_uuid' => 'phase14-negative-stock',
        'qty_out' => '2.000000',
        'unit_cost' => '4.000000',
        'total_cost' => '8.000000',
        'idempotency_key' => 'phase14:negative-stock:v1',
        'metadata' => ['source' => 'phase14_negative_stock_cost_test'],
    ], ['id' => 14004, 'item_type' => 'ingredient', 'track_stock' => 1], [
        // Historical migration evidence may contain a pre-existing negative
        // balance. The live sale path remains governed by the negative-stock
        // policy; this fixture only proves deterministic rebuild handling.
        'enforce_negative_policy' => false,
    ]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 14005,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'phase14-outbound-cost-purchase',
        'qty_in' => '10.000000',
        'unit_cost' => '2.000000',
        'total_cost' => '20.000000',
        'idempotency_key' => 'phase14:outbound-cost:purchase:v1',
        'metadata' => ['source' => 'phase14_outbound_cost_test'],
    ], ['id' => 14005, 'item_type' => 'ingredient', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 14005,
        'movement_type' => 'sale_direct',
        'source_type' => 'manual',
        'source_uuid' => 'phase14-outbound-cost-sale',
        'qty_out' => '9.000000',
        'unit_cost' => '100.000000',
        'total_cost' => '900.000000',
        'idempotency_key' => 'phase14:outbound-cost:sale:v1',
        'metadata' => ['source' => 'phase14_outbound_cost_test'],
    ], ['id' => 14005, 'item_type' => 'ingredient', 'track_stock' => 1]);
}

function inventoryPhase14Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryPhase14Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}
