<?php

$root = dirname(__DIR__, 2);

$operationalSource = singleStoreOperationalSource($root . '/includes/pos_operational_store.php');
$defaultsSource = singleStoreOperationalSource($root . '/includes/pos_default_accounts.php');
$schemaSource = singleStoreOperationalSource($root . '/classes/Sync/SchemaManager.php');
$scopeResolverSource = singleStoreOperationalSource($root . '/classes/Inventory/InventoryScopeResolver.php');
$recipeScopeResolverSource = singleStoreOperationalSource($root . '/classes/Recipe/RecipeScopeResolver.php');
$settingsEditSource = singleStoreOperationalSource($root . '/do/doedit_settings.php');
$preflightSource = singleStoreOperationalSource($root . '/tools/inventory_single_store_preflight.php');
$mergeSource = singleStoreOperationalSource($root . '/tools/inventory_merge_stores_to_operational.php');
$lazySource = singleStoreOperationalSource($root . '/ajax/load_items_lazy.php');
$searchSource = singleStoreOperationalSource($root . '/ajax/search_items.php');
$categorySource = singleStoreOperationalSource($root . '/ajax/get_category_items.php');
$posContent = singleStoreOperationalSource($root . '/includes/pos_content.php');
$transferCommon = singleStoreOperationalSource($root . '/ajax/inventory_transfer_common.php');
$countCommon = singleStoreOperationalSource($root . '/ajax/inventory_count_common.php');
$purchaseReceive = singleStoreOperationalSource($root . '/ajax/inventory_purchase_receive.php');
$posOrderMutationSource = singleStoreOperationalSource($root . '/classes/Pos/Service/PosOrderMutationService.php');
$doeditInvoiceSource = singleStoreOperationalSource($root . '/do/doedit_invoice.php');
$cofeCreateOrderSource = singleStoreOperationalSource($root . '/ajax/cofe_create_order.php');
$saveStartBalanceSource = singleStoreOperationalSource($root . '/save_start_balance.php');
$doaddStoreSource = singleStoreOperationalSource($root . '/do/doadd_store.php');
$moovaMenuSyncSource = singleStoreOperationalSource($root . '/ajax/moova_menu_sync_payload.php');

singleStoreOperationalAssert(is_file($root . '/includes/pos_operational_store.php'), 'missing pos_operational_store.php');
singleStoreOperationalAssert(is_file($root . '/tools/inventory_single_store_preflight.php'), 'missing preflight tool');
singleStoreOperationalAssert(is_file($root . '/tools/inventory_merge_stores_to_operational.php'), 'missing merge tool');
singleStoreOperationalAssert(is_file($root . '/tools/inventory_reclassify_store_scope.php'), 'missing immutable scope reclassification tool');
singleStoreOperationalAssert(is_file($root . '/classes/Inventory/InventoryStoreScopeReclassificationService.php'), 'missing immutable scope reclassification service');

foreach ([
    'posmain_single_store_mode_enabled',
    'posmain_operational_store_id',
    'posmain_assert_operational_store_id',
    'posmain_resolve_store_scope_for_read',
    'posmain_resolve_store_scope_for_write',
    'posmain_list_operational_stores',
    'posmain_sync_operational_store_flags',
    'posmain_pos_availability_scope',
    'posmain_inventory_store_select_options',
    'posmain_inventory_enforce_operational_store_write',
    'posmain_inventory_transfers_allowed',
    'posmain_apply_read_store_filter',
] as $symbol) {
    singleStoreOperationalAssert(strpos($operationalSource, 'function ' . $symbol) !== false, 'operational helper missing: ' . $symbol);
}

singleStoreOperationalAssert(strpos($schemaSource, 'is_operational_store') !== false, 'SchemaManager should migrate is_operational_store');
singleStoreOperationalAssert(strpos($defaultsSource, 'posmain_assert_operational_store_id') !== false, 'invoice account resolver should assert operational store');
singleStoreOperationalAssert(strpos($defaultsSource, 'posmain_operational_store_id') !== false, 'POS defaults should prefer operational store in single-store mode');

singleStoreOperationalAssert(strpos($preflightSource, 'inventorySingleStorePreflightBuildReport') !== false, 'preflight should build structured report');
singleStoreOperationalAssert(strpos($preflightSource, 'blocking') !== false, 'preflight should classify blocking issues');
singleStoreOperationalAssert(strpos($mergeSource, '--backup-confirmed') !== false, 'merge apply should require backup confirmation');
singleStoreOperationalAssert(strpos($mergeSource, 'Direct balance merge apply is retired') !== false, 'unsafe direct balance merge apply should be retired');
singleStoreOperationalAssert(strpos($mergeSource, 'inventory_item_stock_levels') !== false, 'merge should handle stock levels');
singleStoreOperationalAssert(strpos($mergeSource, 'recipe_availability_cache') !== false, 'merge should purge availability cache');

