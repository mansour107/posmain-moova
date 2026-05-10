<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'No data received'], JSON_UNESCAPED_UNICODE);
    exit;
}

$original_order_id = intval($data['order_id'] ?? 0);
$table_id = intval($data['table_id'] ?? 0);
$raw_items = is_array($data['items'] ?? null) ? $data['items'] : [];
$split_requests = [];
foreach ($raw_items as $item) {
    if (is_array($item)) {
        $detailId = intval($item['detail_id'] ?? $item['detailId'] ?? $item['id'] ?? 0);
        $qty = isset($item['qty']) ? floatval($item['qty']) : (isset($item['quantity']) ? floatval($item['quantity']) : null);
    } else {
        $detailId = intval($item);
        $qty = null;
    }

    if ($detailId > 0) {
        if (!isset($split_requests[$detailId])) {
            $split_requests[$detailId] = ['qty' => null];
        }
        if ($qty !== null) {
            $split_requests[$detailId]['qty'] = ($split_requests[$detailId]['qty'] ?? 0) + $qty;
        }
    }
}
$selected_items = array_keys($split_requests);
$paid_amount = floatval($data['paid_amount'] ?? 0);
$payment_method = trim((string) ($data['payment_method'] ?? 'cash'));
$user_id = intval($_SESSION['userid'] ?? 1);

