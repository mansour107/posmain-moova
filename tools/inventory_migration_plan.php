<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryHistoricalMigrationService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'dry-run',
    'tenant:',
    'branch:',
    'store:',
    'item:',
    'limit:',
    'sample-limit:',
    'include-deleted',
    'json',
    'help',
]);

if (isset($options['help']) || !isset($options['dry-run'])) {
    inventoryMigrationPlanUsage();
    exit(isset($options['help']) ? 0 : 1);
}

$filters = inventoryMigrationPlanFilters($options);

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $result = (new InventoryHistoricalMigrationService())->migrationPlan($conn, $filters);
    $conn->close();
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'mode' => 'dry_run',
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => $filters,
        'snapshot' => [],
        'backfill' => ['summary' => [], 'sample_planned_movements' => [], 'sample_ambiguous_rows' => [], 'blockers' => []],
        'rebuild' => ['summary' => [], 'rows' => [], 'blockers' => []],
        'required_before_apply' => ['database_backup', 'clean_or_accepted_reconciliation', 'review_ambiguous_fat_details_rows'],
        'blockers' => ['inventory_migration_database_unreachable'],
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryMigrationPlanPrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function inventoryMigrationPlanUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/inventory_migration_plan.php --dry-run [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--sample-limit=25] [--include-deleted] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Creates a read-only Phase 14 migration plan covering legacy snapshots, fat_details backfill candidates, and ledger balance rebuild differences.\n");
}

function inventoryMigrationPlanFilters(array $options): array
{
    $filters = [
        'pos_tenant' => inventoryMigrationPlanIntOption($options, 'tenant', 0),
        'pos_branch' => inventoryMigrationPlanIntOption($options, 'branch', 0),
        'store_id' => inventoryMigrationPlanIntOption($options, 'store', 0),
        'item_id' => inventoryMigrationPlanIntOption($options, 'item', 0),
        'limit' => max(1, min(5000, inventoryMigrationPlanIntOption($options, 'limit', 1000))),
        'sample_limit' => max(1, min(100, inventoryMigrationPlanIntOption($options, 'sample-limit', 25))),
    ];
    if (isset($options['include-deleted'])) {
        $filters['include_deleted'] = true;
    }

    return $filters;
}

function inventoryMigrationPlanIntOption(array $options, string $name, int $default): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    return max(0, (int) $options[$name]);
}

function inventoryMigrationPlanPrintHuman(array $result): void
{
    fwrite(STDOUT, 'Inventory migration plan: ' . (!empty($result['ok']) ? 'READY FOR REVIEW' : 'BLOCKED') . PHP_EOL);
    $snapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : [];
    $backfill = is_array($result['backfill']['summary'] ?? null) ? $result['backfill']['summary'] : [];
    $rebuild = is_array($result['rebuild']['summary'] ?? null) ? $result['rebuild']['summary'] : [];

    fwrite(STDOUT, '- myitems rows: ' . (int) ($snapshot['myitems']['row_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- fat_details rows scanned: ' . (int) ($backfill['legacy_rows_scanned'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- safe backfill candidates: ' . (int) ($backfill['safe_candidate_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- ambiguous legacy rows: ' . (int) ($backfill['ambiguous_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- already migrated rows: ' . (int) ($backfill['already_migrated_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- derived balance rows: ' . (int) ($rebuild['derived_balance_rows'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- balance differences: ' . (int) ($rebuild['difference_count'] ?? 0) . PHP_EOL);

    $blockers = inventoryMigrationPlanBlockers($result);
    if ($blockers) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($blockers as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }

    fwrite(STDOUT, "Next required checks before any apply tool exists: database backup, accepted reconciliation, ambiguous row review, branch/store/item-category signoff.\n");
}

function inventoryMigrationPlanBlockers(array $result): array
{
    $blockers = [];
    foreach (['snapshot', 'backfill', 'rebuild'] as $section) {
        foreach (($result[$section]['blockers'] ?? []) as $blocker) {
            $blockers[] = (string) $blocker;
        }
    }
    foreach (($result['blockers'] ?? []) as $blocker) {
        $blockers[] = (string) $blocker;
    }

    return array_values(array_unique($blockers));
}
