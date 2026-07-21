<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';

if (!defined('POSMAIN_BRANCH_WORKER_STATUS_LIBRARY')) {
    branchWorkerStatusCli();
}

function branchWorkerStatusCli(): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This tool must be run from the command line.\n");
        exit(1);
    }

    $options = getopt('', [
        'json',
        'help',
        'limit::',
        'recent-minutes::',
        'fail-on-problems',
    ]);

    if (isset($options['help'])) {
        branchWorkerStatusUsage();
        exit(0);
    }

    $limit = isset($options['limit']) ? max(1, min(50, (int) $options['limit'])) : 10;
    $recentMinutes = isset($options['recent-minutes']) ? max(0, min(10080, (int) $options['recent-minutes'])) : 60;

    try {
        $conn = posmain_db_connect();
        $report = branchWorkerStatusReport($conn, $limit, $recentMinutes);
        $conn->close();
    } catch (Throwable $e) {
        $report = branchWorkerStatusUnavailable($e);
    }

    if (isset($options['json'])) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        branchWorkerStatusHuman($report);
    }

    if (empty($report['ok'])) {
        exit(2);
    }

    exit(isset($options['fail-on-problems']) && empty($report['healthy']) ? 2 : 0);
}

function branchWorkerStatusUnavailable(Throwable $e): array
{
    return [
        'ok' => false,
        'healthy' => false,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'error' => 'db_connect_failed',
        'message' => $e->getMessage(),
        'problems' => ['database_unreachable'],
    ];
}

function branchWorkerStatusUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/branch_worker_status.php [--json] [--limit=10] [--recent-minutes=60] [--fail-on-problems]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Read-only status report for branch sync workers, sync_outbox, Moova inbound/ack queues, and recent worker logs.\n");
    fwrite(STDOUT, "--recent-minutes controls which failed worker logs count as current problems; use 0 to include all history.\n");
    fwrite(STDOUT, "Default exit code is 0 when the report can be generated; --fail-on-problems exits 2 when stuck/failed work is detected.\n");
}

function branchWorkerStatusReport(mysqli $conn, int $limit, int $recentMinutes): array
{
    $requiredTables = ['sync_outbox', 'moova_pos_inbound_events', 'sync_worker_logs'];
    $missing = [];
    foreach ($requiredTables as $table) {
        if (!branchWorkerStatusTableExists($conn, $table)) {
            $missing[] = $table;
        }
    }

    $checks = [
        'tables' => [
            'ok' => empty($missing),
            'required' => $requiredTables,
            'missing' => $missing,
        ],
    ];
    $problems = [];

    if ($missing) {
        foreach ($missing as $table) {
            $problems[] = 'table_missing_' . $table;
        }
    } else {
        $checks['sync_outbox'] = branchWorkerStatusOutbox($conn, $limit);
        $checks['moova_inbound'] = branchWorkerStatusMoovaInbound($conn, $limit);
        $checks['worker_logs'] = branchWorkerStatusWorkerLogs($conn, $limit, $recentMinutes);

        $problems = array_merge(
            $problems,
            branchWorkerStatusProblems($checks['sync_outbox'], 'outbox'),
            branchWorkerStatusProblems($checks['moova_inbound'], 'moova'),
            branchWorkerStatusLogProblems($checks['worker_logs'])
        );
    }

    if (branchWorkerStatusTableExists($conn, 'moova_catalog_sync_outbox')) {
        $checks['moova_catalog'] = branchWorkerStatusMoovaCatalog($conn, $limit);
        $problems = array_merge($problems, branchWorkerStatusProblems($checks['moova_catalog'], 'moova_catalog'));
        if ((int) ($checks['moova_catalog']['retry_errors'] ?? 0) > 0) {
            $problems[] = 'moova_catalog_retry_errors';
        }
    } else {
        $checks['moova_catalog'] = ['available' => false];
    }

    $problems = array_values(array_unique($problems));

    return [
        'ok' => true,
        'healthy' => empty($problems),
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'database' => branchWorkerStatusDatabaseSummary(),
        'recent_minutes' => $recentMinutes,
        'checks' => $checks,
        'problems' => $problems,
    ];
}

function branchWorkerStatusTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['c'] ?? 0) > 0;
}

