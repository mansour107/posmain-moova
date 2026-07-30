<?php

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Sync/SchemaManager.php';

$manager = new SyncSchemaManager();
$planned = $manager->plannedStatements();

$workflowTables = [
    'inventory_item_stock_levels',
    'inventory_reason_codes',
    'inventory_counts',
    'inventory_count_lines',
    'inventory_transfers',
    'inventory_transfer_lines',
    'inventory_purchase_orders',
    'inventory_purchase_order_lines',
    'inventory_purchase_receipts',
    'inventory_purchase_receipt_lines',
];

foreach ($workflowTables as $table) {
    inventoryPhase2Assert(array_key_exists($table, $planned), 'Phase 2 should plan workflow table: ' . $table);
    inventoryPhase2Assert(
        strpos($planned[$table], 'CREATE TABLE IF NOT EXISTS ' . $table) !== false,
        'Phase 2 table should be additive CREATE TABLE IF NOT EXISTS: ' . $table
    );
    inventoryPhase2Assert(
        preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $planned[$table]) !== 1,
        'Phase 2 table statement should not be destructive: ' . $table
    );
}

inventoryPhase2Assert(!array_key_exists('inventory_store_settings', $planned), 'inventory_store_settings should not be added until a workflow proves it is needed');

foreach ([
    'inventory_movements',
    'inventory_item_balances',
    'stock_reservations',
    'recipe_order_line_usage',
    'production_batches',
    'recipe_availability_cache',
] as $existingTable) {
    inventoryPhase2Assert(array_key_exists($existingTable, $planned), 'existing ledger/recipe table should remain planned: ' . $existingTable);
}

inventoryPhase2Assert(
    strpos($planned['inventory_movements'], 'UNIQUE KEY uq_inventory_idempotency (pos_tenant, pos_branch, store_id, idempotency_key)') !== false,
    'inventory movement store-scoped idempotency key should be preserved'
);
inventoryPhase2Assert(
    strpos($planned['inventory_movements'], 'KEY idx_inventory_item_time (pos_tenant, pos_branch, store_id, item_id, created_at)') !== false,
    'inventory movements should keep item/time index'
);
inventoryPhase2Assert(
    strpos($planned['inventory_movements'], 'KEY idx_inventory_source (source_type, source_id)') !== false,
    'inventory movements should keep source index'
);
inventoryPhase2Assert(
    strpos($planned['inventory_movements'], 'UNIQUE KEY uq_inventory_movement_uuid (movement_uuid)') !== false,
    'inventory movements should keep movement uuid uniqueness'
);
inventoryPhase2Assert(
    strpos($planned['inventory_movements'], 'KEY idx_inventory_movement_type_time (movement_type, created_at)') !== false,
    'inventory movements should expose movement type/time index'
);
inventoryPhase2Assert(
    strpos($planned['inventory_movements'], "'purchase_return'") !== false,
    'inventory movements should include dedicated purchase_return movement type'
);
inventoryPhase2Assert(
    strpos($planned['inventory_item_balances'], 'UNIQUE KEY uq_inventory_balance_item (pos_tenant, pos_branch, store_id, item_id)') !== false,
    'inventory balances should keep scoped unique balance key'
);
inventoryPhase2Assert(
    strpos($planned['stock_reservations'], 'UNIQUE KEY uq_stock_reservation_idem (pos_tenant, pos_branch, store_id, idempotency_key)') !== false,
    'stock reservations should keep scoped idempotency key'
);
inventoryPhase2Assert(
    strpos($planned['stock_reservations'], 'KEY idx_stock_reservation_expiry (status, expires_at)') !== false,
    'stock reservations should keep expiry index'
);
inventoryPhase2Assert(
    strpos($planned['stock_reservations'], 'KEY idx_stock_reservation_order_line (order_id, order_line_uuid)') !== false,
    'stock reservations should expose order/order-line index'
);
inventoryPhase2Assert(
    strpos($planned['inventory_item_stock_levels'], 'preferred_count_unit_id BIGINT UNSIGNED NULL') !== false
        && strpos($planned['inventory_item_stock_levels'], 'preferred_purchase_unit_id BIGINT UNSIGNED NULL') !== false
        && strpos($planned['inventory_item_stock_levels'], 'default_supplier_account_id BIGINT UNSIGNED NULL') !== false,
    'stock levels should carry optional preferred count, purchase unit, and default supplier preferences'
);

