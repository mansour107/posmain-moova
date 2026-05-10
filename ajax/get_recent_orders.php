<?php
// Use AJAX header (no HTML output) instead of pos_simple_header
include(__DIR__ . '/../includes/ajax_header.php');

header('Content-Type: application/json; charset=utf-8');

try {
    // استعلام لجلب آخر 10 طلبات
    $sql = "SELECT
                o.id,
                o.pro_id as invoice_number,
                o.table_id,
                o.order_status,
                o.payment_status,
                CASE
                    WHEN o.table_id IS NOT NULL
                     AND o.table_id <> 0
                     AND COALESCE(o.order_status, 'active') = 'active'
                     AND COALESCE(o.payment_status, 'unpaid') IN ('unpaid', 'partial')
                    THEN 1
                    ELSE 0
                END as can_delete,
                DATE_FORMAT(COALESCE(o.mdtime, o.crtime, o.pro_date), '%Y-%m-%d %H:%i') as date,
	                c.aname as customer_name,
	                CASE
	                    WHEN o.order_type = 'table' THEN 'طاولة'
	                    WHEN o.order_type = 'delivery' THEN 'دليفري'
	                    WHEN o.order_type = 'takeaway' THEN 'تيك أواي'
	                    ELSE 'تيك أواي'
	                END as type,
	                o.fat_net as total,
	                CASE
	                    WHEN o.isdeleted = 1 THEN 'ملغى'
	                    WHEN o.order_status = 'active' THEN 'نشط'
	                    WHEN o.payment_status = 'partial' THEN 'مدفوع جزئياً'
	                    ELSE 'مكتمل'
	                END as status,
                o.info as notes
            FROM ot_head o
            LEFT JOIN acc_head c ON o.acc1 = c.id
            WHERE o.pro_tybe = 9
            AND o.isdeleted = 0
            ORDER BY COALESCE(o.mdtime, o.crtime, o.pro_date) DESC, o.id DESC
            LIMIT 10";

    $result = $conn->query($sql);

    $orders = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = [
                'id' => $row['id'],
                'invoice_number' => $row['invoice_number'] ?: 'ORD-' . $row['id'],
                'table_id' => intval($row['table_id'] ?? 0),
                'can_delete' => intval($row['can_delete'] ?? 0) === 1,
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
