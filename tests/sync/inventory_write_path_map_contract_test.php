<?php

$root = dirname(__DIR__, 2);

$docs = [
    'implementation' => inventoryWritePathDoc($root . '/docs/inventory/implementation_discovery.md'),
    'write_map' => inventoryWritePathDoc($root . '/docs/inventory/write_path_map.md'),
    'invoice_types' => inventoryWritePathDoc($root . '/docs/inventory/invoice_type_map.md'),
    'schema' => inventoryWritePathDoc($root . '/docs/inventory/current_schema_findings.md'),
    'phase0_audit' => inventoryWritePathDoc($root . '/docs/inventory/phase0_completion_audit.md'),
];

foreach ([
    'fat_details',
    'myitems.itmqty',
    'inventory_movements',
    'inventory_item_balances',
    'stock_reservations',
    'RecipeInventoryMovementService',
    'RecipeReservationService',
    'StockReservationRepository',
    'save_start_balance.php',
    'ajax/cofe_create_order.php',
    'do/doadd_invoice.php',
    'do/doedit_invoice.php',
    'do/dodel_invoice.php',
] as $needle) {
    inventoryWritePathAssert(
        strpos(implode("\n", $docs), $needle) !== false,
        'inventory docs should mention critical write path or table: ' . $needle
    );
}

foreach ([
    'classes/Sync/CloudLegacyPosMirrorService.php',
    'classes/Pos/Service/PosOrderMutationService.php',
    'classes/Pos/Service/InventoryMovementService.php',
    'classes/Inventory/InventoryPurchaseReceivingService.php',
    'classes/Inventory/InventoryLegacyMirrorService.php',
    'classes/Inventory/InventoryStockLevelService.php',
    'classes/Inventory/InventoryTransferService.php',
    'classes/Inventory/InventoryCountService.php',
    'classes/Inventory/InventoryAdjustmentService.php',
    'classes/Inventory/InventoryHistoricalMigrationService.php',
    'classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php',
    'classes/Recipe/RecipeOrderLifecycleService.php',
    'classes/Recipe/ProductionBatchMutationService.php',
    'classes/Recipe/ProductionBatchService.php',
    'classes/Recipe/Repository/ProductionBatchRepository.php',
    'recipe_manage.php',
    'classes/Recipe/RecipeEditorMutationService.php',
    'classes/Recipe/RecipeDefinitionService.php',
    'classes/Recipe/RecipeCostService.php',
    'classes/Recipe/RecipeAvailabilityService.php',
    'classes/Recipe/Repository/RecipeRepository.php',
    'classes/Recipe/Repository/RecipeLineRepository.php',
    'classes/Recipe/Repository/RecipeVariantLineRepository.php',
    'classes/Recipe/Repository/RecipeCostSnapshotRepository.php',
    'classes/Recipe/Repository/RecipeOrderLineUsageRepository.php',
    'classes/Recipe/Repository/RecipeAvailabilityCacheRepository.php',
    'classes/Pos/Service/TableMergeService.php',
    'classes/TableOrderService.php',
    'recipe_production.php',
    'production.php',
    'do/doadd_production.php',
    'do/doedit_production.php',
    'do/dodel_production.php',
    'do/doadd_invoice_clothes.php',
    'do/offline_sync.php',
    'do/dbase/do_turncate.php',
    'pos_sync.php',
    'js/ajax/add_row_to_fat_details.php',
    'js/ajax/insertfatdet.php',
    'js/ajax/delitmdet.php',
    'do/dodel_pro.php',
    'do/recost.php',
    'js/ajax/recost.php',
    'do/doadd_item.php',
    'js/ajax/doadd_item.php',
    'setup_demo_data.php',
    'do/uploaditems.php',
    'do/update_item_price.php',
    'do/doedit_item.php',
    'do/dodel_item.php',
    'do/reset_manual_prices.php',
    'js/ajax/reindex.php',
    'js/ajax/update_price.php',
    'classes/Items/ItemRecipeCatalogService.php',
    'classes/Pos/Service/ItemVariantService.php',
    'classes/Recipe/RecipePilotFixtureService.php',
] as $secondaryNeedle) {
    inventoryWritePathAssert(
        strpos($docs['write_map'], $secondaryNeedle) !== false,
        'write path map should classify secondary stock/cost surface: ' . $secondaryNeedle
    );
}

foreach ([
    'tools/inventory_backfill_from_fat_details.php',
    'tools/inventory_rebuild_balances.php',
    'tools/inventory_reconciliation_repair.php',
    'tools/inventory_retire_legacy_triggers.php',
    'tools/inventory_migration_plan.php',
    'tools/inventory_reconciliation_check.php',
    'tools/inventory_cutover_readiness.php',
] as $toolNeedle) {
    inventoryWritePathAssert(
        strpos($docs['write_map'], $toolNeedle) !== false,
        'write path map should classify inventory CLI tool surface: ' . $toolNeedle
    );
}

foreach ([
    'tools/recipe_pilot_fixture.php',
    'tools/recipe_fixture_stock_adjustment.php',
    'tools/recipe_migrated_write_smoke.php',
    'tools/recipe_cashier_browser_fixture.php',
] as $recipeToolNeedle) {
    inventoryWritePathAssert(
        strpos($docs['write_map'], $recipeToolNeedle) !== false,
        'write path map should classify recipe CLI stock/fixture surface: ' . $recipeToolNeedle
    );
}

inventoryWritePathAssert(
    strpos($docs['write_map'], 'tools/recipe_expire_reservations.php') !== false,
    'write path map should classify recipe reservation expiry apply tool surface'
);

foreach ([
    'db/DB.sql:904',
    'do/doadd_invoice.php:551',
    'do/doadd_invoice.php:859',
    'do/doadd_invoice.php:864',
    'do/doadd_invoice.php:1282',
    'do/doedit_invoice.php:481',
    'do/dodel_invoice.php:190',
    'classes/PosOrderService.php:955',
    'ajax/cofe_create_order.php:333',
    'save_start_balance.php:241',
    'save_start_balance.php:659',
    'classes/Inventory/InventoryInvoiceBridge.php:324',
    'classes/Inventory/InventoryLegacyMirrorService.php:37',
    'classes/Inventory/InventoryLegacyMirrorService.php:57',
    'classes/Recipe/RecipeInventoryMovementService.php:60',
    'classes/Recipe/RecipeInventoryMovementService.php:830',
    'classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php:127',
    'classes/Recipe/RecipeOrderLifecycleService.php:94',
    'recipe_production.php:28',
    'classes/Recipe/ProductionBatchMutationService.php:48',
    'classes/Recipe/ProductionBatchService.php:149',
    'classes/Recipe/ProductionBatchService.php:168',
    'classes/Recipe/RecipeInventoryMovementService.php:647',
    'classes/Recipe/RecipeInventoryMovementService.php:700',
    'classes/Recipe/Repository/ProductionBatchRepository.php:36',
    'classes/Recipe/Repository/InventoryMovementRepository.php:84',
    'classes/Recipe/Repository/InventoryBalanceRepository.php:21',
    'classes/Recipe/RecipeReservationService.php:56',
    'classes/Recipe/Repository/StockReservationRepository.php:32',
    'tools/recipe_pilot_fixture.php:43',
    'tools/recipe_fixture_stock_adjustment.php:171',
    'tools/recipe_migrated_write_smoke.php:182',
    'tools/recipe_cashier_browser_fixture.php:266',
    'tools/recipe_expire_reservations.php:58',
    'ajax/inventory_purchase_receive.php:36',
    'ajax/inventory_transfer_send.php:17',
    'ajax/inventory_transfer_receive.php:17',
    'ajax/inventory_count_close.php:22',
    'ajax/inventory_count_reverse.php:21',
    'ajax/inventory_adjustment.php:52',
    'do/dodel_pro.php:42',
    'do/dbase/do_turncate.php:3',
    'do/dbase/do_turncate.php:47',
    'do/dbase/do_turncate.php:56',
    'do/dbase/do_turncate.php:65',
    'do/dbase/do_turncate.php:111',
    'do/doadd_item.php:92',
    'js/ajax/doadd_item.php:42',
    'do/doedit_item.php:139',
    'classes/Pos/Service/ItemVariantService.php:532',
    'classes/Pos/Service/ItemVariantService.php:539',
] as $proofNeedle) {
    inventoryWritePathAssert(
        strpos($docs['write_map'], $proofNeedle) !== false,
        'write path map should include source-line proof: ' . $proofNeedle
    );
}

