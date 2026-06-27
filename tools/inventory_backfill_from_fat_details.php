<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryHistoricalMigrationService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'dry-run',
    'rehearse',
    'apply',
    'backup-file:',
    'decisions-file:',
    'skip-ambiguous',
    'tenant:',
    'branch:',
    'store:',
    'item:',
    'limit:',
    'min-fat-detail-id:',
    'include-deleted',
    'json',
    'help',
]);

$dryRun = isset($options['dry-run']);
$rehearse = isset($options['rehearse']);
$apply = isset($options['apply']);
if (isset($options['help']) || (($dryRun ? 1 : 0) + ($rehearse ? 1 : 0) + ($apply ? 1 : 0)) !== 1) {
    inventoryFatDetailsBackfillUsage();
    exit(isset($options['help']) ? 0 : 1);
}

$filters = inventoryFatDetailsBackfillFilters($options);
$decisionErrors = inventoryFatDetailsBackfillLoadDecisions($options, $filters);
$backupFile = trim((string) ($options['backup-file'] ?? ''));
$conn = null;
$connected = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($decisionErrors) {
        throw new InvalidArgumentException(implode(',', $decisionErrors));
    }
    $conn = posmain_db_connect();
    $connected = true;
    $service = new InventoryHistoricalMigrationService();
    if ($apply) {
        if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1) {
            $result = [
                'mode' => 'apply',
                'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'filters' => $filters,
                'summary' => [
                    'legacy_rows_scanned' => 0,
                    'safe_candidate_count' => 0,
                    'ambiguous_count' => 0,
                    'already_migrated_count' => 0,
                    'applied_count' => 0,
                    'idempotent_replay_count' => 0,
                    'dry_run_only' => false,
                ],
                'applied_movements' => [],
                'idempotent_replays' => [],
                'ambiguous_rows' => [],
                'blockers' => ['readable_database_backup_file_required_for_backfill_apply'],
            ];
        } else {
            $result = $service->applyFatDetailsBackfill($conn, $filters, [
                'skip_ambiguous' => isset($options['skip-ambiguous']),
            ]);
        }
    } elseif ($rehearse) {
        $result = $service->rehearseFatDetailsBackfill($conn, $filters, [
            'skip_ambiguous' => isset($options['skip-ambiguous']),
        ]);
    } else {
        $result = $service->fatDetailsBackfillPlan($conn, $filters);
        $result['mode'] = 'dry_run';
    }
    $result['generated_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $result['filters'] = inventoryFatDetailsBackfillPublicFilters($filters);
    $conn->close();
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    $result = [
        'mode' => $apply ? 'apply' : ($rehearse ? 'rehearse' : 'dry_run'),
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => inventoryFatDetailsBackfillPublicFilters($filters),
        'summary' => [
            'legacy_rows_scanned' => 0,
            'safe_candidate_count' => 0,
            'ambiguous_count' => 0,
            'already_migrated_count' => 0,
            'dry_run_only' => !$apply,
        ],
        'planned_movements' => [],
        'applied_movements' => [],
        'rehearsed_movements' => [],
        'idempotent_replays' => [],
        'ambiguous_rows' => [],
        'blockers' => $decisionErrors ?: [$connected ? 'inventory_backfill_execution_failed' : 'inventory_backfill_database_unreachable'],
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryFatDetailsBackfillPrintHuman($result);
}

exit(empty($result['blockers']) ? 0 : 2);

function inventoryFatDetailsBackfillUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/inventory_backfill_from_fat_details.php --dry-run [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--min-fat-detail-id=5000] [--include-deleted] [--decisions-file=/absolute/path/to/decisions.json] [--json]\n");
    fwrite(STDOUT, "Rehearse: php tools/inventory_backfill_from_fat_details.php --rehearse [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--decisions-file=/absolute/path/to/decisions.json] [--json]\n");
    fwrite(STDOUT, "Apply: php tools/inventory_backfill_from_fat_details.php --apply --backup-file=/absolute/path/to/recent.sql [--tenant=0] [--branch=0] [--store=0] [--limit=1000] [--decisions-file=/absolute/path/to/decisions.json] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Previews, rehearses, or applies safe legacy fat_details rows as idempotent inventory_movements. Rehearse executes through the ledger service inside a rolled-back transaction. Apply requires a backup file and blocks on ambiguous rows unless reviewed decisions or --skip-ambiguous are explicit.\n");
}

