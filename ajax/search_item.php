<?php
// إيقاف عرض الأخطاء لضمان JSON نظيف
error_reporting(0);
ini_set('display_errors', 0);

session_start();

// استخدام dirname للحصول على المسار الصحيح
$root_path = dirname(__DIR__);
include($root_path . '/includes/connect.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['barcode'])) {
    $barcode = trim($_POST['barcode']);
    
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'الباركود فارغ']);
        exit;
    }
    
    // البحث بالباركود أو ID أو اسم الصنف
    // محاولة تحويل الباركود لرقم للبحث بالـ ID
    $numericBarcode = is_numeric($barcode) ? intval($barcode) : 0;
    
    $sql = "SELECT * FROM myitems WHERE (barcode = ? OR id = ? OR id = ? OR iname LIKE ?) AND isdeleted = 0 LIMIT 1";
    $stmt = $conn->prepare($sql);
    $searchLike = "%{$barcode}%";
    $stmt->bind_param("siss", $barcode, $numericBarcode, $barcode, $searchLike);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        
        // تحديد السعر - جرب price أو price1
        $price = 0;
        if (isset($item['price']) && !empty($item['price'])) {
            $price = floatval($item['price']);
        } elseif (isset($item['price1']) && !empty($item['price1'])) {
            $price = floatval($item['price1']);
        }
        
        echo json_encode([
            'success' => true,
            'item' => [
                'id' => $item['id'],
                'name' => $item['iname'],
                'price' => $price,
                'barcode' => $item['barcode']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الصنف غير موجود']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طلب غير صحيح']);
}
?>
