<?php
/**
 * Lazy Load Items - AJAX
 * تحميل الأصناف بشكل تدريجي
 */

header('Content-Type: application/json');
require_once '../includes/connect.php';
require_once '../classes/Pos/Service/ItemAvailabilityService.php';
require_once '../includes/pos_item_card.php';

try {
    // معاملات البحث والصفحات
    $search = isset($_GET['search']) ? substr(trim((string) $_GET['search']), 0, 120) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset = ($page - 1) * $limit;
    
    // بناء الاستعلام
    $where = "m.isdeleted = 0";
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where .= " AND (m.iname LIKE ? OR m.name2 LIKE ? OR m.barcode LIKE ?)";
        $search_param = "%{$search}%";
        $params = [$search_param, $search_param, $search_param];
        $types = 'sss';
    }
    
    // استعلام محسّن - جلب الأعمدة المطلوبة فقط
    $query = "SELECT m.id, m.iname, m.name2, m.price1, m.barcode, m.group1, m.info, i.iname AS img_filename
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
    while ($row = $result->fetch_assoc()) {
        $item = [
            'id' => $row['id'],
            'iname' => $row['iname'],
            'name2' => $row['name2'] ?? '',
            'price1' => floatval($row['price1'] ?? 0),
            'barcode' => $row['barcode'] ?? '',
            'group1' => $row['group1'] ?? '',
            'info' => $row['info'] ?? '',
            'img_filename' => $row['img_filename'] ?? '',
        ];
        $item['html'] = pos_render_item_card($item);
        $items[] = $item;
    }

    $branchConfig = function_exists('posmain_app_config')
        ? (posmain_app_config()['branch'] ?? [])
        : [];
    $availabilityScope = [
        'tenant' => (int)($branchConfig['pos_tenant'] ?? 0),
        'branch' => (int)($branchConfig['pos_branch'] ?? 0),
        'channel' => 'pos',
    ];
    $items = (new ItemAvailabilityService())->decorateItems($conn, $items, $availabilityScope);
    
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
