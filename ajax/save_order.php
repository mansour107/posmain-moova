<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/OrderInputValidator.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        throw new Exception('بيانات غير صحيحة');
    }
    $data = OrderInputValidator::validateTableSave($data);

    $tableId = intval($data['table_id'] ?? 0);
    $orderId = intval($data['order_id'] ?? 0);
    $orderDate = trim((string) ($data['order_date'] ?? date('Y-m-d')));
    $storeId = intval($data['store_id'] ?? 0);
    $empId = intval($data['emp_id'] ?? 0);
    $fundId = intval($data['fund_id'] ?? 0);
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $total = floatval($data['total'] ?? 0);
    $discount = floatval($data['discount'] ?? 0);
    $net = floatval($data['net'] ?? max(0, $total - $discount));
    $userId = intval($_SESSION['userid'] ?? 1);
    $isUpdate = $orderId > 0;
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($data, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($data);

    if ($tableId <= 0) {
        throw new Exception('الرجاء اختيار طاولة');
    }
    if (!$items) {
        throw new Exception('الرجاء إضافة أصناف للطلب');
    }
    if ($storeId <= 0 || $empId <= 0 || $fundId <= 0) {
        throw new Exception('بيانات المخزن أو الموظف أو الصندوق ناقصة');
    }

    $posMutationService = new PosOrderMutationService();
    $syncOutbox = new SyncOutboxEventService();
    $conn->begin_transaction();

    $idempotency = $idempotencyService->begin($conn, PosOrderMutationService::SCOPE_TABLE_SAVE, $idempotencyKey, $idempotencyHash, [
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
        throw new Exception('طلب سابق بنفس المفتاح لا يزال قيد المعالجة');
    }

    $saveEnvelope = $posMutationService->saveTableOrder($conn, [
        'table_id' => $tableId,
        'order_id' => $orderId,
        'order_date' => $orderDate,
        'store_id' => $storeId,
        'emp_id' => $empId,
        'fund_id' => $fundId,
        'items' => $items,
        'total' => $total,
        'discount' => $discount,
        'net' => $net,
        'user_id' => $userId,
    ], ['user_id' => $userId, 'in_transaction' => true]);
    $saveData = $saveEnvelope['data'] ?? [];
    $orderId = (int) ($saveData['order_id'] ?? 0);
    $orderStatus = (string) ($saveData['order_status'] ?? 'active');

    $syncOutbox->recordOrderSnapshot($conn, $orderId, [
        'event_type' => $isUpdate ? 'order.updated' : 'order.saved',
        'source_system' => 'pos_table',
    ]);
    $syncOutbox->recordTableSnapshot($conn, $tableId, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table',
        'active_order_id' => $orderStatus === 'completed' ? null : $orderId,
    ]);
    $response = [
        'success' => true,
        'code' => 'OK',
        'order_id' => $orderId,
        'message' => 'تم حفظ الطلب بنجاح',
        'request_id' => $idempotencyKey,
    ];
    $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_TABLE_SAVE, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    $payload = posmain_exception_payload(
        $e,
        'حدث خطأ أثناء حفظ الطلب، يرجى المحاولة مرة أخرى',
        'ERROR',
        true,
        'save_order'
    );
    if ($e instanceof InvalidArgumentException) {
        $payload['code'] = 'VALIDATION_FAILED';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
?>
