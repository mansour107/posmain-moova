<?php
session_start();
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        throw new Exception('بيانات غير صحيحة');
    }

    $tableId = intval($data['table_id'] ?? 0);
    $orderId = intval($data['order_id'] ?? 0);
    $orderDate = trim((string) ($data['order_date'] ?? date('Y-m-d')));
    $storeId = intval($data['store_id'] ?? 0);
    $empId = intval($data['emp_id'] ?? 0);
    $fundId = intval($data['fund_id'] ?? 0);
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $total = floatval($data['total'] ?? 0);
    $discount = floatval($data['discount'] ?? 0);
    $net = floatval($data['net'] ?? max(0, $total - $discount));
    $userId = intval($_SESSION['userid'] ?? 1);

    if ($tableId <= 0) {
        throw new Exception('الرجاء اختيار طاولة');
    }
    if (!$items) {
        throw new Exception('الرجاء إضافة أصناف للطلب');
    }
    if ($storeId <= 0 || $empId <= 0 || $fundId <= 0) {
        throw new Exception('بيانات المخزن أو الموظف أو الصندوق ناقصة');
    }

    $service = new TableOrderService();
    $conn->begin_transaction();

    $table = $service->requireTable($conn, $tableId);
    $existingPaid = 0;
    if ($orderId > 0) {
        $activeOrder = $service->findActiveOrderByTableAndOrderId($conn, $tableId, $orderId, true);
        if (!$activeOrder) {
            throw new Exception('الطلب المحدد لا يخص هذه الطاولة أو لم يعد نشطاً');
        }
        $existingPaid = floatval($activeOrder['paid_amount'] ?? 0);
    } else {
        $existingOrder = $service->findActiveOrderByTableId($conn, $tableId, true);
        if ($existingOrder) {
            throw new Exception('هذه الطاولة لديها طلب نشط بالفعل. أعد تحميل الطلب قبل الحفظ.');
        }
    }

    $settings = $conn->query("SELECT def_pos_client FROM settings WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1");
    $settingsRow = $settings ? $settings->fetch_assoc() : null;
    $clientId = $service->resolveDefaultCustomerId($conn, intval($settingsRow['def_pos_client'] ?? 0));

    $info = $service->buildInfo('table', $table['tname'], '');
    if ($orderId > 0) {
        $service->execute($conn, "
            UPDATE ot_head
            SET pro_date = ?,
                accural_date = ?,
                store_id = ?,
                emp_id = ?,
                emp2_id = ?,
                acc1 = ?,
                acc2 = ?,
                fat_total = ?,
                fat_disc = ?,
                fat_net = ?,
                pro_value = ?,
                remaining_amount = ?,
                table_id = ?,
                order_type = 'table',
                payment_status = 'unpaid',
                invoice_status = 'draft',
                order_status = 'active',
                waiter_id = ?,
                info = ?
            WHERE id = ?
        ", [
            $orderDate,
            $orderDate,
            $storeId,
            $empId,
            $empId,
            $fundId,
            $clientId,
            $total,
            $discount,
            $net,
            $net,
            $net,
            $tableId,
            $empId,
            $info,
            $orderId,
        ]);
        $service->execute($conn, "UPDATE fat_details SET isdeleted = 1 WHERE fatid = ?", [$orderId]);
    } else {
        $next = $conn->query("SELECT COALESCE(MAX(CAST(pro_id AS UNSIGNED)), 0) + 1 AS next_id FROM ot_head WHERE pro_tybe = 9")->fetch_assoc();
        $proId = intval($next['next_id'] ?? 1);
        $service->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, pro_date, accural_date, store_id, emp_id, emp2_id,
                acc1, acc2, fat_total, fat_disc, fat_net, pro_value, remaining_amount,
                table_id, order_type, payment_status, invoice_status, order_status, waiter_id,
                info, user, crtime
            ) VALUES (
                ?, 9, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, 'table', 'unpaid', 'draft', 'active', ?,
                ?, ?, CURRENT_TIMESTAMP
            )
        ", [
            $proId,
            $orderDate,
            $orderDate,
            $storeId,
            $empId,
            $empId,
            $fundId,
            $clientId,
            $total,
            $discount,
            $net,
            $net,
            $net,
            $tableId,
            $empId,
            $info,
            $userId,
        ]);
        $orderId = (int) $conn->insert_id;
    }

    foreach ($items as $item) {
        $itemId = intval($item['id'] ?? 0);
        $qty = floatval($item['qty'] ?? 0);
        $price = floatval($item['price'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }
        $detValue = $qty * $price;
        $service->execute($conn, "
            INSERT INTO fat_details (
                pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                discount, det_value, fatid, fat_tybe, det_store
            ) VALUES (9, ?, ?, 1, 0, ?, ?, 0, ?, ?, 9, ?)
        ", [$orderId, $itemId, $qty, $price, $detValue, $orderId, $storeId]);
    }

    $totals = $service->recalculateOrderTotals($conn, $orderId);
    $appliedPaid = min($existingPaid, $totals['net']);
    $remaining = max(0, $totals['net'] - $appliedPaid);
    if ($appliedPaid <= 0) {
        $paymentStatus = 'unpaid';
        $invoiceStatus = 'draft';
        $orderStatus = 'active';
    } elseif ($remaining <= 0.0001) {
        $paymentStatus = 'paid';
        $invoiceStatus = 'completed';
        $orderStatus = 'completed';
    } else {
        $paymentStatus = 'partial';
        $invoiceStatus = 'draft';
        $orderStatus = 'active';
    }
    $service->execute($conn, "
        UPDATE ot_head
        SET paid_amount = ?,
            remaining_amount = ?,
            payment_status = ?,
            invoice_status = ?,
            order_status = ?,
            completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
        WHERE id = ?
          AND table_id = ?
    ", [$appliedPaid, $remaining, $paymentStatus, $invoiceStatus, $orderStatus, $orderStatus, $orderId, $tableId]);

    if ($orderStatus === 'completed') {
        $service->setTableFreeIfNoActiveOrder($conn, $tableId);
    } else {
        $service->markTableOccupied($conn, $tableId);
    }
    $conn->commit();

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'message' => 'تم حفظ الطلب بنجاح'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
