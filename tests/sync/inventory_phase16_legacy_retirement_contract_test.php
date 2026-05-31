<?php

$root = dirname(__DIR__, 2);

$sidebar = inventoryPhase16Source($root . '/includes/sidebar.php');
$openingPage = inventoryPhase16Source($root . '/items_start_balance.php');
$openingSave = inventoryPhase16Source($root . '/save_start_balance.php');
$legacyMirror = inventoryPhase16Source($root . '/classes/Inventory/InventoryLegacyMirrorService.php');
$endpointGuard = inventoryPhase16Source($root . '/classes/Inventory/InventoryLegacyStockEndpointGuard.php');
$retiredEndpoint = inventoryPhase16Source($root . '/classes/Inventory/InventoryRetiredLegacyEndpoint.php');
$readinessService = inventoryPhase16Source($root . '/classes/Inventory/InventoryLegacyRetirementReadinessService.php');
$tool = inventoryPhase16Source($root . '/tools/inventory_legacy_retirement_check.php');
$triggerTool = inventoryPhase16Source($root . '/tools/inventory_retire_legacy_triggers.php');
$docs = inventoryPhase16Source($root . '/docs/inventory/phase16_legacy_retirement_contracts.md');

inventoryPhase16Assert(strpos($sidebar, '$posmainInventoryLegacyOpeningBalanceVisible') !== false, 'sidebar should gate old item opening balance link');
inventoryPhase16Assert(strpos($openingPage, 'inventory_adjustments.php?legacy_opening_balance=retired') !== false, 'old opening balance page should redirect in live mode');
inventoryPhase16Assert(strpos($openingSave, 'opening_balance_legacy_workflow_retired_in_live_inventory_mode') !== false, 'old opening balance save should be blocked in live mode');
inventoryPhase16Assert(strpos($openingSave, 'InventoryLegacyMirrorService') !== false, 'old opening balance save should delegate compatibility mirror writes to inventory service');
inventoryPhase16Assert(strpos($legacyMirror, 'UPDATE myitems SET itmqty') !== false, 'legacy mirror service should own the compatibility myitems.itmqty update');
inventoryPhase16Assert(strpos($openingSave, 'UPDATE myitems SET itmqty') === false, 'old opening balance save should not directly update myitems.itmqty');
inventoryPhase16Assert(strpos(inventoryPhase16Source($root . '/js/ajax/reindex.php'), 'InventoryRetiredLegacyEndpoint::respond') !== false, 'legacy reindex endpoint should be retired');
inventoryPhase16Assert(strpos(inventoryPhase16Source($root . '/js/ajax/reindex.php'), 'UPDATE myitems SET itmqty') === false, 'legacy reindex should not directly update myitems.itmqty');
inventoryPhase16Assert(strpos($retiredEndpoint, 'http_response_code(410)') !== false, 'retired legacy endpoints should emit gone responses');

foreach ([
    'inventoryLegacyRetirementCheck',
    'pending_retirement_items',
    'ready_to_delete_legacy_stock_core',
    'fat_details_stock_triggers_still_defined_in_db_schema',
    'direct_myitems_itmqty_update',
    'unsafe_legacy_stock_endpoint_still_present',
    'legacy_stock_endpoint_live_guarded',
    'unsafeLegacyStockEndpoints',
    'sourceContainsLiveGuard',
    'sourceContainsRetiredEndpoint',
    'global_myitems_itmqty_reindex_from_fat_details',
    'legacy_offline_stock_replay_still_present',
    'legacy_pos_sync_stock_replay_still_present',
    'specialized_invoice_stock_writer_still_present',
    'InventoryLegacyMirrorService.php',
] as $needle) {
    inventoryPhase16Assert(strpos($tool . $readinessService, $needle) !== false, 'retirement readiness should contain: ' . $needle);
}
inventoryPhase16Assert(
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[^A-Za-z_]|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $tool),
    'legacy retirement check tool must remain read-only'
);
foreach ([
    'js/ajax/reindex.php' => 'legacy_reindex_retired',
    'js/ajax/add_row_to_fat_details.php' => 'detached_fat_details_insert_retired',
    'js/ajax/insertfatdet.php' => 'detached_fat_details_insert_retired',
    'js/ajax/delitmdet.php' => 'hard_delete_fat_details_retired',
    'do/offline_sync.php' => 'offline_stock_replay_retired',
    'pos_sync.php' => 'pos_sync_stock_replay_retired',
    'do/doadd_invoice_clothes.php' => 'clothes_invoice_stock_writer_retired',
] as $path => $code) {
    $source = inventoryPhase16Source($root . '/' . $path);
    inventoryPhase16Assert(strpos($source, 'InventoryRetiredLegacyEndpoint::respond') !== false, $path . ' should call the retired legacy endpoint responder');
    inventoryPhase16Assert(strpos($source, $code) !== false, $path . ' should expose a stable retired endpoint code');
    inventoryPhase16Assert(strpos($source, 'INSERT INTO fat_details') === false, $path . ' should not insert legacy fat_details after retirement');
    inventoryPhase16Assert(strpos($source, 'DELETE FROM fat_details') === false, $path . ' should not delete legacy fat_details after retirement');
    inventoryPhase16Assert(strpos($source, 'refreshAllItemQtySummariesFromFatDetails') === false, $path . ' should not trigger legacy global stock rebuild after retirement');
}

