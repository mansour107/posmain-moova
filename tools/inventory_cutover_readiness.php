<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryCutoverReadinessService.php';

$options = getopt('', [
    'tenant:',
    'branch:',
    'store:',
    'item:',
    'limit:',
    'sample-limit:',
    'acceptance-file:',
    'decisions-file:',
    'allow-accepted-reconciliation',
    'rebuild-acceptance-file:',
    'allow-accepted-rebuild-differences',
    'accounting-acceptance-file:',
    'allow-accepted-accounting-reconciliation',
    'skip-accounting-gate',
    'json',
    'help',
]);

if (isset($options['help'])) {
    inventoryCutoverReadinessUsage();
    exit(0);
}

$filters = [
    'pos_tenant' => inventoryCutoverReadinessIntOption($options, 'tenant', 0),
    'pos_branch' => inventoryCutoverReadinessIntOption($options, 'branch', 0),
    'store_id' => inventoryCutoverReadinessIntOption($options, 'store', 0),
    'item_id' => inventoryCutoverReadinessIntOption($options, 'item', 0),
    'limit' => max(1, min(5000, inventoryCutoverReadinessIntOption($options, 'limit', 1000))),
    'sample_limit' => max(1, min(100, inventoryCutoverReadinessIntOption($options, 'sample-limit', 25))),
];
$decisionErrors = inventoryCutoverReadinessLoadDecisions($options, $reviewedDecisions);
$reviewOptions = [
    'acceptance_file' => trim((string) ($options['acceptance-file'] ?? '')),
    'reviewed_decisions' => $reviewedDecisions,
    'allow_accepted_reconciliation' => isset($options['allow-accepted-reconciliation']),
    'rebuild_acceptance_file' => trim((string) ($options['rebuild-acceptance-file'] ?? '')),
    'allow_accepted_rebuild_differences' => isset($options['allow-accepted-rebuild-differences']),
    'accounting_acceptance_file' => trim((string) ($options['accounting-acceptance-file'] ?? '')),
    'allow_accepted_accounting_reconciliation' => isset($options['allow-accepted-accounting-reconciliation']),
    'require_accounting' => !isset($options['skip-accounting-gate']),
];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($decisionErrors) {
        throw new InvalidArgumentException(implode(',', $decisionErrors));
    }
    $conn = posmain_db_connect();
    $result = (new InventoryCutoverReadinessService())->review($conn, $filters, $reviewOptions);
    $conn->close();
} catch (Throwable $exception) {
    $result = [
        'ready_for_cutover' => false,
        'ready_for_legacy_retirement' => false,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => $filters,
        'blockers' => ['inventory_cutover_readiness_database_unreachable'],
        'legacy_retirement_blockers' => ['inventory_cutover_readiness_database_unreachable'],
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryCutoverReadinessPrint($result);
}

exit(!empty($result['ready_for_cutover']) ? 0 : 2);

function inventoryCutoverReadinessUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/inventory_cutover_readiness.php [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--sample-limit=25] [--decisions-file=/absolute/path/to/reviewed-decisions.json] [--acceptance-file=/absolute/path/to/accepted.json] [--allow-accepted-reconciliation] [--rebuild-acceptance-file=/absolute/path/to/accepted-rebuild.json] [--allow-accepted-rebuild-differences] [--accounting-acceptance-file=/absolute/path/to/accepted-accounting.json] [--allow-accepted-accounting-reconciliation] [--skip-accounting-gate] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Read-only Phase 15/16 gate that combines migration, reconciliation, accounting, hardening, and legacy-trigger retirement readiness. It does not repair data, drop triggers, post journals, or change feature flags.\n");
}

function inventoryCutoverReadinessLoadDecisions(array $options, ?array &$reviewedDecisions): array
{
    $reviewedDecisions = null;
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

    $reviewedDecisions = $decisions;

    return [];
}

function inventoryCutoverReadinessIntOption(array $options, string $name, int $default): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    return max(0, (int) $options[$name]);
}

function inventoryCutoverReadinessPrint(array $result): void
{
    fwrite(STDOUT, 'Inventory cutover readiness: ' . (!empty($result['ready_for_cutover']) ? 'READY' : 'BLOCKED') . PHP_EOL);
    fwrite(STDOUT, '- legacy retirement: ' . (!empty($result['ready_for_legacy_retirement']) ? 'READY' : 'BLOCKED') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? '') . PHP_EOL);
    fwrite(STDOUT, '- migration ambiguous rows: ' . (int) ($result['migration']['summary']['ambiguous_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- migration rebuild differences: ' . (int) ($result['migration']['summary']['difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- migration cost differences: ' . (int) ($result['migration']['summary']['cost_difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- unaccepted rebuild candidates: ' . (int) ($result['migration']['summary']['unaccepted_rebuild_candidate_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- reconciliation unaccepted differences: ' . (int) ($result['reconciliation']['unaccepted_difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- accounting unaccepted problems: ' . (int) ($result['accounting_reconciliation']['unaccepted_problem_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- missing hardening indexes: ' . count($result['hardening']['missing_indexes'] ?? []) . PHP_EOL);
    fwrite(STDOUT, '- legacy triggers: ' . implode(', ', $result['legacy_retirement']['trigger_names'] ?? []) . PHP_EOL);

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- cutover blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
    if (!empty($result['legacy_retirement_blockers'])) {
        fwrite(STDOUT, "- legacy-retirement blockers:\n");
        foreach ($result['legacy_retirement_blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
}
