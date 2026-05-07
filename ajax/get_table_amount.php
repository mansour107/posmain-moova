<?php
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    $table_id = intval($_POST['table_id']);
    
    // البحث عن طلب نشط للطاولة باستخدام أكثر من طريقة
    $searchPatterns = [
        "طاولة رقم $table_id",
        "طاولة $table_id", 
        "table $table_id",
        "Table $table_id"
    ];
    
    $total = 0;
    $discount = 0;
    $paid = 0;
    $orderId = 0;
    
    foreach ($searchPatterns as $pattern) {
        $query = "SELECT * FROM ot_head 
                  WHERE info LIKE ? 
                  AND pro_tybe = 9 
                  ORDER BY id DESC 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $searchTerm = "%$pattern%";
        $stmt->bind_param("s", $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();
            $orderId = $order['id'];
            break;
        }
    }
    
    if ($orderId > 0) {
        // حساب إجمالي الأصناف
        $itemsQuery = "SELECT SUM(price * (qty_out - qty_in)) as total_amount 
                      FROM fat_details 
                      WHERE pro_id = ? AND isdeleted = 0";
        
        $itemsStmt = $conn->prepare($itemsQuery);
        $itemsStmt->bind_param("i", $orderId);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        
        if ($itemsResult->num_rows > 0) {
            $row = $itemsResult->fetch_assoc();
            $total = floatval($row['total_amount'] ?? 0);
        }
        
        $discount = floatval($order['fat_disc'] ?? 0);
        $paid = floatval($order['paid'] ?? 0);
    }
    
    $net = $total - $discount;
    $remaining = $net - $paid;
    
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