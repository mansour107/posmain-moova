<?php

$sourcePath = __DIR__ . '/../../ajax/merge_table_orders.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read ajax/merge_table_orders.php');
}

phase4MergeEndpointAssert(strpos($source, "require_once('../includes/auth_guard.php')") !== false, 'endpoint should require auth guard');
phase4MergeEndpointAssert(strpos($source, "require_once('../includes/csrf.php')") !== false, 'endpoint should require CSRF helper');
phase4MergeEndpointAssert(strpos($source, "require_once('../classes/Pos/Service/TableMergeService.php')") !== false, 'endpoint should require TableMergeService');
phase4MergeEndpointAssert(strpos($source, "require_once('../classes/Pos/Service/IdempotencyService.php')") !== false, 'endpoint should require IdempotencyService');
phase4MergeEndpointAssert(strpos($source, "require_once('../classes/Sync/SyncOutboxEventService.php')") !== false, 'endpoint should require SyncOutboxEventService');
phase4MergeEndpointAssert(strpos($source, 'require_pos_authenticated();') !== false, 'endpoint should require POS auth');
phase4MergeEndpointAssert(strpos($source, "require_csrf('pos_browser');") !== false, 'endpoint should require browser CSRF on POST');
phase4MergeEndpointAssert(strpos($source, "\$scope = 'pos.table.merge';") !== false, 'endpoint should use pos.table.merge idempotency scope');
phase4MergeEndpointAssert(strpos($source, '$idempotencyService->begin($conn, $scope') !== false, 'endpoint should begin idempotency before merging tables');
phase4MergeEndpointAssert(strpos($source, 'new TableMergeService()') !== false, 'endpoint should instantiate TableMergeService');
phase4MergeEndpointAssert(strpos($source, '->mergeOrders($conn') !== false, 'endpoint should delegate to mergeOrders');
phase4MergeEndpointAssert(strpos($source, "'in_transaction' => true") !== false, 'endpoint should call service inside endpoint transaction');
phase4MergeEndpointAssert(substr_count($source, 'recordRequiredOrderSnapshot') >= 2, 'endpoint should require source and destination order snapshots');
phase4MergeEndpointAssert(substr_count($source, 'recordRequiredTableSnapshot') >= 2, 'endpoint should require source and destination table snapshots');
phase4MergeEndpointAssert(strpos($source, "'source_table_id' => \$sourceTableId") !== false, 'response should include source_table_id');
phase4MergeEndpointAssert(strpos($source, "'destination_table_id' => \$destinationTableId") !== false, 'response should include destination_table_id');
phase4MergeEndpointAssert(strpos($source, "'source_order_id' => (int) \$merge['source_order_id']") !== false, 'response should include source_order_id');
phase4MergeEndpointAssert(strpos($source, "'destination_order_id' => (int) \$merge['destination_order_id']") !== false, 'response should include destination_order_id');
phase4MergeEndpointAssert(strpos($source, "'merged_detail_count' => (int) \$merge['merged_detail_count']") !== false, 'response should include merged_detail_count');
phase4MergeEndpointAssert(strpos($source, "'source_freed' => (bool) (\$merge['source_freed'] ?? false)") !== false, 'response should include source_freed');
phase4MergeEndpointAssert(strpos($source, "'payment_status' => (string) (\$merge['payment_status'] ?? '')") !== false, 'response should include payment_status');
phase4MergeEndpointAssert(strpos($source, "'remaining_amount' => (string) (\$merge['remaining_amount'] ?? '0.00')") !== false, 'response should preserve exact decimal remaining_amount');
phase4MergeEndpointAssert(strpos($source, "تم دمج الطلبين في الطاولة الهدف") !== false, 'Arabic success message should be present');
phase4MergeEndpointAssert(strpos($source, 'posmain_exception_payload(') !== false, 'endpoint should return normalized error payload');

echo "phase4-merge-table-endpoint-contract-ok\n";

function phase4MergeEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
