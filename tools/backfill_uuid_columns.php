<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'apply', 'confirm-no-backup', 'batch-size:', 'help']);
if (isset($options['help'])) {
    uuidBackfillUsage();
    exit(0);
}

$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);
if ($dryRun === $apply) {
    uuidBackfillUsage(STDERR);
    exit(1);
}

if ($apply && !isset($options['confirm-no-backup'])) {
    fwrite(STDERR, "--apply requires --confirm-no-backup for this local/dev scaffold.\n");
    exit(1);
}

$batchSize = isset($options['batch-size']) ? max(1, min(5000, (int) $options['batch-size'])) : 500;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = posmain_db_connect();
$targets = uuidBackfillTargets();

if ($dryRun) {
    echo "Dry run: UUID backfill batch_size={$batchSize}.\n";
    foreach ($targets as $table => $target) {
        $status = uuidBackfillTableStatus($conn, $table, $target);
        if (!$status['ready']) {
            echo sprintf(
                "table=%s status=missing_schema reason=%s batch=%d uuid_column=%s\n",
                $table,
                $status['reason'],
                $batchSize,
                $target['column']
            );
            continue;
        }

        echo sprintf(
            "table=%s status=ready missing_uuid=%d batch=%d uuid_column=%s\n",
            $table,
            uuidBackfillMissingCount($conn, $table, $target),
            $batchSize,
            $target['column']
        );
    }
    exit(0);
}

foreach ($targets as $table => $target) {
    $status = uuidBackfillTableStatus($conn, $table, $target);
    if (!$status['ready']) {
        fwrite(STDERR, "Cannot backfill {$table}: {$status['reason']}. Run tools/run_migrations.php --apply first.\n");
        exit(1);
    }
}

$totalUpdated = 0;
foreach ($targets as $table => $target) {
    $conn->begin_transaction();
    try {
        $ids = uuidBackfillClaimIds($conn, $table, $target, $batchSize);
        $updated = 0;
        foreach ($ids as $id) {
            if (uuidBackfillOneRow($conn, $table, $target, $id)) {
                $updated++;
            }
        }
        $conn->commit();
        $totalUpdated += $updated;

        echo sprintf(
            "Applied batch: table=%s updated=%d remaining=%d batch_size=%d uuid_column=%s\n",
            $table,
            $updated,
            uuidBackfillMissingCount($conn, $table, $target),
            $batchSize,
            $target['column']
        );
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

echo "Backfilled {$totalUpdated} UUID value(s). Re-run until remaining=0 for every table.\n";

function uuidBackfillUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/backfill_uuid_columns.php --dry-run [--batch-size=500]\n");
    fwrite($stream, "Apply one bounded batch per table: php tools/backfill_uuid_columns.php --apply --confirm-no-backup [--batch-size=500]\n");
}

function uuidBackfillTargets(): array
{
    return [
        'ot_head' => ['pk' => 'id', 'column' => 'uuid'],
        'fat_details' => ['pk' => 'id', 'column' => 'uuid'],
        'order_payments' => ['pk' => 'id', 'column' => 'uuid'],
        'tables' => ['pk' => 'id', 'column' => 'uuid'],
        'closed_orders' => ['pk' => 'id', 'column' => 'uuid'],
    ];
}

function uuidBackfillTableStatus(mysqli $conn, string $table, array $target): array
{
    if (!uuidBackfillTableExists($conn, $table)) {
        return ['ready' => false, 'reason' => 'missing_table'];
    }
    if (!uuidBackfillColumnExists($conn, $table, $target['pk'])) {
        return ['ready' => false, 'reason' => 'missing_primary_key_column'];
    }
    if (!uuidBackfillColumnExists($conn, $table, $target['column'])) {
        return ['ready' => false, 'reason' => 'missing_uuid_column'];
    }

    return ['ready' => true, 'reason' => 'ready'];
}

function uuidBackfillTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS table_count
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int) ($row['table_count'] ?? 0)) > 0;
}

function uuidBackfillColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS column_count
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int) ($row['column_count'] ?? 0)) > 0;
}

function uuidBackfillMissingCount(mysqli $conn, string $table, array $target): int
{
    $quotedTable = uuidBackfillQuoteName($table);
    $quotedColumn = uuidBackfillQuoteName($target['column']);
    $result = $conn->query("
        SELECT COUNT(*) AS missing_count
        FROM {$quotedTable}
        WHERE {$quotedColumn} IS NULL OR {$quotedColumn} = ''
    ");
    $row = $result->fetch_assoc();

    return (int) ($row['missing_count'] ?? 0);
}

function uuidBackfillClaimIds(mysqli $conn, string $table, array $target, int $batchSize): array
{
    $quotedTable = uuidBackfillQuoteName($table);
    $quotedPk = uuidBackfillQuoteName($target['pk']);
    $quotedColumn = uuidBackfillQuoteName($target['column']);
    $result = $conn->query("
        SELECT {$quotedPk} AS row_id
        FROM {$quotedTable}
        WHERE {$quotedColumn} IS NULL OR {$quotedColumn} = ''
        ORDER BY {$quotedPk} ASC
        LIMIT {$batchSize}
        FOR UPDATE
    ");

    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['row_id'];
    }

    return $ids;
}

function uuidBackfillOneRow(mysqli $conn, string $table, array $target, int $id): bool
{
    $quotedTable = uuidBackfillQuoteName($table);
    $quotedPk = uuidBackfillQuoteName($target['pk']);
    $quotedColumn = uuidBackfillQuoteName($target['column']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $uuid = uuidBackfillUuidV4();
        try {
            $stmt = $conn->prepare("
                UPDATE {$quotedTable}
                SET {$quotedColumn} = ?
                WHERE {$quotedPk} = ?
                  AND ({$quotedColumn} IS NULL OR {$quotedColumn} = '')
            ");
            $stmt->bind_param('si', $uuid, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            return $affected > 0;
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1062) {
                throw $e;
            }
        }
    }

    throw new RuntimeException("Unable to generate unique UUID for {$table}.{$id}");
}

function uuidBackfillUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function uuidBackfillQuoteName(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
