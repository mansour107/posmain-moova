<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Sync/DocumentCounterService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'apply', 'confirm-no-backup']);
$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);

if ($dryRun === $apply) {
    fwrite(STDERR, "Usage: php tools/backfill_document_counters.php --dry-run | --apply --confirm-no-backup\n");
    exit(1);
}

if ($apply && !isset($options['confirm-no-backup'])) {
    fwrite(STDERR, "--apply requires --confirm-no-backup for this local/dev scaffold.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = syncBackfillConnect();
$manager = new SyncSchemaManager();
$pendingSchema = $manager->pendingStatements($conn);

if ($pendingSchema) {
    fwrite(STDERR, "Missing sync schema tables. Run tools/run_migrations.php first.\n");
    exit(1);
}

$rows = syncBackfillRows($conn);

if ($dryRun) {
    echo "Dry run: " . count($rows) . " document counter seed(s).\n";
    foreach ($rows as $row) {
        echo sprintf(
            "%s:%s tenant=%d branch=%d current_value=%d\n",
            $row['counter_type'],
            $row['counter_key'],
            $row['pos_tenant'],
            $row['pos_branch'],
            $row['current_value']
        );
    }
    exit(0);
}

$service = new DocumentCounterService();
foreach ($rows as $row) {
    $service->ensureCounterRow(
        $conn,
        $row['pos_tenant'],
        $row['pos_branch'],
        $row['counter_type'],
        $row['counter_key'],
        $row['current_value']
    );
}
echo "Backfilled " . count($rows) . " document counter seed(s).\n";

function syncBackfillRows(mysqli $conn)
{
    $rows = [];

    $invoiceSql = "
        SELECT COALESCE(tenant, 0) AS tenant_id,
               COALESCE(branch, 0) AS branch_id,
               pro_tybe,
               COALESCE(MAX(CAST(pro_id AS UNSIGNED)), 0) AS max_value
        FROM ot_head
        WHERE pro_id IS NOT NULL
        GROUP BY COALESCE(tenant, 0), COALESCE(branch, 0), pro_tybe
    ";
    $result = $conn->query($invoiceSql);
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'pos_tenant' => (int) $row['tenant_id'],
            'pos_branch' => (int) $row['branch_id'],
            'counter_type' => 'pro_id',
            'counter_key' => 'pro_tybe:' . (string) $row['pro_tybe'],
            'current_value' => (int) $row['max_value'],
        ];
    }

    $journalSql = "
        SELECT COALESCE(tenant, 0) AS tenant_id,
               COALESCE(branch, 0) AS branch_id,
               COALESCE(MAX(journal_id), 0) AS max_value
        FROM journal_heads
        WHERE journal_id IS NOT NULL
        GROUP BY COALESCE(tenant, 0), COALESCE(branch, 0)
    ";
    $result = $conn->query($journalSql);
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'pos_tenant' => (int) $row['tenant_id'],
            'pos_branch' => (int) $row['branch_id'],
            'counter_type' => 'journal_id',
            'counter_key' => 'journal:default',
            'current_value' => (int) $row['max_value'],
        ];
    }

    return $rows;
}

function syncBackfillConnect()
{
    return posmain_db_connect();
}
