<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerOrderSideEffects.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerMigrationService.php';

$options = getopt('', ['db::', 'all-pos::', 'include-tests', 'dry-run', 'customer::', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/reconcile_pos_customer_rollups.php [--db=name] [--all-pos] [--customer=id] [--dry-run]\n");
    exit(0);
}

$config = posmain_app_config();
$dbConfig = $config['database'];
$dryRun = array_key_exists('dry-run', $options);
$includeTests = array_key_exists('include-tests', $options);
$customerFilter = isset($options['customer']) ? (int) $options['customer'] : 0;
$targets = [];

if (isset($options['db'])) {
    $targets[] = (string) $options['db'];
} elseif (array_key_exists('all-pos', $options)) {
    $targets = discoverReconcileDatabases($dbConfig, $includeTests);
} else {
    $name = trim((string) ($dbConfig['name'] ?? ''));
    if ($name === '') {
        fwrite(STDERR, "POSMAIN_DB_NAME is not configured\n");
        exit(1);
    }
    $targets[] = $name;
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

        if (!reconcileDatabaseLooksLikePosShop($conn)) {
            fwrite(STDOUT, "   skip: no POS settings table\n");
            $conn->close();
            continue;
        }

        posmain_apply_pos_customer_migrations($conn);
        $migration = new PosCustomerMigrationService();
        $backfill = $migration->backfillOrderFulfillmentCustomers($conn);

        $sideEffects = new PosCustomerOrderSideEffects();
        $customerIds = reconcileListCustomerIds($conn, $customerFilter);
        $rebuilt = 0;

        foreach ($customerIds as $customerId) {
            $sideEffects->rebuildCustomerRollups($conn, $customerId);
            $rebuilt++;
        }

        $conn->close();
        fwrite(STDOUT, '   backfill: ' . (int) ($backfill['updated'] ?? 0) . " fulfillment row(s)\n");
        fwrite(STDOUT, '   rebuilt: ' . $rebuilt . " customer(s)\n");
    } catch (Throwable $e) {
        fwrite(STDERR, '   error: ' . $e->getMessage() . PHP_EOL);
        $exitCode = 1;
    }
}

exit($exitCode);

function discoverReconcileDatabases(array $dbConfig, bool $includeTests): array
{
    $conn = posmain_raw_db_connect([
        'host' => $dbConfig['host'],
        'user' => $dbConfig['user'],
        'pass' => $dbConfig['pass'],
        'name' => '',
        'port' => $dbConfig['port'],
        'charset' => $dbConfig['charset'] ?? 'utf8mb4',
    ]);
    $result = $conn->query('SHOW DATABASES');
    $databases = [];
    while ($row = $result->fetch_assoc()) {
        $name = (string) ($row['Database'] ?? '');
        if ($name === '' || in_array($name, ['information_schema', 'mysql', 'performance_schema', 'sys'], true)) {
            continue;
        }
        if (!$includeTests && preg_match('/^posmain_.*_e2e_/i', $name)) {
            continue;
        }
        $databases[] = $name;
    }
    $conn->close();

    return $databases;
}

function reconcileDatabaseLooksLikePosShop(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'settings'");
    return $result && $result->num_rows > 0;
}

function reconcileListCustomerIds(mysqli $conn, int $customerFilter): array
{
    if ($customerFilter > 0) {
        return [$customerFilter];
    }

    $result = $conn->query('SELECT id FROM pos_customers WHERE isdeleted = 0 ORDER BY id');
    if (!$result) {
        return [];
    }

    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }

    return $ids;
}