foreach ([
    'classes/Sync/CloudLegacyPosMirrorService.php:241',
    'classes/Pos/Service/PosOrderMutationService.php:697',
    'classes/Pos/Service/PosOrderMutationService.php:477',
    'classes/Pos/Service/InventoryMovementService.php:51',
    'classes/Pos/Service/InventoryMovementService.php:90',
    'classes/Pos/Service/InventoryMovementService.php:100',
    'classes/Inventory/InventoryPurchaseReceivingService.php:88',
    'classes/Inventory/InventoryPurchaseReceivingService.php:203',
    'classes/Inventory/InventoryPurchaseReceivingService.php:282',
    'classes/Inventory/InventoryPurchaseReceivingService.php:434',
    'classes/Inventory/InventoryPurchaseReceivingService.php:462',
    'classes/Inventory/InventoryTransferService.php:142',
    'classes/Inventory/InventoryTransferService.php:250',
    'classes/Inventory/InventoryTransferService.php:707',
    'classes/Inventory/InventoryCountService.php:534',
    'classes/Inventory/InventoryCountService.php:602',
    'classes/Inventory/InventoryAdjustmentService.php:87',
    'classes/Inventory/InventoryHistoricalMigrationService.php:196',
    'classes/Pos/Service/TableMergeService.php:88',
    'classes/TableOrderService.php:331',
    'do/offline_sync.php:31',
    'pos_sync.php:50',
    'pos_sync.php:161',
    'pos_sync.php:193',
    'pos_sync.php:198',
    'js/ajax/add_row_to_fat_details.php:4',
    'js/ajax/insertfatdet.php:4',
    'js/ajax/delitmdet.php:4',
    'do/recost.php:51',
    'do/doadd_item.php:52',
    'do/doadd_item.php:92',
    'js/ajax/doadd_item.php:31',
    'js/ajax/doadd_item.php:42',
    'setup_demo_data.php:98',
    'do/doedit_item.php:116',
    'do/doedit_item.php:139',
    'do/dodel_item.php:10',
    'do/reset_manual_prices.php:6',
    'js/ajax/reindex.php:4',
    'js/ajax/update_price.php:7',
    'classes/Pos/Service/ItemVariantService.php:428',
    'classes/Pos/Service/ItemVariantService.php:532',
    'classes/Pos/Service/ItemVariantService.php:539',
    'classes/Sync/CloudLegacyPosMirrorService.php:509',
    'classes/Recipe/RecipePilotFixtureService.php:769',
    'classes/Recipe/RecipeReservationService.php:90',
    'classes/Recipe/RecipeReservationService.php:154',
    'classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php:202',
    'classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php:377',
    'classes/Recipe/RecipeOrderLifecycleService.php:292',
    'classes/Recipe/RecipeOrderLifecycleService.php:774',
    'classes/Recipe/ProductionBatchMutationService.php:33',
    'classes/Recipe/ProductionBatchMutationService.php:57',
    'classes/Recipe/ProductionBatchService.php:121',
    'classes/Recipe/ProductionBatchService.php:153',
    'classes/Recipe/ProductionBatchService.php:175',
    'classes/Recipe/ProductionBatchService.php:200',
    'classes/Recipe/ProductionBatchService.php:207',
    'recipe_manage.php:28',
    'classes/Recipe/RecipeDefinitionService.php:49',
    'classes/Recipe/RecipeDefinitionService.php:86',
    'classes/Recipe/RecipeDefinitionService.php:118',
    'classes/Recipe/RecipeDefinitionService.php:163',
    'classes/Recipe/Repository/RecipeRepository.php:37',
    'classes/Recipe/Repository/RecipeLineRepository.php:36',
    'classes/Recipe/Repository/RecipeLineRepository.php:98',
    'classes/Recipe/Repository/RecipeLineRepository.php:109',
    'classes/Recipe/Repository/RecipeVariantLineRepository.php:62',
    'classes/Recipe/Repository/RecipeVariantLineRepository.php:95',
    'classes/Recipe/RecipeCostService.php:75',
    'classes/Recipe/Repository/RecipeCostSnapshotRepository.php:26',
    'classes/Recipe/RecipeOrderLifecycleService.php:452',
    'classes/Recipe/RecipeOrderLifecycleService.php:500',
    'classes/Recipe/Repository/RecipeOrderLineUsageRepository.php:47',
    'classes/Recipe/Repository/RecipeOrderLineUsageRepository.php:109',
    'classes/Recipe/Repository/RecipeOrderLineUsageRepository.php:122',
    'classes/Recipe/RecipeAvailabilityService.php:303',
    'classes/Recipe/Repository/RecipeAvailabilityCacheRepository.php:36',
    'classes/Recipe/RecipePilotFixtureService.php:850',
    'classes/Recipe/Repository/ProductionBatchRepository.php:57',
    'classes/Recipe/Repository/ProductionBatchRepository.php:92',
    'classes/Recipe/Repository/ProductionBatchRepository.php:113',
    'do/doadd_production.php:9',
    'do/doedit_production.php:10',
    'do/doedit_production.php:12',
    'do/dodel_production.php:4',
    'classes/Pos/Service/PosOrderMutationService.php:1126',
    'classes/Pos/Service/PosOrderMutationService.php:1138',
    'classes/Pos/Service/PosOrderMutationService.php:1153',
    'classes/Pos/Service/PosOrderMutationService.php:1370',
    'classes/Recipe/Repository/StockReservationRepository.php:132',
    'ajax/inventory_purchase_receive.php:33',
    'ajax/inventory_adjustment.php:55',
] as $secondaryProofNeedle) {
    inventoryWritePathAssert(
        strpos($docs['write_map'], $secondaryProofNeedle) !== false,
        'write path map should include secondary source-line proof: ' . $secondaryProofNeedle
    );
}

$undocumentedToolWriters = inventoryWritePathUndocumentedInventoryToolWriters($root, $docs['write_map']);
inventoryWritePathAssert(
    $undocumentedToolWriters === [],
    'write path map is missing inventory CLI apply surface(s): ' . implode(', ', $undocumentedToolWriters)
);

