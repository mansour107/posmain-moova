<?php

$sourcePath = __DIR__ . '/../../tables.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read tables.php');
}

phase4MergeUiAssert(strpos($source, '$merge_table_options = [];') !== false, 'tables.php should initialize merge table options');
phase4MergeUiAssert(strpos($source, 'INNER JOIN ot_head oh ON oh.table_id = t.id') !== false, 'merge options should join active table orders');
phase4MergeUiAssert(strpos($source, "COALESCE(oh.payment_status, 'unpaid') IN ('unpaid', 'partial')") !== false, 'merge options should require unpaid/partial active orders');
phase4MergeUiAssert(strpos($source, 'AND t.id <> ?') !== false, 'merge options should exclude the current table');
phase4MergeUiAssert(strpos($source, 'id="merge_destination_table"') !== false, 'merge destination select expected');
phase4MergeUiAssert(strpos($source, 'data-order-id="<?= (int) $merge_table[\'order_id\'] ?>"') !== false, 'merge select should keep destination order id');
phase4MergeUiAssert(strpos($source, 'mergeTableOrders(') !== false, 'merge action should call mergeTableOrders');
phase4MergeUiAssert(strpos($source, 'function mergeTableOrders(sourceTableId, sourceOrderId)') !== false, 'mergeTableOrders function expected');
phase4MergeUiAssert(strpos($source, "const requestScope = 'pos.table.merge';") !== false, 'merge UI should use pos.table.merge idempotency scope');
phase4MergeUiAssert(strpos($source, "url: 'ajax/merge_table_orders.php'") !== false, 'merge UI should call merge endpoint');
phase4MergeUiAssert(strpos($source, 'source_table_id: sourceTableId') !== false, 'merge UI should submit source_table_id');
phase4MergeUiAssert(strpos($source, 'destination_table_id: destinationTableId') !== false, 'merge UI should submit destination_table_id');
phase4MergeUiAssert(strpos($source, 'source_order_id: sourceOrderId') !== false, 'merge UI should submit source_order_id');
phase4MergeUiAssert(strpos($source, 'destination_order_id: destinationOrderId') !== false, 'merge UI should submit destination_order_id');
phase4MergeUiAssert(strpos($source, "idempotency_key: getPOSTablePageIdempotencyKey(requestScope)") !== false, 'merge UI should submit idempotency key');
phase4MergeUiAssert(strpos($source, 'clearPOSTablePageIdempotencyKey(requestScope);') !== false, 'merge UI should clear idempotency key on success');
phase4MergeUiAssert(strpos($source, "window.location.href = 'tables.php?table_id=' + encodeURIComponent(destinationTableId);") !== false, 'merge UI should reload destination table on success');
phase4MergeUiAssert(strpos($source, "csrf_meta_tag('pos_browser', 'posmain-csrf-token')") !== false, 'existing table page CSRF meta tag should remain');
phase4MergeUiAssert(strpos($source, 'window.POSMAIN_ATTACH_CSRF_HEADER') !== false, 'existing AJAX CSRF header hook should remain');

echo "phase4-table-merge-ui-contract-ok\n";

function phase4MergeUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
