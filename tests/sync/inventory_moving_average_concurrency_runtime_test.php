<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryLedgerService.php';

if (!function_exists('pcntl_fork')) {
    throw new RuntimeException('pcntl is required for the inventory concurrency certification test');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_inventory_cost_race_' . getmypid() . '_' . bin2hex(random_bytes(4));
$barrierRoot = sys_get_temp_dir() . '/posmain-inventory-cost-race-' . getmypid() . '-' . bin2hex(random_bytes(4));
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    if (!mkdir($barrierRoot, 0700, true) && !is_dir($barrierRoot)) {
        throw new RuntimeException('failed to create inventory race barrier directory');
    }

    for ($iteration = 1; $iteration <= 5; $iteration++) {
        $itemId = 5000 + $iteration;
        $iterationDir = $barrierRoot . '/iteration-' . $iteration;
        if (!mkdir($iterationDir, 0700, true) && !is_dir($iterationDir)) {
            throw new RuntimeException('failed to create inventory race iteration directory');
        }
        $conn->close();

        $children = [];
        foreach ([
            'A' => ['qty' => '3.000000', 'cost' => '2.000000'],
            'B' => ['qty' => '7.000000', 'cost' => '4.000000'],
        ] as $label => $receipt) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('failed to fork inventory race worker');
            }
            if ($pid === 0) {
                inventoryCostRaceChild(
                    $host,
                    $port,
                    $user,
                    $pass,
                    $db,
                    $iterationDir,
                    $label,
                    $itemId,
                    $receipt['qty'],
                    $receipt['cost'],
                    $iteration
                );
            }
            $children[$label] = $pid;
        }

        inventoryCostRaceWaitForFiles([
            $iterationDir . '/ready-A',
            $iterationDir . '/ready-B',
        ], 5.0);
        file_put_contents($iterationDir . '/go', 'go');

        foreach ($children as $label => $pid) {
            pcntl_waitpid($pid, $status);
            inventoryCostRaceAssert(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                'inventory race worker ' . $label . ' failed: ' . inventoryCostRaceRead($iterationDir . '/result-' . $label)
            );
        }

        $conn = new mysqli($host, $user, $pass, $db, $port);
        $balance = $conn->query(
            'SELECT qty_on_hand, qty_available, moving_average_cost, last_movement_id'
            . " FROM inventory_item_balances WHERE item_id = {$itemId} LIMIT 1"
        )->fetch_assoc();
        inventoryCostRaceAssert((string) ($balance['qty_on_hand'] ?? '') === '10.000000', 'concurrent receipts must not lose quantity');
        inventoryCostRaceAssert((string) ($balance['qty_available'] ?? '') === '10.000000', 'concurrent receipts must preserve available quantity');
        inventoryCostRaceAssert((string) ($balance['moving_average_cost'] ?? '') === '3.400000', 'moving-average cost must be exact and commit-order independent');

        $movement = $conn->query(
            'SELECT COUNT(*) AS c, MIN(id) AS min_id, MAX(id) AS max_id,'
            . ' CAST(SUM(qty_in) AS DECIMAL(18,6)) AS qty,'
            . ' CAST(SUM(total_cost) AS DECIMAL(18,6)) AS value'
            . " FROM inventory_movements WHERE item_id = {$itemId}"
        )->fetch_assoc();
        inventoryCostRaceAssert((int) ($movement['c'] ?? 0) === 2, 'each concurrent receipt must be recorded exactly once');
        inventoryCostRaceAssert((string) ($movement['qty'] ?? '') === '10.000000', 'movement quantity must reconcile exactly');
        inventoryCostRaceAssert((string) ($movement['value'] ?? '') === '34.000000', 'movement value must reconcile exactly');
        inventoryCostRaceAssert(
            (int) ($balance['last_movement_id'] ?? 0) === (int) ($movement['max_id'] ?? 0),
            'balance revision must point at the last committed movement'
        );

        $outbox = $conn->query(
            "SELECT aggregate_type, COUNT(*) AS c, COUNT(DISTINCT event_version) AS versions"
            . " FROM sync_outbox WHERE aggregate_type IN ('inventory_movement', 'inventory_balance')"
            . " AND event_version BETWEEN " . (int) $movement['min_id'] . ' AND ' . (int) $movement['max_id']
            . ' GROUP BY aggregate_type'
        );
        $outboxRows = [];
        while ($row = $outbox->fetch_assoc()) {
            $outboxRows[(string) $row['aggregate_type']] = $row;
        }
        inventoryCostRaceAssert(
            (int) ($outboxRows['inventory_movement']['c'] ?? 0) === 2
                && (int) ($outboxRows['inventory_movement']['versions'] ?? 0) === 2,
            'concurrent movements must each have one distinct outbox revision'
        );
        inventoryCostRaceAssert(
            (int) ($outboxRows['inventory_balance']['c'] ?? 0) === 2
                && (int) ($outboxRows['inventory_balance']['versions'] ?? 0) === 2,
            'concurrent balance changes must each have one distinct outbox revision'
        );
    }

    echo "inventory-moving-average-concurrency-runtime-ok db={$db} iterations=5\n";
} finally {
    try {
        $cleanup = new mysqli($host, $user, $pass, '', $port);
        $cleanup->query("DROP DATABASE IF EXISTS `{$db}`");
        $cleanup->close();
    } catch (Throwable $ignored) {
    }
    inventoryCostRaceRemoveTree($barrierRoot);
}

