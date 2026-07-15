<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'طريقة الطلب غير صحيحة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('pos_browser');

$action = strtolower(trim((string) ($_POST['action'] ?? 'refund')));
if (!in_array($action, ['refund', 'void'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'VALIDATION_FAILED',
        'message' => 'نوع العملية غير صحيح',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_pos_authenticated();
$reversalPermission = $action === 'void' ? 'pos.void.paid' : 'pos.refund';
require_pos_lane_permission($reversalPermission, $conn);

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);
$idempotencyService = new IdempotencyService();
$scope = $action === 'void' ? PosOrderMutationService::SCOPE_ORDER_VOID : PosOrderMutationService::SCOPE_ORDER_REFUND;

try {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new InvalidArgumentException('ORDER_ID_REQUIRED');
    }

    $idempotencyKey = $idempotencyService->resolveKey($_POST, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($_POST);
    $posMutationService = new PosOrderMutationService();
    $syncOutbox = new SyncOutboxEventService();

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
        throw new RuntimeException('طلب سابق بنفس المفتاح لا يزال قيد المعالجة');
    }

    $result = $posMutationService->reversePaidOrder($conn, [
        'order_id' => $orderId,
        'action' => $action,
        'reason' => $_POST['reason'] ?? '',
        'refund_stock_policy' => $_POST['refund_stock_policy'] ?? '',
        'manager_approval_id' => $_POST['manager_approval_id'] ?? null,
        'idempotency_key' => $idempotencyKey,
    ], [
        'user_id' => $userId,
        'event_source' => $action === 'void' ? 'pos_paid_void' : 'pos_paid_refund',
        'in_transaction' => true,
    ]);

    $data = $result['data'] ?? [];
    $syncOutbox->recordOrderSnapshot($conn, $orderId, [
        'event_type' => $action === 'void' ? 'order.voided' : 'order.refunded',
        'source_system' => 'pos_paid_reversal',
    ]);
    if ((int) ($data['table_id'] ?? 0) > 0) {
        $syncOutbox->recordTableSnapshot($conn, (int) $data['table_id'], [
            'event_type' => 'table.updated',
            'source_system' => 'pos_paid_reversal',
            'active_order_id' => null,
        ]);
    }

    $response = array_merge($result, ['request_id' => $idempotencyKey]);
    $idempotencyService->complete($conn, $scope, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }

    echo json_encode(posmain_exception_payload(
        $exception,
        'حدث خطأ أثناء معالجة الاسترداد أو الإلغاء',
        'ORDER_REVERSAL_FAILED',
        true,
        'refund_order'
    ), JSON_UNESCAPED_UNICODE);
}
?>
