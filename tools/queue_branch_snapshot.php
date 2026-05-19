<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/SyncOutboxEventService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'apply',
    'catalog',
    'tables',
    'orders',
    'all',
    'include-deleted',
    'force-resend',
    'limit:',
    'help',
]);

if (isset($options['help'])) {
    queueBranchSnapshotUsage();
    exit(0);
}

$apply = isset($options['apply']);
$all = isset($options['all']);
$includeCatalog = $all || isset($options['catalog']);
$includeTables = $all || isset($options['tables']);
$includeOrders = $all || isset($options['orders']);
$includeDeleted = isset($options['include-deleted']);
$forceResend = isset($options['force-resend']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;

if (!$includeCatalog && !$includeTables && !$includeOrders) {
    queueBranchSnapshotUsage(STDERR);
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = posmain_db_connect();
$config = posmain_app_config([
    'sync' => [
        'menu_sync_enabled' => true,
        'outbox_enabled' => true,
    ],
]);
$service = new SyncOutboxEventService();
$summary = [
    'apply' => $apply,
    'force_resend' => $forceResend,
    'catalog' => 0,
    'tables' => 0,
    'orders' => 0,
    'resent' => 0,
    'skipped' => 0,
];

if ($includeCatalog) {
    foreach (queueBranchSnapshotIds($conn, 'myitems', $includeDeleted, $limit) as $itemId) {
        $summary['catalog']++;
        if (!$apply) {
            continue;
        }

        $result = $service->recordMenuItemSnapshot($conn, $itemId, [
            'event_type' => 'menu.item_saved',
            'source_system' => 'initial_branch_snapshot',
            'config' => $config,
        ]);
        queueBranchSnapshotForceResend($conn, $result, $forceResend, $summary);
    }
}

if ($includeTables) {
    foreach (queueBranchSnapshotIds($conn, 'tables', $includeDeleted, $limit) as $tableId) {
        $summary['tables']++;
        if (!$apply) {
            continue;
        }

        $result = $service->recordTableSnapshot($conn, $tableId, [
            'event_type' => 'table.updated',
            'source_system' => 'initial_branch_snapshot',
            'active_order_id' => '__auto__',
            'config' => $config,
        ]);
        queueBranchSnapshotForceResend($conn, $result, $forceResend, $summary);
    }
}

if ($includeOrders) {
    $where = 'WHERE pro_tybe = 9';
    if (!$includeDeleted) {
        $where .= ' AND COALESCE(isdeleted, 0) = 0';
    }
    $sql = "SELECT id FROM ot_head {$where} ORDER BY id ASC" . ($limit > 0 ? ' LIMIT ' . $limit : '');
    $resultSet = $conn->query($sql);
    while ($row = $resultSet->fetch_assoc()) {
        $summary['orders']++;
        if (!$apply) {
            continue;
        }

        $result = $service->recordOrderSnapshot($conn, (int) $row['id'], [
            'event_type' => 'order.saved',
            'source_system' => 'initial_branch_snapshot',
            'config' => $config,
        ]);
        queueBranchSnapshotForceResend($conn, $result, $forceResend, $summary);
    }
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

function queueBranchSnapshotUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/queue_branch_snapshot.php --apply --all [--include-deleted] [--force-resend]\n");
    fwrite($stream, "Dry-run by omission: php tools/queue_branch_snapshot.php --all\n");
}

function queueBranchSnapshotIds(mysqli $conn, string $table, bool $includeDeleted, int $limit): array
{
    $where = $includeDeleted ? '' : ' WHERE COALESCE(isdeleted, 0) = 0';
    $sql = "SELECT id FROM `{$table}`{$where} ORDER BY id ASC" . ($limit > 0 ? ' LIMIT ' . $limit : '');
    $result = $conn->query($sql);
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }

    return $ids;
}

function queueBranchSnapshotForceResend(mysqli $conn, ?array $result, bool $forceResend, array &$summary): void
{
    if (!$result || empty($result['outbox_id'])) {
        $summary['skipped']++;
        return;
    }

    if (!$forceResend) {
        return;
    }

    $outboxId = (int) $result['outbox_id'];
    $conn->query("
        UPDATE sync_outbox
        SET status = 'pending',
            attempts = 0,
            locked_by = NULL,
            locked_until = NULL,
            next_retry_at = NULL,
            last_error = NULL,
            synced_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = {$outboxId}
    ");
    $summary['resent']++;
}
