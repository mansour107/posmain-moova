<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_schema_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4CreateLegacyTables($conn);

    $manager = new SyncSchemaManager();
    $planned = $manager->plannedStatements();
    $plannedSql = implode("\n", $planned);

    $requiredTables = [
        'item_availability',
        'item_variants',
        'modifier_groups',
        'modifier_options',
        'item_modifier_groups',
        'order_line_modifiers',
        'order_line_notes',
        'table_areas',
        'payment_methods',
        'manager_approvals',
        'drawer_sessions',
        'drawer_movements',
        'printers',
        'print_jobs',
        'item_nutrition_profiles',
    ];

    foreach ($requiredTables as $table) {
        phase4Assert(isset($planned[$table]), "{$table} planned statement missing");
        phase4Assert(strpos($planned[$table], "CREATE TABLE IF NOT EXISTS {$table}") !== false, "{$table} create statement missing");
    }

    phase4Assert(strpos($plannedSql, 'UNIQUE KEY uq_item_branch_channel') !== false, 'item availability uniqueness missing');
    phase4Assert(strpos($plannedSql, 'UNIQUE KEY uq_item_variant_parent_child') !== false, 'item variant parent-child uniqueness missing');
    phase4Assert(strpos($plannedSql, 'price_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000') !== false, 'modifier price delta missing');
    phase4Assert(strpos($plannedSql, "note_type ENUM('kitchen','cashier','customer')") !== false, 'line note type enum missing');
    phase4Assert(strpos($plannedSql, 'UNIQUE KEY uq_payment_methods_code (code)') !== false, 'payment method code uniqueness missing');
    phase4Assert(strpos($plannedSql, "status ENUM('open','closed','forced_closed')") !== false, 'drawer session status enum missing');
    phase4Assert(strpos($plannedSql, "job_type ENUM('receipt','kot','kitchen','z_report','x_report')") !== false, 'print job type enum missing');
    phase4Assert(strpos($plannedSql, 'UNIQUE KEY uq_item_nutrition (item_id)') !== false, 'nutrition item uniqueness missing');

    $legacyTargets = $manager->phase4LegacyTargets();
    foreach (['tables', 'ot_head', 'myitems', 'fat_details'] as $table) {
        phase4Assert(isset($legacyTargets[$table]), "{$table} legacy target missing");
    }

    $pendingBefore = $manager->pendingStatements($conn);
    phase4Assert(isset($pendingBefore['tables.add_area_id']), 'tables area_id migration missing');
    phase4Assert(isset($pendingBefore['tables.add_idx_tables_area_order']), 'tables area index migration missing');
    phase4Assert(isset($pendingBefore['ot_head.add_cofe_idempotency_key']), 'ot_head Cofe idempotency column migration missing');
    phase4Assert(isset($pendingBefore['ot_head.add_uq_ot_head_cofe_idempotency']), 'ot_head Cofe idempotency unique index migration missing');
    phase4Assert(isset($pendingBefore['ot_head.add_guest_count']), 'ot_head guest_count migration missing');
    phase4Assert(isset($pendingBefore['ot_head.add_waiter_id']), 'ot_head waiter_id migration missing');
    phase4Assert(isset($pendingBefore['ot_head.add_idx_ot_head_waiter']), 'ot_head waiter index migration missing');
    phase4Assert(isset($pendingBefore['myitems.add_item_type']), 'myitems item_type migration missing');
    phase4Assert(isset($pendingBefore['myitems.add_track_stock']), 'myitems track_stock migration missing');
    phase4Assert(isset($pendingBefore['myitems.add_preferred_unit_id']), 'myitems preferred_unit_id migration missing');
    phase4Assert(isset($pendingBefore['myitems.add_is_active']), 'myitems is_active migration missing');
    phase4Assert(isset($pendingBefore['fat_details.add_idx_fat_details_stock_item']), 'fat_details stock index migration missing');
    phase4AssertNoDestructiveStatements($pendingBefore);

    $manager->apply($conn);
    $inspect = $manager->inspect($conn);

    foreach ($requiredTables as $table) {
        phase4Assert(!empty($inspect[$table]['exists']), "{$table} table not created");
    }

    phase4Assert(in_array('uq_item_branch_channel', $inspect['item_availability']['indexes'], true), 'item availability unique index not found');
    phase4Assert(in_array('uq_item_variant_parent_child', $inspect['item_variants']['indexes'], true), 'item variant parent-child unique index not found');
    phase4Assert(in_array('idx_modifier_options_group', $inspect['modifier_options']['indexes'], true), 'modifier options group index not found');
    phase4Assert(in_array('uq_item_group', $inspect['item_modifier_groups']['indexes'], true), 'item modifier group unique index not found');
    phase4Assert(in_array('idx_order_line_notes_order', $inspect['order_line_notes']['indexes'], true), 'line notes order index not found');
    phase4Assert(in_array('uq_payment_methods_code', $inspect['payment_methods']['indexes'], true), 'payment method unique index not found');
    phase4Assert(in_array('uq_drawer_sessions_uuid', $inspect['drawer_sessions']['indexes'], true), 'drawer session uuid index not found');
    phase4Assert(in_array('idx_print_jobs_status', $inspect['print_jobs']['indexes'], true), 'print job status index not found');
    phase4Assert(in_array('uq_item_nutrition', $inspect['item_nutrition_profiles']['indexes'], true), 'nutrition unique index not found');

    phase4AssertColumns($conn, 'tables', ['area_id', 'capacity', 'pos_x', 'pos_y', 'shape', 'display_order']);
    phase4AssertColumns($conn, 'ot_head', ['cofe_idempotency_key', 'guest_count', 'waiter_id']);
    phase4AssertColumns($conn, 'myitems', ['item_type', 'track_stock', 'preferred_unit_id', 'is_active']);
    phase4AssertIndexes($conn, 'tables', ['idx_tables_area_order']);
    phase4AssertIndexes($conn, 'ot_head', ['uq_ot_head_cofe_idempotency', 'idx_ot_head_waiter']);
    phase4AssertIndexes($conn, 'myitems', ['idx_myitems_type_stock', 'idx_myitems_barcode_deleted', 'idx_myitems_group_deleted', 'idx_myitems_active_deleted']);
    phase4AssertIndexes($conn, 'fat_details', ['idx_fat_details_stock_item', 'idx_fat_details_fatid_deleted']);
    phase4Assert($manager->pendingStatements($conn) === [], 'Phase 4 schema apply should be idempotent');

    echo "phase4-midscale-schema-migration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4CreateLegacyTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE tables (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            table_case INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            table_id BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            iname VARCHAR(255) NULL,
            barcode VARCHAR(191) NULL,
            group1 BIGINT UNSIGNED NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fatid BIGINT UNSIGNED NULL,
            item_id BIGINT UNSIGNED NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
            qty_out DECIMAL(15,4) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function phase4AssertColumns(mysqli $conn, string $table, array $expected): void
{
    $columns = phase4ColumnsFor($conn, $table);
    foreach ($expected as $column) {
        phase4Assert(in_array($column, $columns, true), "{$table}.{$column} missing");
    }
}

function phase4AssertIndexes(mysqli $conn, string $table, array $expected): void
{
    $indexes = phase4IndexesFor($conn, $table);
    foreach ($expected as $index) {
        phase4Assert(in_array($index, $indexes, true), "{$table}.{$index} missing");
    }
}

function phase4ColumnsFor(mysqli $conn, string $table): array
{
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['COLUMN_NAME'];
    }
    $stmt->close();

    return $columns;
}

function phase4IndexesFor(mysqli $conn, string $table): array
{
    $stmt = $conn->prepare("
        SELECT DISTINCT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        ORDER BY INDEX_NAME
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = $row['INDEX_NAME'];
    }
    $stmt->close();

    return $indexes;
}

function phase4AssertNoDestructiveStatements(array $statements): void
{
    foreach ($statements as $label => $sql) {
        phase4Assert(
            preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', (string) $sql) !== 1,
            "destructive statement found in {$label}"
        );
    }
}

function phase4Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
