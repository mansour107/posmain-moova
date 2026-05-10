<?php
session_start();
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    $query = "
        SELECT
            t.*,
            o.id AS order_id,
            o.fat_net,
            CASE WHEN o.id IS NULL THEN 0 ELSE 1 END AS has_active_order
        FROM tables t
        LEFT JOIN (
            SELECT oh.*
            FROM ot_head oh
            INNER JOIN (
                SELECT table_id, MAX(id) AS max_id
                FROM ot_head
                WHERE table_id IS NOT NULL
                  AND table_id <> 0
                  AND pro_tybe = 9
                  AND isdeleted = 0
                  AND COALESCE(order_status, 'active') = 'active'
                  AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
                GROUP BY table_id
            ) latest ON latest.max_id = oh.id
        ) o ON o.table_id = t.id
        WHERE t.isdeleted = 0
        ORDER BY t.id ASC
    ";
    $result = $conn->query($query);
    
    $tables = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['table_case'] = (int) ($row['has_active_order'] ?? 0);
            $tables[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'tables' => $tables
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