inventoryPhase2Assert(
    strpos($planned['inventory_counts'], "status ENUM('draft','submitted','approved','rejected','closed','cancelled')") !== false,
    'inventory counts should model review/close workflow states'
);
inventoryPhase2Assert(
    strpos($planned['inventory_count_lines'], 'snapshot_qty DECIMAL(18,6)') !== false
        && strpos($planned['inventory_count_lines'], 'unit_conversion_to_base DECIMAL(18,8)') !== false
        && strpos($planned['inventory_count_lines'], 'snapshot_last_movement_id BIGINT UNSIGNED NULL') !== false
        && strpos($planned['inventory_count_lines'], 'stale_count_conflict TINYINT(1)') !== false,
    'inventory count lines should store snapshot, frozen unit conversion, and stale-conflict safety fields'
);
inventoryPhase2Assert(
    strpos($planned['inventory_transfers'], "status ENUM('draft','submitted','sent','partially_received','received','closed','cancelled','returned','variance_closed')") !== false,
    'inventory transfers should model send/receive/variance lifecycle states'
);
inventoryPhase2Assert(
    strpos($planned['inventory_purchase_orders'], "status ENUM('draft','submitted','approved','rejected','partially_received','received','closed','cancelled')") !== false,
    'purchase orders should model approval and partial receiving states'
);
inventoryPhase2Assert(
    strpos($planned['inventory_purchase_receipts'], 'legacy_ot_head_id BIGINT UNSIGNED NULL') !== false,
    'purchase receipts should allow legacy invoice linkage without replacing old invoices'
);

$doc = inventoryPhase2Source($root . '/docs/inventory/phase2_schema_contracts.md');
foreach ([
    'does not wire the new tables',
    'inventory_store_settings` is not added',
    'changing that uniqueness shape is deferred',
    'table creation and index creation only',
] as $needle) {
    inventoryPhase2Assert(strpos($doc, $needle) !== false, 'Phase 2 doc should record safety decision: ' . $needle);
}

$runtimeReferences = inventoryPhase2RuntimeReferences($root, $workflowTables);
$runtimeReferences = array_values(array_filter($runtimeReferences, 'inventoryPhase2UnexpectedRuntimeReference'));
inventoryPhase2Assert(
    $runtimeReferences === [],
    'Phase 2 workflow tables should only be used by approved later-phase runtime code: ' . implode(', ', $runtimeReferences)
);

$phase2DbApplied = inventoryPhase2ApplyOnTemporaryDatabase($manager, $workflowTables);
if (!$phase2DbApplied) {
    echo "inventory-phase2-schema-contract-skipped-db-unavailable\n";
}

echo "inventory-phase2-schema-contract-ok\n";

