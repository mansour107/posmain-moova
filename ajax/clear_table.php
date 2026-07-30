<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $table_id = TableInputValidator::positiveInt($_POST['table_id'] ?? 0, 'معرف الطاولة غير صحيح');
    $order_id = TableInputValidator::optionalPositiveInt($_POST['order_id'] ?? 0, 'معرف الطلب غير صحيح');
    $reason = TableInputValidator::reason($_POST['reason'] ?? '', 'تم تفريغ الطاولة من نقطة البيع');
} catch (Exception $e) {
    echo json_encode(TableInputValidator::failureResponse($e), JSON_UNESCAPED_UNICODE);
    exit;
}
$user_id = current_user_id();
$idempotencyService = new IdempotencyService();

if ($table_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرف الطاولة غير صحيح'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $tableOrderService = new TableOrderService();
    $posMutationService = new PosOrderMutationService();
    $idempotencyKey = $idempotencyService->resolveKey($_POST, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($_POST);
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

    $tableOrderService->requireTable($conn, $table_id);
    if ($order_id <= 0) {
        $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
        if (!$activeOrder) {
            throw new Exception('لا يوجد طلب نشط لهذه الطاولة');
        }
        $order_id = (int) $activeOrder['id'];
    }

    $cancelResult = $posMutationService->cancelTableOrder($conn, [
        'table_id' => $table_id,
        'order_id' => $order_id,
        'reason' => $reason,
        'user_id' => $user_id,
        'mutation_version' => $_POST['mutation_version'] ?? $_POST['order_version'] ?? null,
    ], [
        'user_id' => $user_id,
        'in_transaction' => true,
        'skip_idempotency' => true,
    ]);
    $cancelData = is_array($cancelResult['data'] ?? null) ? $cancelResult['data'] : [];
    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => 'تم تفريغ الطاولة وإلغاء الطلب بدون حذف نهائي',
        'order_id' => $order_id,
        'mutation_version' => (int) ($cancelData['mutation_version'] ?? 0),
        'request_id' => $idempotencyKey,
    ];
    $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(TableInputValidator::failureResponse($e), JSON_UNESCAPED_UNICODE);
}
?>