foreach ([$lazySource, $searchSource, $categorySource] as $endpointSource) {
    singleStoreOperationalAssert(strpos($endpointSource, 'posmain_pos_availability_scope') !== false, 'availability endpoint should use operational scope helper');
}

singleStoreOperationalAssert(strpos($posContent, 'posmain_pos_availability_scope') !== false, 'POS grid should decorate with operational store scope');
singleStoreOperationalAssert(strpos($posContent, 'posmain_inventory_store_select_options') !== false, 'POS setup should list operational stores only');

singleStoreOperationalAssert(strpos($transferCommon, 'inventoryTransferNormalizePayload') !== false, 'transfer ajax should normalize store scope');
singleStoreOperationalAssert(strpos($transferCommon, 'INVENTORY_TRANSFERS_DISABLED') !== false, 'transfer ajax should block transfers in single-store mode');
singleStoreOperationalAssert(strpos($countCommon, 'inventoryCountNormalizePayload') !== false, 'count ajax should normalize store scope');
singleStoreOperationalAssert(strpos($operationalSource, 'def_pos_store') !== false, 'operational store should be derived from settings.def_pos_store');
singleStoreOperationalAssert(strpos($operationalSource, 'is_operational_store, 0) = 1') === false, 'operational store resolver must not read stale is_operational_store flags');
singleStoreOperationalAssert(strpos($operationalSource, 'ALTER TABLE acc_head') === false, 'operational store helper must not run runtime DDL');
singleStoreOperationalAssert(strpos($scopeResolverSource, 'resolveForConn') !== false, 'inventory scope resolver should expose resolveForConn');
singleStoreOperationalAssert(strpos($recipeScopeResolverSource, 'resolveForConn') !== false, 'recipe scope resolver should expose resolveForConn');
singleStoreOperationalAssert(strpos($settingsEditSource, 'posmain_sync_operational_store_flags') !== false, 'settings save should sync operational store flags');
singleStoreOperationalAssert(strpos($settingsEditSource, 'posmain_resolve_default_account_id') !== false, 'settings save should validate def_pos_store');
singleStoreOperationalAssert(strpos($mergeSource, 'pos_tenant = ?') !== false, 'merge tool should preserve tenant/branch scope keys');
singleStoreOperationalAssert(strpos($mergeSource, 'begin_transaction') !== false, 'merge apply should run in a transaction');

singleStoreOperationalAssert(strpos($operationalSource, 'return true') !== false, 'single-store mode should be hardcoded on');
singleStoreOperationalAssert(strpos($posOrderMutationSource, 'resolvePosRequestAccounts($conn, $request)') !== false, 'table save should resolve POS accounts before write');
singleStoreOperationalAssert(strpos($doeditInvoiceSource, 'posmain_resolve_pos_invoice_accounts') !== false, 'invoice edit should resolve operational store');
singleStoreOperationalAssert(
    strpos($cofeCreateOrderSource, "pos_api_dispatch(\$conn, 'integrations.cofe.orders')") !== false,
    'cofe create order should use the canonical authenticated dispatcher whose mutation service resolves operational store'
);
singleStoreOperationalAssert(strpos($saveStartBalanceSource, 'posmain_operational_store_id') !== false, 'opening balance should use operational store');
singleStoreOperationalAssert(strpos($doaddStoreSource, 'posmain_single_store_mode_enabled') !== false, 'add store handler should block in single-store mode');
singleStoreOperationalAssert(strpos($moovaMenuSyncSource, 'resolveForConn') !== false, 'moova menu sync should use recipe resolveForConn');

singleStoreOperationalAssert(strpos($purchaseReceive, 'posmain_inventory_enforce_operational_store_write') !== false, 'purchase receive should enforce operational store');
singleStoreOperationalAssert(is_file($root . '/tests/sync/single_store_operational_service_test.php'), 'runtime service test should exist');

singleStoreOperationalAssert(
    strpos($operationalSource, 'InventoryScopeResolver') === false,
    'operational store helper should not depend on InventoryScopeResolver'
);

echo "single-store-operational-contract-ok\n";

function singleStoreOperationalSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function singleStoreOperationalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
