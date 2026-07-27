<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryValuationCutoverService.php';

$options = getopt('', [
    'plan', 'rehearse', 'apply', 'reviewed-manifest:', 'backup:', 'tenant:', 'branch:', 'store:',
    'inventory-account:', 'offset-account:', 'cutover-date:', 'approved-by:', 'approval-reason:',
    'created-by:', 'json', 'help',
]);
if (isset($options['help'])) {
    echo "Usage: php tools/inventory_valuation_cutover.php [--plan|--rehearse|--apply --reviewed-manifest=HASH --backup=/absolute/backup.sql] --store=ID --inventory-account=ID --offset-account=ID --approved-by=NAME --approval-reason=TEXT [--tenant=0 --branch=0 --cutover-date=YYYY-MM-DD --created-by=ID] [--json]\n";
    exit(0);
}
$modes = array_values(array_filter(['plan', 'rehearse', 'apply'], static fn(string $mode): bool => isset($options[$mode])));
if (count($modes) > 1) {
    fwrite(STDERR, "Choose only one mode.\n");
    exit(1);
}
$mode = $modes[0] ?? 'plan';
$serviceOptions = [
    'pos_tenant' => (int) ($options['tenant'] ?? 0),
    'pos_branch' => (int) ($options['branch'] ?? 0),
    'store_id' => (int) ($options['store'] ?? 0),
    'inventory_asset_account_id' => (int) ($options['inventory-account'] ?? 0),
    'offset_account_id' => (int) ($options['offset-account'] ?? 0),
    'cutover_date' => (string) ($options['cutover-date'] ?? date('Y-m-d')),
    'approved_by' => (string) ($options['approved-by'] ?? ''),
    'approval_reason' => (string) ($options['approval-reason'] ?? ''),
    'created_by' => (int) ($options['created-by'] ?? 0),
];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $service = new InventoryValuationCutoverService();
    $result = $mode === 'apply'
        ? $service->apply($conn, $serviceOptions, (string) ($options['reviewed-manifest'] ?? ''), (string) ($options['backup'] ?? ''))
        : ($mode === 'rehearse' ? $service->rehearse($conn, $serviceOptions) : $service->plan($conn, $serviceOptions));
    $conn->close();
} catch (Throwable $exception) {
    $result = ['ok' => false, 'mode' => $mode, 'error' => $exception->getMessage(), 'blockers' => [$exception->getMessage()]];
}

echo isset($options['json'])
    ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    : 'Inventory valuation cutover: ' . (!empty($result['ok']) ? 'READY' : 'BLOCKED') . PHP_EOL
        . '- manifest: ' . (string) ($result['manifest_hash'] ?? '') . PHP_EOL
        . '- difference: ' . (string) ($result['difference_2dp'] ?? '0.00') . PHP_EOL
        . '- blockers: ' . implode(',', array_map('strval', $result['blockers'] ?? [])) . PHP_EOL;

exit(!empty($result['ok']) ? 0 : 2);
