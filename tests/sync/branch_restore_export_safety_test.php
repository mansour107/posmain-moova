<?php
declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchRestoreExportService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOperationalMirrorService.php';
require_once __DIR__ . '/../../classes/Sync/OperationalSyncEventService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$database = 'restore_export_safety_' . getmypid();
$admin = new mysqli($host, $user, $pass, '', $port);
$conn = null;

try {
    $admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
    $conn = new mysqli($host, $user, $pass, $database, $port);
    (new SyncSchemaManager())->apply($conn);
    $conn->query("
        CREATE TABLE moova_pos_shop_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            moova_shop_id VARCHAR(100) NOT NULL,
            moova_branch_id VARCHAR(100) NOT NULL,
            moova_device_token_hash CHAR(64) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $branch = '41414141-4141-4141-8141-414141414141';
    $onlyStaleBranch = '42424242-4242-4242-8242-424242424242';
    $normal = restoreExportSafetyOrderEvent('normal', 1);
    $stale = restoreExportSafetyOrderEvent('stale', 1);
    $duplicate = restoreExportSafetyOrderEvent('duplicate', 2);
    restoreExportSafetyInsertInbox($conn, $branch, $normal, 'processed', ['status' => 'processed', 'applied' => true]);
    restoreExportSafetyInsertInbox($conn, $branch, $stale, 'processed', ['status' => 'stale', 'applied' => false]);
    restoreExportSafetyInsertInbox($conn, $branch, $duplicate, 'duplicate', ['status' => 'duplicate', 'applied' => false]);
    $onlyStale = $stale;
    $onlyStale['event_uuid'] = '45454545-4545-4545-8545-454545454545';
    restoreExportSafetyInsertInbox($conn, $onlyStaleBranch, $onlyStale, 'processed', ['status' => 'stale', 'applied' => false]);

    $exporter = new CloudBranchRestoreExportService();
    restoreExportSafetyAssert($exporter->hasInboxEvents($conn, $branch), 'normal and duplicate rows should keep inbox restore available');
    restoreExportSafetyAssert(!$exporter->hasInboxEvents($conn, $onlyStaleBranch), 'stale-only inbox must not become a restore source');

    $first = $exporter->exportPage($conn, $branch, RestoreEventPhase::ORDERS, 0, 1, 'sync_inbox');
    restoreExportSafetyAssert((int) $first['count'] === 1, 'first page should contain one eligible event');
    restoreExportSafetyAssert(($first['events'][0]['event']['payload']['marker'] ?? '') === 'normal', 'first page should contain normal applied event');
    restoreExportSafetyAssert(!empty($first['has_more']), 'has-more must scan past an excluded stale row to an exact duplicate');

    $second = $exporter->exportPage($conn, $branch, RestoreEventPhase::ORDERS, (int) $first['next_after_id'], 5, 'sync_inbox');
    restoreExportSafetyAssert((int) $second['count'] === 1, 'second page should exclude stale and retain duplicate');
    restoreExportSafetyAssert(($second['events'][0]['event']['payload']['marker'] ?? '') === 'duplicate', 'exact duplicate must remain recoverable');

    $realTokenHash = hash('sha256', 'real-device-token');
    $stmt = $conn->prepare('INSERT INTO moova_pos_shop_links (moova_shop_id, moova_branch_id, moova_device_token_hash, status) VALUES (?, ?, ?, ?)');
    $shopId = 'shop-restore-safety';
    $branchId = 'branch-restore-safety';
    $status = 'active';
    $stmt->bind_param('ssss', $shopId, $branchId, $realTokenHash, $status);
    $stmt->execute();
    $linkId = (int) $conn->insert_id;
    $stmt->close();

    $config = [
        'role' => 'branch',
        'branch' => ['uuid' => $branch, 'name' => 'Restore Export Safety'],
        'sync' => [
            'branch_sync_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];
    (new SyncBranchIdentity())->ensure($conn, $config);
    $recorded = (new OperationalSyncEventService())->recordMoovaShopLinkSnapshot($conn, $linkId, ['config' => $config]);
    $outbox = $conn->query('SELECT payload_json FROM sync_outbox WHERE id = ' . (int) $recorded['outbox_id'])->fetch_assoc();
    $outboxPayload = json_decode((string) $outbox['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    restoreExportSafetyAssert(!array_key_exists('moova_device_token_hash', $outboxPayload['link']), 'branch snapshot must not contain token hash');

    $legacy = $exporter->exportPage($conn, $branch, RestoreEventPhase::OPERATIONAL, 0, 100, 'cloud_snapshot');
    $legacyLink = null;
    foreach ($legacy['events'] as $entry) {
        if (($entry['event']['payload']['snapshot_type'] ?? '') === 'moova_shop_link') {
            $legacyLink = $entry['event']['payload']['link'] ?? null;
            break;
        }
    }
    restoreExportSafetyAssert(is_array($legacyLink), 'legacy snapshot fallback should retain Moova link metadata');
    restoreExportSafetyAssert(!array_key_exists('moova_device_token_hash', $legacyLink), 'legacy snapshot fallback must strip token hash');

    (new CloudOperationalMirrorService())->applyFromBranchEvent($conn, $branch, [
        'payload' => [
            'snapshot_type' => 'moova_shop_link',
            'branch_uuid' => $branch,
            'link' => [
                'id' => $linkId,
                'moova_shop_id' => $shopId,
                'moova_branch_id' => $branchId,
                'moova_device_token_hash' => $realTokenHash,
                'status' => 'active',
            ],
        ],
    ]);
    $storedHash = (string) $conn->query('SELECT moova_device_token_hash FROM moova_pos_shop_links WHERE id = ' . $linkId)->fetch_assoc()['moova_device_token_hash'];
    restoreExportSafetyAssert($storedHash === '', 'projector must defensively blank token hash and force re-pairing');

    echo "branch-restore-export-safety-ok\n";
} finally {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    $admin->query("DROP DATABASE IF EXISTS `{$database}`");
    $admin->close();
}

function restoreExportSafetyOrderEvent(string $marker, int $revision): array
{
    return [
        'event_uuid' => sprintf('43434343-4343-4343-8343-%012d', crc32($marker)),
        'idempotency_key' => 'restore-export-safety:' . $marker,
        'event_type' => 'order.updated',
        'event_version' => $revision,
        'aggregate_type' => 'order',
        'aggregate_uuid' => '44444444-4444-4444-8444-444444444444',
        'source_system' => 'restore_export_safety',
        'payload' => [
            'marker' => $marker,
            'order' => [
                'order_uuid' => '44444444-4444-4444-8444-444444444444',
                'sync_revision' => $revision,
            ],
        ],
    ];
}

function restoreExportSafetyInsertInbox(mysqli $conn, string $branch, array $event, string $status, array $result): void
{
    $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $payloadHash = hash('sha256', $payload);
    $resultJson = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare("
        INSERT INTO sync_inbox (
            event_uuid, branch_uuid, direction, source_system, idempotency_key,
            payload_hash, payload_json, status, result_json, processed_at
        ) VALUES (?, ?, 'branch_to_cloud', 'restore_export_safety', ?, ?, ?, ?, ?, NOW(6))
    ");
    $stmt->bind_param('sssssss', $event['event_uuid'], $branch, $event['idempotency_key'], $payloadHash, $payload, $status, $resultJson);
    $stmt->execute();
    $stmt->close();
}

function restoreExportSafetyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
