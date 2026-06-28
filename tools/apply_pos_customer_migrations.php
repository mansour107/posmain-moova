<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';

$options = getopt('', ['db::', 'all-pos::', 'include-tests', 'dry-run', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/apply_pos_customer_migrations.php [--db=name] [--all-pos] [--include-tests] [--dry-run]\n");
    fwrite(STDOUT, "  --db=name         Apply to one database (default: POSMAIN_DB_NAME)\n");
    fwrite(STDOUT, "  --all-pos         Apply to every local DB that looks like a POS shop (has settings table)\n");
    fwrite(STDOUT, "  --include-tests   With --all-pos, also include ephemeral posmain_* test databases\n");
    fwrite(STDOUT, "  --dry-run         List targets only\n");
    exit(0);
}

$config = posmain_app_config();
$dbConfig = $config['database'];
$dryRun = array_key_exists('dry-run', $options);
$includeTests = array_key_exists('include-tests', $options);
$targets = [];

if (isset($options['db'])) {
    $targets[] = (string) $options['db'];
} elseif (array_key_exists('all-pos', $options)) {
    $targets = discoverPosShopDatabases($dbConfig, $includeTests);
} else {
    $name = trim((string) ($dbConfig['name'] ?? ''));
    if ($name === '') {
        fwrite(STDERR, "POSMAIN_DB_NAME is not configured\n");
        exit(1);
    }
    $targets[] = $name;
}

if (!$targets) {
    fwrite(STDERR, "No target databases found\n");
    exit(1);
}

$exitCode = 0;
foreach ($targets as $database) {
    fwrite(STDOUT, '==> ' . $database . PHP_EOL);
    if ($dryRun) {
        continue;
    }

    try {
        $conn = posmain_raw_db_connect([
            'host' => $dbConfig['host'],
            'user' => $dbConfig['user'],
            'pass' => $dbConfig['pass'],
            'name' => $database,
            'port' => $dbConfig['port'],
            'charset' => $dbConfig['charset'] ?? 'utf8mb4',
        ]);

        if (!databaseLooksLikePosShop($conn)) {
            fwrite(STDOUT, "   skip: no POS settings table\n");
            $conn->close();
            continue;
        }

        $result = posmain_apply_pos_customer_migrations($conn);
        $conn->close();

        $tables = $result['schema_tables'] ?? [];
        $delivery = $result['delivery_migration'] ?? [];
        $backfill = $result['fulfillment_backfill'] ?? [];

        fwrite(STDOUT, '   schema: ' . count($tables) . " change(s)\n");
        if ($tables) {
            fwrite(STDOUT, '   applied: ' . implode(', ', $tables) . PHP_EOL);
        }
        fwrite(STDOUT, '   delivery_clients: migrated=' . (int) ($delivery['migrated'] ?? 0)
            . ' skipped=' . (!empty($delivery['skipped']) ? 'yes' : 'no') . PHP_EOL);
        fwrite(STDOUT, '   fulfillment_backfill: updated=' . (int) ($backfill['updated'] ?? 0) . PHP_EOL);
    } catch (Throwable $e) {
        $exitCode = 1;
        fwrite(STDERR, '   error: ' . $e->getMessage() . PHP_EOL);
    }
}

exit($exitCode);

function discoverPosShopDatabases(array $dbConfig, bool $includeTests): array
{
    $conn = new mysqli(
        (string) $dbConfig['host'],
        (string) $dbConfig['user'],
        (string) $dbConfig['pass'],
        '',
        (int) $dbConfig['port']
    );
    $conn->set_charset((string) ($dbConfig['charset'] ?? 'utf8mb4'));

    $result = $conn->query('SHOW DATABASES');
    $targets = [];
    while ($row = $result->fetch_assoc()) {
        $name = (string) ($row['Database'] ?? '');
        if ($name === '' || isSystemDatabase($name)) {
            continue;
        }
        if (!$includeTests && isEphemeralTestDatabase($name)) {
            continue;
        }
        $targets[] = $name;
    }
    $conn->close();
    sort($targets);

    return $targets;
}

function isSystemDatabase(string $name): bool
{
    return in_array($name, ['information_schema', 'performance_schema', 'mysql', 'sys'], true);
}

function isEphemeralTestDatabase(string $name): bool
{
    return (bool) preg_match(
        '/^posmain_(table_save_service|customer_service|cost_verify|delivery_prod|diag|recipe_dbg|recipe_diag|moova_partial|kody_merge_e2e)_/i',
        $name
    );
}

function databaseLooksLikePosShop(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'settings'");
    return $result && $result->num_rows > 0;
}
