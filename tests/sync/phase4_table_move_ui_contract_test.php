<?php

$sourcePath = __DIR__ . '/../../tables.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read tables.php');
}

phase4MoveUiAssert(strpos($source, '$move_table_options = [];') !== false, 'tables.php should initialize move table options');
phase4MoveUiAssert(strpos($source, 'NOT EXISTS (') !== false, 'move options should exclude tables with active orders');
phase4MoveUiAssert(strpos($source, 'id="move_destination_table"') !== false, 'move destination select expected');
phase4MoveUiAssert(strpos($source, 'moveTableOrder(') !== false, 'move action should call moveTableOrder');
phase4MoveUiAssert(strpos($source, 'function moveTableOrder(sourceTableId, orderId)') !== false, 'moveTableOrder function expected');
phase4MoveUiAssert(strpos($source, "const requestScope = 'pos.table.move';") !== false, 'move UI should use pos.table.move idempotency scope');
phase4MoveUiAssert(strpos($source, "url: 'ajax/move_table_order.php'") !== false, 'move UI should call move endpoint');
phase4MoveUiAssert(strpos($source, 'source_table_id: sourceTableId') !== false, 'move UI should submit source_table_id');
phase4MoveUiAssert(strpos($source, 'destination_table_id: destinationTableId') !== false, 'move UI should submit destination_table_id');
phase4MoveUiAssert(strpos($source, 'order_id: orderId') !== false, 'move UI should submit order_id');
phase4MoveUiAssert(strpos($source, "idempotency_key: getPOSTablePageIdempotencyKey(requestScope)") !== false, 'move UI should submit idempotency key');
phase4MoveUiAssert(strpos($source, 'clearPOSTablePageIdempotencyKey(requestScope);') !== false, 'move UI should clear idempotency key on success');
phase4MoveUiAssert(strpos($source, "window.location.href = 'tables.php?table_id=' + encodeURIComponent(destinationTableId);") !== false, 'move UI should reload destination table on success');
phase4MoveUiAssert(strpos($source, "csrf_meta_tag('pos_browser', 'posmain-csrf-token')") !== false, 'existing table page CSRF meta tag should remain');
phase4MoveUiAssert(strpos($source, 'window.POSMAIN_ATTACH_CSRF_HEADER') !== false, 'existing AJAX CSRF header hook should remain');

echo "phase4-table-move-ui-contract-ok\n";

function phase4MoveUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