$undocumentedRecipeToolWriters = inventoryWritePathUndocumentedRecipeToolWriters($root, $docs['write_map']);
inventoryWritePathAssert(
    $undocumentedRecipeToolWriters === [],
    'write path map is missing recipe CLI stock/fixture surface(s): ' . implode(', ', $undocumentedRecipeToolWriters)
);

$undocumentedOtherToolWriters = inventoryWritePathUndocumentedOtherToolWriters($root, $docs['write_map']);
inventoryWritePathAssert(
    $undocumentedOtherToolWriters === [],
    'write path map is missing non-inventory CLI stock/catalog surface(s): ' . implode(', ', $undocumentedOtherToolWriters)
);

$undocumentedRuntimeWriters = inventoryWritePathUndocumentedRuntimeWriters($root, $docs['write_map']);
inventoryWritePathAssert(
    $undocumentedRuntimeWriters === [],
    'write path map is missing runtime inventory writer(s): ' . implode(', ', $undocumentedRuntimeWriters)
);

$undocumentedServiceDelegates = inventoryWritePathUndocumentedServiceDelegates($root, $docs['write_map']);
inventoryWritePathAssert(
    $undocumentedServiceDelegates === [],
    'write path map is missing stock service delegate(s): ' . implode(', ', $undocumentedServiceDelegates)
);

foreach ([
    'No runtime behavior is changed',
    'db_connect_failed',
    'Connection refused',
    'POSMAIN_DB_PORT=3307',
    'ready_for_recipe_operator_qa = true',
    'Dry run: 0 pending sync schema change(s).',
    'type `14`',
    'dual-truth migration problem',
    'InventoryInvoiceBridge',
    'InventoryLegacyMirrorService',
    'inventory CLI apply tools',
    'Reservation writers',
    'Recipe production writers',
    'Recipe CLI stock/fixture writers',
    'internal `do/doadd_invoice.php` `edit_id` replacement behavior',
    'zero-balance initialization',
    'endpoint-to-service delegate lines',
    'recipe production delegate lines',
    'stock reservation writes',
    'destructive inventory-table operations',
    'guarded destructive admin reset',
    'Recipe definition writers',
    'recipe reservation expiry tool',
    'default DB endpoint refuses connections',
    'write path source-line proof drifted',
] as $needle) {
    inventoryWritePathAssert(
        strpos($docs['implementation'], $needle) !== false
            || strpos($docs['invoice_types'], $needle) !== false,
        'inventory discovery should record Phase 0 guardrail/finding: ' . $needle
    );
}

foreach ([
    'Phase 0 Completion Audit',
    'Requirement Audit',
    'Automated Sweep Coverage',
    'Runtime PHP sweep is clean',
    'inventory CLI apply-surface sweep',
    'recipe CLI stock/fixture sweep',
    'InventoryInvoiceBridge',
    'InventoryLegacyMirrorService',
    'service-level stock delegates',
    'recipe production batch stock',
    'recipe zero-balance placeholder creation',
    'write path source-line proof drifted',
    'Arabic premium/smooth UI goal',
    'read-only local runtime preflight',
    'Docker daemon',
    'live-schema proof must be rerun',
    'Cannot connect to the Docker daemon',
    'default DB endpoint',
    '127.0.0.1:3306',
    '2026-05-31T12:09:29Z',
    'Earlier local Docker runtime preflight',
    'Allowed Without DB',
    'Must Wait For DB',
    'Carry-Forward Invariants',
    'Minimum Phase 1 Entry Tests',
    'quantity read/monitoring',
    'workflow/control writes',
    'purchase receipt evidence',
    'old `fat_details` / `myitems.itmqty` behavior remains active',
    'helper writes through',
    'unit conversion writers',
    'recipe/BOM writers',
    'recipe usage and availability cache writers',
    'POSMAIN_INVENTORY_COST_PUBLIC_PAYLOADS',
    'live DB must be rerun',
    'historical evidence only',
] as $auditNeedle) {
    inventoryWritePathAssert(
        strpos($docs['phase0_audit'], $auditNeedle) !== false,
        'phase0 audit should record requirement evidence: ' . $auditNeedle
    );
}

