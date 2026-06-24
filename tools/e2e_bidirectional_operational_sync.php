<?php

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../classes/Sync/BranchCatalogPushService.php';
require_once __DIR__ . '/../classes/Sync/BranchSyncWorker.php';
require_once __DIR__ . '/../classes/Sync/BranchRestoreFromHostedService.php';
require_once __DIR__ . '/../classes/Sync/BranchCloudSyncPollWorker.php';
require_once __DIR__ . '/../classes/Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/../classes/Sync/OperationalSyncDomains.php';
require_once __DIR__ . '/../classes/Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../classes/Sync/RestoreEventPhase.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php tools/e2e_bidirectional_operational_sync.php\n");
    fwrite(STDOUT, "Requires Docker posmain-mysql on 3307 and PHP extensions curl,mysqli,pcntl,posix.\n");
    exit(0);
}

assertE2eBidirectionalRequirements();

$runId = 'bsync:' . date('YmdHis');
$branchUuid = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
$branchSecret = 'e2e-bidirectional-branch-secret';
$branchDb = 'posmain_e2e_bsync_branch';
$cloudDb = 'posmain_e2e_bsync_cloud';
$tag = 'E2E-BSYNC';
$tmpRoot = sys_get_temp_dir() . '/posmain-bsync-e2e-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $runId);
mkdir($tmpRoot, 0777, true);

$children = [];
$results = [];

