<?php

$root = dirname(__DIR__, 2);

$serviceSource = inventoryPhase15Source($root . '/classes/Inventory/InventoryStockReadService.php');
$itemsApiSource = inventoryPhase15Source($root . '/api/items.php');
$readinessSource = inventoryPhase15Source($root . '/classes/Inventory/InventoryCutoverReadinessService.php');
$legacyReadinessSource = inventoryPhase15Source($root . '/classes/Inventory/InventoryLegacyRetirementReadinessService.php');
$readinessTool = inventoryPhase15Source($root . '/tools/inventory_cutover_readiness.php');
$myitemsSource = inventoryPhase15Source($root . '/myitems.php');
$itemSummarySource = inventoryPhase15Source($root . '/item_summery.php');
$stagnantSource = inventoryPhase15Source($root . '/stagnant-items-report.php');
$dashboardItemsSource = inventoryPhase15Source($root . '/elements/main/main_tables.php');
$docs = inventoryPhase15Source($root . '/docs/inventory/phase15_cutover_contracts.md');

foreach ([
    'shouldReadLedger',
    "\$this->flags->mode() === 'live'",
    'inventory_item_balances',
    'inventory_movements',
    'decorateItems',
    'decoratePublicItemPayload',
    'movementHistoryForItem',
    'itemListLedgerJoin',
] as $needle) {
    inventoryPhase15Assert(strpos($serviceSource, $needle) !== false, 'cutover stock read service should contain: ' . $needle);
}
foreach ([
    'InventoryStockReadService.php',
    'decoratePublicItemPayload',
    'stock_quantity_source',
] as $needle) {
    inventoryPhase15Assert(strpos($itemsApiSource . $serviceSource, $needle) !== false, 'public items API should use cutover stock read service: ' . $needle);
}
foreach ([
    'InventoryCutoverReadinessService',
    'InventoryBalanceRebuildAcceptanceService',
    'InventoryLegacyRetirementReadinessService',
    'ready_for_cutover',
    'ready_for_legacy_retirement',
    'pending_retirement_items',
    'ambiguous_legacy_rows_require_review',
    '--decisions-file',
    'inventory_rebuild_has_cost_differences',
    'accepted_balance_rebuild_differences_require_explicit_allow_flag',
    '--rebuild-acceptance-file',
    'inventory_accounting_reconciliation_not_ready',
    'fat_details_stock_triggers_still_defined_in_db_schema',
    'unsafe_legacy_stock_endpoint_still_present',
] as $needle) {
    inventoryPhase15Assert(strpos($readinessSource . $legacyReadinessSource . $readinessTool, $needle) !== false, 'cutover readiness should contain: ' . $needle);
}
inventoryPhase15Assert(
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[^A-Za-z_]|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $readinessTool),
    'cutover readiness tool must remain read-only'
);

inventoryPhase15Assert(strpos($myitemsSource, 'InventoryStockReadService.php') !== false, 'item list should load stock read service');
inventoryPhase15Assert(strpos($myitemsSource, 'decorateItems($conn') !== false, 'item list should decorate quantities through stock read service');
inventoryPhase15Assert(
    strpos($dashboardItemsSource, 'itmqty') === false
        && strpos($dashboardItemsSource, 'fat_details') === false,
    'dashboard must not reintroduce a legacy stock read when no stock quantity is rendered'
);
inventoryPhase15Assert(strpos($itemSummarySource, 'movementHistoryForItem') !== false, 'item summary should read movement history through stock read service');
inventoryPhase15Assert(strpos($stagnantSource, 'itemListLedgerJoin') !== false, 'stagnant report should use cutover-aware stock join');
inventoryPhase15Assert(strpos($stagnantSource, 'current_qty') !== false, 'stagnant report should filter/order by current stock alias');

foreach ([
    '`POSMAIN_INVENTORY_LEDGER_MODE=live`',
    '`myitems.itmqty` remains a compatibility mirror',
    '`php tools/inventory_cutover_readiness.php --json`',
    '`--decisions-file=/absolute/path/to/reviewed-decisions.json`',
    '`--rebuild-acceptance-file`',
    '`accepted_balance_rebuild_differences_require_explicit_allow_flag`',
    '`ready_for_cutover` can pass before live mode',
    '`ready_for_legacy_retirement` is stricter',
    'unsafe legacy stock endpoints',
    'no feature flag default is flipped',
    'in-app browser path',
] as $needle) {
    inventoryPhase15Assert(strpos($docs, $needle) !== false, 'cutover docs should explain: ' . $needle);
}

echo "inventory-phase15-cutover-contract-ok\n";

function inventoryPhase15Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase15Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
