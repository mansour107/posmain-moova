<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$table_id = intval($_POST['table_id'] ?? 0);
$order_id = intval($_POST['order_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? 'تم تفريغ الطاولة'));
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
            $tableOrderService->setTableFreeIfNoActiveOrder($conn, $table_id);
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'الطاولة فارغة بالفعل', 'total' => '0.00'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $order_id = (int) $activeOrder['id'];
    }

    $order = $tableOrderService->cancelTableOrder($conn, $table_id, $order_id, $reason, $user_id);
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'تم تفريغ الطاولة بنجاح',
        'order_id' => $order_id,
        'total' => number_format((float) ($order['fat_total'] ?? 0), 2),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
