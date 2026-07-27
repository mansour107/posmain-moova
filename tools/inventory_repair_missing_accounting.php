<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryHistoricalAccountingRepairService.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', [
    'plan', 'rehearse', 'apply', 'reviewed-manifest:', 'backup:', 'created-by:',
    'tenant:', 'branch:', 'store:', 'inventory-account:', 'purchase-clearing-account:',
    'cogs-account:', 'waste-account:', 'adjustment-account:', 'json', 'help',
    'decision-file:',
]);
if (isset($options['help'])) {
    echo "Usage: php tools/inventory_repair_missing_accounting.php [--plan|--rehearse|--apply --reviewed-manifest=<sha256> --backup=/absolute/backup.sql] --inventory-account=ID --purchase-clearing-account=ID --cogs-account=ID --waste-account=ID --adjustment-account=ID [--decision-file=/absolute/reviewed-decisions.json] [--tenant=0 --branch=0 --store=27 --created-by=ID] [--json]\n";
    exit(0);
}
$modes = array_values(array_filter(['plan', 'rehearse', 'apply'], static fn(string $mode): bool => isset($options[$mode])));
if (count($modes) > 1) {
    fwrite(STDERR, "Choose only one mode.\n");
    exit(1);
}
$mode = $modes[0] ?? 'plan';
$serviceOptions = [
    'accounts' => [
        'inventory_asset_account_id' => (int) ($options['inventory-account'] ?? 0),
        'purchase_clearing_account_id' => (int) ($options['purchase-clearing-account'] ?? 0),
        'cogs_account_id' => (int) ($options['cogs-account'] ?? 0),
        'waste_expense_account_id' => (int) ($options['waste-account'] ?? 0),
        'adjustment_gain_loss_account_id' => (int) ($options['adjustment-account'] ?? 0),
    ],
    'created_by' => (int) ($options['created-by'] ?? 0),
];
$decisionFile = trim((string) ($options['decision-file'] ?? ''));
if ($decisionFile !== '') {
    if (!is_file($decisionFile) || !is_readable($decisionFile)) {
        fwrite(STDERR, "Decision file must be readable.\n");
        exit(1);
    }
    $decodedDecisions = json_decode((string) file_get_contents($decisionFile), true, 512, JSON_THROW_ON_ERROR);
    $serviceOptions['reviewed_decisions'] = is_array($decodedDecisions['decisions'] ?? null)
        ? $decodedDecisions['decisions']
        : $decodedDecisions;
}
foreach (['tenant' => 'pos_tenant', 'branch' => 'pos_branch', 'store' => 'store_id'] as $argument => $key) {
    if (isset($options[$argument])) {
        $serviceOptions[$key] = (int) $options[$argument];
    }
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $service = new InventoryHistoricalAccountingRepairService();
    if ($mode === 'apply') {
        $result = $service->apply(
            $conn,
            $serviceOptions,
            (string) ($options['reviewed-manifest'] ?? ''),
            (string) ($options['backup'] ?? '')
        );
    } elseif ($mode === 'rehearse') {
        $result = $service->rehearse($conn, $serviceOptions);
    } else {
        $result = $service->plan($conn, $serviceOptions);
    }
    $conn->close();
} catch (Throwable $exception) {
    $result = ['ok' => false, 'mode' => $mode, 'error' => $exception->getMessage(), 'blockers' => [$exception->getMessage()]];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'Inventory accounting repair: ' . (!empty($result['ok']) ? 'READY' : 'BLOCKED') . PHP_EOL;
    echo '- mode: ' . (string) ($result['mode'] ?? $mode) . PHP_EOL;
    echo '- manifest: ' . (string) ($result['manifest_hash'] ?? '') . PHP_EOL;
    echo '- entries: ' . (int) ($result['summary']['entry_count'] ?? 0) . PHP_EOL;
    echo '- total: ' . (string) ($result['summary']['journal_total'] ?? '0.000000') . PHP_EOL;
    foreach (($result['blockers'] ?? []) as $blocker) {
        echo '- blocker: ' . (is_array($blocker) ? json_encode($blocker, JSON_UNESCAPED_SLASHES) : (string) $blocker) . PHP_EOL;
    }
}

exit(!empty($result['ok']) ? 0 : 2);
