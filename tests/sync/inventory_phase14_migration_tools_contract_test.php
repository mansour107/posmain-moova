<?php

$root = dirname(__DIR__, 2);

$expectedFiles = [
    'classes/Inventory/InventoryHistoricalMigrationService.php',
    'tools/inventory_migration_plan.php',
    'tools/inventory_backfill_from_fat_details.php',
    'tools/inventory_rebuild_balances.php',
    'docs/inventory/phase14_migration_contracts.md',
];

foreach ($expectedFiles as $relativePath) {
    inventoryPhase14ToolsAssert(is_file($root . '/' . $relativePath), 'missing Phase 14 file: ' . $relativePath);
}

$planSource = inventoryPhase14ToolsSource($root . '/tools/inventory_migration_plan.php');
$backfillSource = inventoryPhase14ToolsSource($root . '/tools/inventory_backfill_from_fat_details.php');
$rebuildSource = inventoryPhase14ToolsSource($root . '/tools/inventory_rebuild_balances.php');
$serviceSource = inventoryPhase14ToolsSource($root . '/classes/Inventory/InventoryHistoricalMigrationService.php');
$docs = inventoryPhase14ToolsSource($root . '/docs/inventory/phase14_migration_contracts.md');

foreach ([$planSource] as $source) {
    inventoryPhase14ToolsAssert(strpos($source, "'dry-run'") !== false, 'Phase 14 tools must require --dry-run');
    inventoryPhase14ToolsAssert(strpos($source, 'InventoryHistoricalMigrationService.php') !== false, 'Phase 14 tools must use the migration service');
    inventoryPhase14ToolsAssert(strpos($source, 'posmain_db_connect') !== false, 'Phase 14 tools must use normal POSMAIN DB bootstrap');
    inventoryPhase14ToolsAssert(
        !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[^A-Za-z_]|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $source),
        'Phase 14 CLI wrappers must remain read-only'
    );
}

inventoryPhase14ToolsAssert(strpos($backfillSource, "'dry-run'") !== false && strpos($backfillSource, "'rehearse'") !== false && strpos($backfillSource, "'apply'") !== false, 'backfill tool should require explicit dry-run, rehearse, or apply mode');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'decisions-file') !== false, 'backfill tool should accept reviewed decisions file for ambiguous rows');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'review_decisions_file_invalid_json') !== false, 'backfill tool should validate reviewed decisions JSON');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'backup-file') !== false, 'backfill apply should require backup-file option');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'readable_database_backup_file_required_for_backfill_apply') !== false, 'backfill apply should block without readable backup');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'applyFatDetailsBackfill') !== false, 'backfill apply should use migration service apply path');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'rehearseFatDetailsBackfill') !== false, 'backfill rehearse should use migration service rehearsal path');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'rolled-back transaction') !== false, 'backfill rehearse should describe rollback behavior');
inventoryPhase14ToolsAssert(strpos($backfillSource, 'posmain_db_connect') !== false, 'backfill tool must use normal POSMAIN DB bootstrap');

inventoryPhase14ToolsAssert(strpos($rebuildSource, "'dry-run'") !== false && strpos($rebuildSource, "'rehearse'") !== false && strpos($rebuildSource, "'apply'") !== false, 'rebuild tool should require explicit dry-run, rehearse, or apply mode');
inventoryPhase14ToolsAssert(strpos($rebuildSource, 'backup-file') !== false, 'rebuild apply should require backup-file option');
inventoryPhase14ToolsAssert(strpos($rebuildSource, 'readable_database_backup_file_required_for_balance_rebuild_apply') !== false, 'rebuild apply should block without readable backup');
inventoryPhase14ToolsAssert(strpos($rebuildSource, 'rehearseBalanceRebuild') !== false, 'rebuild rehearse should use migration service rehearsal path');
inventoryPhase14ToolsAssert(strpos($rebuildSource, 'applyBalanceRebuild') !== false, 'rebuild apply should use migration service apply path');
inventoryPhase14ToolsAssert(strpos($rebuildSource, 'rolled-back transaction') !== false, 'rebuild rehearse should describe rollback behavior');
inventoryPhase14ToolsAssert(strpos($rebuildSource, 'posmain_db_connect') !== false, 'rebuild tool must use normal POSMAIN DB bootstrap');

foreach ([
    'migrationPlan',
    'fatDetailsBackfillPlan',
    'applyFatDetailsBackfill',
    'rehearseFatDetailsBackfill',
    'reviewAmbiguousFatDetailsRow',
    'non_stock_item_not_migrated_to_inventory_ledger',
    'phase14_reviewed_fat_details_backfill',
    'migration:fat_details:',
    'reviewed:v1',
    'rebuildBalancesPlan',
    'rehearseBalanceRebuild',
    'applyBalanceRebuild',
    'movingAverageCostForDerivedBalance',
    'absoluteDecimal',
    'pro_tybe_14_opening_balance_offer_collision',
    'deleted_legacy_row',
    'already_migrated',
    'ambiguous_legacy_rows_require_review',
    'source_type' => "'source_type' => 'fat_details'",
] as $label => $needle) {
    $needle = is_string($needle) ? $needle : (string) $label;
    inventoryPhase14ToolsAssert(strpos($serviceSource, $needle) !== false, 'migration service should contain: ' . $needle);
}

foreach ([
    'database backup evidence',
    '`pro_tybe=14`',
    'idempotent',
    '--rehearse',
    '--decisions-file',
    '--apply --backup-file',
    'balance rebuild',
    'negative on-hand',
    'rolled-back transaction',
    'reviewed ambiguous',
    'branch, store, and item-category signoff',
    'No runtime page switches',
] as $needle) {
    inventoryPhase14ToolsAssert(strpos($docs, $needle) !== false, 'Phase 14 docs should explain: ' . $needle);
}

echo "inventory-phase14-migration-tools-contract-ok\n";

function inventoryPhase14ToolsSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase14ToolsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