inventoryWritePathAssert(
    strpos($docs['write_map'], '| `do/doadd_invoice.php` |') !== false,
    'write path map should table the add-invoice stock writer'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], '| `classes/Recipe/RecipeInventoryMovementService.php` |') !== false,
    'write path map should table the recipe inventory writer'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], '| `classes/Recipe/ProductionBatchService.php` |') !== false
        && strpos($docs['write_map'], '| `classes/Recipe/Repository/ProductionBatchRepository.php` |') !== false
        && strpos($docs['write_map'], 'Recipe production batch proof') !== false
        && strpos($docs['write_map'], 'rows in `productions` only') !== false,
    'write path map should table recipe production stock and distinguish legacy production naming'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], '| `classes/Inventory/InventoryPurchaseReceivingService.php` |') !== false
        && strpos($docs['write_map'], '| `classes/Inventory/InventoryTransferService.php` |') !== false
        && strpos($docs['write_map'], '| `classes/Inventory/InventoryCountService.php` |') !== false
        && strpos($docs['write_map'], '| `classes/Inventory/InventoryAdjustmentService.php` |') !== false,
    'write path map should table current Inventory module workflow writers'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], '| `classes/Inventory/InventoryStockLevelService.php` |') !== false
        && strpos($docs['write_map'], 'inventory_item_stock_levels') !== false
        && strpos($docs['write_map'], 'recipe_audit_log') !== false
        && strpos($docs['write_map'], 'does not mutate stock movements or balances') !== false
        && strpos($docs['write_map'], 'ajax/inventory_stock_level_save.php:26') !== false,
    'write path map should table stock-level policy writes as audited non-movement inventory policy'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], '| `classes/Pos/Service/InventoryMovementService.php` |') !== false
        && strpos($docs['write_map'], 'Does not write stock directly; normalizes POS/takeaway invoice lines') !== false
        && strpos($docs['write_map'], 'InventoryMovementService::normalizeInvoiceLines()') !== false,
    'write path map should classify POS line quantity normalization as a non-writer stock-effect surface'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], 'classes/Inventory/InventoryLedgerService.php:129') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryLedgerService.php:130') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryLedgerService.php:142') !== false,
    'write path map should use current InventoryLedgerService source-line proof for movement, balance, and audit writes'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], 'classes/Inventory/InventoryInvoiceBridge.php:117') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryInvoiceBridge.php:247') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryInvoiceBridge.php:324') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryInvoiceBridge.php:342') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryInvoiceBridge.php:407') !== false
        && strpos($docs['write_map'], '| `classes/Inventory/InventoryLegacyMirrorService.php` |') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryLegacyMirrorService.php:14') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryLegacyMirrorService.php:57') !== false
        && strpos($docs['write_map'], 'classes/Inventory/InventoryLedgerService.php:365') !== false,
    'write path map should prove bridge idempotency/reversal and legacy mirror write lines'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], 'CLI Migration And Repair Tools') !== false
        && strpos($docs['write_map'], 'Exactly one of `--dry-run`, `--rehearse`, or `--apply`') !== false
        && strpos($docs['write_map'], 'browser operator QA') !== false,
    'write path map should document CLI apply gates and trigger-retirement QA gate'
);
foreach ([
    ['db/DB.sql', 904, 'Retired: legacy `update_after_update`'],
    ['db/DB.sql', 905, 'summary triggers are intentionally no longer created'],
    ['db/DB.sql', 907, 'tools/inventory_retire_legacy_triggers.php'],
    ['do/doadd_invoice.php', 551, 'UPDATE fat_details'],
    ['do/doadd_invoice.php', 859, 'UPDATE fat_details SET isdeleted = 1'],
    ['do/doadd_invoice.php', 864, 'DELETE FROM fat_details'],
    ['do/doadd_invoice.php', 1245, 'INSERT INTO fat_details'],
    ['do/doadd_invoice.php', 1256, 'SELECT cost_price, itmqty FROM myitems'],
    ['do/doadd_invoice.php', 1262, 'UPDATE myitems SET last_price'],
    ['do/doadd_invoice.php', 1282, 'PURCHASE_ORDER'],
    ['do/doadd_invoice.php', 1288, '$qty_in = $itmqty * $u_val'],
    ['do/doadd_invoice.php', 1293, '$qty_out = $itmqty * $u_val'],
    ['do/doadd_invoice.php', 1345, '$stmt_details->bind_param'],
    ['do/doadd_invoice.php', 1388, 'recordInvoiceLines'],
    ['components/pos_clothes/order_form.php', 16, 'action="do/doadd_invoice.php"'],
    ['components/pos_clothes/order_form.php', 17, "csrf_input('pos_browser')"],
    ['components/pos_clothes/scripts.js', 398, 'paidCashInput'],
    ['components/pos_clothes/scripts.js', 418, 'paymentFundInput'],
    ['do/doadd_invoice_clothes.php', 2, 'InventoryRetiredLegacyEndpoint.php'],
    ['do/doadd_invoice_clothes.php', 4, 'clothes_invoice_stock_writer_retired'],
    ['do/offline_sync.php', 8, 'InventoryRetiredLegacyEndpoint.php'],
    ['do/offline_sync.php', 31, 'offline_stock_replay_retired'],
    ['do/doedit_invoice.php', 460, 'recordInvoiceReversalLines'],
    ['do/doedit_invoice.php', 481, 'UPDATE fat_details SET isdeleted = 1'],
    ['do/doedit_invoice.php', 490, 'INSERT INTO fat_details'],
    ['do/doedit_invoice.php', 525, 'PURCHASE_ORDER'],
    ['do/doedit_invoice.php', 531, '$qty_in = $itmqty * $u_val'],
    ['do/doedit_invoice.php', 536, '$qty_out = $itmqty * $u_val'],
    ['do/doedit_invoice.php', 617, 'recordInvoiceLines'],
    ['do/dodel_invoice.php', 133, 'SELECT id, item_id'],
    ['do/dodel_invoice.php', 158, 'recordCurrentOrderDeleted'],
    ['do/dodel_invoice.php', 167, 'recordInvoiceReversalLines'],
    ['do/dodel_invoice.php', 190, 'UPDATE fat_details SET isdeleted = 1'],
    ['do/dodel_pro.php', 42, 'recordInvoiceReversalLines'],
    ['do/dbase/do_turncate.php', 3, 'production_guard_deny_route'],
    ['do/dbase/do_turncate.php', 47, '"fat_details"'],
    ['do/dbase/do_turncate.php', 56, '"item_units"'],
    ['do/dbase/do_turncate.php', 65, '"myitems"'],
    ['do/dbase/do_turncate.php', 111, 'DELETE FROM $table'],
    ['do/doadd_item.php', 52, 'INSERT INTO myitems'],
    ['do/doadd_item.php', 92, 'INSERT INTO item_units'],
    ['js/ajax/doadd_item.php', 31, 'INSERT INTO myitems'],
    ['js/ajax/doadd_item.php', 42, 'INSERT INTO item_units'],
    ['setup_demo_data.php', 98, 'INSERT INTO myitems'],
    ['pos_sync.php', 8, 'InventoryRetiredLegacyEndpoint.php'],
    ['pos_sync.php', 50, 'pos_sync_stock_replay_retired'],
    ['pos_sync.php', 161, 'INSERT INTO myitems'],
    ['pos_sync.php', 198, 'INSERT INTO myitems'],
    ['save_start_balance.php', 200, 'UPDATE fat_details'],
    ['save_start_balance.php', 242, 'INSERT INTO fat_details'],
    ['save_start_balance.php', 349, 'UPDATE inventory_movements'],
    ['save_start_balance.php', 418, 'INSERT INTO inventory_movements'],
    ['save_start_balance.php', 660, 'INSERT INTO inventory_item_balances'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 37, 'recordInvoiceLines'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 86, 'recordInvoiceReversalLines'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 117, 'existingMovementFor'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 220, 'movementTypeForInvoice'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 247, 'idempotencyKey'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 301, 'SELECT id'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 324, 'recordShadowMovement'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 325, 'recordMovement'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 342, 'postAccountingForMovements'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 366, 'postSaleCogs'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 378, 'postRefundReversal'],
    ['classes/Inventory/InventoryInvoiceBridge.php', 407, 'reversalMovementTypeForOriginal'],
    ['classes/Inventory/InventoryLegacyMirrorService.php', 14, 'UPDATE myitems SET itmqty'],
    ['classes/Inventory/InventoryLegacyMirrorService.php', 37, 'UPDATE myitems SET itmqty'],
    ['classes/Inventory/InventoryLegacyMirrorService.php', 57, 'UPDATE myitems'],
    ['classes/Inventory/InventoryLedgerService.php', 365, 'UPDATE myitems SET itmqty'],
    ['classes/Inventory/InventoryLedgerService.php', 373, 'UPDATE myitems SET itmqty'],
    ['classes/Inventory/InventoryLedgerService.php', 381, 'UPDATE myitems SET itmqty'],
    ['classes/Sync/CloudLegacyPosMirrorService.php', 509, 'INSERT INTO myitems'],
    ['do/doedit_item.php', 139, 'UPDATE item_units'],
    ['classes/Pos/Service/PosOrderMutationService.php', 54, 'new InventoryMovementService()'],
    ['classes/Pos/Service/PosOrderMutationService.php', 477, 'normalizeInvoiceLines'],
    ['classes/Pos/Service/InventoryMovementService.php', 51, 'movementQuantities'],
    ['classes/Pos/Service/InventoryMovementService.php', 90, 'movementQuantities'],
    ['classes/Pos/Service/InventoryMovementService.php', 100, 'TYPE_SALES'],
    ['classes/Inventory/InventoryLedgerService.php', 129, 'createMovement'],
    ['classes/Inventory/InventoryLedgerService.php', 130, 'putBalance'],
    ['classes/Inventory/InventoryLedgerService.php', 142, 'writeAudit'],
    ['classes/Inventory/InventoryPurchaseReceivingService.php', 282, 'INSERT INTO inventory_purchase_receipts'],
    ['classes/Inventory/InventoryPurchaseReceivingService.php', 434, 'INSERT INTO inventory_purchase_receipt_lines'],
    ['classes/Inventory/InventoryPurchaseReceivingService.php', 462, 'UPDATE inventory_purchase_receipt_lines SET inventory_movement_id'],
    ['classes/Inventory/InventoryStockLevelService.php', 42, 'INSERT INTO inventory_item_stock_levels'],
    ['classes/Inventory/InventoryStockLevelService.php', 59, 'INSERT INTO inventory_item_stock_levels'],
    ['classes/Inventory/InventoryStockLevelService.php', 133, 'recordStockLevelAudit'],
    ['classes/Inventory/InventoryStockLevelService.php', 460, 'recordStockLevelAudit'],
    ['classes/Inventory/InventoryStockLevelService.php', 476, 'INSERT INTO recipe_audit_log'],
    ['classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php', 106, 'onOrderLineCancelled'],
    ['classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php', 127, 'onOrderPaid'],
    ['classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php', 153, 'recordCurrentOrderVoided'],
    ['classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php', 202, 'onOrderVoided'],
    ['classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php', 377, 'onOrderPaid'],
    ['classes/Recipe/RecipeOrderLifecycleService.php', 94, 'reserveExplosion'],
    ['classes/Recipe/RecipeOrderLifecycleService.php', 144, 'releaseForUsageIds'],
    ['classes/Recipe/RecipeOrderLifecycleService.php', 292, 'consumeForOrderLine'],
    ['classes/Recipe/RecipeOrderLifecycleService.php', 647, 'consumeForOrderLine'],
    ['classes/Recipe/RecipeOrderLifecycleService.php', 774, 'recordRefundReversal'],
    ['recipe_production.php', 28, 'ProductionBatchMutationService'],
    ['classes/Recipe/ProductionBatchMutationService.php', 33, 'createDraft'],
    ['classes/Recipe/ProductionBatchMutationService.php', 48, 'commit'],
    ['classes/Recipe/ProductionBatchMutationService.php', 57, 'cancel'],
    ['classes/Recipe/ProductionBatchService.php', 121, 'transactionRetry'],
    ['classes/Recipe/ProductionBatchService.php', 149, 'recordProductionInput'],
    ['classes/Recipe/ProductionBatchService.php', 153, 'createBatchLine'],
    ['classes/Recipe/ProductionBatchService.php', 168, 'recordProductionOutput'],
    ['classes/Recipe/ProductionBatchService.php', 175, 'createBatchLine'],
    ['classes/Recipe/ProductionBatchService.php', 200, 'postProductionBatch'],
    ['classes/Recipe/ProductionBatchService.php', 206, 'refreshAvailabilityForProduction'],
    ['classes/Recipe/ProductionBatchService.php', 207, 'updateCommitted'],
    ['recipe_manage.php', 28, 'RecipeEditorMutationService'],
    ['classes/Recipe/RecipeDefinitionService.php', 49, 'createHeader'],
    ['classes/Recipe/RecipeDefinitionService.php', 86, 'createLine'],
    ['classes/Recipe/RecipeDefinitionService.php', 118, 'removeLine'],
    ['classes/Recipe/RecipeDefinitionService.php', 163, 'updateStatus'],
    ['classes/Recipe/Repository/RecipeRepository.php', 37, 'insertRow($conn, \'recipe_headers\''],
    ['classes/Recipe/Repository/RecipeLineRepository.php', 36, 'insertRow($conn, \'recipe_lines\''],
    ['classes/Recipe/Repository/RecipeLineRepository.php', 98, 'UPDATE recipe_lines'],
    ['classes/Recipe/Repository/RecipeLineRepository.php', 109, 'DELETE FROM recipe_lines'],
    ['classes/Recipe/Repository/RecipeVariantLineRepository.php', 62, 'DELETE FROM recipe_variant_lines'],
    ['classes/Recipe/Repository/RecipeVariantLineRepository.php', 95, 'insertRow($conn, \'recipe_variant_lines\''],
    ['classes/Recipe/RecipeCostService.php', 75, 'createSnapshot'],
    ['classes/Recipe/Repository/RecipeCostSnapshotRepository.php', 26, 'insertRow($conn, \'recipe_cost_snapshots\''],
    ['classes/Recipe/RecipeOrderLifecycleService.php', 452, 'createUsage'],
    ['classes/Recipe/RecipeOrderLifecycleService.php', 500, 'refreshForOrderLine'],
    ['classes/Recipe/Repository/RecipeOrderLineUsageRepository.php', 47, 'insertRow($conn, \'recipe_order_line_usage\''],
    ['classes/Recipe/Repository/RecipeOrderLineUsageRepository.php', 109, 'UPDATE recipe_order_line_usage'],
    ['classes/Recipe/Repository/RecipeOrderLineUsageRepository.php', 122, 'UPDATE recipe_order_line_usage'],
    ['classes/Recipe/Repository/RecipeAvailabilityCacheRepository.php', 36, 'INSERT INTO recipe_availability_cache'],
    ['classes/Recipe/RecipeAvailabilityService.php', 303, 'putAvailability'],
    ['classes/Recipe/RecipePilotFixtureService.php', 850, 'putAvailability'],
    ['classes/Recipe/Repository/ProductionBatchRepository.php', 36, 'insertRow($conn, \'production_batches\''],
    ['classes/Recipe/Repository/ProductionBatchRepository.php', 57, 'insertRow($conn, \'production_batch_lines\''],
    ['classes/Recipe/Repository/ProductionBatchRepository.php', 92, 'UPDATE production_batches'],
    ['classes/Recipe/Repository/ProductionBatchRepository.php', 113, 'UPDATE production_batches'],
    ['classes/Pos/Service/ItemVariantService.php', 532, 'DELETE FROM item_units'],
    ['classes/Pos/Service/ItemVariantService.php', 539, 'INSERT INTO item_units'],
    ['do/doadd_production.php', 9, 'INSERT INTO productions'],
    ['do/doedit_production.php', 10, 'DELETE FROM productions'],
    ['do/doedit_production.php', 12, 'INSERT INTO productions'],
    ['do/dodel_production.php', 4, 'DELETE FROM `productions`'],
    ['classes/Recipe/RecipeInventoryMovementService.php', 647, 'recordMovement'],
    ['classes/Recipe/RecipeInventoryMovementService.php', 700, 'recordMovement'],
    ['classes/Recipe/RecipeInventoryMovementService.php', 830, 'INSERT IGNORE INTO inventory_item_balances'],
    ['classes/Recipe/RecipeReservationService.php', 56, 'createReservation'],
    ['classes/Recipe/RecipeReservationService.php', 75, 'recordReservationMovement'],
    ['classes/Recipe/RecipeReservationService.php', 90, 'recordReservationRelease'],
    ['classes/Recipe/RecipeReservationService.php', 132, 'updateStatus'],
    ['classes/Recipe/RecipeReservationService.php', 154, 'recordReservationRelease'],
    ['classes/Recipe/RecipeReservationService.php', 156, 'updateStatus'],
    ['classes/Recipe/Repository/StockReservationRepository.php', 32, 'insertRow($conn, \'stock_reservations\''],
    ['classes/Recipe/Repository/StockReservationRepository.php', 132, 'UPDATE stock_reservations SET status'],
    ['classes/Recipe/Repository/StockReservationRepository.php', 137, 'UPDATE stock_reservations SET status'],
    ['tools/recipe_pilot_fixture.php', 43, '$service->run'],
    ['tools/recipe_fixture_stock_adjustment.php', 170, 'new InventoryAdjustmentService'],
    ['tools/recipe_fixture_stock_adjustment.php', 171, 'recordAdjustment'],
    ['tools/recipe_fixture_stock_adjustment.php', 172, 'recordAdjustment'],
    ['tools/recipe_migrated_write_smoke.php', 169, 'new PosOrderMutationService'],
    ['tools/recipe_migrated_write_smoke.php', 182, 'createTakeawayOrder'],
    ['tools/recipe_migrated_write_smoke.php', 183, 'createTakeawayOrder'],
    ['tools/recipe_cashier_browser_fixture.php', 25, 'posmain_cashier_browser_'],
    ['tools/recipe_cashier_browser_fixture.php', 46, 'CREATE DATABASE'],
    ['tools/recipe_cashier_browser_fixture.php', 156, 'UPDATE myitems SET group1'],
    ['tools/recipe_cashier_browser_fixture.php', 266, 'INSERT INTO fat_details'],
    ['tools/recipe_cashier_browser_fixture.php', 307, 'INSERT INTO fat_details'],
    ['tools/recipe_cashier_browser_fixture.php', 363, 'DROP DATABASE'],
    ['tools/recipe_expire_reservations.php', 58, 'expireReservations'],
    ['tools/phase6_load_concurrency_check.php', 122, 'INSERT INTO myitems'],
    ['tools/phase6_load_concurrency_check.php', 565, 'DELETE FROM myitems'],
    ['tools/seed_demo_restaurant.php', 138, "softReset('myitems'"],
    ['tools/seed_demo_restaurant.php', 300, "\$this->upsert('myitems'"],
    ['tools/seed_demo_restaurant.php', 308, "'itmqty' => 200"],
    ['tools/inventory_backfill_from_fat_details.php', 50, '$apply'],
    ['tools/inventory_backfill_from_fat_details.php', 71, 'applyFatDetailsBackfill'],
    ['tools/inventory_rebuild_balances.php', 43, '$apply'],
    ['tools/inventory_rebuild_balances.php', 61, 'applyBalanceRebuild'],
    ['tools/inventory_reconciliation_repair.php', 53, '$apply'],
    ['tools/inventory_reconciliation_repair.php', 69, 'applyMirrorRepair'],
    ['tools/inventory_retire_legacy_triggers.php', 85, 'DROP TRIGGER IF EXISTS update_after_update'],
    ['tools/inventory_retire_legacy_triggers.php', 86, 'DROP TRIGGER IF EXISTS update_balance_trigger'],
    ['tools/inventory_retire_legacy_triggers.php', 62, '$apply'],
    ['tools/inventory_retire_legacy_triggers.php', 63, 'inventoryRetireLegacyTriggersApply'],
    ['tools/inventory_retire_legacy_triggers.php', 205, '$apply'],
    ['classes/Inventory/InventoryHistoricalMigrationService.php', 196, 'recordMovement'],
    ['classes/Inventory/InventoryHistoricalMigrationService.php', 386, 'putBalance'],
    ['classes/Inventory/InventoryReconciliationRepairService.php', 92, 'refreshItemQtySummary'],
    ['ajax/inventory_purchase_receive.php', 33, 'returnItems'],
    ['ajax/inventory_purchase_receive.php', 36, 'receive'],
    ['ajax/inventory_transfer_send.php', 17, 'send'],
    ['ajax/inventory_transfer_receive.php', 17, 'receive'],
    ['ajax/inventory_count_close.php', 22, 'close'],
    ['ajax/inventory_count_reverse.php', 21, 'reverseClosed'],
    ['ajax/inventory_adjustment.php', 52, 'recordWaste'],
    ['ajax/inventory_adjustment.php', 55, 'recordAdjustment'],
] as $lineProof) {
    inventoryWritePathAssert(
        inventoryWritePathSourceLineContains($root, $lineProof[0], $lineProof[1], $lineProof[2]),
        'write path source-line proof drifted: ' . $lineProof[0] . ':' . $lineProof[1]
    );
}
inventoryWritePathAssert(
    strpos($docs['write_map'], 'not wired to runtime endpoints yet') === false,
    'write path map should not claim the shared ledger is unwired after workflow endpoints were added'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], 'purchase receiving, transfer, count, adjustment, reservation, production batch, and migration writes') !== false,
    'write path map should explain that new inventory services are part of the current sweep'
);
inventoryWritePathAssert(
    strpos($docs['write_map'], 'destructive new-inventory table operations') !== false
        && strpos($docs['write_map'], 'DELETE FROM inventory_movements') !== false
        && strpos($docs['write_map'], 'TRUNCATE inventory_*') !== false,
    'write path map should document destructive new-inventory table sweep patterns'
);
inventoryWritePathAssert(
    strpos($docs['invoice_types'], '| `PURCHASE` | 4 |') !== false
        && strpos($docs['invoice_types'], '| `POS` | 9 |') !== false
        && strpos($docs['invoice_types'], '| `OFFER` | 14 |') !== false,
    'invoice type map should document stock-relevant constants'
);
inventoryWritePathAssert(
    strpos($docs['schema'], 'tenant/branch/store') !== false
        || strpos($docs['schema'], 'pos_tenant') !== false,
    'schema findings should document scoped balance identity'
);
foreach ([
    'db/DB.sql:1162',
    'db/DB.sql:1683',
    'classes/Sync/SchemaManager.php:1500',
    'classes/Sync/SchemaManager.php:1549',
    'classes/Sync/SchemaManager.php:1600',
    'classes/Sync/SchemaManager.php:1624',
    'classes/Sync/SchemaManager.php:1676',
    'classes/Sync/SchemaManager.php:1738',
    'classes/Sync/SchemaManager.php:1802',
    'classes/Sync/SchemaManager.php:1855',
    'Quantity Read And Monitoring Surfaces',
    'get/get_iteminfo.php:9',
    'classes/InvoiceDetails.php:346',
    'inventory_stock_levels.php:72',
    'inventory_adjustments.php:56',
    'classes/Inventory/InventoryReportsService.php:46',
    'classes/Recipe/RecipeOperationalDashboardService.php:72',
    'classes/Recipe/RecipeAvailabilityService.php:347',
    'Inventory Workflow And Control Tables',
    'classes/Inventory/InventoryStockLevelService.php:42',
    'classes/Inventory/InventoryStockLevelService.php:59',
    'classes/Inventory/InventoryReasonCodeService.php:105',
    'classes/Inventory/InventoryPurchaseOrderService.php:191',
    'classes/Inventory/InventoryPurchaseReceivingService.php:282',
    'classes/Inventory/InventoryPurchaseReceivingService.php:434',
    'classes/Inventory/InventoryPurchaseReceivingService.php:462',
    'classes/Inventory/InventoryCountService.php:416',
    'classes/Inventory/InventoryTransferService.php:601',
] as $schemaProofNeedle) {
    inventoryWritePathAssert(
        strpos($docs['schema'], $schemaProofNeedle) !== false,
        'schema findings should include source-line proof: ' . $schemaProofNeedle
    );
}
foreach ([
    ['myitems.php', 97, 'stock_qty_display'],
    ['elements/main/main_tables.php', 190, 'decorateItems'],
    ['items_start_balance.php', 59, 'qty_on_hand'],
    ['items_start_balance.php', 158, 'qty_on_hand'],
    ['get/get_iteminfo.php', 9, 'SELECT * FROM myitems'],
    ['classes/InvoiceDetails.php', 346, 'data.itmqty'],
    ['item_summery.php', 243, 'SELECT'],
    ['item_summery.php', 251, '$total_in - $total_out'],
    ['inventory_stock_levels.php', 72, 'qty_on_hand'],
    ['inventory_stock_levels.php', 293, 'qty_available'],
    ['inventory_adjustments.php', 56, 'qty_on_hand'],
    ['inventory_adjustments.php', 458, 'qty_available'],
    ['classes/Inventory/InventoryReportsService.php', 46, 'qty_on_hand'],
    ['classes/Recipe/RecipeOperationalDashboardService.php', 72, 'stock_reservations'],
    ['classes/Recipe/RecipeOperationalDashboardService.php', 140, 'inventory_item_balances'],
    ['classes/Recipe/RecipeAvailabilityService.php', 347, 'qty_on_hand'],
    ['classes/Inventory/InventoryStockLevelService.php', 42, 'INSERT INTO inventory_item_stock_levels'],
    ['classes/Inventory/InventoryStockLevelService.php', 59, 'INSERT INTO inventory_item_stock_levels'],
    ['ajax/inventory_stock_level_save.php', 26, 'save($conn'],
    ['classes/Inventory/InventoryReasonCodeService.php', 79, 'UPDATE inventory_reason_codes'],
    ['classes/Inventory/InventoryReasonCodeService.php', 105, 'INSERT INTO inventory_reason_codes'],
    ['classes/Inventory/InventoryReasonCodeService.php', 135, 'UPDATE inventory_reason_codes'],
    ['ajax/inventory_reason_code.php', 34, 'save($conn'],
    ['classes/Inventory/InventoryPurchaseOrderService.php', 164, 'UPDATE inventory_purchase_orders SET status'],
    ['classes/Inventory/InventoryPurchaseOrderService.php', 191, 'INSERT INTO inventory_purchase_orders'],
    ['classes/Inventory/InventoryPurchaseOrderService.php', 214, 'INSERT INTO inventory_purchase_order_lines'],
    ['classes/Inventory/InventoryPurchaseReceivingService.php', 282, 'INSERT INTO inventory_purchase_receipts'],
    ['classes/Inventory/InventoryPurchaseReceivingService.php', 434, 'INSERT INTO inventory_purchase_receipt_lines'],
    ['classes/Inventory/InventoryPurchaseReceivingService.php', 462, 'UPDATE inventory_purchase_receipt_lines SET inventory_movement_id'],
    ['classes/Inventory/InventoryPurchaseReceivingService.php', 475, 'UPDATE inventory_purchase_order_lines SET received_qty'],
    ['classes/Inventory/InventoryCountService.php', 394, 'UPDATE inventory_counts SET status'],
    ['classes/Inventory/InventoryCountService.php', 416, 'INSERT INTO inventory_counts'],
    ['classes/Inventory/InventoryCountService.php', 460, 'INSERT INTO inventory_count_lines'],
    ['classes/Inventory/InventoryCountService.php', 512, 'UPDATE inventory_count_lines'],
    ['classes/Inventory/InventoryTransferService.php', 601, 'INSERT INTO inventory_transfers'],
    ['classes/Inventory/InventoryTransferService.php', 635, 'INSERT INTO inventory_transfer_lines'],
    ['classes/Inventory/InventoryTransferService.php', 650, 'UPDATE inventory_transfer_lines SET sent_qty'],
    ['classes/Inventory/InventoryTransferService.php', 658, 'UPDATE inventory_transfer_lines SET variance_qty'],
    ['classes/Sync/SchemaManager.php', 1500, 'CREATE TABLE IF NOT EXISTS recipe_order_line_usage'],
    ['classes/Sync/SchemaManager.php', 1549, 'CREATE TABLE IF NOT EXISTS inventory_movements'],
    ['classes/Sync/SchemaManager.php', 1600, 'CREATE TABLE IF NOT EXISTS inventory_item_balances'],
    ['classes/Sync/SchemaManager.php', 1624, 'CREATE TABLE IF NOT EXISTS inventory_item_stock_levels'],
    ['classes/Sync/SchemaManager.php', 1676, 'CREATE TABLE IF NOT EXISTS inventory_counts'],
    ['classes/Sync/SchemaManager.php', 1738, 'CREATE TABLE IF NOT EXISTS inventory_transfers'],
    ['classes/Sync/SchemaManager.php', 1802, 'CREATE TABLE IF NOT EXISTS inventory_purchase_orders'],
    ['classes/Sync/SchemaManager.php', 1855, 'CREATE TABLE IF NOT EXISTS inventory_purchase_receipts'],
] as $readLineProof) {
    inventoryWritePathAssert(
        inventoryWritePathSourceLineContains($root, $readLineProof[0], $readLineProof[1], $readLineProof[2]),
        'quantity read-path source-line proof drifted: ' . $readLineProof[0] . ':' . $readLineProof[1]
    );
}

