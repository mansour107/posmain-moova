<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/get_table_amount.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Financial/Decimal.php');

header('Content-Type: application/json');

try {
    $table_id = intval($_POST['table_id'] ?? $_GET['table_id'] ?? 0);
    $tableOrderService = new TableOrderService();
    $tableOrderService->requireTable($conn, $table_id);
    $order = $tableOrderService->findActiveOrderByTableId($conn, $table_id);

    $total = '0.00';
    $discount = '0.00';
    $paid = '0.00';
    $net = '0.00';
    $remaining = '0.00';
    $orderId = 0;
    $mutationVersion = null;

    if ($order) {
        $orderId = (int) $order['id'];
        $mutationVersion = max(1, (int) ($order['mutation_version'] ?? 1));
        $totals = $tableOrderService->recalculateOrderTotals($conn, $orderId);
        $total = FinancialDecimal::normalize($totals['total'], 2);
        $discount = FinancialDecimal::normalize($order['fat_disc'] ?? '0', 2);
        $net = tableAmountSubtractFloorZero($total, $discount);
        $paid = FinancialDecimal::normalize($order['paid_amount'] ?? '0', 2);
        $remaining = tableAmountSubtractFloorZero($net, $paid);
    }
    
    echo json_encode([
        'success' => true,
        'total' => $total,
        'discount' => $discount,
        'net' => $net,
        'paid' => $paid,
        'remaining' => $remaining,
        'order_id' => $orderId,
        'mutation_version' => $mutationVersion
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'total' => '0.00',
        'discount' => '0.00',
        'net' => '0.00',
        'paid' => '0.00',
        'remaining' => '0.00',
        'mutation_version' => null
    ]);
}

function tableAmountSubtractFloorZero(string $left, string $right): string
{
    $result = FinancialDecimal::subtract($left, $right, 2);

    return FinancialDecimal::compare($result, '0.00', 2) > 0 ? $result : '0.00';
}
?>
