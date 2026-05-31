<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/classes/Inventory/InventoryLegacyRetirementReadinessService.php';

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/inventory_legacy_retirement_check.php [--json]\n");
    fwrite(STDOUT, "Read-only Phase 16 readiness check for retiring legacy inventory stock paths.\n");
    exit(0);
}

$result = inventoryLegacyRetirementCheck($root);
if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryLegacyRetirementPrint($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function inventoryLegacyRetirementCheck(string $root): array
{
    return (new InventoryLegacyRetirementReadinessService())->review(null, $root);
}

function inventoryLegacyRetirementPrint(array $result): void
{
    fwrite(STDOUT, 'Inventory legacy retirement: ' . (!empty($result['ok']) ? 'READY' : 'NOT READY') . PHP_EOL);
    fwrite(STDOUT, '- ready to delete legacy stock core: ' . (!empty($result['ready_to_delete_legacy_stock_core']) ? 'yes' : 'no') . PHP_EOL);
    if (!empty($result['proven_controls'])) {
        fwrite(STDOUT, "- proven controls:\n");
        foreach ($result['proven_controls'] as $control) {
            fwrite(STDOUT, '  - ' . $control . PHP_EOL);
        }
    }
    if (!empty($result['pending_retirement_items'])) {
        fwrite(STDOUT, "- pending retirement items:\n");
        foreach ($result['pending_retirement_items'] as $item) {
            fwrite(STDOUT, '  - ' . $item . PHP_EOL);
        }
    }
}
