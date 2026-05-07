<?php
// تمكين تسجيل الأخطاء للتشخيص
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();

// استخدام dirname للحصول على المسار الصحيح
$root_path = dirname(__DIR__);
include($root_path . '/includes/connect.php');

header('Content-Type: application/json');

// تسجيل الطلب
file_put_contents('logs/search_debug.log', date('Y-m-d H:i:s') . ' - Search request received: ' . json_encode($_POST) . "\n", FILE_APPEND);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search = trim($_POST['search']);
    
    if (empty($search)) {
        echo json_encode(['success' => false, 'message' => 'البحث فارغ']);
        exit;
    }
    
    // البحث في الأصناف بالاسم أو الباركود
    $searchLike = "%{$search}%";
    
    $sql = "SELECT m.*, i.iname as img_filename
            FROM myitems m 
            LEFT JOIN imgs i ON i.itemid = m.id 
            WHERE m.isdeleted = 0 
            AND (m.iname LIKE ? OR m.barcode LIKE ? OR m.id = ?)
            GROUP BY m.id
            ORDER BY m.iname
            LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    $numericSearch = is_numeric($search) ? intval($search) : 0;
    $stmt->bind_param("ssi", $searchLike, $searchLike, $numericSearch);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        // تحديد السعر
        $price = 0;
        if (isset($row['price1']) && !empty($row['price1'])) {
            $price = floatval($row['price1']);
        } elseif (isset($row['price']) && !empty($row['price'])) {
            $price = floatval($row['price']);
        }
        
        // الصورة
        $itemImage = '';
        if (isset($row['img_filename']) && !empty($row['img_filename'])) {
            $itemImage = 'uploads/' . htmlspecialchars($row['img_filename']);
        }
        
        $items[] = [
            'id' => $row['id'],
            'name' => $row['iname'],
            'price' => $price,
            'barcode' => $row['barcode'],
            'image' => $itemImage,
            'desc' => $row['info'] ?? '',
            'category' => $row['group1'] ?? ''
        ];
    }
    
    echo json_encode([
        'success' => true,
        'items' => $items,
        'count' => count($items)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'طلب غير صحيح']);
}
?>