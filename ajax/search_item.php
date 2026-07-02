<?php
// إيقاف عرض الأخطاء لضمان JSON نظيف
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemAvailabilityService.php';
require_once __DIR__ . '/../classes/Items/ItemCatalogStatus.php';
require_once __DIR__ . '/../classes/Items/ItemUnitResolver.php';

// استخدام dirname للحصول على المسار الصحيح
$root_path = dirname(__DIR__);
include($root_path . '/includes/connect.php');
require_once($root_path . '/includes/pos_default_accounts.php');

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

    if ($result->num_rows === 0) {
        $unitSql = "
            SELECT myitems.*, iu.u_val, iu.unit_barcode AS unit_row_barcode, iu.price1 AS unit_price1, {$variantSelect}
            FROM item_units iu
            INNER JOIN myitems ON myitems.id = iu.item_id
            WHERE (iu.unit_barcode = ? OR iu.unit_barcode = ?)
              AND COALESCE(iu.isdeleted, 0) = 0
              AND myitems.isdeleted = 0
              {$activeFilter}
            LIMIT 1
        ";
        $unitStmt = $conn->prepare($unitSql);
        $unitStmt->bind_param('ss', $barcode, $barcode);
        $unitStmt->execute();
        $result = $unitStmt->get_result();
    }
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        
        $price = ItemUnitResolver::sellPriceForItem($conn, (int) $item['id']);
        if ($price <= 0) {
            if (isset($item['unit_price1']) && (float) $item['unit_price1'] > 0) {
                $price = (float) $item['unit_price1'];
            } elseif (isset($item['price1']) && (float) $item['price1'] > 0) {
                $price = (float) $item['price1'];
            }
        }
        
        $barcodeItem = [
            'id' => (int) $item['id'],
            'name' => $item['iname'],
            'price' => $price,
            'barcode' => $item['barcode'],
            'u_val' => (float) ($item['u_val'] ?? ItemUnitResolver::sellToStockFactor($conn, (int) $item['id'])),
            'has_variants' => (int) ($item['has_variants'] ?? 0) === 1
        ];
        $decoratedItems = (new ItemAvailabilityService())->decorateItems($conn, [$barcodeItem], posmain_pos_availability_scope($conn));

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