try {
    fwrite(STDERR, "[e2e] setup\n");
    e2eBsyncLog($tmpRoot, 'setup_databases');
    e2eBsyncCloneSchema($branchDb, $cloudDb);

    $branchConn = e2eBsyncConnect($branchDb);
    $cloudConn = e2eBsyncConnect($cloudDb);
    (new SyncSchemaManager())->apply($branchConn);
    (new SyncSchemaManager())->apply($cloudConn);
    e2eBsyncRegisterPairing($branchConn, $cloudConn, $branchUuid, $branchSecret, $tag);

    $cloudPort = e2eBsyncFreePort();
    $cloudUrl = 'http://127.0.0.1:' . $cloudPort;
    $cloud = e2eBsyncStartCloudServer($cloudPort, $cloudDb, $branchUuid, $branchSecret, $tmpRoot);
    $children[] = $cloud;

    $stmt = $branchConn->prepare('UPDATE sync_branch_identity SET cloud_base_url = ? WHERE id = 1');
    $stmt->bind_param('s', $cloudUrl);
    $stmt->execute();
    $stmt->close();

    $branchConfig = e2eBsyncBranchConfig($branchUuid, $branchSecret, $cloudUrl);
    $cloudConfig = e2eBsyncCloudConfig($branchUuid, $branchSecret);

    $seed = e2eBsyncSeedBranch($branchConn, $tag);
    e2eBsyncLog($tmpRoot, 'seed_complete', $seed);

    fwrite(STDERR, "[e2e] seed complete\n");

    $results[] = e2eBsyncScenario('manual_push_local_to_hosted', function () use ($branchConn, $branchConfig, $cloudConn, $seed, $tag, $tmpRoot) {
        fwrite(STDERR, "[e2e] manual push start\n");
        $summary = (new BranchCatalogPushService())->pushToHosted($branchConn, $branchConfig, [
            'include_deleted' => false,
            'drain_outbox' => false,
            'max_batches' => 25,
            'batch_size' => 25,
        ]);
        for ($attempt = 0; $attempt < 25; $attempt++) {
            if ((int) ($summary['pending_outbox'] ?? 1) === 0) {
                break;
            }
            $batch = (new BranchCatalogPushService())->runPushDispatchBatch($branchConn, $branchConfig, [
                'batch_size' => 25,
            ]);
            $summary['pending_outbox'] = (int) ($batch['pending_outbox'] ?? 0);
            $summary['dispatch_batches'][] = $batch;
            if (!empty($batch['done'])) {
                break;
            }
        }
        $checks = [
            (int) ($summary['pending_outbox'] ?? 1) === 0,
            (int) ($summary['dispatch']['synced'] ?? 0) >= 1,
        ];
        foreach ($seed as $domain => $meta) {
            if (!e2eBsyncTableExists($cloudConn, $meta['table'])) {
                continue;
            }
            $checks[] = e2eBsyncRowExists($cloudConn, $meta['table'], (int) $meta['id'], $tag, $meta['marker_column'] ?? null);
        }
        $inboxCount = e2eBsyncCount($cloudConn, "
            SELECT COUNT(*) AS c FROM sync_inbox
            WHERE branch_uuid = '" . $cloudConn->real_escape_string($branchConfig['branch']['uuid']) . "'
              AND direction = 'branch_to_cloud'
              AND status IN ('processed','duplicate','received')
        ");
        $checks[] = $inboxCount > 0;

        return e2eBsyncResult($checks, ['push_summary' => $summary, 'cloud_inbox_events' => $inboxCount]);
    });

    $results[] = e2eBsyncScenario('worker_push_new_local_change', function () use ($branchConn, $branchConfig, $cloudConn, $tag) {
        $zoneId = e2eBsyncInsertRow($branchConn, 'delivery_zones', [
            'name' => $tag . '-worker-zone',
            'fee' => '3.500',
            'is_active' => 1,
            'sort_order' => 99,
            'tenant' => 0,
            'branch' => 0,
        ]);
        $recorded = (new OperationalSyncEventService())->recordRowSnapshot($branchConn, 'delivery_zone', $zoneId, [
            'config' => $branchConfig,
            'source_system' => 'e2e_worker',
        ]);
        $worker = (new BranchSyncWorker())->runOnce($branchConn, $branchConfig, [
            'batch_size' => 25,
            'worker_id' => 'e2e-bsync-worker',
        ]);
        $cloudRow = e2eBsyncFetchRow($cloudConn, 'delivery_zones', $zoneId);

        return e2eBsyncResult([
            $recorded !== null,
            (int) ($worker['synced'] ?? 0) >= 1,
            $cloudRow && strpos((string) ($cloudRow['name'] ?? ''), $tag . '-worker-zone') !== false,
        ], ['zone_id' => $zoneId, 'worker' => $worker]);
    });

    $results[] = e2eBsyncScenario('manual_restore_hosted_to_local', function () use ($branchConn, $branchConfig, $seed, $tag) {
        foreach ($seed as $meta) {
            if (e2eBsyncTableExists($branchConn, $meta['table'])) {
                $branchConn->query('DELETE FROM `' . $meta['table'] . '` WHERE id = ' . (int) $meta['id']);
            }
        }
        $restore = (new BranchRestoreFromHostedService())->restore($branchConn, $branchConfig, [
            'apply' => true,
            'limit' => 25,
            'max_pages_per_phase' => 10,
            'phases' => RestoreEventPhase::all(),
        ]);
        $checks = [
            (int) ($restore['failed'] ?? 1) === 0,
            (int) ($restore['mirrored'] ?? 0) > 0,
        ];
        foreach ($seed as $domain => $meta) {
            if (!e2eBsyncTableExists($branchConn, $meta['table'])) {
                continue;
            }
            $checks[] = e2eBsyncRowExists($branchConn, $meta['table'], (int) $meta['id'], $tag, $meta['marker_column'] ?? null);
        }

        return e2eBsyncResult($checks, ['restore' => $restore]);
    });

    $results[] = e2eBsyncScenario('automatic_poll_hosted_change_to_local', function () use ($branchConn, $cloudConn, $branchConfig, $cloudConfig, $tag) {
        $clientId = e2eBsyncInsertRow($cloudConn, 'delivery_clients', [
            'client_name' => $tag . '-cloud-client',
            'phone' => '0599' . random_int(100000, 999999),
            'address' => 'cloud street',
            'isdeleted' => 0,
        ]);
        $branchConn->query('DELETE FROM delivery_clients WHERE id = ' . (int) $clientId);

        $published = (new OperationalSyncEventService())->recordRowSnapshot($cloudConn, 'delivery_client', $clientId, [
            'config' => $cloudConfig,
            'source_system' => 'e2e_cloud',
        ]);

        $metrics = (new BranchCloudSyncPollWorker())->runOnce($branchConn, $branchConfig, [
            'batch_size' => 25,
        ]);
        $local = e2eBsyncFetchRow($branchConn, 'delivery_clients', $clientId);

        return e2eBsyncResult([
            $published !== null,
            !empty($published['cloud_branch_events']),
            (int) ($metrics['applied'] ?? 0) >= 1,
            $local && strpos((string) ($local['client_name'] ?? ''), $tag . '-cloud-client') !== false,
        ], ['client_id' => $clientId, 'published' => $published, 'poller' => $metrics]);
    });

    $summary = [
        'run_id' => $runId,
        'branch_uuid' => $branchUuid,
        'databases' => ['branch' => $branchDb, 'cloud' => $cloudDb],
        'cloud_url' => $cloudUrl,
        'seed' => $seed,
        'results' => $results,
        'pass' => !e2eBsyncHasFailures($results),
    ];
    $report = $tmpRoot . '/report.json';
    file_put_contents($report, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode($summary + ['report_path' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $branchConn->close();
    $cloudConn->close();
    exit($summary['pass'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
} finally {
    foreach ($children as $child) {
        e2eBsyncStopServer($child);
    }
}

function e2eBsyncScenario(string $name, callable $runner): array
{
    try {
        $result = $runner();
        $result['name'] = $name;
        return $result;
    } catch (Throwable $e) {
        return [
            'name' => $name,
            'pass' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function e2eBsyncResult(array $checks, array $details = []): array
{
    return [
        'pass' => !in_array(false, $checks, true),
        'checks' => array_values($checks),
        'details' => $details,
    ];
}

function e2eBsyncHasFailures(array $results): bool
{
    foreach ($results as $result) {
        if (empty($result['pass'])) {
            return true;
        }
    }
    return false;
}

function e2eBsyncDbHost(): string
{
    return getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
}

function e2eBsyncDbPort(): int
{
    return (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
}

function e2eBsyncConnect(string $db): mysqli
{
    $conn = new mysqli(e2eBsyncDbHost(), getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root', getenv('POSMAIN_TEST_MYSQL_PASS') ?: '', $db, e2eBsyncDbPort());
    $conn->set_charset('utf8mb4');
    return $conn;
}

function e2eBsyncCloneSchema(string $branchDb, string $cloudDb): void
{
    $source = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';
    $sql = 'DROP DATABASE IF EXISTS ' . $branchDb . '; DROP DATABASE IF EXISTS ' . $cloudDb
        . '; CREATE DATABASE ' . $branchDb . '; CREATE DATABASE ' . $cloudDb . ';';
    $initCmd = sprintf(
        'docker exec posmain-mysql mariadb -uroot -e %s',
        escapeshellarg($sql)
    );
    exec($initCmd, $out1, $code1);
    if ($code1 !== 0) {
        throw new RuntimeException('Failed to create e2e databases: ' . implode("\n", $out1));
    }

    foreach ([$branchDb, $cloudDb] as $targetDb) {
        $dumpCmd = sprintf(
            'docker exec posmain-mysql sh -c %s',
            escapeshellarg('mariadb-dump -uroot --no-data ' . $source . ' | mariadb -uroot ' . $targetDb)
        );
        exec($dumpCmd, $out2, $code2);
        if ($code2 !== 0) {
            throw new RuntimeException('Failed to clone schema into ' . $targetDb . ': ' . implode("\n", $out2));
        }
    }
}

function e2eBsyncRegisterPairing(mysqli $branchConn, mysqli $cloudConn, string $branchUuid, string $secret, string $tag): void
{
    $hash = hash('sha256', $secret);
    $name = $tag . ' Branch';
    $cloudUrlPlaceholder = 'http://127.0.0.1:1';

    $stmt = $cloudConn->prepare("
        INSERT INTO cloud_branches (branch_uuid, branch_name, status, sync_secret_hash)
        VALUES (?, ?, 'active', ?)
        ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name), status = 'active', sync_secret_hash = VALUES(sync_secret_hash)
    ");
    $stmt->bind_param('sss', $branchUuid, $name, $hash);
    $stmt->execute();
    $stmt->close();

    $branchConn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    $stmt = $branchConn->prepare("
        INSERT INTO sync_branch_identity (id, branch_uuid, branch_name, pos_tenant, pos_branch, cloud_base_url, current_menu_version)
        VALUES (1, ?, ?, 0, 0, ?, 0)
    ");
    $stmt->bind_param('sss', $branchUuid, $name, $cloudUrlPlaceholder);
    $stmt->execute();
    $stmt->close();
}

function e2eBsyncBranchConfig(string $branchUuid, string $secret, string $cloudUrl): array
{
    return posmain_app_config([
        'role' => 'branch',
        'branch' => [
            'uuid' => $branchUuid,
            'name' => 'E2E Branch',
            'cloud_base_url' => $cloudUrl,
        ],
        'sync' => [
            'branch_secret' => $secret,
            'branch_sync_enabled' => true,
            'worker_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
            'cloud_pull_enabled' => true,
            'cloud_apply_enabled' => true,
            'legacy_pos_mirror_enabled' => true,
        ],
    ]);
}

function e2eBsyncCloudConfig(string $branchUuid, string $secret): array
{
    return posmain_app_config([
        'role' => 'fake_cloud',
        'branch' => [
            'uuid' => $branchUuid,
        ],
        'sync' => [
            'cloud_branch_secrets' => [$branchUuid => $secret],
            'cloud_apply_enabled' => true,
            'legacy_pos_mirror_enabled' => true,
            'cloud_to_branch_publish_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ]);
}

function e2eBsyncSeedBranch(mysqli $conn, string $tag): array
{
    $seed = [];

    $categoryId = e2eBsyncInsertRow($conn, 'item_group', ['gname' => $tag . '-cat', 'isdeleted' => 0]);
    $seed['item_category'] = ['table' => 'item_group', 'id' => $categoryId, 'marker_column' => 'gname'];

    $itemId = e2eBsyncInsertRow($conn, 'myitems', [
        'iname' => $tag . '-item',
        'barcode' => 'E2E' . random_int(10000, 99999),
        'group1' => $categoryId,
        'price1' => 12.5,
        'price2' => 12.5,
        'price3' => 12.5,
        'cost_price' => 4.0,
        'market_price' => 12.5,
        'last_price' => 0,
        'isdeleted' => 0,
    ]);
    $seed['menu_item'] = ['table' => 'myitems', 'id' => $itemId, 'marker_column' => 'iname'];

    $tableId = e2eBsyncInsertRow($conn, 'tables', ['tname' => $tag . '-table', 'isdeleted' => 0]);
    $seed['table'] = ['table' => 'tables', 'id' => $tableId, 'marker_column' => 'tname'];

    $zoneId = e2eBsyncInsertRow($conn, 'delivery_zones', [
        'name' => $tag . '-zone',
        'fee' => '5.000',
        'is_active' => 1,
        'sort_order' => 1,
        'tenant' => 0,
        'branch' => 0,
    ]);
    $seed['delivery_zone'] = ['table' => 'delivery_zones', 'id' => $zoneId, 'marker_column' => 'name'];

    $clientId = e2eBsyncInsertRow($conn, 'delivery_clients', [
        'client_name' => $tag . '-client',
        'phone' => '0598' . random_int(100000, 999999),
        'address' => 'local street',
        'isdeleted' => 0,
    ]);
    $seed['delivery_client'] = ['table' => 'delivery_clients', 'id' => $clientId, 'marker_column' => 'client_name'];

    if (e2eBsyncTableExists($conn, 'payment_methods')) {
        $pmId = e2eBsyncInsertRow($conn, 'payment_methods', [
            'code' => 'e2e_' . random_int(1000, 9999),
            'name_ar' => $tag . '-cash',
            'type' => 'cash',
            'is_active' => 1,
            'sort_order' => 1,
        ]);
        $seed['payment_method'] = ['table' => 'payment_methods', 'id' => $pmId, 'marker_column' => 'name_ar'];
    }

    if (e2eBsyncTableExists($conn, 'modifier_groups')) {
        $groupId = e2eBsyncInsertRow($conn, 'modifier_groups', [
            'name_ar' => $tag . '-mod',
            'name_en' => $tag . '-mod-en',
            'selection_min' => 0,
            'selection_max' => 2,
            'is_required' => 0,
            'is_active' => 1,
            'tenant' => 0,
            'branch' => 0,
            'sort_order' => 1,
        ]);
        $seed['modifier_group'] = ['table' => 'modifier_groups', 'id' => $groupId, 'marker_column' => 'name_ar'];
    }

    if (e2eBsyncTableExists($conn, 'settings')) {
        $existing = (int) ($conn->query('SELECT COUNT(*) AS c FROM settings WHERE id = 1')->fetch_assoc()['c'] ?? 0);
        if ($existing === 0) {
            e2eBsyncInsertRow($conn, 'settings', ['id' => 1, 'company_name' => $tag . '-shop']);
        } else {
            $conn->query("UPDATE settings SET company_name = '" . $conn->real_escape_string($tag . '-shop') . "' WHERE id = 1");
        }
        $seed['shop_settings'] = ['table' => 'settings', 'id' => 1, 'marker_column' => 'company_name'];
    }

    if (e2eBsyncTableExists($conn, 'moova_pos_shop_links')) {
        $linkId = e2eBsyncInsertRow($conn, 'moova_pos_shop_links', [
            'moova_shop_id' => $tag . '-shop-id',
            'moova_branch_id' => $tag . '-branch-id',
            'moova_device_token_hash' => hash('sha256', $tag . '-token'),
            'status' => 'active',
        ]);
        $seed['moova_shop_link'] = ['table' => 'moova_pos_shop_links', 'id' => $linkId, 'marker_column' => 'moova_shop_id'];
    }

    if (e2eBsyncTableExists($conn, 'closed_orders')) {
        $closeId = e2eBsyncInsertRow($conn, 'closed_orders', [
            'shift' => $tag,
            'date' => date('Y-m-d'),
            'strttime' => date('Y-m-d') . ' 08:00:00',
            'endtime' => '16:00:00',
            'total_sales' => 500,
            'delevery' => 0,
            'tables' => 0,
            'takeaway' => 0,
            'expenses' => 0,
            'fund_before' => 0,
            'fund_after' => 100,
            'cash' => 100,
            'tenant' => 1,
            'branch' => 1,
        ]);
        $seed['closed_order'] = ['table' => 'closed_orders', 'id' => $closeId, 'marker_column' => 'shift'];
    }

    $orderId = e2eBsyncInsertRow($conn, 'ot_head', [
        'pro_tybe' => 9,
        'pro_date' => date('Y-m-d'),
        'isdeleted' => 0,
    ]);
    $seed['order'] = ['table' => 'ot_head', 'id' => $orderId];

    return $seed;
}

function e2eBsyncInsertRow(mysqli $conn, string $table, array $row): int
{
    $columns = e2eBsyncTableColumns($conn, $table);
    $fields = [];
    $values = [];
    foreach ($row as $column => $value) {
        if (!in_array($column, $columns, true)) {
            continue;
        }
        $fields[] = '`' . $column . '`';
        $values[] = $value;
    }
    if ($fields === []) {
        throw new RuntimeException('No valid columns for insert into ' . $table);
    }
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($values));
    $refs = [];
    foreach ($values as $k => &$v) {
        $refs[$k] = &$v;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

function e2eBsyncTableColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['Field'];
    }
    return $columns;
}

function e2eBsyncTableExists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return $result && $result->num_rows > 0;
}

function e2eBsyncFetchRow(mysqli $conn, string $table, int $id): ?array
{
    if ($id <= 0 || !e2eBsyncTableExists($conn, $table)) {
        return null;
    }
    return $conn->query('SELECT * FROM `' . $table . '` WHERE id = ' . $id)->fetch_assoc() ?: null;
}

function e2eBsyncRowExists(mysqli $conn, string $table, int $id, string $tag, ?string $markerColumn): bool
{
    $row = e2eBsyncFetchRow($conn, $table, $id);
    if (!$row) {
        return false;
    }
    if ($markerColumn && array_key_exists($markerColumn, $row)) {
        return strpos((string) $row[$markerColumn], $tag) !== false;
    }
    return true;
}

function e2eBsyncCount(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc();
    return (int) ($row['c'] ?? $row['COUNT(*)'] ?? 0);
}

function e2eBsyncStartCloudServer(int $port, string $cloudDb, string $branchUuid, string $secret, string $tmpRoot): array
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork cloud server');
    }
    if ($pid === 0) {
        $env = [
            'POSMAIN_ROLE' => 'fake_cloud',
            'POSMAIN_DB_HOST' => e2eBsyncDbHost(),
            'POSMAIN_DB_PORT' => (string) e2eBsyncDbPort(),
            'POSMAIN_DB_NAME' => $cloudDb,
            'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
            'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
            'POSMAIN_CLOUD_APPLY_ENABLED' => '1',
            'POSMAIN_CLOUD_LEGACY_POS_MIRROR_ENABLED' => '1',
            'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED' => '1',
            'POSMAIN_OPERATIONAL_SYNC_ENABLED' => '1',
            'POSMAIN_BRANCH_UUID' => $branchUuid,
            'POSMAIN_CLOUD_BRANCH_SECRETS' => $branchUuid . '=' . $secret,
            'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
        ];
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
        chdir(dirname(__DIR__));
        $router = __DIR__ . '/e2e_pos_sync_router.php';
        $log = fopen($tmpRoot . '/cloud-server.log', 'a');
        fclose($log);
        cli_set_process_title('posmain-e2e-cloud');
        passthru(PHP_BINARY . ' -d display_errors=0 -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router) . ' 2>/dev/null');
        exit(0);
    }

    e2eBsyncWaitForPort($port);
    return ['pid' => $pid, 'port' => $port];
}

function e2eBsyncStopServer(array $server): void
{
    if (empty($server['pid'])) {
        return;
    }
    @posix_kill((int) $server['pid'], SIGTERM);
    pcntl_waitpid((int) $server['pid'], $status, WNOHANG);
}

function e2eBsyncFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($server, false);
    fclose($server);
    return (int) substr(strrchr($name, ':'), 1);
}

function e2eBsyncWaitForPort(int $port): void
{
    $deadline = microtime(true) + 5;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($socket) {
            fclose($socket);
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Cloud server did not start on port ' . $port);
}

function e2eBsyncLog(string $tmpRoot, string $event, array $context = []): void
{
    file_put_contents(
        $tmpRoot . '/trace.jsonl',
        json_encode(['ts' => gmdate('c'), 'event' => $event] + $context, JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

function assertE2eBidirectionalRequirements(): void
{
    $missing = [];
    foreach (['curl', 'mysqli', 'pcntl', 'posix'] as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    if ($missing) {
        fwrite(STDERR, 'Missing PHP extensions: ' . implode(', ', $missing) . PHP_EOL);
        exit(2);
    }
}
