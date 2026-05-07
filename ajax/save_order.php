<?php
session_start();
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    // قراءة البيانات من JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('بيانات غير صحيحة');
    }
    
    $tableId = intval($data['table_id']);
    $tableName = $data['table_name'];
    $orderId = isset($data['order_id']) && $data['order_id'] ? intval($data['order_id']) : 0;
    $orderDate = $data['order_date'];
    $storeId = intval($data['store_id']);
    $empId = intval($data['emp_id']);
    $fundId = intval($data['fund_id']);
    $items = $data['items'];
    $total = floatval($data['total']);
    $discount = floatval($data['discount']);
    $net = floatval($data['net']);
    
    $userId = $_SESSION['userid'] ?? 1;
    
    $conn->begin_transaction();
    
    if ($orderId > 0) {
        // تحديث طلب موجود
        $updateQuery = "UPDATE ot_head SET 
                       pro_date = ?,
                       store_id = ?,
                       emp_id = ?,
                       fat_total = ?,
                       fat_disc = ?,
                       pro_value = ?,
                       mdtime = CURRENT_TIMESTAMP
                       WHERE id = ?";
        
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("siidddi", $orderDate, $storeId, $empId, $total, $discount, $net, $orderId);
        $stmt->execute();
        
        // حذف الأصناف القديمة
        $deleteItems = "UPDATE fat_details SET isdeleted = 1 WHERE pro_id = ?";
        $stmt = $conn->prepare($deleteItems);
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        
    } else {
        // إنشاء طلب جديد
        $insertQuery = "INSERT INTO ot_head (
                       pro_id, pro_tybe, pro_date, accural_date, store_id, emp_id, 
                       acc1, acc2, fat_total, fat_disc, pro_value, info, user, crtime
                       ) VALUES (
                       1, 9, ?, ?, ?, ?, 
                       122, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
                       )";
        
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("ssiiiiddsi", 
            $orderDate, $orderDate, $storeId, $empId,
            $fundId, $total, $discount, $net, $tableName, $userId
        );
        $stmt->execute();
        $orderId = $conn->insert_id;
        
        // تحديث حالة الطاولة
        $updateTable = "UPDATE tables SET table_case = 1 WHERE id = ?";
        $stmt = $conn->prepare($updateTable);
        $stmt->bind_param("i", $tableId);
        $stmt->execute();
    }
    
    // إضافة الأصناف
    $insertItemQuery = "INSERT INTO fat_details (
                       pro_id, item_id, qty, price, isdeleted
                       ) VALUES (?, ?, ?, ?, 0)";
    
    $stmt = $conn->prepare($insertItemQuery);
    
    foreach ($items as $item) {
        $itemId = intval($item['id']);
        $qty = floatval($item['qty']);
        $price = floatval($item['price']);
        
        $stmt->bind_param("iidd", $orderId, $itemId, $qty, $price);
        $stmt->execute();
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'message' => 'تم حفظ الطلب بنجاح'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

