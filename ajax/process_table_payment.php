<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
session_start();
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Validation/PaymentInputValidator.php');
require_once('../classes/Pos/Validation/TableInputValidator.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Pos/Service/AccountingPostingService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

require_pos_authenticated();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('pos_browser');
}

try {
    $paymentInput = PaymentInputValidator::validateTablePayment($_POST);
} catch (Exception $e) {
    echo json_encode(TableInputValidator::failureResponse($e), JSON_UNESCAPED_UNICODE);
    exit;
}

$table_id = intval($paymentInput['table_id'] ?? 0);
$order_id = intval($paymentInput['order_id'] ?? 0);
$discount = $paymentInput['discount'];
$net = $paymentInput['net'];
$paid = floatval($paymentInput['paid'] ?? 0);
$payment_method = (string) ($paymentInput['payment_method'] ?? 'cash');
$notes = (string) ($paymentInput['notes'] ?? '');
$user_id = intval($_SESSION['userid'] ?? 1);

if ($table_id <= 0 || $paid <= 0) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $tableOrderService = new TableOrderService();
    $posMutationService = new PosOrderMutationService();
    $accountingPostingService = new AccountingPostingService();
    $syncOutbox = new SyncOutboxEventService();
    $idempotencyService = new IdempotencyService();
    $idempotencyKey = $idempotencyService->resolveKey($_POST, $_SERVER);
    $idempotencyHash = $idempotencyService->requestHashForPayload($_POST);
    $conn->begin_transaction();

    $idempotency = $idempotencyService->begin($conn, PosOrderMutationService::SCOPE_TABLE_PAYMENT, $idempotencyKey, $idempotencyHash, [
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

    $table = $tableOrderService->requireTable($conn, $table_id);
    if ($order_id <= 0) {
        $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
        if (!$activeOrder) {
            throw new Exception('لا يوجد طلب نشط لهذه الطاولة');
        }
        $order_id = (int) $activeOrder['id'];
    }

    $paymentEnvelope = $posMutationService->payTableOrder($conn, [
        'table_id' => $table_id,
        'order_id' => $order_id,
        'paid' => $paid,
        'payment_method' => $payment_method,
        'notes' => $notes,
        'user_id' => $user_id,
        'discount' => $discount,
        'net' => $net,
    ], ['user_id' => $user_id]);
    $paymentResult = $paymentEnvelope['data'] ?? [];

    $order = $tableOrderService->queryOne($conn, "SELECT * FROM ot_head WHERE id = ? LIMIT 1", [$order_id]);
    if (!$order) {
        throw new Exception('الطلب غير موجود');
    }

    $receipt_id = null;
    $actual_paid = (float) ($paymentResult['applied_amount'] ?? 0);
    if ($actual_paid > 0) {
        $date = date('Y-m-d');
        $safe_acc = 51;
        $safe_res = $conn->query("SELECT id FROM acc_head WHERE aname LIKE '%خزينة%' OR aname LIKE '%صندوق%' LIMIT 1");
        if ($safe_res && $safe_res->num_rows > 0) {
            $safe_acc = (int) $safe_res->fetch_assoc()['id'];
        }

        $customer_acc = $tableOrderService->resolveDefaultCustomerId($conn, (int) ($order['acc2'] ?? 0));
        $emp_id = (int) ($order['emp_id'] ?? 0);
        $accountingResult = $accountingPostingService->postTablePaymentReceipt($conn, [
            'order_id' => $order_id,
            'table_name' => $table['tname'] ?? '',
            'amount' => $actual_paid,
            'safe_account_id' => $safe_acc,
            'customer_account_id' => $customer_acc,
            'emp_id' => $emp_id,
            'payment_date' => $date,
            'user_id' => $user_id,
        ], ['user_id' => $user_id, 'tenant' => 0, 'branch' => 0]);
        $receipt_id = $accountingResult['receipt_id'] ?? null;
    }

    $syncOutbox->recordOrderSnapshot($conn, $order_id, [
        'event_type' => 'order.payment_recorded',
        'source_system' => 'pos_table_payment',
    ]);
    $syncOutbox->recordTableSnapshot($conn, $table_id, [
        'event_type' => 'table.updated',
        'source_system' => 'pos_table_payment',
        'active_order_id' => $paymentResult['fully_paid'] ? null : $order_id,
    ]);

    $response = [
        'success' => true,
        'code' => 'OK',
        'message' => $paymentResult['fully_paid'] ? 'تم السداد بالكامل' : 'تم تسجيل دفعة جزئية',
        'receipt_id' => $receipt_id,
        'order_id' => $order_id,
        'invoice_id' => $order_id,
        'payment_status' => $paymentResult['payment_status'],
        'remaining_amount' => $paymentResult['remaining_amount'],
        'request_id' => $idempotencyKey,
    ];
    $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_TABLE_PAYMENT, $idempotencyKey, $idempotencyHash, $response);
    $conn->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(TableInputValidator::failureResponse($e), JSON_UNESCAPED_UNICODE);
}
?>
