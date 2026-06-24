<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchRestoreFromHostedService.php';
require_once __DIR__ . '/../classes/Sync/RestoreEventPhase.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'apply',
    'menu',
    'tables',
    'orders',
    'operational',
    'all',
    'limit:',
    'help',
]);

if (isset($options['help'])) {
    restoreBranchFromHostedUsage();
    exit(0);
}

$apply = isset($options['apply']);
$all = isset($options['all']);
$phases = [];
if ($all || isset($options['menu'])) {
    $phases[] = RestoreEventPhase::MENU;
}
if ($all || isset($options['tables'])) {
    $phases[] = RestoreEventPhase::TABLES;
}
if ($all || isset($options['orders'])) {
    $phases[] = RestoreEventPhase::ORDERS;
}
if ($all || isset($options['operational'])) {
    $phases[] = RestoreEventPhase::OPERATIONAL;
}
if ($phases === []) {
    $phases = RestoreEventPhase::all();
}

$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = posmain_db_connect();
$config = posmain_app_config();
$service = new BranchRestoreFromHostedService();

try {
    $summary = $service->restore($conn, $config, [
        'apply' => $apply,
        'limit' => $limit,
        'phases' => $phases,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit(($summary['failed'] ?? 0) > 0 ? 2 : 0);

function restoreBranchFromHostedUsage($stream = STDOUT): void
{
    fwrite($stream, "Usage: php tools/restore_branch_from_hosted.php [--apply] [--all|--menu|--tables|--orders|--operational] [--limit=N]\n");
    fwrite($stream, "Dry-run by default. Stop branch sync workers before --apply on a replacement PC.\n");
}