function branchWorkerStatusOutbox(mysqli $conn, int $limit): array
{
    return [
        'counts_by_status' => branchWorkerStatusCounts($conn, 'sync_outbox', 'status'),
        'retryable_due' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM sync_outbox
            WHERE status IN ('pending','failed')
              AND (next_retry_at IS NULL OR next_retry_at <= NOW(6))
        "),
        'expired_syncing_locks' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM sync_outbox
            WHERE status = 'syncing'
              AND locked_until IS NOT NULL
              AND locked_until < NOW(6)
        "),
        'oldest_unsynced_at' => branchWorkerStatusNullableScalar($conn, "
            SELECT MIN(created_at) AS value
            FROM sync_outbox
            WHERE status IN ('pending','failed','syncing')
        "),
        'recent_errors' => branchWorkerStatusRows($conn, "
            SELECT id, status, attempts, last_error, updated_at
            FROM sync_outbox
            WHERE last_error IS NOT NULL
            ORDER BY updated_at DESC, id DESC
            LIMIT {$limit}
        "),
    ];
}

function branchWorkerStatusMoovaInbound(mysqli $conn, int $limit): array
{
    return [
        'counts_by_status' => branchWorkerStatusCounts($conn, 'moova_pos_inbound_events', 'status'),
        'pending_apply' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM moova_pos_inbound_events
            WHERE status IN ('received','failed')
               OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < NOW(6))
        "),
        'expired_processing_locks' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM moova_pos_inbound_events
            WHERE status = 'processing'
              AND locked_until IS NOT NULL
              AND locked_until < NOW(6)
        "),
        'pending_cloud_ack' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM moova_pos_inbound_events
            WHERE status IN ('applied','declined','failed')
              AND (cloud_ack_status IS NULL OR cloud_ack_status = 'failed')
        "),
        'failed_cloud_ack' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM moova_pos_inbound_events
            WHERE cloud_ack_status = 'failed'
        "),
        'recent_errors' => branchWorkerStatusRows($conn, "
            SELECT id, status, event_type, cloud_ack_status, attempt_count, cloud_ack_attempt_count, error_message, cloud_ack_error, received_at
            FROM moova_pos_inbound_events
            WHERE error_message IS NOT NULL OR cloud_ack_error IS NOT NULL
            ORDER BY COALESCE(cloud_ack_last_attempt_at, last_attempt_at, received_at) DESC, id DESC
            LIMIT {$limit}
        "),
    ];
}

function branchWorkerStatusMoovaCatalog(mysqli $conn, int $limit): array
{
    return [
        'available' => true,
        'counts_by_status' => branchWorkerStatusCounts($conn, 'moova_catalog_sync_outbox', 'state'),
        'retryable_due' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM moova_catalog_sync_outbox
            WHERE state = 'pending' AND available_at <= NOW()
        "),
        'expired_syncing_locks' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM moova_catalog_sync_outbox
            WHERE state = 'processing'
              AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        "),
        'retry_errors' => branchWorkerStatusScalar($conn, "
            SELECT COUNT(*) AS value
            FROM moova_catalog_sync_outbox
            WHERE attempts > 0 AND last_error IS NOT NULL
        "),
        'recent_errors' => branchWorkerStatusRows($conn, "
            SELECT id, shop_link_id, state, attempts, last_error, available_at, updated_at
            FROM moova_catalog_sync_outbox
            WHERE last_error IS NOT NULL
            ORDER BY updated_at DESC, id DESC
            LIMIT {$limit}
        "),
    ];
}

function branchWorkerStatusWorkerLogs(mysqli $conn, int $limit, int $recentMinutes): array
{
    $rows = branchWorkerStatusRows($conn, "
        SELECT worker_name, run_uuid, status, message, metrics_json, created_at
        FROM sync_worker_logs
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ");
    $latestByWorker = [];
    foreach ($rows as $row) {
        $worker = (string) ($row['worker_name'] ?? '');
        if ($worker !== '' && !isset($latestByWorker[$worker])) {
            $latestByWorker[$worker] = $row;
        }
    }

    $failedWhere = "status = 'failed'";
    if ($recentMinutes > 0) {
        $failedWhere .= " AND created_at >= DATE_SUB(NOW(6), INTERVAL {$recentMinutes} MINUTE)";
    }

    return [
        'latest' => array_values($latestByWorker),
        'recent_failed' => branchWorkerStatusRows($conn, "
            SELECT worker_name, run_uuid, status, message, metrics_json, created_at
            FROM sync_worker_logs
            WHERE {$failedWhere}
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
        "),
        'recent_minutes' => $recentMinutes,
    ];
}

function branchWorkerStatusCounts(mysqli $conn, string $table, string $column): array
{
    $rows = branchWorkerStatusRows($conn, "SELECT {$column} AS name, COUNT(*) AS count FROM {$table} GROUP BY {$column}");
    $counts = [];
    foreach ($rows as $row) {
        $counts[(string) $row['name']] = (int) $row['count'];
    }

    return $counts;
}

function branchWorkerStatusScalar(mysqli $conn, string $sql): int
{
    return (int) branchWorkerStatusNullableScalar($conn, $sql);
}

function branchWorkerStatusNullableScalar(mysqli $conn, string $sql)
{
    $row = $conn->query($sql)->fetch_assoc();

    return $row['value'] ?? null;
}

function branchWorkerStatusRows(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(function ($value) {
            return is_numeric($value) && preg_match('/^-?\d+$/', (string) $value) ? (int) $value : $value;
        }, $row);
    }

    return $rows;
}

function branchWorkerStatusProblems(array $check, string $prefix): array
{
    $problems = [];
    foreach ([
        'retryable_due',
        'expired_syncing_locks',
        'pending_apply',
        'expired_processing_locks',
        'pending_cloud_ack',
        'failed_cloud_ack',
    ] as $key) {
        if ((int) ($check[$key] ?? 0) > 0) {
            $problems[] = $prefix . '_' . $key;
        }
    }

    $failed = (int) ($check['counts_by_status']['failed'] ?? 0);
    if ($failed > 0) {
        $problems[] = $prefix . '_failed_rows';
    }

    $dead = (int) ($check['counts_by_status']['dead'] ?? 0);
    if ($dead > 0) {
        $problems[] = $prefix . '_dead_rows';
    }

    return $problems;
}

function branchWorkerStatusLogProblems(array $check): array
{
    return empty($check['recent_failed']) ? [] : ['recent_worker_failures'];
}

function branchWorkerStatusDatabaseSummary(): array
{
    $config = function_exists('posmain_app_config') ? posmain_app_config() : [];
    $db = $config['database'] ?? [];

    return [
        'host' => (string) ($db['host'] ?? ''),
        'port' => (int) ($db['port'] ?? 0),
        'name' => (string) ($db['name'] ?? ''),
        'user' => (string) ($db['user'] ?? ''),
    ];
}

function branchWorkerStatusHuman(array $report): void
{
    if (empty($report['ok'])) {
        fwrite(STDOUT, "Branch worker status: unavailable\n");
        fwrite(STDOUT, "- " . (string) ($report['message'] ?? $report['error'] ?? 'unknown error') . "\n");
        return;
    }

    fwrite(STDOUT, 'Branch worker status: ' . (!empty($report['healthy']) ? 'healthy' : 'attention needed') . "\n");
    $recentMinutes = (int) ($report['recent_minutes'] ?? 0);
    $failureWindow = $recentMinutes === 0 ? 'all history' : 'last ' . $recentMinutes . ' minute(s)';
    fwrite(STDOUT, 'Worker failure window: ' . $failureWindow . "\n");

    if (!empty($report['problems'])) {
        fwrite(STDOUT, "\nProblems:\n");
        foreach ($report['problems'] as $problem) {
            fwrite(STDOUT, "- {$problem}\n");
        }
    }

    $outbox = $report['checks']['sync_outbox'] ?? [];
    fwrite(STDOUT, "\nOutbox:\n");
    fwrite(STDOUT, '- retryable_due: ' . (int) ($outbox['retryable_due'] ?? 0) . "\n");
    fwrite(STDOUT, '- expired_syncing_locks: ' . (int) ($outbox['expired_syncing_locks'] ?? 0) . "\n");

    $moova = $report['checks']['moova_inbound'] ?? [];
    fwrite(STDOUT, "\nMoova inbound:\n");
    fwrite(STDOUT, '- pending_apply: ' . (int) ($moova['pending_apply'] ?? 0) . "\n");
    fwrite(STDOUT, '- pending_cloud_ack: ' . (int) ($moova['pending_cloud_ack'] ?? 0) . "\n");

    $catalog = $report['checks']['moova_catalog'] ?? [];
    fwrite(STDOUT, "\nMoova catalog:\n");
    if (empty($catalog['available'])) {
        fwrite(STDOUT, "- not configured\n");
    } else {
        fwrite(STDOUT, '- retryable_due: ' . (int) ($catalog['retryable_due'] ?? 0) . "\n");
        fwrite(STDOUT, '- retry_errors: ' . (int) ($catalog['retry_errors'] ?? 0) . "\n");
    }

    $logs = $report['checks']['worker_logs']['latest'] ?? [];
    fwrite(STDOUT, "\nLatest worker logs:\n");
    if (!$logs) {
        fwrite(STDOUT, "- none\n");
        return;
    }

    foreach ($logs as $row) {
        fwrite(STDOUT, '- ' . (string) $row['worker_name'] . ' ' . (string) $row['status'] . ' at ' . (string) $row['created_at'] . "\n");
    }
}
