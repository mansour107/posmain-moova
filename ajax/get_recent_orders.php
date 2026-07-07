<?php
// Use AJAX header (no HTML output) instead of pos_simple_header
include(__DIR__ . '/../includes/ajax_header.php');
require_once(__DIR__ . '/../includes/auth_guard.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 30;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
    $fetchLimit = $limit + 1;

    $defaultClientId = 0;
    $settingsResult = $conn->query('SELECT def_pos_client FROM settings ORDER BY id ASC LIMIT 1');
    if ($settingsResult && $settingsResult->num_rows > 0) {
        $defaultClientId = (int) ($settingsResult->fetch_assoc()['def_pos_client'] ?? 0);
    }

    $hasPosCustomers = recentOrdersTableExists($conn, 'order_fulfillment')
        && recentOrdersTableExists($conn, 'pos_customers');
    $posCustomerSelect = $hasPosCustomers
        ? 'pc.display_name as pos_customer_name, of.pos_customer_id as pos_customer_id'
        : 'NULL as pos_customer_name, NULL as pos_customer_id';
    $posCustomerJoin = $hasPosCustomers
        ? 'LEFT JOIN order_fulfillment of ON of.order_id = o.id
            LEFT JOIN pos_customers pc ON pc.id = of.pos_customer_id AND pc.isdeleted = 0'
        : '';

    $sql = "SELECT
                o.id,
                o.pro_id as invoice_number,
                o.table_id,
                o.acc2,
                o.order_status,
                o.payment_status,
                CASE
                    WHEN o.table_id IS NOT NULL
                     AND o.table_id <> 0
                     AND COALESCE(o.order_status, 'active') = 'active'
                     AND COALESCE(o.payment_status, 'unpaid') IN ('unpaid', 'partial')
                    THEN 1
                    ELSE 0
                END as delete_eligible,
                DATE_FORMAT(COALESCE(o.mdtime, o.crtime, o.pro_date), '%Y-%m-%d %H:%i') as date,
                c.aname as acc_customer_name,
                {$posCustomerSelect},
                CASE
                    WHEN o.order_type = 'table' THEN 'طاولة'
                    WHEN o.order_type = 'delivery' THEN 'دليفري'
                    WHEN o.order_type = 'takeaway' THEN 'تيك أواي'
                    ELSE 'تيك أواي'
                END as type,
                o.fat_net as total,
                CASE
                    WHEN o.payment_status = 'refunded' THEN 'مسترد'
                    WHEN o.payment_status = 'voided' THEN 'ملغى'
                    WHEN o.isdeleted = 1 THEN 'ملغى'
                    WHEN o.order_status = 'active' THEN 'نشط'
                    WHEN o.payment_status = 'partial' THEN 'مدفوع جزئياً'
                    ELSE 'مكتمل'
                END as status,
                o.info as notes
            FROM ot_head o
            LEFT JOIN acc_head c ON o.acc2 = c.id
            {$posCustomerJoin}
            WHERE o.pro_tybe = 9
            AND o.isdeleted = 0
            ORDER BY COALESCE(o.mdtime, o.crtime, o.pro_date) DESC, o.id DESC
            LIMIT {$fetchLimit} OFFSET {$offset}";

    $result = $conn->query($sql);

    $orders = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $deleteEligible = intval($row['delete_eligible'] ?? 0) === 1;
            $paidCompleted = (string) ($row['payment_status'] ?? '') === 'paid'
                && (string) ($row['order_status'] ?? '') === 'completed';
            $refundEligible = $paidCompleted;
            $voidEligible = $paidCompleted;
            $orders[] = [
                'id' => $row['id'],
                'invoice_number' => $row['invoice_number'] ?: 'ORD-' . $row['id'],
                'table_id' => intval($row['table_id'] ?? 0),
                'delete_eligible' => $deleteEligible,
                'refund_eligible' => $refundEligible,
                'void_eligible' => $voidEligible,
                'can_delete' => $deleteEligible,
                'can_refund' => $refundEligible,
                'can_void' => $voidEligible,
                'payment_status' => (string) ($row['payment_status'] ?? ''),
                'order_status' => (string) ($row['order_status'] ?? ''),
                'date' => $row['date'],
                'customer_name' => recentOrdersResolveCustomerName($row, $defaultClientId),
                'pos_customer_id' => $hasPosCustomers ? max(0, (int) ($row['pos_customer_id'] ?? 0)) : 0,
                'type' => $row['type'],
                'total' => floatval($row['total'] ?? 0),
                'status' => $row['status'],
                'notes' => $row['notes']
            ];
        }
    }

    $hasMore = count($orders) > $limit;
    if ($hasMore) {
        array_pop($orders);
    }

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'count' => count($orders),
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => $hasMore,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'orders' => [],
        'has_more' => false,
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();

function recentOrdersTableExists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

    return $result && $result->num_rows > 0;
}

function recentOrdersResolveCustomerName(array $row, int $defaultClientId): string
{
    $posCustomerName = trim((string) ($row['pos_customer_name'] ?? ''));
    if ($posCustomerName !== '') {
        return $posCustomerName;
    }

    $accCustomerName = trim((string) ($row['acc_customer_name'] ?? ''));
    $acc2Id = (int) ($row['acc2'] ?? 0);
    if ($accCustomerName !== '' && ($defaultClientId <= 0 || $acc2Id !== $defaultClientId)) {
        return $accCustomerName;
    }

    return '-';
}
?>
