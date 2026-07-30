<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

try {
    if (!isset($_POST['table_id'])) {
        throw new Exception('المعاملات مفقودة');
    }

    $table_id = TableInputValidator::positiveInt($_POST['table_id'] ?? 0, 'رقم الطاولة غير صحيح');
    $order_id = TableInputValidator::optionalPositiveInt($_POST['order_id'] ?? 0, 'معرف الطلب غير صحيح');
    $action = TableInputValidator::tableStatusAction($_POST);
    $reason = TableInputValidator::reason($_POST['reason'] ?? '', 'تم تفريغ الطاولة');
    $user_id = current_user_id();
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($_POST, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($_POST);

    if ($table_id <= 0) {
        throw new Exception('رقم الطاولة غير صحيح');
    }

    $tableOrderService = new TableOrderService();
    $posMutationService = new PosOrderMutationService();
    $syncOutbox = new SyncOutboxEventService();
    $cancelRecordedOutbox = false;
    $mutationVersion = 0;
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

    if ($action === 'clear') {
        if ($order_id <= 0) {
            $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
            if ($activeOrder) {
                $order_id = (int) $activeOrder['id'];
            }
        }

        if ($order_id > 0) {
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
            $mutationVersion = (int) ($cancelData['mutation_version'] ?? 0);
            $cancelRecordedOutbox = true;
        } else {
            $tableOrderService->setTableFreeIfNoActiveOrder($conn, $table_id);
        }
        $message = 'تم تفريغ الطاولة بنجاح';
    } elseif ($action === 'activate') {
        $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
        if (!$activeOrder) {
            $tableOrderService->setTableFreeIfNoActiveOrder($conn, $table_id);
            throw new Exception('لا يمكن تشغيل الطاولة بدون طلب نشط');
        }
        $order_id = (int) $activeOrder['id'];
        $tableOrderService->markTableOccupied($conn, $table_id);
        $message = 'تم تشغيل الطاولة بنجاح';
    } else {
        throw new Exception('عملية غير صحيحة');
    }

    if (!$cancelRecordedOutbox && $order_id > 0) {
        $syncOutbox->recordRequiredOrderSnapshot($conn, $order_id, [
            'event_type' => $action === 'clear' ? 'order.cancelled' : 'order.table_status_updated',
            'source_system' => 'pos_table_status',
        ]);
    }
    if (!$cancelRecordedOutbox) {
        $syncOutbox->recordRequiredTableSnapshot($conn, $table_id, [
            'event_type' => 'table.updated',
            'source_system' => 'pos_table_status',
            'active_order_id' => $action === 'activate' ? $order_id : null,
        ]);
    }

    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => $message,
        'table_id' => $table_id,
        'order_id' => $order_id,
        'mutation_version' => $mutationVersion,
        'request_id' => $idempotencyKey,
    ];
    $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_ORDER_CANCEL, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    echo json_encode(posmain_exception_payload(
        $e,
        'حدث خطأ أثناء تحديث حالة الطاولة، يرجى المحاولة مرة أخرى',
        'ERROR',
        true,
        'update_table_status'
    ), JSON_UNESCAPED_UNICODE);
}
?>
