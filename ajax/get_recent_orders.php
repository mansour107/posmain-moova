<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
// Use AJAX header (no HTML output) instead of pos_simple_header
include(__DIR__ . '/../includes/ajax_header.php');
require_once(__DIR__ . '/../includes/auth_guard.php');
require_once(__DIR__ . '/../classes/Pos/Service/PaymentMethodService.php');

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
    $hasCreditNotes = recentOrdersTableExists($conn, 'credit_notes');
    $refundSelect = $hasCreditNotes
        ? "COALESCE((SELECT SUM(cn.total_amount) FROM credit_notes cn WHERE cn.original_order_id = o.id AND cn.status = 'posted'), 0)"
        : '0';
    $visibleOrderPredicate = $hasCreditNotes
        ? "(o.isdeleted = 0 OR EXISTS (SELECT 1 FROM credit_notes visible_cn WHERE visible_cn.original_order_id = o.id AND visible_cn.status = 'posted'))"
        : 'o.isdeleted = 0';

    $sql = "SELECT
                o.id,
                o.pro_id as invoice_number,
                o.table_id,
                o.acc2,
                o.order_status,
                o.payment_status,
                o.paid_amount,
                {$refundSelect} AS refunded_amount,
                CASE
                    WHEN o.table_id IS NOT NULL
                     AND o.table_id <> 0
                     AND COALESCE(o.order_status, 'active') = 'active'
                     AND COALESCE(o.payment_status, 'unpaid') = 'unpaid'
                     AND COALESCE(o.paid_amount, 0) = 0
                    THEN 1
                    ELSE 0
                END as delete_eligible,
                CASE
                    WHEN COALESCE(o.order_status, 'active') = 'active'
                     AND COALESCE(o.payment_status, 'unpaid') = 'unpaid'
                     AND COALESCE(o.paid_amount, 0) = 0
                     AND {$refundSelect} = 0
                    THEN 1
                    ELSE 0
                END as edit_eligible,
                DATE_FORMAT(COALESCE(o.payment_date, o.completed_at, o.crtime, o.pro_date), '%Y-%m-%d %H:%i') as date,
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
            AND {$visibleOrderPredicate}
            ORDER BY COALESCE(o.payment_date, o.completed_at, o.crtime, o.pro_date) DESC, o.id DESC
            LIMIT {$fetchLimit} OFFSET {$offset}";

    $result = $conn->query($sql);

    $orders = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $deleteEligible = intval($row['delete_eligible'] ?? 0) === 1;
            $editEligible = intval($row['edit_eligible'] ?? 0) === 1;
            $paidCompleted = (string) ($row['payment_status'] ?? '') === 'paid'
                && (string) ($row['order_status'] ?? '') === 'completed';
            $originalAmount = max(0.0, (float) ($row['total'] ?? 0));
            $refundedAmount = max(0.0, (float) ($row['refunded_amount'] ?? 0));
            $remainingRefundable = max(0.0, $originalAmount - $refundedAmount);
            $reversalStatus = $refundedAmount <= 0.005
                ? 'none'
                : ($remainingRefundable <= 0.005 ? 'full' : 'partial');
            $refundEligible = $paidCompleted && $remainingRefundable > 0.005;
            $voidEligible = $refundEligible;
            $status = $reversalStatus === 'full'
                ? 'مسترد بالكامل'
                : ($reversalStatus === 'partial' ? 'مسترد جزئياً' : (string) $row['status']);
            $orders[] = [
                'id' => $row['id'],
                'invoice_number' => $row['invoice_number'] ?: 'ORD-' . $row['id'],
                'table_id' => intval($row['table_id'] ?? 0),
                'delete_eligible' => $deleteEligible,
                'edit_eligible' => $editEligible,
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
                'refunded_amount' => $refundedAmount,
                'remaining_refundable_amount' => $remainingRefundable,
                'reversal_status' => $reversalStatus,
                'status' => $status,
                'notes' => $row['notes']
            ];
        }
    }

    $hasMore = count($orders) > $limit;
    if ($hasMore) {
        array_pop($orders);
    }
    $refundContext = recentOrdersRefundContext($conn, $orders);
    foreach ($orders as &$order) {
        $order['original_payments'] = $refundContext['payments_by_order'][(int) $order['id']] ?? [];
        $order['refundable_lines'] = $refundContext['lines_by_order'][(int) $order['id']] ?? [];
    }
    unset($order);

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'refund_tenders' => $refundContext['refund_tenders'],
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

