<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
phase6DocsAssert(is_string($root), 'repo root should resolve');

$files = [
    'go_live' => $root . '/docs/production/pilot_go_live_checklist.md',
    'daily' => $root . '/docs/production/pilot_daily_review_template.md',
    'exit' => $root . '/docs/production/pilot_exit_criteria.md',
];

foreach ($files as $label => $path) {
    phase6DocsAssert(is_file($path), "{$label} document should exist");
    phase6DocsAssert(filesize($path) > 500, "{$label} document should not be a placeholder");
}

$goLive = phase6DocsRead($files['go_live']);
$daily = phase6DocsRead($files['daily']);
$exit = phase6DocsRead($files['exit']);

phase6DocsAssertContains($goLive, [
    'tools/seed_demo_restaurant.php',
    'tools/branch_go_live_readiness.php',
    'docs/production/backup_restore_runbook.md',
    'docs/production/moova_mode_decision.md',
    'Release branch clean',
    'Backup file created',
    'Restore rehearsal done',
    'Migrations applied in staging',
    'Production guard enabled',
    'Debug routes denied',
    'Upload PHP blocked',
    'Least privilege DB user',
    'POS devices tested',
    'Printer tested',
    'Drawer and shift tested',
    'Moova disabled or direct-widget configured',
    'Cashier training done',
    'Rollback plan documented',
    'Support process defined',
    'Daily backup scheduled',
    'Logs monitored',
], 'go-live checklist');

phase6DocsAssertContains($daily, [
    'Order count',
    'Failed transactions',
    'Payment mismatches',
    'Cancelled/voided orders',
    'Printer failures',
    'Table stuck incidents',
    'Stock mismatches',
    'Moova failed events',
    'Moova duplicate events',
    'Cashier complaints',
    'Average POS response time',
    'No duplicate order caused by retry',
    'No duplicate payment caused by retry',
    'No table remains occupied after paid/cancelled order',
], 'daily review template');

phase6DocsAssertContains($exit, [
    'Seven consecutive service days without critical data loss',
    'Z report matches cash/card/wallet totals within accepted variance',
    'No duplicate order or payment caused by retry',
    'Stock movement passes sample audit',
    'Receipt totals match orders',
    'Cashiers can use it without developer intervention',
    'Backup/restore remains valid',
    'Local tests, demo seed data, and staging rehearsals are necessary preparation, but they do not replace live pilot evidence.',
], 'exit criteria');

foreach ([$goLive, $daily, $exit] as $content) {
    phase6DocsAssert(!str_contains($content, 'TODO'), 'pilot docs should not contain TODO placeholders');
}

echo "phase6-pilot-docs-contract-ok\n";

function phase6DocsRead(string $path): string
{
    $content = file_get_contents($path);
    phase6DocsAssert(is_string($content), "could not read {$path}");

    return $content;
}

/**
 * @param list<string> $needles
 */
function phase6DocsAssertContains(string $content, array $needles, string $label): void
{
    foreach ($needles as $needle) {
        phase6DocsAssert(str_contains($content, $needle), "{$label} missing required text: {$needle}");
    }
}

function phase6DocsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
