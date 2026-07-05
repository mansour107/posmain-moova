<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/Pos/Service/TableTransferService.php');
require_once('../classes/Pos/Service/IdempotencyService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

require_permission('pos.table.move', $conn);

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
        'رقم الطاولة الجديدة غير صحيح'
    );
    $orderId = TableInputValidator::optionalPositiveInt($data['order_id'] ?? 0, 'معرف الطلب غير صحيح');
    $userId = current_user_id();

    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($data, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($data);
    $scope = 'pos.table.move';
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

    $transfer = (new TableTransferService())->moveOrder($conn, [
        'source_table_id' => $sourceTableId,
        'destination_table_id' => $destinationTableId,
        'order_id' => $orderId,
        'user_id' => $userId,
    ], [
        'user_id' => $userId,
        'tenant' => 0,
        'branch' => 0,
        'event_source' => 'pos_table_move',
        'in_transaction' => true,
    ]);

    $syncOutbox = new SyncOutboxEventService();
    $syncOutbox->recordOrderSnapshot($conn, (int) $transfer['order_id'], [
        'event_type' => 'order.table_moved',
        'source_system' => 'pos_table_move',
    ]);
    $syncOutbox->recordTableSnapshot($conn, $sourceTableId, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table_move',
        'active_order_id' => null,
    ]);
    $syncOutbox->recordTableSnapshot($conn, $destinationTableId, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table_move',
        'active_order_id' => (int) $transfer['order_id'],
    ]);

    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => 'تم نقل الطلب إلى الطاولة الجديدة',
        'order_id' => (int) $transfer['order_id'],
        'source_table_id' => $sourceTableId,
        'destination_table_id' => $destinationTableId,
        'source_freed' => (bool) ($transfer['source_freed'] ?? false),
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
        'حدث خطأ أثناء نقل الطلب إلى طاولة أخرى، يرجى المحاولة مرة أخرى',
        'ERROR',
        true,
        'move_table_order'
    ), JSON_UNESCAPED_UNICODE);
}
?>
