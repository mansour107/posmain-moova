<?php
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$table_id = intval($_POST['table_id'] ?? 0);
$order_id = intval($_POST['order_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? 'تم تفريغ الطاولة من نقطة البيع'));
$user_id = intval($_SESSION['userid'] ?? 1);

if ($table_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرف الطاولة غير صحيح'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $tableOrderService = new TableOrderService();
    $conn->begin_transaction();

    $tableOrderService->requireTable($conn, $table_id);
    if ($order_id <= 0) {
        $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
        if (!$activeOrder) {
            throw new Exception('لا يوجد طلب نشط لهذه الطاولة');
        }
        $order_id = (int) $activeOrder['id'];
    }

    $tableOrderService->cancelTableOrder($conn, $table_id, $order_id, $reason, $user_id);
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'تم تفريغ الطاولة وإلغاء الطلب بدون حذف نهائي',
        'order_id' => $order_id,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
