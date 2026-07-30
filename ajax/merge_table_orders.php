<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/Pos/Service/TableMergeService.php');
require_once('../classes/Pos/Service/IdempotencyService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

require_permission('pos.table.merge', $conn);

$data = $_POST;
$json = json_decode(file_get_contents('php://input'), true);
if (is_array($json) && $json) {
    $data = array_merge($data, $json);
}

try {
    $sourceTableId = TableInputValidator::positiveInt(
        $data['source_table_id'] ?? $data['from_table_id'] ?? 0,
        'رقم الطاولة المصدر غير صحيح'
    );
    $destinationTableId = TableInputValidator::positiveInt(
        $data['destination_table_id'] ?? $data['to_table_id'] ?? 0,
        'رقم الطاولة الهدف غير صحيح'
    );
    $sourceOrderId = TableInputValidator::optionalPositiveInt(
        $data['source_order_id'] ?? $data['order_id'] ?? 0,
        'معرف الطلب المصدر غير صحيح'
    );
    $destinationOrderId = TableInputValidator::optionalPositiveInt(
        $data['destination_order_id'] ?? 0,
        'معرف الطلب الهدف غير صحيح'
    );
    $userId = current_user_id();

    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($data, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($data);
    $scope = 'pos.table.merge';
    $conn->begin_transaction();

    $idempotency = $idempotencyService->begin($conn, $scope, $idempotencyKey, $idempotencyHash, [
        'user_id' => $userId,
        'tenant' => 0,
        'branch' => 0,
        'stale_after_seconds' => 300,
    ]);
    if (($idempotency['status'] ?? '') === 'conflict') {
        $conn->rollback();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'code' => 'IDEMPOTENCY_CONFLICT',
            'message' => 'تم استخدام نفس مفتاح الطلب مع بيانات مختلفة',
            'request_id' => $idempotencyKey,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($idempotency['status'] ?? '') === 'completed') {
        $conn->commit();
        echo json_encode($idempotency['response'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($idempotency['status'] ?? '', ['started', 'reclaimed'], true)) {
        throw new RuntimeException('IDEMPOTENCY_PROCESSING');
    }

    $merge = (new TableMergeService())->mergeOrders($conn, [
        'source_table_id' => $sourceTableId,
        'destination_table_id' => $destinationTableId,
        'source_order_id' => $sourceOrderId,
        'destination_order_id' => $destinationOrderId,
        'source_mutation_version' => $data['source_mutation_version'] ?? $data['mutation_version'] ?? null,
        'destination_mutation_version' => $data['destination_mutation_version'] ?? null,
    ], [
        'user_id' => $userId,
        'tenant' => 0,
        'branch' => 0,
        'event_source' => 'pos_table_merge',
        'in_transaction' => true,
        'record_outbox' => false,
    ]);

    $syncOutbox = new SyncOutboxEventService();
    $syncOutbox->recordRequiredOrderSnapshot($conn, (int) $merge['source_order_id'], [
        'event_type' => 'order.table_merged_source',
        'source_system' => 'pos_table_merge',
    ]);
    $syncOutbox->recordRequiredOrderSnapshot($conn, (int) $merge['destination_order_id'], [
        'event_type' => 'order.table_merged_destination',
        'source_system' => 'pos_table_merge',
    ]);
    $syncOutbox->recordRequiredTableSnapshot($conn, $sourceTableId, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table_merge',
        'active_order_id' => null,
    ]);
    $syncOutbox->recordRequiredTableSnapshot($conn, $destinationTableId, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table_merge',
        'active_order_id' => (int) $merge['destination_order_id'],
    ]);

    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => 'تم دمج الطلبين في الطاولة الهدف',
        'source_table_id' => $sourceTableId,
        'destination_table_id' => $destinationTableId,
        'source_order_id' => (int) $merge['source_order_id'],
        'destination_order_id' => (int) $merge['destination_order_id'],
        'merged_detail_count' => (int) $merge['merged_detail_count'],
        'source_freed' => (bool) ($merge['source_freed'] ?? false),
        'payment_status' => (string) ($merge['payment_status'] ?? ''),
        'remaining_amount' => (string) ($merge['remaining_amount'] ?? '0.00'),
        'source_mutation_version' => (int) ($merge['source_mutation_version'] ?? 0),
        'destination_mutation_version' => (int) ($merge['destination_mutation_version'] ?? 0),
        'mutation_version' => (int) ($merge['mutation_version'] ?? 0),
        'request_id' => $idempotencyKey,
    ];
    $idempotencyService->complete($conn, $scope, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    echo json_encode(posmain_exception_payload(
        $e,
        'حدث خطأ أثناء دمج الطلبات، يرجى المحاولة مرة أخرى',
        'ERROR',
        true,
        'merge_table_orders'
    ), JSON_UNESCAPED_UNICODE);
}
?>
