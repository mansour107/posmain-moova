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
    'status::',
    'branch-uuid::',
    'id::',
    'include-payloads',
    'resolve::',
    'resolution-status::',
    'notes::',
    'dry-run',
]);

if (isset($options['help'])) {
    syncConflictUsage();
    exit(0);
}

try {
    $conn = posmain_db_connect();
    if (!syncConflictTableExists($conn)) {
        $result = [
            'ok' => false,
            'error' => 'sync_conflicts_missing',
            'message' => 'sync_conflicts table does not exist. Run sync migrations first.',
        ];
    } elseif (isset($options['resolve'])) {
        $result = syncConflictResolve($conn, $options);
    } else {
        $result = syncConflictList($conn, $options);
    }
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
    syncConflictHuman($result);
}

exit(empty($result['ok']) ? 2 : 0);

function syncConflictUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/sync_conflict_tool.php [--json] [--status=open|ignored|resolved|remote_rejected|local_rejected|all] [--limit=20] [--branch-uuid=UUID] [--id=ID] [--include-payloads]\n");
    fwrite(STDOUT, "       php tools/sync_conflict_tool.php --resolve=ID --resolution-status=ignored|resolved|remote_rejected|local_rejected [--notes=text] [--dry-run] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Lists sync_conflicts by default. Resolution is explicit, single-row, and only updates open sync_conflicts rows.\n");
}

function syncConflictList(mysqli $conn, array $options): array
{
    $status = syncConflictStatus((string) ($options['status'] ?? 'open'), true);
    if ($status === null) {
        return syncConflictError('invalid_status', 'Use --status=open|ignored|resolved|remote_rejected|local_rejected|all.');
    }

    $branchUuid = syncConflictOptionalUuid($options['branch-uuid'] ?? null);
    if (($options['branch-uuid'] ?? null) !== null && $branchUuid === null) {
        return syncConflictError('invalid_branch_uuid', 'Use a valid UUID for --branch-uuid.');
    }

    $id = syncConflictOptionalPositiveInt($options['id'] ?? null);
    if (($options['id'] ?? null) !== null && $id === null) {
        return syncConflictError('invalid_id', 'Use a positive integer for --id.');
    }

    $limit = syncConflictBoundedInt($options['limit'] ?? 20, 1, 100);
    $includePayloads = isset($options['include-payloads']);

    return [
        'ok' => true,
        'action' => 'list',
        'status_filter' => $status,
        'branch_uuid_filter' => $branchUuid,
        'id_filter' => $id,
        'limit' => $limit,
        'counts_by_status' => syncConflictCounts($conn),
        'conflicts' => syncConflictRows($conn, $status, $branchUuid, $id, $limit, $includePayloads),
    ];
}

