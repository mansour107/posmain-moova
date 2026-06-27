<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'dry-run',
    'apply',
    'backup-file:',
    'pattern:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/inventory_qa_fixture_cleanup.php --dry-run|--apply [--backup-file=/path.sql] [--pattern=regex]\n");
    fwrite(STDOUT, "Clears stock layers for QA fixture items (TEMP TEST, Recipe QA, codex/Codex QA) on the selected shop DB.\n");
    exit(0);
}

$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);
if (($dryRun ? 1 : 0) + ($apply ? 1 : 0) !== 1) {
    fwrite(STDERR, "Specify exactly one of --dry-run or --apply\n");
    exit(1);
}

$patterns = [
    '/^TEMP TEST/i',
    '/^Recipe QA/i',
    '/^codex/i',
    '/^Codex QA/i',
    '/^codex_/i',
    '/اختبار/i',
];
if (!empty($options['pattern'])) {
    $patterns = ['/' . str_replace('/', '\/', (string) $options['pattern']) . '/i'];
}

$backupFile = trim((string) ($options['backup-file'] ?? ''));
if ($apply && ($backupFile === '' || !is_file($backupFile) || filesize($backupFile) < 1)) {
    fwrite(STDERR, "--apply requires a readable --backup-file\n");
    exit(1);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $itemIds = inventoryQaFixtureItemIds($conn, $patterns);
    $summary = [
        'item_count' => count($itemIds),
        'item_ids' => $itemIds,
        'movements_deleted' => 0,
        'balances_deleted' => 0,
        'fat_details_soft_deleted' => 0,
        'items_zeroed' => 0,
    ];

    if ($apply && $itemIds !== []) {
        $conn->begin_transaction();
    }

    if ($itemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types = str_repeat('i', count($itemIds));

        if (inventoryQaFixtureTableExists($conn, 'inventory_movements')) {
            $stmt = $conn->prepare("DELETE FROM inventory_movements WHERE item_id IN ($placeholders)");
            $stmt->bind_param($types, ...$itemIds);
            if (!$dryRun) {
                $stmt->execute();
                $summary['movements_deleted'] = $stmt->affected_rows;
            } else {
                $count = $conn->query(
                    'SELECT COUNT(*) AS c FROM inventory_movements WHERE item_id IN (' . implode(',', array_map('intval', $itemIds)) . ')'
                )->fetch_assoc();
                $summary['movements_deleted'] = (int) ($count['c'] ?? 0);
            }
            $stmt->close();
        }

        if (inventoryQaFixtureTableExists($conn, 'inventory_item_balances')) {
            $stmt = $conn->prepare("DELETE FROM inventory_item_balances WHERE item_id IN ($placeholders)");
            $stmt->bind_param($types, ...$itemIds);
            if (!$dryRun) {
                $stmt->execute();
                $summary['balances_deleted'] = $stmt->affected_rows;
            } else {
                $count = $conn->query(
                    'SELECT COUNT(*) AS c FROM inventory_item_balances WHERE item_id IN (' . implode(',', array_map('intval', $itemIds)) . ')'
                )->fetch_assoc();
                $summary['balances_deleted'] = (int) ($count['c'] ?? 0);
            }
            $stmt->close();
        }

        if (inventoryQaFixtureTableExists($conn, 'fat_details')) {
            $sql = inventoryQaFixtureColumnExists($conn, 'fat_details', 'isdeleted')
                ? "UPDATE fat_details SET isdeleted = 1 WHERE item_id IN ($placeholders) AND COALESCE(isdeleted, 0) = 0"
                : "DELETE FROM fat_details WHERE item_id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$itemIds);
            if (!$dryRun) {
                $stmt->execute();
                $summary['fat_details_soft_deleted'] = $stmt->affected_rows;
            } else {
                $count = $conn->query(
                    'SELECT COUNT(*) AS c FROM fat_details WHERE item_id IN (' . implode(',', array_map('intval', $itemIds)) . ')'
                    . (inventoryQaFixtureColumnExists($conn, 'fat_details', 'isdeleted') ? ' AND COALESCE(isdeleted,0)=0' : '')
                )->fetch_assoc();
                $summary['fat_details_soft_deleted'] = (int) ($count['c'] ?? 0);
            }
            $stmt->close();
        }

        if (inventoryQaFixtureTableExists($conn, 'myitems') && inventoryQaFixtureColumnExists($conn, 'myitems', 'itmqty')) {
            $setParts = ['itmqty = 0'];
            if (inventoryQaFixtureColumnExists($conn, 'myitems', 'track_stock')) {
                $setParts[] = 'track_stock = 0';
            }
            $stmt = $conn->prepare('UPDATE myitems SET ' . implode(', ', $setParts) . " WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$itemIds);
            if (!$dryRun) {
                $stmt->execute();
                $summary['items_zeroed'] = $stmt->affected_rows;
            } else {
                $summary['items_zeroed'] = count($itemIds);
            }
            $stmt->close();
        }
    }

    if ($apply && $itemIds !== []) {
        $conn->commit();
    }
    $conn->close();

    $payload = [
        'ok' => true,
        'mode' => $dryRun ? 'dry_run' : 'apply',
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'patterns' => $patterns,
        'summary' => $summary,
    ];

    if (isset($options['json'])) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDOUT, 'QA fixture cleanup: ' . ($dryRun ? 'DRY RUN' : 'APPLIED') . PHP_EOL);
        fwrite(STDOUT, 'Items matched: ' . $summary['item_count'] . PHP_EOL);
        foreach ($summary as $key => $value) {
            if ($key === 'item_ids') {
                continue;
            }
            fwrite(STDOUT, $key . ': ' . $value . PHP_EOL);
        }
    }

    exit(0);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}

function inventoryQaFixtureItemIds(mysqli $conn, array $patterns): array
{
    $rows = $conn->query('SELECT id, iname FROM myitems ORDER BY id ASC');
    $ids = [];
    while ($row = $rows->fetch_assoc()) {
        $name = (string) ($row['iname'] ?? '');
        foreach ($patterns as $pattern) {
            if ($name !== '' && preg_match($pattern, $name)) {
                $ids[] = (int) $row['id'];
                break;
            }
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function inventoryQaFixtureTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function inventoryQaFixtureColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}
