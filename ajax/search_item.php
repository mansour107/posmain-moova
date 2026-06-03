<?php
// إيقاف عرض الأخطاء لضمان JSON نظيف
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemAvailabilityService.php';
require_once __DIR__ . '/../classes/Items/ItemCatalogStatus.php';

// استخدام dirname للحصول على المسار الصحيح
$root_path = dirname(__DIR__);
include($root_path . '/includes/connect.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['barcode'])) {
    $barcode = substr(trim((string) $_POST['barcode']), 0, 120);
    
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'الباركود فارغ']);
        exit;
    }
    
    // البحث بالباركود أو ID أو اسم الصنف
    // محاولة تحويل الباركود لرقم للبحث بالـ ID
    $numericBarcode = preg_match('/^\d+$/', $barcode) ? intval($barcode) : 0;
    
    $hasVariantTable = false;
    $variantTable = $conn->query("SHOW TABLES LIKE 'item_variants'");
    $hasVariantTable = $variantTable && $variantTable->num_rows > 0;
    $variantSelect = $hasVariantTable
        ? "(EXISTS (SELECT 1 FROM item_variants iv WHERE iv.parent_item_id = myitems.id AND iv.is_active = 1)) AS has_variants"
        : "0 AS has_variants";

    $activeFilter = ItemCatalogStatus::activeOnlySql($conn, 'myitems')
        . ItemCatalogStatus::posSellableOnlySql($conn, 'myitems');
    $sql = "SELECT myitems.*, {$variantSelect} FROM myitems WHERE (barcode = ? OR id = ? OR iname LIKE ?) AND isdeleted = 0 {$activeFilter} LIMIT 1";
    $stmt = $conn->prepare($sql);
    $searchLike = "%{$barcode}%";
    $stmt->bind_param("sis", $barcode, $numericBarcode, $searchLike);
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
        
        $barcodeItem = [
            'id' => (int) $item['id'],
            'name' => $item['iname'],
            'price' => $price,
            'barcode' => $item['barcode'],
            'has_variants' => (int) ($item['has_variants'] ?? 0) === 1
        ];
        $branchConfig = function_exists('posmain_app_config')
            ? (posmain_app_config()['branch'] ?? [])
            : [];
        $decoratedItems = (new ItemAvailabilityService())->decorateItems($conn, [$barcodeItem], [
            'tenant' => (int)($branchConfig['pos_tenant'] ?? 0),
            'branch' => (int)($branchConfig['pos_branch'] ?? 0),
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        echo json_encode([
            'success' => true,
            'item' => $decoratedItems[0] ?? $barcodeItem
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => 'الصنف غير موجود']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طلب غير صحيح']);
}
?>
