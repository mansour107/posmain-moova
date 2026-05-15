<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/PasswordService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase6_demo_seed_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase6SeedCreateSchema($conn);

    $dryRun = phase6SeedRunTool($db, ['--json', '--dry-run']);
    phase6SeedAssert($dryRun['code'] === 0, 'dry run should exit successfully: ' . $dryRun['output']);
    $dryJson = phase6SeedJson($dryRun['output']);
    phase6SeedAssert($dryJson['dry_run'] === true, 'dry run JSON flag expected');
    phase6SeedAssert((int)$dryJson['counts']['categories'] === 3, 'dry run should plan 3 categories');
    phase6SeedAssert((int)$dryJson['counts']['items'] === 54, 'dry run should plan 54 items');
    phase6SeedAssert((int)$dryJson['counts']['modifier_options'] === 10, 'dry run should plan 10 modifier options');
    phase6SeedAssert((int)$dryJson['counts']['tables'] === 20, 'dry run should plan 20 tables');
    phase6SeedAssert(phase6SeedCount($conn, "SELECT COUNT(*) FROM myitems WHERE barcode LIKE 'P6DEMO-%'") === 0, 'dry run should not insert items');

    $apply = phase6SeedRunTool($db, ['--json', '--apply', '--with-moova-dummy']);
    phase6SeedAssert($apply['code'] === 0, 'apply should exit successfully: ' . $apply['output']);
    $applyJson = phase6SeedJson($apply['output']);
    phase6SeedAssert($applyJson['dry_run'] === false, 'apply JSON flag expected');
    phase6SeedAssert((int)$applyJson['counts']['users'] === 4, 'apply should seed 4 users');

    $snapshot = phase6SeedAssertDataset($conn);

    $again = phase6SeedRunTool($db, ['--json', '--apply', '--with-moova-dummy']);
    phase6SeedAssert($again['code'] === 0, 'second apply should be idempotent: ' . $again['output']);
    phase6SeedAssert(phase6SeedAssertDataset($conn) === $snapshot, 'second apply should not duplicate demo rows');

    $reset = phase6SeedRunTool($db, ['--json', '--apply', '--reset-demo', '--with-moova-dummy']);
    phase6SeedAssert($reset['code'] === 0, 'reset/apply should exit successfully: ' . $reset['output']);
    phase6SeedAssert(phase6SeedAssertDataset($conn) === $snapshot, 'reset/apply should restore the same active demo shape');

    $prod = phase6SeedRunTool($db, ['--json', '--apply'], ['POSMAIN_PRODUCTION_MODE' => '1']);
    phase6SeedAssert($prod['code'] === 2, 'production mode should be refused');
    phase6SeedAssert(str_contains($prod['output'], 'Refusing to seed Phase 6 demo data'), 'production refusal message expected');

    echo "phase6-seed-demo-restaurant-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase6SeedCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            aname VARCHAR(255) NULL,
            parent_id INT NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_acc_head_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            company_name VARCHAR(255) NULL,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            pos_has_password TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE item_group (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            gname VARCHAR(255) NOT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_item_group_name (gname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            barcode VARCHAR(80) NOT NULL,
            iname VARCHAR(255) NULL,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            sprice DECIMAL(15,4) NOT NULL DEFAULT 0,
            last_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            group1 INT NOT NULL DEFAULT 0,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            item_type VARCHAR(40) NULL,
            track_stock TINYINT(1) NOT NULL DEFAULT 0,
            info TEXT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_myitems_barcode (barcode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE table_areas (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_table_area_name (name_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(255) NOT NULL,
            table_case INT NOT NULL DEFAULT 0,
            area_id INT NOT NULL DEFAULT 0,
            capacity INT NOT NULL DEFAULT 4,
            pos_x INT NOT NULL DEFAULT 0,
            pos_y INT NOT NULL DEFAULT 0,
            shape VARCHAR(20) NULL,
            display_order INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_tables_name (tname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE modifier_groups (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NOT NULL,
            selection_min INT NOT NULL DEFAULT 0,
            selection_max INT NOT NULL DEFAULT 1,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_modifier_group_name (name_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE modifier_options (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            name_ar VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NOT NULL,
            price_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_modifier_option_name (name_en),
            KEY idx_modifier_options_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE item_modifier_groups (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            group_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_item_group (item_id, group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE payment_methods (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            name_ar VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'cash',
            requires_reference TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_payment_methods_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rollname VARCHAR(80) NOT NULL,
            show_users TINYINT(1) NOT NULL DEFAULT 0,
            add_users TINYINT(1) NOT NULL DEFAULT 0,
            edit_users TINYINT(1) NOT NULL DEFAULT 0,
            delete_users TINYINT(1) NOT NULL DEFAULT 0,
            show_sales TINYINT(1) NOT NULL DEFAULT 0,
            add_sales TINYINT(1) NOT NULL DEFAULT 0,
            edit_sales TINYINT(1) NOT NULL DEFAULT 0,
            delete_sales TINYINT(1) NOT NULL DEFAULT 0,
            show_payment TINYINT(1) NOT NULL DEFAULT 0,
            add_payment TINYINT(1) NOT NULL DEFAULT 0,
            edit_payment TINYINT(1) NOT NULL DEFAULT 0,
            delete_payment TINYINT(1) NOT NULL DEFAULT 0,
            show_items TINYINT(1) NOT NULL DEFAULT 0,
            add_items TINYINT(1) NOT NULL DEFAULT 0,
            edit_items TINYINT(1) NOT NULL DEFAULT 0,
            delete_items TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_usr_pwrs_rollname (rollname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uname VARCHAR(20) NOT NULL,
            name VARCHAR(255) NULL,
            password VARCHAR(255) NOT NULL,
            crtime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            usertype INT NOT NULL DEFAULT 1,
            userrole INT NOT NULL DEFAULT 0,
            is_waiter TINYINT(1) NOT NULL DEFAULT 0,
            img VARCHAR(255) NOT NULL DEFAULT '',
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_users_uname (uname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE moova_pos_shop_links (
            id INT(11) NOT NULL AUTO_INCREMENT,
            moova_shop_id VARCHAR(128) DEFAULT NULL,
            moova_branch_id VARCHAR(128) NOT NULL,
            moova_device_token_hash CHAR(64) NOT NULL,
            moova_device_token_last4 VARCHAR(16) DEFAULT NULL,
            pos_tenant INT(11) NOT NULL DEFAULT 0,
            pos_branch INT(11) NOT NULL DEFAULT 0,
            widget_url VARCHAR(255) NOT NULL DEFAULT 'https://withmoova.com/pos-widget',
            locale VARCHAR(16) NOT NULL DEFAULT 'ar',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_moova_token_branch_status (moova_device_token_hash, moova_branch_id, status),
            UNIQUE KEY uq_pos_scope_status (pos_tenant, pos_branch, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE moova_pos_table_links (
            id INT(11) NOT NULL AUTO_INCREMENT,
            moova_branch_id VARCHAR(128) NOT NULL,
            moova_table_id VARCHAR(128) NOT NULL,
            pos_tenant INT(11) NOT NULL DEFAULT 0,
            pos_branch INT(11) NOT NULL DEFAULT 0,
            pos_table_id INT(11) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_moova_table_scope (moova_branch_id, moova_table_id, pos_tenant, pos_branch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * @param list<string> $args
 * @param array<string,string> $extraEnv
 * @return array{code:int,output:string}
 */
function phase6SeedRunTool(string $db, array $args, array $extraEnv = []): array
{
    $root = realpath(__DIR__ . '/../..');
    phase6SeedAssert(is_string($root), 'repo root should resolve');

    $env = array_merge([
        'POSMAIN_ENV' => 'test',
        'POSMAIN_PRODUCTION_MODE' => '0',
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_POS_TENANT' => '0',
        'POSMAIN_POS_BRANCH' => '0',
    ], $extraEnv);

    $parts = [];
    foreach ($env as $key => $value) {
        $parts[] = $key . '=' . escapeshellarg((string)$value);
    }
    $parts[] = escapeshellarg(PHP_BINARY);
    $parts[] = escapeshellarg($root . '/tools/seed_demo_restaurant.php');
    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }

    $output = [];
    $code = 0;
    exec(implode(' ', $parts) . ' 2>&1', $output, $code);

    return ['code' => $code, 'output' => implode("\n", $output)];
}

/**
 * @return array<string,mixed>
 */
function phase6SeedJson(string $output): array
{
    $json = json_decode($output, true);
    phase6SeedAssert(is_array($json), 'valid JSON expected, got: ' . $output);

    return $json;
}

/**
 * @return array<string,int>
 */
function phase6SeedAssertDataset(mysqli $conn): array
{
    $snapshot = [
        'categories' => phase6SeedCount($conn, "SELECT COUNT(*) FROM item_group WHERE gname LIKE 'P6-DEMO%' AND isdeleted = 0"),
        'items' => phase6SeedCount($conn, "SELECT COUNT(*) FROM myitems WHERE barcode LIKE 'P6DEMO-%' AND isdeleted = 0"),
        'modifier_options' => phase6SeedCount($conn, "SELECT COUNT(*) FROM modifier_options WHERE name_en LIKE 'P6-DEMO%' AND is_active = 1"),
        'table_areas' => phase6SeedCount($conn, "SELECT COUNT(*) FROM table_areas WHERE name_en LIKE 'P6-DEMO%' AND is_active = 1"),
        'tables' => phase6SeedCount($conn, "SELECT COUNT(*) FROM tables WHERE tname LIKE 'P6-DEMO-%' AND isdeleted = 0"),
        'roles' => phase6SeedCount($conn, "SELECT COUNT(*) FROM usr_pwrs WHERE rollname LIKE 'P6 Demo%' AND isdeleted = 0"),
        'users' => phase6SeedCount($conn, "SELECT COUNT(*) FROM users WHERE uname LIKE 'p6_%' AND isdeleted = 0"),
        'payment_methods' => phase6SeedCount($conn, "SELECT COUNT(*) FROM payment_methods WHERE code LIKE 'p6_%' AND is_active = 1"),
        'moova_shop_links' => phase6SeedCount($conn, "SELECT COUNT(*) FROM moova_pos_shop_links WHERE moova_branch_id = 'p6-demo-branch' AND status = 'active'"),
        'moova_table_links' => phase6SeedCount($conn, "SELECT COUNT(*) FROM moova_pos_table_links WHERE moova_branch_id = 'p6-demo-branch' AND status = 'active'"),
    ];

    phase6SeedAssert($snapshot['categories'] === 3, 'expected 3 active demo categories');
    phase6SeedAssert($snapshot['items'] === 54, 'expected 54 active demo items');
    phase6SeedAssert($snapshot['modifier_options'] === 10, 'expected 10 active modifier options');
    phase6SeedAssert($snapshot['table_areas'] === 2, 'expected 2 active table areas');
    phase6SeedAssert($snapshot['tables'] === 20, 'expected 20 active tables');
    phase6SeedAssert($snapshot['roles'] === 4, 'expected 4 active roles');
    phase6SeedAssert($snapshot['users'] === 4, 'expected 4 active users');
    phase6SeedAssert($snapshot['payment_methods'] === 3, 'expected 3 active payment methods');
    phase6SeedAssert($snapshot['moova_shop_links'] === 1, 'expected 1 active Moova dummy shop link');
    phase6SeedAssert($snapshot['moova_table_links'] === 20, 'expected 20 active Moova dummy table links');

    $settings = $conn->query('SELECT def_pos_client, def_pos_store, def_pos_employee, def_pos_fund, pos_has_password FROM settings WHERE id = 1')->fetch_assoc();
    phase6SeedAssert(is_array($settings), 'settings row should exist');
    foreach (['def_pos_client', 'def_pos_store', 'def_pos_employee', 'def_pos_fund'] as $column) {
        phase6SeedAssert((int)$settings[$column] > 0, "{$column} should be populated");
    }
    phase6SeedAssert((int)$settings['pos_has_password'] === 1, 'POS password setting should be enabled');

    $password = (string)$conn->query("SELECT password FROM users WHERE uname = 'p6_admin'")->fetch_assoc()['password'];
    phase6SeedAssert($password !== 'P6demo123!', 'demo password must be hashed');
    phase6SeedAssert(PasswordService::verifyPassword('P6demo123!', $password), 'demo password hash should verify');

    return $snapshot;
}

function phase6SeedCount(mysqli $conn, string $sql): int
{
    return (int)$conn->query($sql)->fetch_row()[0];
}

function phase6SeedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
