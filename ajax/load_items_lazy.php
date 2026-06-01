<?php
/**
 * Lazy Load Items - AJAX
 * تحميل الأصناف بشكل تدريجي
 */

header('Content-Type: application/json');
require_once '../includes/connect.php';
require_once '../classes/Pos/Service/ItemAvailabilityService.php';
require_once '../classes/Pos/Service/ItemVariantService.php';
require_once '../classes/Items/ItemCatalogStatus.php';
require_once '../includes/pos_item_card.php';

try {
    // معاملات البحث والصفحات
    $search = isset($_GET['search']) ? substr(trim((string) $_GET['search']), 0, 120) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset = ($page - 1) * $limit;
    $hasVariantTable = false;
    $variantTableResult = $conn->query("SHOW TABLES LIKE 'item_variants'");
    $hasVariantTable = $variantTableResult && $variantTableResult->num_rows > 0;
    
    // بناء الاستعلام
    $where = "m.isdeleted = 0" . ItemCatalogStatus::activeOnlySql($conn, 'm');
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where .= " AND (m.iname LIKE ? OR m.name2 LIKE ? OR m.barcode LIKE ?)";
        $search_param = "%{$search}%";
        $params = [$search_param, $search_param, $search_param];
        $types = 'sss';
    }
    if ($hasVariantTable && $search === '') {
        $where .= " AND NOT EXISTS (
            SELECT 1
            FROM item_variants ivc
            WHERE ivc.variant_item_id = m.id
              AND ivc.is_active = 1
        )";
    }
    $variantSelect = $hasVariantTable
        ? "(EXISTS (SELECT 1 FROM item_variants iv WHERE iv.parent_item_id = m.id AND iv.is_active = 1)) AS has_variants"
        : "0 AS has_variants";
    
    // استعلام محسّن - جلب الأعمدة المطلوبة فقط
    $query = "SELECT m.id, m.iname, m.name2, m.price1, m.barcode, m.group1, m.info, i.iname AS img_filename, {$variantSelect}
              FROM myitems m
              LEFT JOIN (
                  SELECT itemid, MIN(id) AS image_id
                  FROM imgs
                  WHERE isdeleted = 0
                  GROUP BY itemid
              ) image_pick ON image_pick.itemid = m.id
              LEFT JOIN imgs i ON i.id = image_pick.image_id
              WHERE {$where} 
              ORDER BY COALESCE(m.salesqty, 0) DESC, m.iname
              LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('فشل في تحضير الاستعلام');
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    $variantParentIds = [];
    while ($row = $result->fetch_assoc()) {
        $hasVariants = (int) ($row['has_variants'] ?? 0) === 1;
        $item = [
            'id' => $row['id'],
            'iname' => $row['iname'],
            'name2' => $row['name2'] ?? '',
            'price1' => floatval($row['price1'] ?? 0),
            'barcode' => $row['barcode'] ?? '',
            'group1' => $row['group1'] ?? '',
            'info' => $row['info'] ?? '',
            'img_filename' => $row['img_filename'] ?? '',
            'has_variants' => $hasVariants,
        ];
        if ($hasVariants) {
            $variantParentIds[] = (int) $row['id'];
        }
        $items[] = $item;
    }

    if ($variantParentIds) {
        $variantsByParent = (new ItemVariantService())->activeVariantsForParents($conn, $variantParentIds);
        foreach ($items as &$item) {
            if (!empty($item['has_variants'])) {
                $itemId = (int) $item['id'];
                $item['variants'] = $variantsByParent[$itemId] ?? [];
            }
        }
        unset($item);
    }

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

    foreach ($items as &$item) {
        $item['html'] = pos_render_item_card($item);
    }
    unset($item);
    
    // حساب إجمالي العدد
    $count_query = "SELECT COUNT(*) as total FROM myitems m WHERE {$where}";
    $count_stmt = $conn->prepare($count_query);
    
    if (!empty($search)) {
        $count_stmt->bind_param('sss', $search_param, $search_param, $search_param);
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    $stmt->close();
    $count_stmt->close();
    
    echo json_encode([
        'success' => true,
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'has_more' => ($offset + $limit) < $total
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    $payload = posmain_exception_payload(
        $e,
        'حدث خطأ أثناء تحميل الأصناف، يرجى المحاولة مرة أخرى',
        'ERROR',
        false,
        'load_items_lazy'
    );
    $payload['error'] = $payload['message'];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