if ($original_order_id <= 0 || $table_id <= 0 || !$selected_items || $paid_amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'بيانات السداد المقسم غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new TableOrderService();
    $conn->begin_transaction();

    $service->requireTable($conn, $table_id);
    $orig_order = $service->findActiveOrderByTableAndOrderId($conn, $table_id, $original_order_id, true);
    if (!$orig_order) {
        throw new Exception('الطلب الأصلي غير موجود أو لم يعد نشطاً لهذه الطاولة');
    }

    $placeholders = implode(',', array_fill(0, count($selected_items), '?'));
    $detailParams = array_merge([$original_order_id], $selected_items);
    $details = $service->queryAll($conn, "
        SELECT *
        FROM fat_details
        WHERE fatid = ?
          AND isdeleted = 0
          AND id IN ($placeholders)
        FOR UPDATE
    ", $detailParams);

    if (count($details) !== count($selected_items)) {
        throw new Exception('بعض الأصناف المختارة لا تخص الطلب الأصلي');
    }

    $detailsById = [];
    foreach ($details as $detail) {
        $detailsById[(int) $detail['id']] = $detail;
    }

    $splitLines = [];
    $childTotal = 0;
    foreach ($selected_items as $detailId) {
        $detail = $detailsById[$detailId] ?? null;
        if (!$detail) {
            throw new Exception('بعض الأصناف المختارة لا تخص الطلب الأصلي');
        }

        $availableQty = max(0, floatval($detail['qty_out'] ?? 0) - floatval($detail['qty_in'] ?? 0));
        $requestedQty = $split_requests[$detailId]['qty'];
        if ($requestedQty === null) {
            $requestedQty = $availableQty;
        }
        if ($availableQty <= 0 || $requestedQty <= 0 || $requestedQty > $availableQty + 0.0001) {
            throw new Exception('كمية الصنف المختارة غير صحيحة');
        }

        $ratio = min(1, $requestedQty / $availableQty);
        $childValue = round((float) ($detail['det_value'] ?? 0) * $ratio, 4);
        $childProfit = round((float) ($detail['profit'] ?? 0) * $ratio, 4);
        $splitLines[] = [
            'detail' => $detail,
            'qty' => $requestedQty,
            'value' => $childValue,
            'profit' => $childProfit,
            'is_full' => abs($requestedQty - $availableQty) <= 0.0001,
        ];
        $childTotal += $childValue;
    }
    if ($childTotal <= 0) {
        throw new Exception('قيمة الأصناف المختارة غير صحيحة');
    }
    if ($paid_amount + 0.0001 < $childTotal) {
        throw new Exception('المبلغ المدفوع أقل من قيمة الأصناف المختارة');
    }

    $next = $conn->query("SELECT COALESCE(MAX(CAST(pro_id AS UNSIGNED)), 0) + 1 AS next_id FROM ot_head WHERE pro_tybe = 9")->fetch_assoc();
    $new_invoice_num = intval($next['next_id'] ?? 1);
    $split_group_id = bin2hex(random_bytes(16));
    $date = date('Y-m-d');
    $new_info = "سداد جزئي من طاولة " . $table_id . " - أصل الطلب " . $original_order_id;

    $service->execute($conn, "
        INSERT INTO ot_head (
            pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, payment_method, payment_date, completed_at, parent_order_id,
            split_group_id, info, user
        ) VALUES (
            ?, ?, ?, 'table', 9, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, 0,
            ?, ?, 0, 'paid', 'completed',
            'completed', ?, NOW(), NOW(), ?,
            ?, ?, ?
        )
    ", [
        $new_invoice_num,
        (int) ($orig_order['branch_id'] ?? 0),
        $table_id,
        $date,
        $date,
        (int) ($orig_order['store_id'] ?? 0),
        (int) ($orig_order['emp_id'] ?? 0),
        (int) ($orig_order['emp2_id'] ?? $orig_order['emp_id'] ?? 0),
        (int) ($orig_order['acc1'] ?? 0),
        (int) ($orig_order['acc2'] ?? 0),
        $childTotal,
        $childTotal,
        $childTotal,
        $childTotal,
        $payment_method,
        $original_order_id,
        $split_group_id,
        $new_info,
        $user_id,
    ]);
    $new_head_id = (int) $conn->insert_id;

    foreach ($splitLines as $line) {
        $detailId = (int) $line['detail']['id'];
        if ($line['is_full']) {
            $service->execute($conn, "
                UPDATE fat_details
                SET fatid = ?,
                    pro_id = ?,
                    pro_tybe = 9,
                    fat_tybe = 9
                WHERE id = ?
            ", [$new_head_id, $new_head_id, $detailId]);
        } else {
            $service->execute($conn, "
                INSERT INTO fat_details (
                    pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
                    price, cost_price, stock_value, discount, plus, det_value,
                    profit, fatid, fat_tybe, tenant, branch
                )
                SELECT
                    9, det_store, ?, item_id, u_val, 0, ?,
                    price, cost_price, stock_value, discount, plus, ?,
                    ?, ?, 9, tenant, branch
                FROM fat_details
                WHERE id = ?
            ", [$new_head_id, $line['qty'], $line['value'], $line['profit'], $new_head_id, $detailId]);

            $service->execute($conn, "
                UPDATE fat_details
                SET qty_out = qty_out - ?,
                    det_value = GREATEST(0, det_value - ?),
                    profit = profit - ?
                WHERE id = ?
            ", [$line['qty'], $line['value'], $line['profit'], $detailId]);
        }
    }

    $remainingTotals = $service->recalculateOrderTotals($conn, $original_order_id);
    $remainingLines = $service->queryOne($conn, "
        SELECT COUNT(*) AS c
        FROM fat_details
        WHERE fatid = ?
          AND isdeleted = 0
          AND qty_out > qty_in
    ", [$original_order_id]);

    if ((int) ($remainingLines['c'] ?? 0) > 0 && $remainingTotals['net'] > 0) {
        $originalPaid = min((float) ($orig_order['paid_amount'] ?? 0), $remainingTotals['net']);
        $originalRemaining = max(0, $remainingTotals['net'] - $originalPaid);
        if ($originalPaid <= 0) {
            $originalPaymentStatus = 'unpaid';
            $originalInvoiceStatus = 'draft';
            $originalOrderStatus = 'active';
        } elseif ($originalRemaining <= 0.0001) {
            $originalPaymentStatus = 'paid';
            $originalInvoiceStatus = 'completed';
            $originalOrderStatus = 'completed';
        } else {
            $originalPaymentStatus = 'partial';
            $originalInvoiceStatus = 'draft';
            $originalOrderStatus = 'active';
        }

        $service->execute($conn, "
            UPDATE ot_head
            SET payment_status = ?,
                invoice_status = ?,
                order_status = ?,
                paid_amount = ?,
                remaining_amount = ?
            WHERE id = ?
              AND table_id = ?
        ", [
            $originalPaymentStatus,
            $originalInvoiceStatus,
            $originalOrderStatus,
            $originalPaid,
            $originalRemaining,
            $original_order_id,
            $table_id,
        ]);
        if ($originalOrderStatus === 'completed') {
            $service->setTableFreeIfNoActiveOrder($conn, $table_id);
        } else {
            $service->markTableOccupied($conn, $table_id);
        }
    } else {
        $service->execute($conn, "
            UPDATE ot_head
            SET payment_status = 'paid',
                invoice_status = 'completed',
                order_status = 'completed',
                paid_amount = 0,
                remaining_amount = 0,
                completed_at = NOW()
            WHERE id = ?
              AND table_id = ?
        ", [$original_order_id, $table_id]);
        $service->setTableFreeIfNoActiveOrder($conn, $table_id);
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'order_payments'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $service->execute($conn, "
            INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ", [$new_head_id, $childTotal, $payment_method, $user_id]);
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => 'تم سداد الأصناف المختارة بنجاح',
        'new_invoice_id' => $new_head_id,
        'split_group_id' => $split_group_id,
        'remaining_total' => $remainingTotals['net'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