inventoryPhase16Assert(strpos(inventoryPhase16Source($root . '/components/pos_clothes/order_form.php'), 'action="do/doadd_invoice.php"') !== false, 'clothes POS form should use the general invoice handler');
inventoryPhase16Assert(strpos(inventoryPhase16Source($root . '/components/pos_clothes/order_form.php'), "csrf_input('pos_browser')") !== false, 'clothes POS form should include the POS browser CSRF token');
inventoryPhase16Assert(strpos(inventoryPhase16Source($root . '/components/pos_clothes/scripts.js'), 'paid_cash') !== false, 'clothes POS submit should provide the general handler cash payment field');
inventoryPhase16Assert(strpos(inventoryPhase16Source($root . '/components/pos_clothes/scripts.js'), 'payment_fund_id') !== false, 'clothes POS submit should provide the general handler payment fund field');
inventoryPhase16Assert(strpos($endpointGuard, "\$flags->mode() !== 'live'") !== false, 'legacy stock endpoint guard should preserve non-live behavior');
inventoryPhase16Assert(strpos($endpointGuard, 'http_response_code(409)') !== false, 'legacy stock endpoint guard should return a conflict response in live mode');
inventoryPhase16Assert(strpos($triggerTool, 'clean_or_accepted_reconciliation') !== false, 'legacy trigger retirement tool should require clean or accepted reconciliation');
inventoryPhase16Assert(strpos($triggerTool, 'InventoryAccountingReconciliationService') !== false, 'legacy trigger retirement tool should check accounting reconciliation');
inventoryPhase16Assert(strpos($triggerTool, 'InventoryAccountingReconciliationAcceptanceService') !== false, 'legacy trigger retirement tool should support accepted accounting reconciliation');
inventoryPhase16Assert(strpos($triggerTool, 'clean_or_accepted_inventory_accounting_reconciliation') !== false, 'legacy trigger retirement tool should require clean or accepted accounting reconciliation before apply');
inventoryPhase16Assert(strpos($triggerTool, 'inventory_accounting_reconciliation_not_ready') !== false, 'legacy trigger retirement tool should block when accounting reconciliation has problems');
inventoryPhase16Assert(strpos($triggerTool, 'accepted_inventory_accounting_reconciliation_requires_explicit_allow_flag') !== false, 'accepted accounting reconciliation should require an explicit allow flag before trigger retirement');
inventoryPhase16Assert(strpos($triggerTool, 'inventoryRetireLegacyTriggersAccountingFilters') !== false, 'legacy trigger retirement tool should normalize accounting filters');
inventoryPhase16Assert(strpos($triggerTool, "\$storeId > 0 ? \$storeId : -1") !== false, 'legacy trigger retirement tool should treat store 0 as all stores for accounting checks');
inventoryPhase16Assert(strpos($triggerTool, 'accepted_reconciliation_requires_explicit_allow_flag') !== false, 'accepted reconciliation should require an explicit allow flag before trigger retirement');
inventoryPhase16Assert(strpos($triggerTool, '--acceptance-file') !== false, 'legacy trigger retirement tool should accept an explicit reconciliation acceptance file');
inventoryPhase16Assert(strpos($triggerTool, '--accounting-acceptance-file') !== false, 'legacy trigger retirement tool should accept an explicit accounting reconciliation acceptance file');
inventoryPhase16Assert(strpos($triggerTool, 'readable_database_backup_file_required_for_trigger_retirement') !== false, 'legacy trigger retirement tool should require a readable backup before apply');
inventoryPhase16Assert(strpos($triggerTool, 'DROP TRIGGER IF EXISTS update_after_update') !== false, 'legacy trigger retirement tool should plan update trigger removal');
inventoryPhase16Assert(strpos($triggerTool, 'DROP TRIGGER IF EXISTS update_balance_trigger') !== false, 'legacy trigger retirement tool should plan insert trigger removal');