function recentOrdersColumnExists(mysqli $conn, string $table, string $column): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");

    return $result && $result->num_rows > 0;
}

/**
 * Return immutable original-payment context plus enabled outgoing refund
 * tenders. The client selects only the tender code; the server calculates the
 * authoritative full-refund allocation from the original payment rows.
 *
 * @return array{payments_by_order:array<int,array<int,array>>,lines_by_order:array<int,array<int,array>>,refund_tenders:array<int,array>}
 */
function recentOrdersRefundContext(mysqli $conn, array $orders): array
{
    $paymentsByOrder = [];
    $linesByOrder = [];
    $orderIds = array_values(array_filter(array_map(
        static fn (array $order): int => max(0, (int) ($order['id'] ?? 0)),
        $orders
    )));

    if (
        $orderIds !== []
        && recentOrdersTableExists($conn, 'order_payments')
        && recentOrdersTableExists($conn, 'payment_refunds')
    ) {
        $ids = implode(',', $orderIds);
        $hasMethods = recentOrdersTableExists($conn, 'payment_methods');
        $methodSelect = $hasMethods
            ? ", COALESCE(NULLIF(pm.name_ar, ''), NULLIF(pm.name_en, ''), op.payment_method) AS method_label,
                 COALESCE(pm.type, '') AS method_type"
            : ', op.payment_method AS method_label, NULL AS method_type';
        $methodJoin = $hasMethods
            ? ' LEFT JOIN payment_methods pm ON pm.code = op.payment_method'
            : '';
        $result = $conn->query("
            SELECT op.id,
                   op.order_id,
                   op.payment_method,
                   op.amount AS original_amount,
                   COALESCE(SUM(CASE
                       WHEN pr.status IN ('posted', 'pending_external', 'settled') THEN pr.amount
                       ELSE 0
                   END), 0) AS refunded_amount
                   {$methodSelect}
            FROM order_payments op
            {$methodJoin}
            LEFT JOIN payment_refunds pr ON pr.original_payment_id = op.id
            WHERE op.order_id IN ({$ids})
            GROUP BY op.id, op.order_id, op.payment_method, op.amount, method_label, method_type
            ORDER BY op.order_id, op.id
        ");
        while ($row = $result->fetch_assoc()) {
            $orderId = (int) $row['order_id'];
            $original = max(0.0, (float) $row['original_amount']);
            $refunded = max(0.0, (float) $row['refunded_amount']);
            $paymentsByOrder[$orderId][] = [
                'id' => (int) $row['id'],
                'payment_method' => (string) $row['payment_method'],
                'label' => (string) $row['method_label'],
                'type' => (string) ($row['method_type'] ?? ''),
                'original_amount' => number_format($original, 2, '.', ''),
                'refunded_amount' => number_format($refunded, 2, '.', ''),
                'refundable_amount' => number_format(max(0.0, $original - $refunded), 2, '.', ''),
            ];
        }
    }

    if ($orderIds !== [] && recentOrdersTableExists($conn, 'fat_details')) {
        $ids = implode(',', $orderIds);
        $hasPosted = recentOrdersColumnExists($conn, 'fat_details', 'posted_qty')
            && recentOrdersColumnExists($conn, 'fat_details', 'posted_net');
        $qtyExpr = $hasPosted
            ? 'COALESCE(fd.posted_qty, ABS(fd.qty_out - fd.qty_in))'
            : 'ABS(fd.qty_out - fd.qty_in)';
        $amountExpr = $hasPosted
            ? 'COALESCE(fd.posted_net, fd.det_value)'
            : 'fd.det_value';
        $taxExpr = $hasPosted && recentOrdersColumnExists($conn, 'fat_details', 'posted_tax')
            ? 'COALESCE(fd.posted_tax, 0)'
            : '0';
        $discountExpr = $hasPosted
            && recentOrdersColumnExists($conn, 'fat_details', 'posted_line_discount')
            && recentOrdersColumnExists($conn, 'fat_details', 'posted_order_discount')
            ? 'COALESCE(fd.posted_line_discount, 0) + COALESCE(fd.posted_order_discount, 0)'
            : 'COALESCE(fd.discount, 0)';
        $hasItems = recentOrdersTableExists($conn, 'myitems');
        $itemLabelExpr = $hasItems
            ? "COALESCE(NULLIF(mi.iname, ''), CONCAT('Item #', fd.item_id))"
            : "CONCAT('Item #', fd.item_id)";
        $itemJoin = $hasItems ? 'LEFT JOIN myitems mi ON mi.id = fd.item_id' : '';
        $refundAggregate = recentOrdersTableExists($conn, 'credit_note_lines')
            && recentOrdersTableExists($conn, 'credit_notes')
            ? "LEFT JOIN (
                    SELECT cnl.original_detail_id,
                           SUM(cnl.quantity) AS refunded_quantity,
                           SUM(cnl.line_amount) AS refunded_amount,
                           SUM(cnl.tax_amount) AS refunded_tax
                    FROM credit_note_lines cnl
                    INNER JOIN credit_notes cn ON cn.id = cnl.credit_note_id
                    WHERE cn.status = 'posted'
                    GROUP BY cnl.original_detail_id
               ) refunded ON refunded.original_detail_id = fd.id"
            : '';
        $refundedQtyExpr = $refundAggregate !== '' ? 'COALESCE(refunded.refunded_quantity, 0)' : '0';
        $refundedAmountExpr = $refundAggregate !== '' ? 'COALESCE(refunded.refunded_amount, 0)' : '0';
        $refundedTaxExpr = $refundAggregate !== '' ? 'COALESCE(refunded.refunded_tax, 0)' : '0';
        $result = $conn->query("
            SELECT fd.id AS original_detail_id,
                   fd.fatid AS order_id,
                   fd.item_id,
                   {$itemLabelExpr} AS item_label,
                   {$qtyExpr} AS original_quantity,
                   {$amountExpr} AS original_amount,
                   {$taxExpr} AS original_tax,
                   {$discountExpr} AS original_discount,
                   {$refundedQtyExpr} AS refunded_quantity,
                   {$refundedAmountExpr} AS refunded_amount,
                   {$refundedTaxExpr} AS refunded_tax
            FROM fat_details fd
            {$itemJoin}
            {$refundAggregate}
            WHERE fd.fatid IN ({$ids})
              AND COALESCE(fd.isdeleted, 0) = 0
            ORDER BY fd.fatid, fd.id
        ");
        while ($row = $result->fetch_assoc()) {
            $orderId = (int) $row['order_id'];
            $originalQty = max(0.0, (float) $row['original_quantity']);
            $refundedQty = max(0.0, (float) $row['refunded_quantity']);
            $originalAmount = max(0.0, (float) $row['original_amount']);
            $refundedAmount = max(0.0, (float) $row['refunded_amount']);
            $originalTax = max(0.0, (float) $row['original_tax']);
            $refundedTax = max(0.0, (float) $row['refunded_tax']);
            $remainingQty = max(0.0, $originalQty - $refundedQty);
            $remainingAmount = max(0.0, $originalAmount - $refundedAmount);
            if ($remainingQty <= 0.0000005 || $remainingAmount <= 0.005) {
                continue;
            }
            $linesByOrder[$orderId][] = [
                'original_detail_id' => (int) $row['original_detail_id'],
                'item_id' => (int) $row['item_id'],
                'label' => (string) $row['item_label'],
                'original_quantity' => number_format($originalQty, 6, '.', ''),
                'refunded_quantity' => number_format($refundedQty, 6, '.', ''),
                'remaining_quantity' => number_format($remainingQty, 6, '.', ''),
                'original_amount' => number_format($originalAmount, 2, '.', ''),
                'refunded_amount' => number_format($refundedAmount, 2, '.', ''),
                'remaining_amount' => number_format($remainingAmount, 2, '.', ''),
                'remaining_tax' => number_format(max(0.0, $originalTax - $refundedTax), 2, '.', ''),
                'original_discount' => number_format(max(0.0, (float) $row['original_discount']), 2, '.', ''),
            ];
        }
    }

    $refundTenders = [];
    if (recentOrdersTableExists($conn, 'payment_methods')) {
        foreach ((new PaymentMethodService())->listActive($conn) as $method) {
            $refundTenders[] = [
                'id' => (int) $method['id'],
                'code' => (string) $method['code'],
                'label' => (string) (($method['name_ar'] ?? '') ?: ($method['name_en'] ?? $method['code'])),
                'type' => (string) $method['type'],
                'requires_reference' => !empty($method['requires_reference']),
                'drawer_impact' => !empty($method['drawer_impact']),
            ];
        }
    }

    return [
        'payments_by_order' => $paymentsByOrder,
        'lines_by_order' => $linesByOrder,
        'refund_tenders' => $refundTenders,
    ];
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
