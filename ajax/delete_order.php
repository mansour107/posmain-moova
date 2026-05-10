<?php
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $order_id = intval($_POST['order_id'] ?? 0);
    $table_id = intval($_POST['table_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? 'تم إلغاء الطلب من نظام الطاولات'));
    $user_id = intval($_SESSION['userid'] ?? 1);

    if ($order_id <= 0) {
        throw new Exception('معرف الطلب مطلوب');
    }

    $tableOrderService = new TableOrderService();
    if ($table_id <= 0) {
        $order = $tableOrderService->queryOne($conn, "
            SELECT table_id
            FROM ot_head
            WHERE id = ?
              AND isdeleted = 0
            LIMIT 1
        ", [$order_id]);

        if (!$order) {
            throw new Exception('الطلب غير موجود');
        }

        $table_id = intval($order['table_id'] ?? 0);
        if ($table_id <= 0) {
            throw new Exception('لا يمكن حذف هذا الطلب من شاشة الطاولات لأنه غير مرتبط بطاولة');
        }
    }

    $conn->begin_transaction();
    $tableOrderService->cancelTableOrder($conn, $table_id, $order_id, $reason, $user_id);
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'تم إلغاء الطلب بنجاح',
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
