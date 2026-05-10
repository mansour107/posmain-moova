<?php
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');

header('Content-Type: application/json');

try {
    $tableId = isset($_GET['table_id']) ? intval($_GET['table_id']) : 0;
    if (!$tableId) {
        throw new Exception('بيانات الطاولة غير صحيحة');
    }

    $tableOrderService = new TableOrderService();
    $table = $tableOrderService->requireTable($conn, $tableId);
    $order = $tableOrderService->findActiveOrderByTableId($conn, $tableId);

    if ($order) {
        $orderId = (int) $order['id'];
        $items = [];
        foreach ($tableOrderService->queryAll($conn, "
            SELECT fd.*, i.iname, i.price1 AS sprice, i.barcode
            FROM fat_details fd
            LEFT JOIN myitems i ON fd.item_id = i.id
            WHERE fd.fatid = ?
              AND fd.isdeleted = 0
            ORDER BY fd.id ASC
        ", [$orderId]) as $item) {
            $qty = floatval($item['qty_out']) - floatval($item['qty_in']);
            $items[] = [
                'id' => $item['item_id'],
                'name' => $item['iname'],
                'price' => floatval($item['price']),
                'qty' => $qty,
                'subtotal' => floatval($item['det_value']),
                'barcode' => $item['barcode'] ?: $item['item_id']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'has_order' => true,
            'order' => array_merge($order, [
                'table_name' => $table['tname'],
            ]),
            'items' => $items
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'has_order' => false,
            'order' => null,
            'items' => []
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