echo "inventory-write-path-map-contract-ok\n";

function inventoryWritePathDoc(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryWritePathAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryWritePathSourceLineContains(string $root, string $relativePath, int $lineNumber, string $needle): bool
{
    $lines = file($root . '/' . $relativePath);
    if (!is_array($lines) || !isset($lines[$lineNumber - 1])) {
        return false;
    }

    return strpos($lines[$lineNumber - 1], $needle) !== false;
}

function inventoryWritePathUndocumentedInventoryToolWriters(string $root, string $writeMap): array
{
    $paths = glob($root . '/tools/inventory_*.php');
    if (!is_array($paths)) {
        return [];
    }

    $patterns = [
        '/applyFatDetailsBackfill\s*\(/',
        '/applyBalanceRebuild\s*\(/',
        '/applyMirrorRepair\s*\(/',
        '/inventoryRetireLegacyTriggersApply\s*\(/',
        '/\b(DROP\s+TRIGGER|INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE)\s+`?(inventory_|stock_reservations|fat_details|myitems|update_after_update|update_balance_trigger)/i',
    ];
    $missing = [];
    foreach ($paths as $path) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        $source = file_get_contents($path);
        if (!is_string($source)) {
            continue;
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                if (strpos($writeMap, '`' . $relative . '`') === false) {
                    $missing[] = $relative;
                }
                break;
            }
        }
    }

    sort($missing);
    return $missing;
}

