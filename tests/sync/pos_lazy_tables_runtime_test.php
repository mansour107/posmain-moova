<?php

$root = dirname(__DIR__, 2);

function posLazyTablesAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$posContent = file_get_contents($root . '/includes/pos_content.php');
$tablesModalStart = strpos($posContent, 'id="tablesModal"');
posLazyTablesAssert($tablesModalStart !== false, 'POS should keep the tables modal');
$tablesModalMarkup = substr($posContent, $tablesModalStart, 5000);
posLazyTablesAssert(strpos($tablesModalMarkup, 'id="tablesGrid"') !== false, 'Tables modal should keep the tables grid mount');
posLazyTablesAssert(strpos($tablesModalMarkup, 'tablesGridLoading') !== false, 'Tables modal should render a loading placeholder first');
posLazyTablesAssert(strpos($tablesModalMarkup, 'SELECT') === false, 'Tables modal should not query tables during initial page render');
posLazyTablesAssert(strpos($tablesModalMarkup, 'UPDATE tables') === false, 'Tables modal should not update table state during initial page render');

$tablesEndpoint = file_get_contents($root . '/ajax/get_tables.php');
posLazyTablesAssert(strpos($tablesEndpoint, 'UPDATE tables SET table_case') !== false, 'Table state updates should remain in the AJAX refresh path');
posLazyTablesAssert(strpos($tablesEndpoint, 'has_active_order') !== false, 'Table AJAX should return active order state');

$posJs = file_get_contents($root . '/js/pos_barcode.js');
foreach (['startTablesAutoRefresh', "typeof window.refreshTablesState === 'function'", 'setInterval(function()', "url: 'ajax/get_tables.php'"] as $needle) {
    posLazyTablesAssert(strpos($posJs, $needle) !== false, 'POS JS should include ' . $needle);
}
posLazyTablesAssert(strpos($posJs, 'function handleTablesApiResponse') !== false, 'Tables modal should not stay on a permanent spinner when refresh fails');
posLazyTablesAssert(strpos($posJs, 'error: function(jqXHR)') !== false, 'Tables refresh should handle AJAX errors');
posLazyTablesAssert(strpos($posJs, 'setTimeout(window.refreshTablesState, 800)') === false, 'Tables preload should not pass an undefined handler before refreshTablesState is assigned');
$refreshAssignmentPos = strpos($posJs, 'window.refreshTablesState = function()');
$startTablesCallPos = strrpos($posJs, '    startTablesAutoRefresh();');
posLazyTablesAssert(strpos($posJs, 'pos-tables-state') !== false, 'Tables modal should use readable empty/error state markup');
posLazyTablesAssert(strpos($posJs, 'handleTablesApiResponse') !== false, 'Tables refresh should interpret API error codes');

echo "pos-lazy-tables-runtime-ok\n";
