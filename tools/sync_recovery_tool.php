<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'help',
    'json',
    'limit::',
    'branch-uuid::',
    'apply',
    'all',
    'release-expired-outbox-locks',
    'requeue-failed-outbox',
    'release-expired-moova-locks',
    'requeue-failed-moova-apply',
    'requeue-failed-moova-ack',
]);

if (isset($options['help'])) {
    syncRecoveryUsage();
    exit(0);
}

try {
    $conn = posmain_db_connect();
    $result = syncRecoveryRun($conn, $options);
    $conn->close();
} catch (Throwable $e) {
    $result = [
        'ok' => false,
        'error' => 'db_connect_failed',
        'message' => $e->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    syncRecoveryHuman($result);
}

exit(empty($result['ok']) ? 2 : 0);

function syncRecoveryUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/sync_recovery_tool.php [--json] [--limit=25] [--branch-uuid=UUID]\n");
    fwrite(STDOUT, "       php tools/sync_recovery_tool.php --all [--apply] [--json]\n");
    fwrite(STDOUT, "       php tools/sync_recovery_tool.php --release-expired-outbox-locks --requeue-failed-outbox --release-expired-moova-locks --requeue-failed-moova-apply --requeue-failed-moova-ack [--apply] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Without --apply, recovery actions are dry-run only. Mutations are limited to sync_outbox and moova_pos_inbound_events recovery fields.\n");
}

function syncRecoveryRun(mysqli $conn, array $options): array
{
    $branchUuid = syncRecoveryOptionalUuid($options['branch-uuid'] ?? null);
    if (($options['branch-uuid'] ?? null) !== null && $branchUuid === null) {
        return syncRecoveryError('invalid_branch_uuid', 'Use a valid UUID for --branch-uuid.');
    }

    $missing = syncRecoveryMissingTables($conn);
    if ($missing) {
        return [
            'ok' => false,
            'error' => 'recovery_tables_missing',
            'missing_tables' => $missing,
            'message' => 'Run sync migrations before using queue recovery.',
        ];
    }

    $limit = syncRecoveryBoundedInt($options['limit'] ?? 25, 1, 100);
    $apply = isset($options['apply']);
    $actions = syncRecoverySelectedActions($options);
    $report = [
        'ok' => true,
        'dry_run' => !$apply,
        'branch_uuid_filter' => $branchUuid,
        'counts' => syncRecoveryCounts($conn, $branchUuid),
        'samples' => syncRecoverySamples($conn, $branchUuid, $limit),
        'actions' => [],
    ];

    foreach ($actions as $action) {
        $report['actions'][$action] = syncRecoveryAction($conn, $action, $branchUuid, $apply);
    }

    return $report;
}

function syncRecoverySelectedActions(array $options): array
{
    $all = [
        'release_expired_outbox_locks',
        'requeue_failed_outbox',
        'release_expired_moova_locks',
        'requeue_failed_moova_apply',
        'requeue_failed_moova_ack',
    ];

    if (isset($options['all'])) {
        return $all;
    }

    $selected = [];
    $map = [
        'release-expired-outbox-locks' => 'release_expired_outbox_locks',
        'requeue-failed-outbox' => 'requeue_failed_outbox',
        'release-expired-moova-locks' => 'release_expired_moova_locks',
        'requeue-failed-moova-apply' => 'requeue_failed_moova_apply',
        'requeue-failed-moova-ack' => 'requeue_failed_moova_ack',
    ];
    foreach ($map as $flag => $action) {
        if (isset($options[$flag])) {
            $selected[] = $action;
        }
    }

    return $selected;
}

function syncRecoveryMissingTables(mysqli $conn): array
{
    $missing = [];
    foreach (['sync_outbox', 'moova_pos_inbound_events'] as $table) {
        $escaped = $conn->real_escape_string($table);
        $row = $conn->query("
            SELECT COUNT(*) AS c
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$escaped}'
        ")->fetch_assoc();
        if ((int) ($row['c'] ?? 0) < 1) {
            $missing[] = $table;
        }
    }

    return $missing;
}