function inventoryPhase2ApplyOnTemporaryDatabase(SyncSchemaManager $manager, array $workflowTables): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
    $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
    $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
    $conn = @new mysqli($host, $user, $pass, '', $port);
    if ($conn->connect_error) {
        return false;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $dbName = 'posmain_inventory_phase2_' . getmypid();
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $conn->select_db($dbName);
    $conn->set_charset('utf8mb4');

    try {
        $first = $manager->apply($conn);
        inventoryPhase2Assert($first !== [], 'clean DB migration apply should create planned schema');
        inventoryPhase2Assert($manager->pendingStatements($conn) === [], 'clean DB migration should be idempotent after first apply');
        inventoryPhase2Assert($manager->apply($conn) === [], 'second migration apply should be idempotent');

        $inspect = $manager->inspect($conn);
        foreach ($workflowTables as $table) {
            inventoryPhase2Assert(!empty($inspect[$table]['exists']), 'workflow table should exist after migration apply: ' . $table);
        }

        inventoryPhase2Assert(
            in_array('uq_inventory_count_uuid', $inspect['inventory_counts']['indexes'], true),
            'inventory_counts uuid index should exist after apply'
        );
        inventoryPhase2Assert(
            in_array('stale_count_conflict', $inspect['inventory_count_lines']['columns'], true),
            'inventory_count_lines stale conflict column should exist after apply'
        );
        inventoryPhase2Assert(
            in_array('preferred_count_unit_id', $inspect['inventory_item_stock_levels']['columns'], true)
                && in_array('preferred_purchase_unit_id', $inspect['inventory_item_stock_levels']['columns'], true)
                && in_array('default_supplier_account_id', $inspect['inventory_item_stock_levels']['columns'], true),
            'inventory_item_stock_levels preferred policy columns should exist after apply'
        );
        inventoryPhase2Assert(
            in_array('unit_conversion_to_base', $inspect['inventory_count_lines']['columns'], true),
            'inventory_count_lines frozen unit conversion column should exist after apply'
        );
        inventoryPhase2Assert(
            in_array('idx_inventory_movement_type_time', $inspect['inventory_movements']['indexes'], true),
            'inventory_movements movement type/time index should exist after apply'
        );
        $movementType = inventoryPhase2One($conn, "
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inventory_movements'
              AND COLUMN_NAME = 'movement_type'
            LIMIT 1
        ");
        inventoryPhase2Assert(
            strpos((string) $movementType['COLUMN_TYPE'], "'purchase_return'") !== false,
            'inventory_movements movement_type enum should include purchase_return after apply'
        );
        inventoryPhase2Assert(
            in_array('idx_stock_reservation_order_line', $inspect['stock_reservations']['indexes'], true),
            'stock_reservations order line index should exist after apply'
        );
    } finally {
        $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
        $conn->close();
    }

    return true;
}

function inventoryPhase2RuntimeReferences(string $root, array $needles): array
{
    $matches = [];
    foreach (inventoryPhase2PhpFiles($root) as $relative) {
        $source = inventoryPhase2Source($root . '/' . $relative);
        foreach ($needles as $needle) {
            if (strpos($source, $needle) !== false) {
                $matches[] = $relative . ':' . $needle;
            }
        }
    }

    sort($matches);
    return $matches;
}

function inventoryPhase2One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase2Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase2UnexpectedRuntimeReference(string $reference): bool
{
    $allowedLaterPhaseReferences = [
        'classes/Inventory/InventoryPurchaseOrderService.php' => [
            'inventory_purchase_orders',
            'inventory_purchase_order_lines',
        ],
        'classes/Inventory/InventoryCountService.php' => [
            'inventory_counts',
            'inventory_count_lines',
            'inventory_item_stock_levels',
        ],
        'classes/Inventory/InventoryTransferService.php' => [
            'inventory_transfers',
            'inventory_transfer_lines',
            'inventory_reason_codes',
        ],
        'classes/Inventory/InventoryPurchaseReceivingService.php' => [
            'inventory_purchase_orders',
            'inventory_purchase_order_lines',
            'inventory_purchase_receipts',
            'inventory_purchase_receipt_lines',
        ],
        'classes/Inventory/InventoryReportsService.php' => [
            'inventory_item_stock_levels',
            'inventory_counts',
            'inventory_count_lines',
            'inventory_transfers',
            'inventory_transfer_lines',
            'inventory_purchase_orders',
            'inventory_purchase_receipts',
            'inventory_purchase_receipt_lines',
        ],
        'classes/Inventory/InventoryStockLevelService.php' => [
            'inventory_item_stock_levels',
        ],
        'classes/Inventory/InventoryQuickItemCreateService.php' => [
            'inventory_item_stock_levels',
        ],
        'classes/Inventory/InventoryReasonCodeService.php' => [
            'inventory_reason_codes',
        ],
        'classes/Items/ItemInventoryUnitSync.php' => [
            'inventory_item_stock_levels',
        ],
        'config/rbac_page_manifest.php' => [
            'inventory_counts',
            'inventory_reason_codes',
            'inventory_transfers',
        ],
        'ajax/inventory_stock_level_save.php' => [
            'inventory_item_stock_levels',
        ],
        'ajax/inventory_stock_level_bulk.php' => [
            'inventory_item_stock_levels',
        ],
        'ajax/inventory_reason_code.php' => [
            'inventory_reason_codes',
        ],
        'inventory_count_detail.php' => [
            'inventory_counts',
            'inventory_count_lines',
        ],
        'inventory_counts.php' => [
            'inventory_counts',
            'inventory_count_lines',
        ],
        'includes/sidebar.php' => [
            'inventory_counts',
            'inventory_transfers',
            'inventory_reason_codes',
        ],
        'inventory_transfer_detail.php' => [
            'inventory_transfers',
            'inventory_transfer_lines',
            'inventory_reason_codes',
        ],
        'inventory_transfers.php' => [
            'inventory_transfers',
            'inventory_transfer_lines',
            'inventory_item_stock_levels',
        ],
        'inventory_purchasing.php' => [
            'inventory_purchase_orders',
            'inventory_purchase_order_lines',
            'inventory_purchase_receipts',
            'inventory_purchase_receipt_lines',
            'inventory_item_stock_levels',
        ],
        'inventory_stock_levels.php' => [
            'inventory_item_stock_levels',
        ],
        'inventory_adjustments.php' => [
            'inventory_item_stock_levels',
        ],
        'inventory_reason_codes.php' => [
            'inventory_reason_codes',
        ],
        'inventory_reports.php' => [
            'inventory_counts',
            'inventory_transfers',
            'inventory_purchase_receipts',
        ],
        'inventory_dashboard.php' => [
            'inventory_counts',
        ],
        'includes/pos_operational_store.php' => [
            'inventory_transfers',
        ],
    ];

    foreach ($allowedLaterPhaseReferences as $file => $tables) {
        foreach ($tables as $table) {
            if ($reference === $file . ':' . $table) {
                return false;
            }
        }
    }

    return true;
}

function inventoryPhase2PhpFiles(string $root): array
{
    $excludedDirs = [
        '.git',
        'vendor',
        'node_modules',
        'tests',
        'tools',
        'docs',
        'dbase',
        'var/release',
        'classes/Sync',
    ];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($root, $excludedDirs): bool {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($current->getPathname(), strlen($root) + 1));
                foreach ($excludedDirs as $excludedDir) {
                    if ($relative === $excludedDir || strpos($relative, $excludedDir . '/') === 0) {
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

function inventoryPhase2Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase2Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