foreach ([
    'destructive removal is deliberately blocked',
    '`php tools/inventory_legacy_retirement_check.php --json`',
    '`php tools/inventory_retire_legacy_triggers.php --dry-run --json`',
    'Existing databases may still contain',
    'return stable 410 retired-endpoint responses',
    'legacy_stock_endpoint_retired:*',
    '`legacy_stock_endpoint_retired:*`',
    '`legacy_stock_endpoint_live_guarded:*`',
    'scans multi-line `myitems.itmqty` updates',
    '`accounting_reconciliation` summary',
    '`inventory_accounting_reconciliation_not_ready` blocker',
    '`--accounting-acceptance-file`',
    '`accepted_inventory_accounting_reconciliation_requires_explicit_allow_flag`',
    '`--store=0` means all stores',
    'Do not delete old stock code',
] as $needle) {
    inventoryPhase16Assert(strpos($docs, $needle) !== false, 'phase 16 docs should explain: ' . $needle);
}

$runtimeJson = shell_exec('cd ' . escapeshellarg($root) . ' && php tools/inventory_legacy_retirement_check.php --json 2>/dev/null');
inventoryPhase16Assert(is_string($runtimeJson) && trim($runtimeJson) !== '', 'legacy retirement check should produce JSON');
$runtime = json_decode((string) $runtimeJson, true);
inventoryPhase16Assert(is_array($runtime), 'legacy retirement check JSON should decode');
$pending = $runtime['pending_retirement_items'] ?? [];
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'direct_myitems_itmqty_update:js/ajax/reindex.php:'), 'legacy retirement check should not report reindex as a direct itmqty writer after delegation to mirror service');
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'unsafe_legacy_stock_endpoint_still_present:js/ajax/reindex.php:'), 'retired reindex endpoint should not remain a pending unsafe stock endpoint');
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'unsafe_legacy_stock_endpoint_still_present:js/ajax/add_row_to_fat_details.php:'), 'retired detached insert endpoint should not remain a pending unsafe stock endpoint');
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'unsafe_legacy_stock_endpoint_still_present:js/ajax/insertfatdet.php:'), 'retired detached insert endpoint should not remain a pending unsafe stock endpoint');
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'unsafe_legacy_stock_endpoint_still_present:js/ajax/delitmdet.php:'), 'retired hard-delete endpoint should not remain a pending unsafe stock endpoint');
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'unsafe_legacy_stock_endpoint_still_present:do/offline_sync.php:'), 'retired offline stock replay should not remain a pending unsafe stock endpoint');
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'unsafe_legacy_stock_endpoint_still_present:pos_sync.php:'), 'retired POS sync stock replay should not remain a pending unsafe stock endpoint');
inventoryPhase16Assert(!inventoryPhase16HasPendingPrefix($pending, 'unsafe_legacy_stock_endpoint_still_present:do/doadd_invoice_clothes.php:'), 'retired clothes stock writer should not remain a pending unsafe stock endpoint');
$proven = $runtime['proven_controls'] ?? [];
inventoryPhase16Assert(
    in_array('legacy_stock_endpoint_retired:js/ajax/reindex.php', $proven, true)
        && in_array('legacy_stock_endpoint_retired:js/ajax/add_row_to_fat_details.php', $proven, true)
        && in_array('legacy_stock_endpoint_retired:js/ajax/insertfatdet.php', $proven, true)
        && in_array('legacy_stock_endpoint_retired:js/ajax/delitmdet.php', $proven, true)
        && in_array('legacy_stock_endpoint_retired:do/offline_sync.php', $proven, true)
        && in_array('legacy_stock_endpoint_retired:pos_sync.php', $proven, true)
        && in_array('legacy_stock_endpoint_retired:do/doadd_invoice_clothes.php', $proven, true),
    'legacy retirement check should report fully retired legacy stock endpoints'
);

echo "inventory-phase16-legacy-retirement-contract-ok\n";

function inventoryPhase16Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase16Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryPhase16HasPendingPrefix(array $pending, string $prefix): bool
{
    foreach ($pending as $item) {
        if (strpos((string) $item, $prefix) === 0) {
            return true;
        }
    }

    return false;
}