function inventoryCostRaceChild(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $db,
    string $barrier,
    string $label,
    int $itemId,
    string $qty,
    string $cost,
    int $iteration
): void {
    try {
        file_put_contents($barrier . '/ready-' . $label, 'ready');
        inventoryCostRaceWaitForFiles([$barrier . '/go'], 5.0);
        $child = new mysqli($host, $user, $pass, $db, $port);
        $flags = new InventoryFeatureFlags(inventoryCostRaceConfig());
        $result = (new InventoryLedgerService($flags))->recordMovement($child, [
            'scope' => [
                'pos_tenant' => 1,
                'pos_branch' => 1,
                'branch_uuid' => '51515151-5151-4151-8151-515151515151',
                'store_id' => 1,
            ],
            'item_id' => $itemId,
            'movement_type' => 'purchase',
            'source_type' => 'purchase_receipt',
            'source_uuid' => 'cost-race-' . $iteration . '-' . $label,
            'qty_in' => $qty,
            'unit_cost' => $cost,
            'idempotency_key' => 'cost-race:' . $iteration . ':' . $label,
            'created_by' => 7,
        ], ['item_id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1]);
        $child->close();
        file_put_contents($barrier . '/result-' . $label, json_encode([
            'ok' => true,
            'movement_id' => (int) ($result['movement_id'] ?? 0),
        ], JSON_UNESCAPED_SLASHES));
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($barrier . '/result-' . $label, json_encode([
            'ok' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES));
        exit(1);
    }
}

function inventoryCostRaceConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => '51515151-5151-4151-8151-515151515151',
            'name' => 'Moving Average Race',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
        'inventory' => [
            'ledger_mode' => 'off',
            'quantity_tracking' => true,
            'accounting' => false,
        ],
    ];
}

function inventoryCostRaceWaitForFiles(array $paths, float $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $missing = array_filter($paths, static fn(string $path): bool => !is_file($path));
        if ($missing === []) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('inventory race barrier timed out waiting for: ' . implode(', ', $missing));
}

function inventoryCostRaceRead(string $path): string
{
    return is_file($path) ? trim((string) file_get_contents($path)) : 'no worker result';
}

function inventoryCostRaceRemoveTree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        if ($path->isDir()) {
            rmdir($path->getPathname());
        } else {
            unlink($path->getPathname());
        }
    }
    rmdir($root);
}

function inventoryCostRaceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
