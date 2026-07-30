<?php

$sourcePath = __DIR__ . '/../../ajax/move_table_order.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read ajax/move_table_order.php');
}

phase4MoveEndpointAssert(strpos($source, "require_once('../includes/auth_guard.php')") !== false, 'endpoint should require auth guard');
phase4MoveEndpointAssert(strpos($source, "require_once('../includes/csrf.php')") !== false, 'endpoint should require CSRF helper');
phase4MoveEndpointAssert(strpos($source, "require_once('../classes/Pos/Service/TableTransferService.php')") !== false, 'endpoint should require TableTransferService');
phase4MoveEndpointAssert(strpos($source, "require_once('../classes/Pos/Service/IdempotencyService.php')") !== false, 'endpoint should require IdempotencyService');
phase4MoveEndpointAssert(strpos($source, "require_once('../classes/Sync/SyncOutboxEventService.php')") !== false, 'endpoint should require SyncOutboxEventService');
phase4MoveEndpointAssert(strpos($source, 'require_pos_authenticated();') !== false, 'endpoint should require POS auth');
phase4MoveEndpointAssert(strpos($source, "require_csrf('pos_browser');") !== false, 'endpoint should require browser CSRF on POST');
phase4MoveEndpointAssert(strpos($source, "\$scope = 'pos.table.move';") !== false, 'endpoint should use pos.table.move idempotency scope');
phase4MoveEndpointAssert(strpos($source, '$idempotencyService->begin($conn, $scope') !== false, 'endpoint should begin idempotency before moving table');
phase4MoveEndpointAssert(strpos($source, 'new TableTransferService()') !== false, 'endpoint should instantiate TableTransferService');
phase4MoveEndpointAssert(strpos($source, '->moveOrder($conn') !== false, 'endpoint should delegate to moveOrder');
phase4MoveEndpointAssert(strpos($source, "'in_transaction' => true") !== false, 'endpoint should call service inside endpoint transaction');
phase4MoveEndpointAssert(substr_count($source, 'recordRequiredTableSnapshot') >= 2, 'endpoint should require source and destination table snapshots');
phase4MoveEndpointAssert(strpos($source, 'recordRequiredOrderSnapshot') !== false, 'endpoint should require moved order snapshot');
phase4MoveEndpointAssert(strpos($source, "'source_table_id' => \$sourceTableId") !== false, 'response should include source_table_id');
phase4MoveEndpointAssert(strpos($source, "'destination_table_id' => \$destinationTableId") !== false, 'response should include destination_table_id');
phase4MoveEndpointAssert(strpos($source, "'order_id' => (int) \$transfer['order_id']") !== false, 'response should include moved order_id');
phase4MoveEndpointAssert(strpos($source, "تم نقل الطلب إلى الطاولة الجديدة") !== false, 'Arabic success message should be present');
phase4MoveEndpointAssert(strpos($source, 'posmain_exception_payload(') !== false, 'endpoint should return normalized error payload');

echo "phase4-move-table-endpoint-contract-ok\n";

function phase4MoveEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
