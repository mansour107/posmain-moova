<?php
// منع أي output قبل JSON
ob_start();

include('../includes/connect.php');
require_once('../classes/Pos/Service/ItemAvailabilityService.php');
require_once('../classes/Items/ItemCatalogStatus.php');

// مسح أي output buffer
ob_end_clean();

// تأكد من JSON header
header('Content-Type: application/json; charset=utf-8');

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$search = substr($search, 0, 120);

if (empty($search)) {
    echo json_encode(['success' => false, 'message' => 'من فضلك أدخل كلمة للبحث'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // تحقق من الاتصال
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $search_param = '%' . $search . '%';
    
    // استخدام الجدول الصحيح myitems والعمود الصحيح price1
    $activeFilter = ItemCatalogStatus::activeOnlySql($conn);
    $query = "SELECT id, iname as name, price1 as price
              FROM myitems
              WHERE (iname LIKE ? OR name2 LIKE ? OR barcode LIKE ?)
                AND isdeleted = 0
                {$activeFilter}
              ORDER BY iname
              LIMIT 50";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('فشل في تحضير استعلام البحث');
    }
    $stmt->bind_param('sss', $search_param, $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'price' => (float)$row['price']
        ];
    }
    $stmt->close();

    $branchConfig = function_exists('posmain_app_config')
        ? (posmain_app_config()['branch'] ?? [])
        : [];
    $availabilityScope = [
        'tenant' => (int)($branchConfig['pos_tenant'] ?? 0),
        'branch' => (int)($branchConfig['pos_branch'] ?? 0),
        'channel' => 'pos',
        'order_type' => 'takeaway',
    ];
    $items = (new ItemAvailabilityService())->decorateItems($conn, $items, $availabilityScope);
    
    echo json_encode([
        'success' => true,
        'items' => $items,
        'count' => count($items)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(posmain_exception_payload(
        $e,
        'حدث خطأ أثناء البحث عن الأصناف، يرجى المحاولة مرة أخرى',
        'ERROR',
        false,
        'search_items'
    ), JSON_UNESCAPED_UNICODE);
}

exit;
?>