function inventoryWritePathUndocumentedRecipeToolWriters(string $root, string $writeMap): array
{
    $paths = glob($root . '/tools/recipe_*.php');
    if (!is_array($paths)) {
        return [];
    }

    $patterns = [
        '/RecipePilotFixtureService[\s\S]{0,1200}->\s*run\s*\(/',
        '/InventoryAdjustmentService[\s\S]{0,1200}->\s*recordAdjustment\s*\(/',
        '/PosOrderMutationService[\s\S]{0,1600}->\s*createTakeawayOrder\s*\(/',
        '/\bINSERT\s+INTO\s+`?fat_details`?/i',
        '/\bUPDATE\s+`?myitems`?\s+SET\b/i',
        '/\b(DROP\s+DATABASE|TRUNCATE|DELETE\s+FROM)\s+`?(inventory_|stock_reservations|fat_details|myitems|posmain_cashier_browser_)/i',
    ];
    $missing = [];
    foreach ($paths as $path) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        $source = file_get_contents($path);
        if (!is_string($source)) {
            continue;
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                if (strpos($writeMap, '`' . $relative . '`') === false) {
                    $missing[] = $relative;
                }
                break;
            }
        }
    }

    sort($missing);
    return $missing;
}

function inventoryWritePathUndocumentedOtherToolWriters(string $root, string $writeMap): array
{
    $paths = glob($root . '/tools/*.php');
    if (!is_array($paths)) {
        return [];
    }

    $patterns = [
        '/\bINSERT\s+INTO\s+`?myitems`?[\s\S]{0,500}\bitmqty\b/i',
        '/\bDELETE\s+FROM\s+`?myitems`?/i',
        '/softReset\s*\(\s*[\'"]myitems[\'"]/i',
        '/upsert\s*\(\s*[\'"]myitems[\'"][\s\S]{0,800}[\'"]itmqty[\'"]\s*=>/i',
        '/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE)\s+`?(inventory_|stock_reservations|fat_details)/i',
        '/\bDROP\s+(TRIGGER|DATABASE)\b/i',
    ];
    $missing = [];
    foreach ($paths as $path) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        if (strpos($relative, 'tools/inventory_') === 0 || strpos($relative, 'tools/recipe_') === 0) {
            continue;
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            continue;
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                if (strpos($writeMap, '`' . $relative . '`') === false) {
                    $missing[] = $relative;
                }
                break;
            }
        }
    }

    sort($missing);
    return $missing;
}

