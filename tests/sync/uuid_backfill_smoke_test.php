<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_uuid_smoke_' . getmypid();
$root = dirname(__DIR__, 2);
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);

    foreach (['ot_head', 'fat_details', 'order_payments', 'tables', 'closed_orders'] as $table) {
        $conn->query("
            CREATE TABLE `{$table}` (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("INSERT INTO `{$table}` () VALUES (), (), ()");
    }

    (new SyncSchemaManager())->apply($conn);

    $dryRun = uuidBackfillSmokeRunTool($root, $db, '--dry-run --batch-size=2');
    uuidBackfillSmokeAssertContains($dryRun, 'Dry run: UUID backfill batch_size=2.');
    uuidBackfillSmokeAssertContains($dryRun, 'table=ot_head status=ready missing_uuid=3 batch=2');

    $first = uuidBackfillSmokeRunTool($root, $db, '--apply --confirm-no-backup --batch-size=2');
    uuidBackfillSmokeAssertContains($first, 'Applied batch: table=ot_head updated=2 remaining=1');

    $second = uuidBackfillSmokeRunTool($root, $db, '--apply --confirm-no-backup --batch-size=2');
    uuidBackfillSmokeAssertContains($second, 'Applied batch: table=ot_head updated=1 remaining=0');

    foreach (['ot_head', 'fat_details', 'order_payments', 'tables', 'closed_orders'] as $table) {
        $row = $conn->query("
            SELECT COUNT(*) AS total_rows,
                   COUNT(uuid) AS uuid_rows,
                   COUNT(DISTINCT uuid) AS distinct_uuid_rows
            FROM `{$table}`
        ")->fetch_assoc();
        if ((int) $row['total_rows'] !== 3 || (int) $row['uuid_rows'] !== 3 || (int) $row['distinct_uuid_rows'] !== 3) {
            throw new RuntimeException("UUID backfill count mismatch for {$table}");
        }

        $invalid = $conn->query("
            SELECT COUNT(*) AS invalid_rows
            FROM `{$table}`
            WHERE uuid NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
        ")->fetch_assoc();
        if ((int) $invalid['invalid_rows'] !== 0) {
            throw new RuntimeException("Invalid UUID format in {$table}");
        }
    }

    echo "uuid-backfill-smoke-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function uuidBackfillSmokeRunTool(string $root, string $db, string $args): string
{
    $env = [
        'POSMAIN_SYNC_DB_NAME' => '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_TEST_MYSQL_DB' => $db,
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
    ];

    $prefix = '';
    foreach ($env as $name => $value) {
        $prefix .= $name . '=' . escapeshellarg((string) $value) . ' ';
    }

    $cmd = $prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/backfill_uuid_columns.php') . ' ' . $args . ' 2>&1';
    exec($cmd, $lines, $code);
    $output = implode("\n", $lines);
    if ($code !== 0) {
        throw new RuntimeException("Backfill tool failed with code {$code}: {$output}");
    }

    return $output;
}

function uuidBackfillSmokeAssertContains(string $haystack, string $needle): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException("Expected output to contain {$needle}; got: {$haystack}");
    }
}
