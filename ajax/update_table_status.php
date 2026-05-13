<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_POST['table_id'])) {
        throw new Exception('المعاملات مفقودة');
    }

    $table_id = intval($_POST['table_id']);
    $order_id = intval($_POST['order_id'] ?? 0);
    $action = isset($_POST['action'])
        ? (string) $_POST['action']
        : (intval($_POST['is_occupied'] ?? 0) === 1 ? 'activate' : 'clear');
    $reason = trim((string) ($_POST['reason'] ?? 'تم تفريغ الطاولة'));
    $user_id = intval($_SESSION['userid'] ?? 1);
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($_POST, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($_POST);

    if ($table_id <= 0) {
        throw new Exception('رقم الطاولة غير صحيح');
    }

    $tableOrderService = new TableOrderService();
    $posMutationService = new PosOrderMutationService();
    $syncOutbox = new SyncOutboxEventService();
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
            $posMutationService->cancelTableOrder($conn, [
                'table_id' => $table_id,
                'order_id' => $order_id,
                'reason' => $reason,
                'user_id' => $user_id,
            ], ['user_id' => $user_id]);
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

    if ($order_id > 0) {
        $syncOutbox->recordOrderSnapshot($conn, $order_id, [
            'event_type' => $action === 'clear' ? 'order.cancelled' : 'order.table_status_updated',
            'source_system' => 'pos_table_status',
        ]);
    }
    $syncOutbox->recordTableSnapshot($conn, $table_id, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table_status',
        'active_order_id' => $action === 'activate' ? $order_id : null,
    ]);

    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => $message,
        'table_id' => $table_id,
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
