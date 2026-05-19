<?php

$contentPath = __DIR__ . '/../../includes/pos_content.php';
$scriptPath = __DIR__ . '/../../js/pos_barcode.js';
$cssPath = __DIR__ . '/../../dist/css/pos_barcode.css';

$content = file_get_contents($contentPath);
$script = file_get_contents($scriptPath);
$css = file_get_contents($cssPath);

if ($content === false || $script === false || $css === false) {
    throw new RuntimeException('Unable to read POS cashier table transfer sources');
}

phase4CashierTransferAssert(strpos($content, 'id="transferTableBtn"') !== false, 'cashier page should expose a table transfer button');
phase4CashierTransferAssert(strpos($content, 'onclick="openTableTransferFlow();"') !== false, 'transfer button should open cashier transfer flow');
phase4CashierTransferAssert(strpos($content, 'id="tableTransferHint"') !== false, 'tables modal should include transfer-mode guidance');
phase4CashierTransferAssert(strpos($content, 'الطاولة الفارغة تنقل الطلب المحفوظ بالكامل') !== false, 'transfer hint should explain empty-table move behavior');
phase4CashierTransferAssert(strpos($content, 'الطاولة المشغولة تدمج الطلبين') !== false, 'transfer hint should explain occupied-table merge behavior');
phase4CashierTransferAssert(strpos($content, 'احفظ أي تعديل قبل النقل') !== false, 'transfer hint should warn about unsaved cashier edits');
phase4CashierTransferAssert(strpos($content, 'https://code.jquery.com/jquery-3.6.0.min.js') !== false, 'cashier page should keep its late jQuery include visible to this contract');
phase4CashierTransferAssert(strpos($content, 'window.jQuery.ajaxSetup({ beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER });') !== false, 'cashier page should attach CSRF headers after the final jQuery load');
phase4CashierTransferAssert(
    strpos($content, 'window.jQuery.ajaxSetup({ beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER });') > strpos($content, 'https://code.jquery.com/jquery-3.6.0.min.js'),
    'cashier CSRF AJAX setup must run after the late jQuery include'
);

phase4CashierTransferAssert(strpos($script, 'let tableTransferMode = false;') !== false, 'cashier JS should track table transfer mode');
phase4CashierTransferAssert(strpos($script, 'window.openTableTransferFlow = function()') !== false, 'cashier JS should expose openTableTransferFlow');
phase4CashierTransferAssert(strpos($script, 'data-transfer-action="${transferAction}"') !== false, 'rendered table buttons should carry transfer action');
phase4CashierTransferAssert(strpos($script, "transferAction = 'move';") !== false, 'empty target tables should use move action');
phase4CashierTransferAssert(strpos($script, "transferAction = 'merge';") !== false, 'occupied target tables should use merge action');
phase4CashierTransferAssert(strpos($script, "url: isMerge ? 'ajax/merge_table_orders.php' : 'ajax/move_table_order.php'") !== false, 'cashier transfer flow should reuse existing move and merge endpoints');
phase4CashierTransferAssert(strpos($script, 'source_table_id: transferData.sourceTableId') !== false, 'cashier transfer should submit source_table_id');
phase4CashierTransferAssert(strpos($script, 'destination_table_id: transferData.destinationTableId') !== false, 'cashier transfer should submit destination_table_id');
phase4CashierTransferAssert(strpos($script, 'idempotency_key: createPOSIdempotencyKey(requestScope)') !== false, 'cashier transfer should submit an idempotency key');
phase4CashierTransferAssert(strpos($script, 'beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER') !== false, 'cashier transfer requests should attach POS CSRF headers explicitly');
phase4CashierTransferAssert(strpos($script, 'loadExistingOrder(nextOrderId, transferData.destinationTableName, { silent: true });') !== false, 'cashier transfer should reload the destination order after success');
phase4CashierTransferAssert(strpos($script, 'updateTransferTableButton();') !== false, 'cashier transfer visibility should update with selected table/order state');

phase4CashierTransferAssert(strpos($css, '.pos-transfer-table-btn') !== false, 'cashier transfer button should have dedicated styling');
phase4CashierTransferAssert(strpos($css, '#tablesModal .pos-transfer-target') !== false, 'transfer-mode table targets should have dedicated styling');

echo "phase4-cashier-table-transfer-ui-contract-ok\n";

function phase4CashierTransferAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
