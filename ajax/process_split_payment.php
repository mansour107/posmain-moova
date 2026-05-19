<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/PaymentInputValidator.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'No data received'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = PaymentInputValidator::validateSplitPayment($data);
} catch (Exception $e) {
    echo json_encode(TableInputValidator::failureResponse($e), JSON_UNESCAPED_UNICODE);
    exit;
}

$original_order_id = intval($data['order_id'] ?? 0);
$table_id = intval($data['table_id'] ?? 0);
$raw_items = is_array($data['items'] ?? null) ? $data['items'] : [];
$split_requests = [];
foreach ($raw_items as $item) {
    if (is_array($item)) {
        $detailId = intval($item['detail_id'] ?? $item['detailId'] ?? $item['id'] ?? 0);
        $qty = isset($item['qty']) ? floatval($item['qty']) : (isset($item['quantity']) ? floatval($item['quantity']) : null);
    } else {
        $detailId = intval($item);
        $qty = null;
    }

    if ($detailId > 0) {
        if (!isset($split_requests[$detailId])) {
            $split_requests[$detailId] = ['qty' => null];
        }
        if ($qty !== null) {
            $split_requests[$detailId]['qty'] = ($split_requests[$detailId]['qty'] ?? 0) + $qty;
        }
    }
}
$selected_items = array_keys($split_requests);
$paid_amount = floatval($data['paid_amount'] ?? 0);
$payment_method = trim((string) ($data['payment_method'] ?? 'cash'));
$user_id = intval($_SESSION['userid'] ?? 1);

if ($original_order_id <= 0 || $table_id <= 0 || !$selected_items || $paid_amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'بيانات السداد المقسم غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $posMutationService = new PosOrderMutationService();
    $syncOutbox = new SyncOutboxEventService();
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($data, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($data);
    $conn->begin_transaction();

    $idempotency = $idempotencyService->begin($conn, PosOrderMutationService::SCOPE_SPLIT_PAYMENT, $idempotencyKey, $idempotencyHash, [
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

    $splitEnvelope = $posMutationService->splitTablePayment($conn, [
        'order_id' => $original_order_id,
        'table_id' => $table_id,
        'items' => $raw_items,
        'paid_amount' => $paid_amount,
        'payment_method' => $payment_method,
        'user_id' => $user_id,
    ], ['user_id' => $user_id, 'in_transaction' => true]);
    $splitData = $splitEnvelope['data'] ?? [];
    $new_head_id = (int) ($splitData['new_invoice_id'] ?? 0);
    $split_group_id = (string) ($splitData['split_group_id'] ?? '');
    $remaining_total = (float) ($splitData['remaining_total'] ?? 0);
    $activeTableOrderId = $splitData['active_order_id'] ?? null;

    $syncOutbox->recordOrderSnapshot($conn, $original_order_id, [
        'event_type' => 'order.updated',
        'source_system' => 'pos_split_payment',
    ]);
    $syncOutbox->recordOrderSnapshot($conn, $new_head_id, [
        'event_type' => 'order.split_paid',
        'source_system' => 'pos_split_payment',
    ]);
    $syncOutbox->recordTableSnapshot($conn, $table_id, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_split_payment',
        'active_order_id' => $activeTableOrderId,
    ]);

    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => 'تم سداد الأصناف المختارة بنجاح',
        'new_invoice_id' => $new_head_id,
        'split_group_id' => $split_group_id,
        'remaining_total' => $remaining_total,
        'request_id' => $idempotencyKey,
    ];
    $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_SPLIT_PAYMENT, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(TableInputValidator::failureResponse($e), JSON_UNESCAPED_UNICODE);
}
?>
