<?php
// Use AJAX header (no HTML output) instead of pos_simple_header
include(__DIR__ . '/../includes/ajax_header.php');

header('Content-Type: application/json; charset=utf-8');

try {
    // استعلام لجلب آخر 10 طلبات
    $sql = "SELECT 
                o.id,
                o.pro_id as invoice_number,
                DATE_FORMAT(o.pro_date, '%Y-%m-%d %H:%i') as date,
                c.aname as customer_name,
                CASE 
                    WHEN o.info LIKE '%نوع الطلب: طاولة%' OR o.info LIKE '%طاولة:%' THEN 'طاولة'
                    WHEN o.info LIKE '%نوع الطلب: دليفري%' OR o.info LIKE '%Delivery%' OR o.info LIKE '%دليفري%' THEN 'دليفري'
                    WHEN o.info LIKE '%نوع الطلب: تيك أواي%' THEN 'تيك أواي'
                    ELSE 'تيك أواي'
                END as type,
                o.fat_net as total,
                CASE 
                    WHEN o.isdeleted = 1 THEN 'ملغى'
                    ELSE 'مكتمل'
                END as status,
                o.info as notes
            FROM ot_head o
            LEFT JOIN acc_head c ON o.acc1 = c.id
            WHERE o.pro_tybe = 9 
            AND o.isdeleted = 0
            ORDER BY o.id DESC 
            LIMIT 10";

    $result = $conn->query($sql);
    
    $orders = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = [
                'id' => $row['id'],
                'invoice_number' => $row['invoice_number'] ?: 'ORD-' . $row['id'],
                'date' => $row['date'],
                'customer_name' => $row['customer_name'] ?: 'عميل نقدي',
                'type' => $row['type'],
                'total' => floatval($row['total']),
                'status' => $row['status'],
                'notes' => $row['notes']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'count' => count($orders)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'orders' => []
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>