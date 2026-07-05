<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/get_tables.php');

require_once __DIR__ . '/../classes/Sync/SyncOutboxEventService.php';

header('Content-Type: application/json');

try {
    $syncOutbox = new SyncOutboxEventService();
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
            $activeOrderCase = (int) ($row['has_active_order'] ?? 0);
            $storedTableCase = (int) ($row['table_case'] ?? 0);
            $tableCase = $activeOrderCase || $storedTableCase ? 1 : 0;
            $tableId = (int) ($row['id'] ?? 0);

            if ($tableId > 0 && $activeOrderCase === 1 && $storedTableCase !== 1) {
                $updateStmt = $conn->prepare("UPDATE tables SET table_case = ? WHERE id = ?");
                $updateStmt->bind_param("ii", $tableCase, $tableId);
                $updateStmt->execute();
                $updateStmt->close();
                try {
                    $syncOutbox->recordTableSnapshot($conn, $tableId, [
                        'event_type' => 'table.updated',
                        'source_system' => 'pos_table_refresh',
                        'active_order_id' => $tableCase === 1 ? (int) ($row['order_id'] ?? 0) : null,
                    ]);
                } catch (Throwable $syncException) {
                    error_log('POS table refresh sync snapshot failed: ' . $syncException->getMessage());
                }
            }

            $row['table_case'] = $tableCase;
            $row['order_id'] = isset($row['order_id']) ? (int) $row['order_id'] : null;
            $row['fat_net'] = isset($row['fat_net']) ? (float) $row['fat_net'] : 0;
            $row['has_active_order'] = $activeOrderCase;
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
