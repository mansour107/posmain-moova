<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase6_load_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase6LoadCreateSchema($conn);

    $run = phase6LoadRunTool($db, ['--json', '--cashiers=5', '--search-requests=100', '--max-search-ms=2000']);
    phase6LoadAssert($run['code'] === 0, 'load/concurrency check should pass: ' . $run['output']);
    $json = phase6LoadJson($run['output']);
    phase6LoadAssert($json['ok'] === true, 'top-level ok expected');
    phase6LoadAssert($json['cleanup'] === true, 'cleanup should default to true');

    $cashiers = $json['scenarios']['cashier_sales'];
    phase6LoadAssert($cashiers['ok'] === true, 'cashier sales scenario should pass');
    phase6LoadAssert((int)$cashiers['expected_cashiers'] === 5, 'expected five cashier writers');
    phase6LoadAssert((int)$cashiers['unique_pro_ids'] === 5, 'cashier pro_id values should be unique');
    phase6LoadAssert($cashiers['duplicate_pro_id'] === false, 'cashier sales should not duplicate pro_id');

    $waiters = $json['scenarios']['waiter_table_saves'];
    phase6LoadAssert($waiters['ok'] === true, 'waiter saves scenario should pass');
    phase6LoadAssert((int)$waiters['successful_table_saves'] === 3, 'three waiter table saves expected');

    $sameTable = $json['scenarios']['same_table_conflict'];
    phase6LoadAssert($sameTable['ok'] === true, 'same-table conflict scenario should pass');
    phase6LoadAssert((int)$sameTable['success_count'] === 1, 'one same-table writer should win');
    phase6LoadAssert((int)$sameTable['conflict_count'] === 1, 'one same-table writer should conflict');

    $payment = $json['scenarios']['duplicate_payment_submit'];
    phase6LoadAssert($payment['ok'] === true, 'duplicate payment scenario should pass');
    phase6LoadAssert((int)$payment['success_count'] === 1, 'one duplicate payment submit should apply');
    phase6LoadAssert((int)$payment['duplicate_count'] === 1, 'one duplicate payment submit should be ignored');
    phase6LoadAssert((float)$payment['remaining_amount'] === 0.0, 'remaining amount should be zero');
    phase6LoadAssert($payment['negative_remaining_amount'] === false, 'remaining amount should never go negative');
    phase6LoadAssert($payment['table_stuck_occupied_after_paid_order'] === false, 'paid table should be clear');

    $search = $json['scenarios']['item_search_requests'];
    phase6LoadAssert($search['ok'] === true, 'item search scenario should pass');
    phase6LoadAssert((int)$search['requests'] === 100, '100 item search requests expected');
    phase6LoadAssert($search['response_time_acceptable'] === true, 'search response time should be acceptable');

    phase6LoadAssert(phase6LoadCount($conn, "SELECT COUNT(*) FROM ot_head WHERE pro_serial LIKE 'P6-CONC-%'") === 0, 'cleanup should remove P6-CONC orders');
    phase6LoadAssert(phase6LoadCount($conn, "SELECT COUNT(*) FROM myitems WHERE barcode LIKE 'P6CONC-%'") === 0, 'cleanup should remove P6-CONC items');
    phase6LoadAssert(phase6LoadCount($conn, "SELECT COUNT(*) FROM tables WHERE tname LIKE 'P6-CONC-%'") === 0, 'cleanup should remove P6-CONC tables');
    phase6LoadAssert(phase6LoadCount($conn, "SELECT COUNT(*) FROM document_counters WHERE counter_type = 'phase6_load_check'") === 0, 'cleanup should remove P6-CONC counters');

    $unsafe = phase6LoadRunTool('kody2', ['--json']);
    phase6LoadAssert($unsafe['code'] === 2, 'ordinary database name should be refused without override');
    phase6LoadAssert(str_contains($unsafe['output'], 'Refusing database'), 'ordinary database refusal message expected');

    $prod = phase6LoadRunTool($db, ['--json'], ['POSMAIN_PRODUCTION_MODE' => '1']);
    phase6LoadAssert($prod['code'] === 2, 'production mode should be refused');
    phase6LoadAssert(str_contains($prod['output'], 'Refusing to run Phase 6 load/concurrency checks'), 'production refusal message expected');

    echo "phase6-load-concurrency-check-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase6LoadCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE document_counters (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            counter_type VARCHAR(50) NOT NULL,
            counter_key VARCHAR(100) NOT NULL,
            current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_document_counter_scope (pos_tenant, pos_branch, counter_type, counter_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(255) NOT NULL,
            table_case INT NOT NULL DEFAULT 0,
            area_id INT NOT NULL DEFAULT 0,
            capacity INT NOT NULL DEFAULT 4,
            display_order INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_tables_name (tname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            barcode VARCHAR(80) NOT NULL,
            iname VARCHAR(255) NULL,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            sprice DECIMAL(15,4) NOT NULL DEFAULT 0,
            group1 INT NOT NULL DEFAULT 0,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_myitems_barcode (barcode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            pro_tybe INT NULL,
            pro_serial VARCHAR(80) NULL,
            order_type VARCHAR(40) NULL,
            table_id INT NULL,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            info TEXT NULL,
            KEY idx_ot_head_pro_serial (pro_serial),
            KEY idx_ot_head_table (table_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * @param list<string> $args
 * @param array<string,string> $extraEnv
 * @return array{code:int,output:string}
 */
function phase6LoadRunTool(string $db, array $args, array $extraEnv = []): array
{
    $root = realpath(__DIR__ . '/../..');
    phase6LoadAssert(is_string($root), 'repo root should resolve');

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
    $parts[] = escapeshellarg($root . '/tools/phase6_load_concurrency_check.php');
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
function phase6LoadJson(string $output): array
{
    $json = json_decode($output, true);
    phase6LoadAssert(is_array($json), 'valid JSON expected, got: ' . $output);

    return $json;
}

function phase6LoadCount(mysqli $conn, string $sql): int
{
    return (int)$conn->query($sql)->fetch_row()[0];
}

function phase6LoadAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
