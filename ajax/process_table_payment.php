<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$table_id = intval($_POST['table_id'] ?? 0);
$order_id = intval($_POST['order_id'] ?? 0);
$discount = isset($_POST['discount']) ? floatval($_POST['discount']) : null;
$net = isset($_POST['net']) ? floatval($_POST['net']) : null;
$paid = floatval($_POST['paid'] ?? $_POST['amount_paid'] ?? 0);
$payment_method = trim((string) ($_POST['payment_method'] ?? 'cash'));
$notes = trim((string) ($_POST['notes'] ?? ''));
$user_id = intval($_SESSION['userid'] ?? 1);

if ($table_id <= 0 || $paid <= 0) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $tableOrderService = new TableOrderService();
    $conn->begin_transaction();

    $table = $tableOrderService->requireTable($conn, $table_id);
    if ($order_id <= 0) {
        $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
        if (!$activeOrder) {
            throw new Exception('لا يوجد طلب نشط لهذه الطاولة');
        }
        $order_id = (int) $activeOrder['id'];
    }

    $paymentResult = $tableOrderService->payTableOrder(
        $conn,
        $table_id,
        $order_id,
        $paid,
        $payment_method,
        $notes,
        $user_id,
        $discount,
        $net
    );

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
        $info_text = "سند قبض - سداد طاولة: " . $table['tname'] . " - فاتورة رقم " . $order_id;

        $stmt = $conn->prepare(
            "INSERT INTO ot_head (
                pro_tybe, is_journal, journal_tybe, info, pro_date,
                emp_id, acc1, acc2, pro_value, fat_net, cost_center, profit, user, op2
            ) VALUES (1, 1, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)"
        );
        $stmt->bind_param(
            "ssiiiddii",
            $info_text,
            $date,
            $emp_id,
            $safe_acc,
            $customer_acc,
            $actual_paid,
            $actual_paid,
            $user_id,
            $order_id
        );
        $stmt->execute();
        $receipt_id = $conn->insert_id;
        $stmt->close();

        $res_jid = $conn->query("SELECT MAX(journal_id) as max_id FROM journal_heads");
        $row_jid = $res_jid->fetch_assoc();
        $journal_id = ($row_jid['max_id'] ?? 0) + 1;

        $stmt = $conn->prepare("INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $j_details = "سند قبض - سداد طاولة " . $table['tname'];
        $stmt->bind_param("idsssii", $journal_id, $receipt_id, $actual_paid, $date, $j_details, $user_id, $order_id);
        $stmt->execute();
        $j_head_id = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, ?, 0, 0, ?)");
        $stmt->bind_param("iidi", $j_head_id, $safe_acc, $actual_paid, $order_id);
        $stmt->execute();
        $stmt->close();

        if ($customer_acc > 0) {
            $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, 0, ?, 1, ?)");
            $stmt->bind_param("iidi", $j_head_id, $customer_acc, $actual_paid, $order_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $paymentResult['fully_paid'] ? 'تم السداد بالكامل' : 'تم تسجيل دفعة جزئية',
        'receipt_id' => $receipt_id,
        'order_id' => $order_id,
        'invoice_id' => $order_id,
        'payment_status' => $paymentResult['payment_status'],
        'remaining_amount' => $paymentResult['remaining_amount'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
