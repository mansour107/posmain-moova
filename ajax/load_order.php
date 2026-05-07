<?php
// تحميل بيانات الطلب النشط للطاولة
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

$root_path = dirname(__DIR__);
include($root_path . '/includes/connect.php');

try {
    if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
        echo json_encode(['success' => false, 'error' => 'Order ID is required']);
        exit;
    }
    
    $order_id = intval($_POST['order_id']);
    
    // جلب بيانات رأس الطلب
    $order_query = "SELECT * FROM ot_head WHERE id = $order_id AND isdeleted = 0 LIMIT 1";
    $order_result = $conn->query($order_query);
    
    if (!$order_result || $order_result->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    
    $order = $order_result->fetch_assoc();
    
    // جلب أصناف الطلب
    $items_query = "SELECT 
                        fd.*,
                        m.iname as item_name,
                        m.barcode,
                        m.info as item_desc
                    FROM fat_details fd
                    LEFT JOIN myitems m ON m.id = fd.item_id
                    WHERE fd.pro_id = $order_id AND fd.isdeleted = 0
                    ORDER BY fd.id";
    
    $items_result = $conn->query($items_query);
    $items = [];
    
    if ($items_result && $items_result->num_rows > 0) {
        while ($item = $items_result->fetch_assoc()) {
            $qty = floatval($item['qty_out']) - floatval($item['qty_in']);
            $items[] = [
                'item_id' => $item['item_id'],
                'item_name' => $item['item_name'] ?: 'صنف غير معروف',
                'item_desc' => $item['item_desc'] ?: '',
                'barcode' => $item['barcode'] ?: $item['item_id'],
                'qty' => $qty,
                'price' => floatval($item['price']),
                'subtotal' => floatval($item['det_value'])
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'order' => [
            'id' => $order['id'],
            'emp_id' => $order['emp_id'],
            'acc1' => $order['acc1'],
            'store_id' => $order['store_id'],
            'fund_id' => $order['acc_fund'],
            'total' => floatval($order['fat_total']),
            'discount' => floatval($order['fat_disc']),
            'net' => floatval($order['fat_net']),
            'paid' => floatval($order['paid']),
            'order_status' => 'active'
        ],
        'items' => $items
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>