<?php

$root = dirname(__DIR__, 2);

foreach ([
    'do/recost.php' => 'InventoryLegacyStockEndpointGuard::blockIfLive',
    'js/ajax/recost.php' => 'InventoryLegacyStockEndpointGuard::blockIfLive',
] as $relative => $needle) {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source) || strpos($source, $needle) === false) {
        fwrite(STDERR, "recost-live-guard-contract-FAIL: {$relative} missing {$needle}\n");
        exit(1);
    }
}

echo "recost-live-guard-contract-ok\n";