function inventoryWritePathUndocumentedRuntimeWriters(string $root, string $writeMap): array
{
    $paths = inventoryWritePathPhpFiles($root);
    $patterns = [
        '/\bINSERT\s+INTO\s+`?fat_details`?/i',
        '/\bUPDATE\s+`?fat_details`?/i',
        '/\bDELETE\s+FROM\s+`?fat_details`?/i',
        '/\bUPDATE\s+`?myitems`?\s+SET\b/i',
        '/\bINSERT\s+INTO\s+`?myitems`?[\s\S]{0,500}\b(cost_price|price1|sprice|barcode)\b/i',
        '/\bINSERT\s+INTO\s+`?item_units`?/i',
        '/\bUPDATE\s+`?item_units`?/i',
        '/\bDELETE\s+FROM\s+`?item_units`?/i',
        '/\bINSERT\s+INTO\s+`?recipe_headers`?/i',
        '/\bUPDATE\s+`?recipe_headers`?/i',
        '/\bINSERT\s+INTO\s+`?recipe_lines`?/i',
        '/\bUPDATE\s+`?recipe_lines`?/i',
        '/\bDELETE\s+FROM\s+`?recipe_lines`?/i',
        '/\bDELETE\s+FROM\s+`?recipe_variant_lines`?/i',
        '/\bINSERT\s+INTO\s+`?recipe_cost_snapshots`?/i',
        '/\bINSERT\s+INTO\s+`?recipe_availability_cache`?/i',
        '/\bUPDATE\s+`?recipe_order_line_usage`?/i',
        '/\bINSERT\s+INTO\s+`?inventory_movements`?/i',
        '/\bUPDATE\s+`?inventory_movements`?/i',
        '/\bDELETE\s+FROM\s+`?inventory_movements`?/i',
        '/\bINSERT\s+INTO\s+`?inventory_item_balances`?/i',
        '/\bINSERT\s+IGNORE\s+INTO\s+`?inventory_item_balances`?/i',
        '/\bUPDATE\s+`?inventory_item_balances`?/i',
        '/\bDELETE\s+FROM\s+`?inventory_item_balances`?/i',
        '/\bINSERT\s+INTO\s+`?stock_reservations`?/i',
        '/\bUPDATE\s+`?stock_reservations`?/i',
        '/\bDELETE\s+FROM\s+`?stock_reservations`?/i',
        '/\bDELETE\s+FROM\s+\$table\b/i',
        '/\bDELETE\s+FROM\s+`?inventory_(counts|count_lines|transfers|transfer_lines|purchase_orders|purchase_order_lines|purchase_receipts|purchase_receipt_lines|reason_codes|item_stock_levels)`?/i',
        '/\bTRUNCATE\s+`?(inventory_|stock_reservations)/i',
        '/\bDROP\s+TABLE\s+`?(inventory_|stock_reservations)/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]inventory_movements[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]recipe_headers[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]recipe_lines[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]recipe_variant_lines[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]recipe_cost_snapshots[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]recipe_order_line_usage[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]stock_reservations[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]production_batches[\'"]/i',
        '/insertRow\s*\(\s*\$conn\s*,\s*[\'"]production_batch_lines[\'"]/i',
        '/->\s*createMovement\s*\(/i',
        '/->\s*putBalance\s*\(/i',
        '/->\s*putAvailability\s*\(/i',
    ];
    $missing = [];

    foreach ($paths as $path) {
        $source = file_get_contents($root . '/' . $path);
        if (!is_string($source)) {
            continue;
        }
        $matches = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $matches = true;
                break;
            }
        }
        if ($matches && strpos($writeMap, '`' . $path . '`') === false) {
            $missing[] = $path;
        }
    }

    sort($missing);
    return $missing;
}