function syncRecoveryCounts(mysqli $conn, ?string $branchUuid): array
{
    return [
        'outbox_expired_syncing_locks' => syncRecoveryScalar($conn, 'sync_outbox', syncRecoveryWhere('outbox_expired_syncing_locks', $branchUuid)),
        'outbox_failed' => syncRecoveryScalar($conn, 'sync_outbox', syncRecoveryWhere('requeue_failed_outbox', $branchUuid)),
        'moova_expired_processing_locks' => syncRecoveryScalar($conn, 'moova_pos_inbound_events', syncRecoveryWhere('release_expired_moova_locks', $branchUuid)),
        'moova_failed_apply' => syncRecoveryScalar($conn, 'moova_pos_inbound_events', syncRecoveryWhere('requeue_failed_moova_apply', $branchUuid)),
        'moova_failed_cloud_ack' => syncRecoveryScalar($conn, 'moova_pos_inbound_events', syncRecoveryWhere('requeue_failed_moova_ack', $branchUuid)),
    ];
}

function syncRecoverySamples(mysqli $conn, ?string $branchUuid, int $limit): array
{
    return [
        'outbox_expired_syncing_locks' => syncRecoveryRows($conn, "
            SELECT id, branch_uuid, status, attempts, locked_by, locked_until, next_retry_at, last_error
            FROM sync_outbox
            WHERE " . syncRecoveryWhere('outbox_expired_syncing_locks', $branchUuid) . "
            ORDER BY locked_until ASC, id ASC
            LIMIT {$limit}
        "),
        'outbox_failed' => syncRecoveryRows($conn, "
            SELECT id, branch_uuid, status, attempts, locked_by, locked_until, next_retry_at, last_error
            FROM sync_outbox
            WHERE " . syncRecoveryWhere('requeue_failed_outbox', $branchUuid) . "
            ORDER BY updated_at ASC, id ASC
            LIMIT {$limit}
        "),
        'moova_expired_processing_locks' => syncRecoveryRows($conn, "
            SELECT id, branch_uuid, status, event_type, locked_by, locked_until, attempt_count, error_message
            FROM moova_pos_inbound_events
            WHERE " . syncRecoveryWhere('release_expired_moova_locks', $branchUuid) . "
            ORDER BY locked_until ASC, id ASC
            LIMIT {$limit}
        "),
        'moova_failed_apply' => syncRecoveryRows($conn, "
            SELECT id, branch_uuid, status, event_type, locked_by, locked_until, attempt_count, error_message
            FROM moova_pos_inbound_events
            WHERE " . syncRecoveryWhere('requeue_failed_moova_apply', $branchUuid) . "
            ORDER BY last_attempt_at ASC, id ASC
            LIMIT {$limit}
        "),
        'moova_failed_cloud_ack' => syncRecoveryRows($conn, "
            SELECT id, branch_uuid, status, event_type, cloud_ack_status, cloud_ack_attempt_count, cloud_ack_error
            FROM moova_pos_inbound_events
            WHERE " . syncRecoveryWhere('requeue_failed_moova_ack', $branchUuid) . "
            ORDER BY cloud_ack_last_attempt_at ASC, id ASC
            LIMIT {$limit}
        "),
    ];
}

function syncRecoveryAction(mysqli $conn, string $action, ?string $branchUuid, bool $apply): array
{
    $table = in_array($action, ['release_expired_outbox_locks', 'requeue_failed_outbox'], true)
        ? 'sync_outbox'
        : 'moova_pos_inbound_events';
    $where = syncRecoveryWhere($action, $branchUuid);
    $count = syncRecoveryScalar($conn, $table, $where);

    if (!$apply) {
        return [
            'applied' => false,
            'would_update' => $count,
        ];
    }

    $sql = syncRecoveryUpdateSql($action, $where);
    $conn->query($sql);

    return [
        'applied' => true,
        'matched_before_update' => $count,
        'updated' => $conn->affected_rows,
    ];
}

function syncRecoveryUpdateSql(string $action, string $where): string
{
    if ($action === 'release_expired_outbox_locks') {
        return "
            UPDATE sync_outbox
               SET status = 'pending',
                   locked_by = NULL,
                   locked_until = NULL,
                   next_retry_at = NOW(6)
             WHERE {$where}
        ";
    }

    if ($action === 'requeue_failed_outbox') {
        return "
            UPDATE sync_outbox
               SET status = 'pending',
                   locked_by = NULL,
                   locked_until = NULL,
                   next_retry_at = NOW(6),
                   last_error = NULL
             WHERE {$where}
        ";
    }

    if ($action === 'release_expired_moova_locks') {
        return "
            UPDATE moova_pos_inbound_events
               SET status = 'received',
                   locked_by = NULL,
                   locked_until = NULL
             WHERE {$where}
        ";
    }

    if ($action === 'requeue_failed_moova_apply') {
        return "
            UPDATE moova_pos_inbound_events
               SET status = 'received',
                   locked_by = NULL,
                   locked_until = NULL,
                   error_message = NULL
             WHERE {$where}
        ";
    }

    if ($action === 'requeue_failed_moova_ack') {
        return "
            UPDATE moova_pos_inbound_events
               SET cloud_ack_status = NULL,
                   cloud_ack_error = NULL
             WHERE {$where}
        ";
    }

    throw new InvalidArgumentException('Unknown recovery action.');
}

function syncRecoveryWhere(string $action, ?string $branchUuid): string
{
    $branchSql = $branchUuid === null ? '' : " AND branch_uuid = '" . addslashes($branchUuid) . "'";

    if ($action === 'outbox_expired_syncing_locks' || $action === 'release_expired_outbox_locks') {
        return "status = 'syncing' AND locked_until IS NOT NULL AND locked_until < NOW(6){$branchSql}";
    }

    if ($action === 'requeue_failed_outbox') {
        return "status = 'failed'{$branchSql}";
    }

    if ($action === 'release_expired_moova_locks') {
        return "status = 'processing' AND locked_until IS NOT NULL AND locked_until < NOW(6){$branchSql}";
    }

    if ($action === 'requeue_failed_moova_apply') {
        return "status = 'failed'{$branchSql}";
    }

    if ($action === 'requeue_failed_moova_ack') {
        return "status IN ('applied','declined','failed') AND cloud_ack_status = 'failed'{$branchSql}";
    }

    throw new InvalidArgumentException('Unknown recovery action.');
}

function syncRecoveryScalar(mysqli $conn, string $table, string $where): int
{
    $row = $conn->query("SELECT COUNT(*) AS value FROM {$table} WHERE {$where}")->fetch_assoc();

    return (int) ($row['value'] ?? 0);
}

function syncRecoveryRows(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static function ($value) {
            return is_numeric($value) && preg_match('/^-?\d+$/', (string) $value) ? (int) $value : $value;
        }, $row);
    }

    return $rows;
}

function syncRecoveryOptionalUuid($value): ?string
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }

    $value = trim((string) $value);

    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
}

function syncRecoveryBoundedInt($value, int $min, int $max): int
{
    if (!is_scalar($value)) {
        return $min;
    }

    return max($min, min($max, (int) $value));
}

function syncRecoveryError(string $error, string $message): array
{
    return [
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ];
}

function syncRecoveryHuman(array $result): void
{
    if (empty($result['ok'])) {
        fwrite(STDOUT, "Sync recovery: unavailable\n");
        fwrite(STDOUT, '- ' . (string) ($result['message'] ?? $result['error'] ?? 'unknown error') . "\n");
        return;
    }

    fwrite(STDOUT, 'Sync recovery: ' . (!empty($result['dry_run']) ? 'dry run' : 'apply') . "\n");
    foreach (($result['counts'] ?? []) as $name => $count) {
        fwrite(STDOUT, '- ' . $name . ': ' . (int) $count . "\n");
    }

    if (!empty($result['actions'])) {
        fwrite(STDOUT, "\nActions:\n");
        foreach ($result['actions'] as $action => $details) {
            $count = !empty($details['applied']) ? (int) ($details['updated'] ?? 0) : (int) ($details['would_update'] ?? 0);
            fwrite(STDOUT, '- ' . $action . ': ' . $count . (!empty($details['applied']) ? ' updated' : ' would update') . "\n");
        }
    }
}
