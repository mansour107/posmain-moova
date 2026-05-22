<?php

$root = dirname(__DIR__, 2);
$content = file_get_contents($root . '/includes/pos_content.php');
$posJs = file_get_contents($root . '/js/pos_barcode.js');
$invoice = file_get_contents($root . '/do/doadd_invoice.php');
$tablesEndpoint = file_get_contents($root . '/ajax/get_tables.php');

if ($content === false || $posJs === false || $invoice === false || $tablesEndpoint === false) {
    throw new RuntimeException('Unable to read POS empty-table option contract sources');
}

posCashierEmptyTableAssert(strpos($content, 'id="pos_empty_table_after_payment" checked') !== false, 'payment modal should include a default-checked empty-table option');
posCashierEmptyTableAssert(strpos($content, 'id="selected_table_case"') !== false, 'cashier form should track occupied table state separately from active order id');
posCashierEmptyTableAssert(strpos($content, "action === 'free_table'") !== false, 'inline submit override should support the free-table action');
posCashierEmptyTableAssert(strpos($posJs, 'function isHeldTableWithoutActiveOrder()') !== false, 'cashier JS should detect occupied tables without active orders');
posCashierEmptyTableAssert(strpos($posJs, 'أفرغ الطاولة') !== false, 'main payment button should switch to empty-table text');
posCashierEmptyTableAssert(strpos($posJs, "submitPOS('free_table')") !== false, 'main payment button should submit free-table action when no items are present');
posCashierEmptyTableAssert(strpos($posJs, 'empty_table_after_payment') !== false, 'cashier JS should submit empty-table preference with payments');
posCashierEmptyTableAssert(strpos($invoice, '$empty_table_after_payment') !== false, 'invoice handler should read empty-table preference');
posCashierEmptyTableAssert(strpos($invoice, '$is_free_table_only = ($submit === \'free_table\')') !== false, 'invoice handler should support direct empty-table action');
posCashierEmptyTableAssert(strpos($invoice, "} elseif (!\$empty_table_after_payment) {\n            \$tableOrderService->markTableOccupied") !== false, 'paid table flow should keep table occupied when empty-table option is unchecked');
posCashierEmptyTableAssert(strpos($tablesEndpoint, '$activeOrderCase') !== false, 'tables endpoint should distinguish active order state from cached table occupancy');
posCashierEmptyTableAssert(strpos($tablesEndpoint, '$storedTableCase') !== false, 'tables endpoint should preserve manually occupied tables with no active order');
posCashierEmptyTableAssert(strpos($tablesEndpoint, '$row[\'has_active_order\'] = $activeOrderCase;') !== false, 'tables response should expose active-order state separately');

echo "pos-cashier-empty-table-option-contract-ok\n";

function posCashierEmptyTableAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