function inventoryWritePathUndocumentedServiceDelegates(string $root, string $writeMap): array
{
    $paths = inventoryWritePathPhpFiles($root);
    $patterns = [
        '/->\s*(recordInvoiceLines|recordInvoiceReversalLines|recordCurrentOrderPaid|recordCurrentOrderDeleted|recordCurrentOrderVoided|recordCurrentOrderRefunded|recordExistingLinesCancelled|recordExternalOrderPaid|recordWaste|recordAdjustment|recordShadowMovement|recordRefundReversal)\s*\(/',
        '/->\s*(recordProductionInput|recordProductionOutput|postProductionBatch)\s*\(/',
        '/InventoryPurchaseReceivingService[\s\S]{0,400}->\s*(receive|returnItems)\s*\(/',
        '/InventoryTransferService[\s\S]{0,400}->\s*(send|receive)\s*\(/',
        '/InventoryCountService[\s\S]{0,400}->\s*(close|reverseClosed)\s*\(/',
        '/InventoryAdjustmentService[\s\S]{0,400}->\s*(recordWaste|recordAdjustment)\s*\(/',
    ];
    $missing = [];

    foreach ($paths as $path) {
        $source = file_get_contents($root . '/' . $path);
        if (!is_string($source)) {
            continue;
        }
        $matches = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $matches = true;
                break;
            }
        }
        if ($matches && strpos($writeMap, '`' . $path . '`') === false) {
            $missing[] = $path;
        }
    }

    sort($missing);
    return $missing;
}

function inventoryWritePathPhpFiles(string $root): array
{
    $excludedDirs = [
        '.git',
        'vendor',
        'node_modules',
        'tests',
        'tools',
        'docs',
    ];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($root, $excludedDirs): bool {
                $relative = substr($current->getPathname(), strlen($root) + 1);
                $parts = explode(DIRECTORY_SEPARATOR, $relative);
                if ($current->isDir() && in_array($current->getFilename(), $excludedDirs, true)) {
                    return false;
                }
                foreach ($parts as $part) {
                    if (in_array($part, $excludedDirs, true)) {
                        return false;
                    }
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    }

    sort($files);
    return $files;
}
