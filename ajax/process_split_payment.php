<?php
// Prevent PHP notices/warnings from corrupting JSON
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any stray whitespace or includes output
ob_start();

session_start();
include('../includes/connect.php');

// Clear any output generated so far (like whitespace from includes)
ob_clean();

header('Content-Type: application/json');

// استلام البيانات
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit;
}

$original_order_id = intval($data['order_id']);
$table_id = intval($data['table_id']);
$selected_items = $data['items']; // Array of detail IDs to split
$paid_amount = floatval($data['paid_amount']);
$payment_method = $data['payment_method'] ?? 'cash'; // 'cash' or 'visa'

if (empty($selected_items)) {
    echo json_encode(['success' => false, 'message' => 'No items selected']);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. إنشاء فاتورة جديدة للأصناف المدفوعة
    
    // جلب بيانات الفاتورة الأصلية لنسخ البيانات (العميل، الموظف، الخ)
    $stmt = $conn->prepare("SELECT * FROM ot_head WHERE id = ?");
    $stmt->bind_param("i", $original_order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $orig_order = $result->fetch_assoc();
    $stmt->close();
    
    if (!$orig_order) throw new Exception("Original order not found");
    
    // إنشاء رأس فاتورة جديدة (مدفوعة)
    $type_sales = 3; // Sales Invoce
    
    // الحصول على رقم فاتورة جديد
    $next_id_stmt = $conn->prepare("SELECT MAX(CAST(pro_id AS UNSIGNED)) + 1 FROM ot_head WHERE pro_tybe = ?");
    $next_id_stmt->bind_param("i", $type_sales);
    $next_id_stmt->execute();
    $inv_num_res = $next_id_stmt->get_result()->fetch_row();
    $new_invoice_num = $inv_num_res[0] ?? 1;
    $next_id_stmt->close();

    $new_info = "سداد جزئي من طاولة " . $table_id;

    $insert_head = $conn->prepare("INSERT INTO ot_head 
        (pro_id, pro_tybe, pro_date, store_id, emp_id, acc1, acc2, pro_value, fat_net, info, user)
        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)");
        
    $pro_value = $paid_amount;
    $fat_net = $paid_amount;
    
    // Note: acc2 here uses store_id, which matches original logic. Ensure store_id is int.
    $acc2 = $orig_order['store_id']; 
    $user_id = isset($_SESSION['userid']) ? $_SESSION['userid'] : 1;

    $insert_head->bind_param("siiiidddsi", 
        $new_invoice_num, 
        $type_sales, 
        $orig_order['store_id'], 
        $orig_order['emp_id'],
        $orig_order['acc1'], 
        $acc2,
        $pro_value,
        $fat_net,
        $new_info,
        $user_id
    );
    
    $insert_head->execute();
    $new_head_id = $conn->insert_id;
    $insert_head->close();

    // 2. نقل الأصناف المختارة من الفاتورة القديمة للجديدة
    foreach ($selected_items as $detail_id) {
        $update_detail = $conn->prepare("UPDATE fat_details SET fatid = ?, pro_id = ?, pro_tybe = ? WHERE id = ?");
        $update_detail->bind_param("isii", $new_head_id, $new_invoice_num, $type_sales, $detail_id);
        $update_detail->execute();
        $update_detail->close();
    }
    
    // 3. إعادة حساب إجمالي الفاتورة القديمة
    $calc_old = $conn->prepare("SELECT SUM(det_value) FROM fat_details WHERE fatid = ? AND isdeleted = 0");
    $calc_old->bind_param("i", $original_order_id);
    $calc_old->execute();
    $res_old = $calc_old->get_result()->fetch_row();
    $remaining_total = floatval($res_old[0] ?? 0);
    $calc_old->close();
    
    // تحديث الفاتورة القديمة
    $update_old = $conn->prepare("UPDATE ot_head SET pro_value = ?, fat_total = ?, fat_net = ? WHERE id = ?");
    $update_old->bind_param("dddi", $remaining_total, $remaining_total, $remaining_total, $original_order_id);
    $update_old->execute();
    $update_old->close();
    
    // 5. التحقق مما إذا كانت الفاتورة القديمة فرغت تماماً
    if ($remaining_total <= 0) {
        // إغلاق الطاولة
        $close_tbl = $conn->prepare("UPDATE tables SET table_case = 0 WHERE id = ?");
        $close_tbl->bind_param("i", $table_id);
        $close_tbl->execute();
        
        // تعليم الفاتورة القديمة كمحذوفة
        $conn->query("UPDATE ot_head SET isdeleted = 1 WHERE id = $original_order_id");
    }

    // 6. إنشاء سند قبض (Receipt Voucher) للمبلغ المدفوع
    $usid = $_SESSION['userid'] ?? 1;
    $date = date('Y-m-d');
    
    // جلب حساب الخزينة/الصندوق
    $safe_acc = 51; // القيمة الافتراضية
    $safe_res = $conn->query("SELECT id FROM acc_head WHERE aname LIKE '%خزينة%' OR aname LIKE '%صندوق%' LIMIT 1");
    if ($safe_res && $safe_res->num_rows > 0) {
        $safe_acc = $safe_res->fetch_assoc()['id'];
    }
    
    // إنشاء سند القبض (pro_tybe = 1)
    $stmt = $conn->prepare(
        "INSERT INTO ot_head (
            pro_tybe, is_journal, journal_tybe, info, pro_date, 
            emp_id, acc1, acc2, pro_value, fat_net, cost_center, profit, user, op2
        ) VALUES (1, 1, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)"
    );
    
    $info_text = "سند قبض - سداد جزئي طاولة " . $table_id . " - فاتورة رقم " . $new_head_id;
    $customer_acc = $orig_order['acc1'] ?? 0;
    $emp_id = $orig_order['emp_id'] ?? 0;
    
    $stmt->bind_param("ssiiiddii", 
        $info_text, $date, $emp_id, $safe_acc, $customer_acc, 
        $paid_amount, $paid_amount, $usid, $new_head_id
    );
    $stmt->execute();
    $receipt_id = $conn->insert_id;
    $stmt->close();
    
    // 7. إنشاء قيد يومية (Journal Entry)
    // الحصول على رقم القيد التالي
    $res_jid = $conn->query("SELECT MAX(journal_id) as max_id FROM journal_heads");
    $row_jid = $res_jid->fetch_assoc();
    $journal_id = ($row_jid['max_id'] ?? 0) + 1;
    
    // رأس القيد
    $stmt = $conn->prepare("INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $j_details = "سند قبض - سداد جزئي طاولة " . $table_id;
    $stmt->bind_param("idsssii", $journal_id, $receipt_id, $paid_amount, $date, $j_details, $usid, $new_head_id);
    $stmt->execute();
    $j_head_id = $conn->insert_id;
    $stmt->close();
    
    // مدين: الخزينة (من ح/ الخزينة)
    $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, ?, 0, 0, ?)");
    $stmt->bind_param("iidi", $j_head_id, $safe_acc, $paid_amount, $new_head_id);
    $stmt->execute();
    $stmt->close();
    
    // دائن: العميل (إلى ح/ العميل)
    if ($customer_acc > 0) {
        $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, 0, ?, 1, ?)");
        $stmt->bind_param("iidi", $j_head_id, $customer_acc, $paid_amount, $new_head_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'تم سداد الأصناف المختارة بنجاح وإنشاء سند القبض', 
        'new_invoice_id' => $new_head_id,
        'receipt_id' => $receipt_id
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
