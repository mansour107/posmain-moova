<?php
// منع أي output قبل JSON
ob_start();

include('../includes/connect.php');

// مسح أي output buffer
ob_end_clean();

// تأكد من JSON header
header('Content-Type: application/json; charset=utf-8');

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (empty($search)) {
    echo json_encode(['success' => false, 'message' => 'من فضلك أدخل كلمة للبحث'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // تحقق من الاتصال
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $search_param = '%' . $conn->real_escape_string($search) . '%';
    
    // استخدام الجدول الصحيح myitems والعمود الصحيح price1
    $query = "SELECT id, iname as name, price1 as price FROM myitems WHERE iname LIKE '$search_param' AND isdeleted = 0 ORDER BY iname LIMIT 50";
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception($conn->error);
    }
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'price' => (float)$row['price']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'items' => $items,
        'count' => count($items)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطأ: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

exit;
?>
