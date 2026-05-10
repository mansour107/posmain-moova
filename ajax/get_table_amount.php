<?php
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');

header('Content-Type: application/json');

try {
    $table_id = intval($_POST['table_id'] ?? $_GET['table_id'] ?? 0);
    $tableOrderService = new TableOrderService();
    $tableOrderService->requireTable($conn, $table_id);
    $order = $tableOrderService->findActiveOrderByTableId($conn, $table_id);

    $total = 0;
    $discount = 0;
    $paid = 0;
    $net = 0;
    $remaining = 0;
    $orderId = 0;

    if ($order) {
        $orderId = (int) $order['id'];
        $totals = $tableOrderService->recalculateOrderTotals($conn, $orderId);
        $total = $totals['total'];
        $discount = floatval($order['fat_disc'] ?? 0);
        $net = max(0, $total - $discount);
        $paid = floatval($order['paid_amount'] ?? 0);
        $remaining = max(0, $net - $paid);
    }
    
    echo json_encode([
        'success' => true,
        'total' => $total,
        'discount' => $discount,
        'net' => $net,
        'paid' => $paid,
        'remaining' => $remaining,
        'order_id' => $orderId
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'total' => 0,
        'discount' => 0,
        'net' => 0,
        'paid' => 0,
        'remaining' => 0
    ]);
}
?>
