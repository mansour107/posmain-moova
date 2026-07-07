<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/ManagerApprovalService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

try {
    $order_id = TableInputValidator::positiveInt($_POST['order_id'] ?? 0, 'معرف الطلب مطلوب');
    $table_id = TableInputValidator::optionalPositiveInt($_POST['table_id'] ?? 0, 'معرف الطاولة غير صحيح');
    $reason = TableInputValidator::reason($_POST['reason'] ?? '', 'تم إلغاء الطلب من نظام الطاولات');
    $user_id = function_exists('pos_acting_user_id') ? pos_acting_user_id() : current_user_id();
    if ($user_id < 1) {
        throw new Exception('USER_ID_REQUIRED');
    }
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($_POST, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($_POST);

    if ($order_id <= 0) {
        throw new Exception('معرف الطلب مطلوب');
    }

    if (!auth_guard_pos_lane_has_permission('pos.cancel.unpaid', $conn)) {
        $approvalService = new ManagerApprovalService();
        $approval = $approvalService->requireApprovedIfNeeded(
            $conn,
            'pos.cancel.unpaid',
            'pos_order',
            $order_id,
            1.0,
            $_POST,
            [
                'user_id' => $user_id,
                'require_manager_approval' => true,
            ]
        );
        if ($approval) {
            $approvalService->consumeApproval($conn, (int) $approval['id'], $user_id);
        }
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
    $code = $e->getMessage();
    if ($e instanceof ManagerApprovalRequiredException || $code === 'MANAGER_APPROVAL_REQUIRED') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'code' => 'MANAGER_APPROVAL_REQUIRED',
            'message' => 'يتطلب اعتماد مدير',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(posmain_exception_payload(
        $e,
        'حدث خطأ أثناء إلغاء الطلب، يرجى المحاولة مرة أخرى',
        'ERROR',
        true,
        'delete_order'
    ), JSON_UNESCAPED_UNICODE);
}
?>
