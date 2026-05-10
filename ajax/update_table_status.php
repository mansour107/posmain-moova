<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');
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

    if ($table_id <= 0) {
        throw new Exception('رقم الطاولة غير صحيح');
    }

    $tableOrderService = new TableOrderService();
    $conn->begin_transaction();
    $tableOrderService->requireTable($conn, $table_id);

    if ($action === 'clear') {
        if ($order_id <= 0) {
            $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
            if ($activeOrder) {
                $order_id = (int) $activeOrder['id'];
            }
        }

        if ($order_id > 0) {
            $tableOrderService->cancelTableOrder($conn, $table_id, $order_id, $reason, $user_id);
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

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $message,
        'table_id' => $table_id,
        'order_id' => $order_id,
    ], JSON_UNESCAPED_UNICODE);
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
