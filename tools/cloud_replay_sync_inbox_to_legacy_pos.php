<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';
require_once __DIR__ . '/../classes/Sync/CloudLegacyPosMirrorService.php';
require_once __DIR__ . '/../classes/Sync/CloudOperationalMirrorService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['apply', 'branch-uuid:', 'shop-id:', 'limit:', 'help']);
if (isset($options['help'])) {
    cloudReplayLegacyUsage();
    exit(0);
}

$apply = isset($options['apply']);
$branchUuid = isset($options['branch-uuid']) ? trim((string) $options['branch-uuid']) : '';
$shopId = isset($options['shop-id']) ? max(0, (int) $options['shop-id']) : 0;
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = cloudReplayConnect($shopId);
$service = new CloudLegacyPosMirrorService();
$operational = new CloudOperationalMirrorService();
$summary = [
    'apply' => $apply,
    'branch_uuid' => $branchUuid !== '' ? $branchUuid : null,
    'scanned' => 0,
    'mirrored' => 0,
    'skipped' => 0,
    'failed' => 0,
    'errors' => [],
];

$where = "WHERE direction = 'branch_to_cloud' AND status IN ('processed', 'duplicate')";
if ($branchUuid !== '') {
    $where .= " AND branch_uuid = '" . $conn->real_escape_string($branchUuid) . "'";
}
$sql = "SELECT id, branch_uuid, payload_json FROM sync_inbox {$where} ORDER BY id ASC" . ($limit > 0 ? ' LIMIT ' . $limit : '');
$resultSet = $conn->query($sql);
$rows = [];
while ($row = $resultSet->fetch_assoc()) {
    $rows[] = $row;
}
$resultSet->free();

foreach ($rows as $row) {
    $summary['scanned']++;
    $event = json_decode((string) $row['payload_json'], true);
    if (!is_array($event)) {
        $summary['skipped']++;
        continue;
    }

    if (!$apply) {
        $summary['mirrored']++;
        continue;
    }

    try {
        $conn->begin_transaction();
        $result = cloudReplayApplyEvent($conn, $service, $operational, (string) $row['branch_uuid'], $event);
        $conn->commit();
        if ($result) {
            $summary['mirrored']++;
        } else {
            $summary['skipped']++;
        }
    } catch (Throwable $e) {
        $conn->rollback();
        $summary['failed']++;
        if (count($summary['errors']) < 10) {
            $summary['errors'][] = [
                'sync_inbox_id' => (int) $row['id'],
                'message' => $e->getMessage(),
            ];
        }
    }
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

function cloudReplayLegacyUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/cloud_replay_sync_inbox_to_legacy_pos.php --apply [--branch-uuid=<uuid>] [--shop-id=N] [--limit=N]\n");
    fwrite(STDOUT, "Dry-run by omission: php tools/cloud_replay_sync_inbox_to_legacy_pos.php [--branch-uuid=<uuid>] [--shop-id=N]\n");
}

function cloudReplayConnect(int $shopId): mysqli
{
    if ($shopId <= 0 || !posmain_router_enabled()) {
        return posmain_db_connect();
    }

    $router = new PosmainShopRouter();
    $routerConn = posmain_router_db_connect();
    try {
        return $router->connectShopById($routerConn, $shopId);
    } finally {
        $routerConn->close();
    }
}

function cloudReplayApplyEvent(mysqli $conn, CloudLegacyPosMirrorService $legacy, CloudOperationalMirrorService $operational, string $branchUuid, array $event): ?array
{
    $payload = $event['payload'] ?? [];
    $snapshotType = is_array($payload) ? strtolower(trim((string) ($payload['snapshot_type'] ?? ''))) : '';
    if (in_array($snapshotType, ['operational_row', 'operational_delete', 'recipe_bundle'], true)) {
        return $operational->applyFromBranchEvent($conn, $branchUuid, $event);
    }

    $result = $legacy->mirrorFromBranchEvent($conn, $branchUuid, $event);
    if ($result) {
        return $result;
    }

    return $operational->applyFromBranchEvent($conn, $branchUuid, $event);
}
