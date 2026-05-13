<?php
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $order_id = intval($_POST['order_id'] ?? 0);
    $table_id = intval($_POST['table_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? 'تم إلغاء الطلب من نظام الطاولات'));
    $user_id = intval($_SESSION['userid'] ?? 1);
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($_POST, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($_POST);

    if ($order_id <= 0) {
        throw new Exception('معرف الطلب مطلوب');
    }

    $tableOrderService = new TableOrderService();
    $posMutationService = new PosOrderMutationService();
    if ($table_id <= 0) {
        $order = $tableOrderService->queryOne($conn, "
            SELECT table_id
            FROM ot_head
            WHERE id = ?
              AND isdeleted = 0
            LIMIT 1
        ", [$order_id]);

        if (!$order) {
            throw new Exception('الطلب غير موجود');
        }

        $table_id = intval($order['table_id'] ?? 0);
        if ($table_id <= 0) {
            throw new Exception('لا يمكن حذف هذا الطلب من شاشة الطاولات لأنه غير مرتبط بطاولة');
        }
    }

    $conn->begin_transaction();
    $idempotency = $idempotencyService->begin($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL, $idempotencyKey, $idempotencyHash, [
        'user_id' => $user_id,
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
        throw new Exception('طلب سابق بنفس المفتاح لا يزال قيد المعالجة');
    }

    $posMutationService->cancelTableOrder($conn, [
        'table_id' => $table_id,
        'order_id' => $order_id,
        'reason' => $reason,
        'user_id' => $user_id,
    ], ['user_id' => $user_id]);
    $syncOutbox = new SyncOutboxEventService();
    $syncOutbox->recordOrderSnapshot($conn, $order_id, [
        'event_type' => 'order.cancelled',
        'source_system' => 'pos_table_cancel',
    ]);
    $syncOutbox->recordTableSnapshot($conn, $table_id, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table_cancel',
        'active_order_id' => null,
    ]);
    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => 'تم إلغاء الطلب بنجاح',
        'order_id' => $order_id,
        'request_id' => $idempotencyKey,
    ];
    $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