function syncConflictResolve(mysqli $conn, array $options): array
{
    $id = syncConflictOptionalPositiveInt($options['resolve'] ?? null);
    if ($id === null) {
        return syncConflictError('invalid_resolve_id', 'Use a positive integer for --resolve.');
    }

    $resolutionStatus = syncConflictStatus((string) ($options['resolution-status'] ?? ''), false);
    if ($resolutionStatus === null || $resolutionStatus === 'open') {
        return syncConflictError('invalid_resolution_status', 'Use --resolution-status=ignored|resolved|remote_rejected|local_rejected.');
    }

    $row = syncConflictRowById($conn, $id);
    if ($row === null) {
        return syncConflictError('conflict_not_found', 'No sync_conflicts row exists for the requested id.');
    }

    $notes = isset($options['notes']) ? trim((string) $options['notes']) : null;
    if ($notes === '') {
        $notes = null;
    }

    if (isset($options['dry-run'])) {
        return [
            'ok' => true,
            'action' => 'would_resolve',
            'id' => $id,
            'resolution_status' => $resolutionStatus,
            'notes' => $notes,
            'conflict' => $row,
        ];
    }

    $stmt = $conn->prepare("
        UPDATE sync_conflicts
           SET resolution_status = ?,
               resolution_notes = ?,
               resolved_at = NOW(6)
         WHERE id = ?
           AND resolution_status = 'open'
    ");
    $stmt->bind_param('ssi', $resolutionStatus, $notes, $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        return [
            'ok' => false,
            'error' => 'conflict_not_open',
            'message' => 'Only open conflicts can be resolved by this tool.',
            'conflict' => syncConflictRowById($conn, $id),
        ];
    }

    return [
        'ok' => true,
        'action' => 'resolved',
        'id' => $id,
        'resolution_status' => $resolutionStatus,
        'conflict' => syncConflictRowById($conn, $id),
    ];
}

function syncConflictTableExists(mysqli $conn): bool
{
    $result = $conn->query("
        SELECT COUNT(*) AS c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'sync_conflicts'
    ");
    $row = $result->fetch_assoc();

    return (int) ($row['c'] ?? 0) > 0;
}

function syncConflictCounts(mysqli $conn): array
{
    $rows = syncConflictFetchRows($conn, "
        SELECT resolution_status AS name, COUNT(*) AS count
        FROM sync_conflicts
        GROUP BY resolution_status
    ");
    $counts = [];
    foreach ($rows as $row) {
        $counts[(string) $row['name']] = (int) $row['count'];
    }

    return $counts;
}

function syncConflictRows(mysqli $conn, string $status, ?string $branchUuid, ?int $id, int $limit, bool $includePayloads): array
{
    $where = [];
    if ($status !== 'all') {
        $where[] = "resolution_status = '" . $conn->real_escape_string($status) . "'";
    }
    if ($branchUuid !== null) {
        $where[] = "branch_uuid = '" . $conn->real_escape_string($branchUuid) . "'";
    }
    if ($id !== null) {
        $where[] = 'id = ' . $id;
    }

    $payloadColumns = $includePayloads ? ", local_payload_json, remote_payload_json" : '';
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    return syncConflictFetchRows($conn, "
        SELECT id,
               branch_uuid,
               conflict_type,
               aggregate_type,
               aggregate_uuid,
               local_entity_id,
               remote_entity_id,
               local_revision,
               remote_revision,
               resolution_status,
               resolution_notes,
               created_at,
               resolved_at,
               OCTET_LENGTH(local_payload_json) AS local_payload_bytes,
               OCTET_LENGTH(remote_payload_json) AS remote_payload_bytes
               {$payloadColumns}
          FROM sync_conflicts
          {$whereSql}
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}
    ");
}

function syncConflictRowById(mysqli $conn, int $id): ?array
{
    $rows = syncConflictRows($conn, 'all', null, $id, 1, false);

    return $rows[0] ?? null;
}

function syncConflictFetchRows(mysqli $conn, string $sql): array
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

function syncConflictStatus(string $status, bool $allowAll): ?string
{
    $status = trim($status);
    $allowed = ['open', 'ignored', 'resolved', 'remote_rejected', 'local_rejected'];
    if ($allowAll) {
        $allowed[] = 'all';
    }

    return in_array($status, $allowed, true) ? $status : null;
}

function syncConflictOptionalUuid($value): ?string
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }

    $value = trim((string) $value);

    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
}

function syncConflictOptionalPositiveInt($value): ?int
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }

    $intValue = (int) $value;

    return $intValue > 0 ? $intValue : null;
}

function syncConflictBoundedInt($value, int $min, int $max): int
{
    if (!is_scalar($value)) {
        return $min;
    }

    return max($min, min($max, (int) $value));
}

function syncConflictError(string $error, string $message): array
{
    return [
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ];
}

function syncConflictHuman(array $result): void
{
    if (empty($result['ok'])) {
        fwrite(STDOUT, "Sync conflict tool: unavailable\n");
        fwrite(STDOUT, '- ' . (string) ($result['message'] ?? $result['error'] ?? 'unknown error') . "\n");
        return;
    }

    if (($result['action'] ?? '') === 'resolved') {
        fwrite(STDOUT, 'Sync conflict ' . (int) $result['id'] . ' resolved as ' . (string) $result['resolution_status'] . ".\n");
        return;
    }

    if (($result['action'] ?? '') === 'would_resolve') {
        fwrite(STDOUT, 'Dry run: sync conflict ' . (int) $result['id'] . ' would be resolved as ' . (string) $result['resolution_status'] . ".\n");
        return;
    }

    $conflicts = $result['conflicts'] ?? [];
    fwrite(STDOUT, 'Sync conflicts: ' . count($conflicts) . ' row(s)');
    fwrite(STDOUT, ' status=' . (string) ($result['status_filter'] ?? 'open'));
    fwrite(STDOUT, "\n");

    foreach ($conflicts as $row) {
        fwrite(
            STDOUT,
            '- #' . (int) $row['id']
            . ' ' . (string) $row['resolution_status']
            . ' ' . (string) $row['conflict_type']
            . ' aggregate=' . (string) ($row['aggregate_type'] ?? '')
            . ' remote=' . (string) ($row['remote_entity_id'] ?? '')
            . ' created_at=' . (string) ($row['created_at'] ?? '')
            . "\n"
        );
    }
}
