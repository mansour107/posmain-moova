<?php
session_start();
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    $tableId = isset($_GET['table_id']) ? intval($_GET['table_id']) : 0;
    $tableName = isset($_GET['table_name']) ? $_GET['table_name'] : '';
    
    if (!$tableId || !$tableName) {
        throw new Exception('بيانات الطاولة غير صحيحة');
    }
    
    // البحث عن طلب نشط للطاولة
    $query = "SELECT * FROM ot_head 
              WHERE info LIKE ? 
              AND pro_tybe = 9 
              ORDER BY id DESC 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $searchTerm = "%$tableName%";
    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        $orderId = $order['id'];
        
        // جلب الأصناف
        $itemsQuery = "SELECT fd.*, i.iname, i.price1 as sprice 
                      FROM fat_details fd 
                      LEFT JOIN myitems i ON fd.item_id = i.id 
                      WHERE fd.pro_id = ? AND fd.isdeleted = 0";
        
        $itemsStmt = $conn->prepare($itemsQuery);
        $itemsStmt->bind_param("i", $orderId);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        
        $items = [];
        while ($item = $itemsResult->fetch_assoc()) {
            $items[] = [
                'id' => $item['item_id'],
                'name' => $item['iname'],
                'price' => floatval($item['price']),
                'qty' => floatval($item['qty']),
                'subtotal' => floatval($item['price']) * floatval($item['qty']),
                'barcode' => $item['item_id']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
    } else {
        echo json_encode([
            'success' => true,
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