function inventoryFatDetailsBackfillFilters(array $options): array
{
    $filters = [
        'pos_tenant' => inventoryFatDetailsBackfillIntOption($options, 'tenant', 0),
        'pos_branch' => inventoryFatDetailsBackfillIntOption($options, 'branch', 0),
        'store_id' => inventoryFatDetailsBackfillIntOption($options, 'store', 0),
        'item_id' => inventoryFatDetailsBackfillIntOption($options, 'item', 0),
        'limit' => max(1, min(5000, inventoryFatDetailsBackfillIntOption($options, 'limit', 1000))),
    ];
    $minFatDetailId = inventoryFatDetailsBackfillIntOption($options, 'min-fat-detail-id', 0);
    if ($minFatDetailId > 0) {
        $filters['min_fat_detail_id'] = $minFatDetailId;
    }
    if (isset($options['include-deleted'])) {
        $filters['include_deleted'] = true;
    }

    return $filters;
}

function inventoryFatDetailsBackfillIntOption(array $options, string $name, int $default): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    return max(0, (int) $options[$name]);
}

function inventoryFatDetailsBackfillLoadDecisions(array $options, array &$filters): array
{
    $path = trim((string) ($options['decisions-file'] ?? ''));
    if ($path === '') {
        return [];
    }
    if (!is_file($path) || !is_readable($path)) {
        return ['readable_review_decisions_file_required'];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return ['review_decisions_file_empty'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['review_decisions_file_invalid_json'];
    }
    $decisions = $decoded['decisions'] ?? $decoded;
    if (!is_array($decisions)) {
        return ['review_decisions_file_missing_decisions'];
    }

    $filters['reviewed_decisions'] = $decisions;
    $filters['reviewed_decisions_file'] = $path;

    return [];
}

function inventoryFatDetailsBackfillPublicFilters(array $filters): array
{
    unset($filters['reviewed_decisions']);

    return $filters;
}

function inventoryFatDetailsBackfillPrintHuman(array $result): void
{
    $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
    fwrite(STDOUT, "Inventory fat_details backfill " . (string) ($result['mode'] ?? 'dry_run') . "\n");
    fwrite(STDOUT, '- rows scanned: ' . (int) ($summary['legacy_rows_scanned'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- safe candidates: ' . (int) ($summary['safe_candidate_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- planned movements: ' . (int) ($summary['planned_movement_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- skipped rows: ' . (int) ($summary['skipped_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- reviewed candidates: ' . (int) ($summary['reviewed_candidate_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- reviewed skips: ' . (int) ($summary['reviewed_skip_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- ambiguous rows: ' . (int) ($summary['ambiguous_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- already migrated rows: ' . (int) ($summary['already_migrated_count'] ?? 0) . PHP_EOL);
    if (($result['mode'] ?? '') === 'apply') {
        fwrite(STDOUT, '- applied movements: ' . (int) ($summary['applied_count'] ?? 0) . PHP_EOL);
        fwrite(STDOUT, '- idempotent replays: ' . (int) ($summary['idempotent_replay_count'] ?? 0) . PHP_EOL);
    } elseif (($result['mode'] ?? '') === 'rehearse') {
        fwrite(STDOUT, '- rehearsed movements: ' . (int) ($summary['rehearsed_count'] ?? 0) . PHP_EOL);
        fwrite(STDOUT, '- idempotent replays: ' . (int) ($summary['idempotent_replay_count'] ?? 0) . PHP_EOL);
    }

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
}
