<?php
/**
 * Lazy Load Items - AJAX
 * تحميل الأصناف بشكل تدريجي
 */

header('Content-Type: application/json');
require_once '../includes/connect.php';

try {
    // معاملات البحث والصفحات
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $offset = ($page - 1) * $limit;
    
    // بناء الاستعلام
    $where = "isdeleted = 0";
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where .= " AND (iname LIKE ? OR name2 LIKE ? OR barcode LIKE ?)";
        $search_param = "%{$search}%";
        $params = [$search_param, $search_param, $search_param];
        $types = 'sss';
    }
    
    // استعلام محسّن - جلب الأعمدة المطلوبة فقط
    $query = "SELECT id, iname, name2, price1, barcode 
              FROM myitems 
              WHERE {$where} 
              ORDER BY iname 
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
        $items[] = [
            'id' => $row['id'],
            'iname' => $row['iname'],
            'name2' => $row['name2'] ?? '',
            'price1' => floatval($row['price1'] ?? 0),
            'barcode' => $row['barcode'] ?? ''
        ];
    }
    
    // حساب إجمالي العدد
    $count_query = "SELECT COUNT(*) as total FROM myitems WHERE {$where}";
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
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
