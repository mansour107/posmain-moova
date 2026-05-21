<?php
$root = dirname(__DIR__, 2);

function posLazyDbOptionsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$posContent = file_get_contents($root . '/includes/pos_content.php');
$posJs = file_get_contents($root . '/js/pos_barcode.js');
$optionsEndpoint = file_get_contents($root . '/ajax/get_pos_options.php');

posLazyDbOptionsAssert(strpos($posContent, "SELECT * FROM `acc_head` WHERE code LIKE '122%'") === false, 'POS page should not load the full customer list during PHP render');
posLazyDbOptionsAssert(strpos($posContent, "id=\"payment_fund_id\" data-options-source=\"fund_id\"") !== false, 'Payment fund select should reuse the already-loaded main fund options');
posLazyDbOptionsAssert(strpos($posContent, "parent_id = 124 OR code LIKE '124%'") === false, 'POS page should not load banks during PHP render');
posLazyDbOptionsAssert(strpos($posContent, 'data-options-loaded="0"') !== false, 'Lazy option selects should advertise unloaded state');

foreach ([
    "type === 'customers'",
    "type === 'banks'",
    "code LIKE '122%'",
    "parent_id = 124 OR code LIKE '124%'",
] as $needle) {
    posLazyDbOptionsAssert(strpos($optionsEndpoint, $needle) !== false, "Options endpoint missing expected contract: {$needle}");
}

foreach ([
    'loadCustomerOptions',
    'syncPaymentFundOptions',
    'loadBankOptions',
    'setTimeout(loadCustomerOptions, 900)',
    "url: 'ajax/get_pos_options.php'",
    'window.POSMainSyncPaymentOptions',
] as $needle) {
    posLazyDbOptionsAssert(strpos($posJs, $needle) !== false, "POS JavaScript missing lazy options hook: {$needle}");
}

posLazyDbOptionsAssert(strpos($posJs, 'startTablesAutoRefresh();') !== false, 'Tables should still start auto-refreshing before click');

echo "pos-lazy-db-options-runtime-ok\n";
