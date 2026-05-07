<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
session_start();
include('../includes/connect.php');
ob_end_clean();

header('Content-Type: application/json');

$table_id = intval($_POST['table_id'] ?? 0);
$total = floatval($_POST['total'] ?? 0);
$discount = floatval($_POST['discount'] ?? 0);
$net = floatval($_POST['net'] ?? 0);
$paid = floatval($_POST['paid'] ?? 0);

if (!$table_id || $paid <= 0) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة']);
    exit;
}

try {
    $conn->begin_transaction();
    
    // 1. جلب بيانات الطاولة
    $table_query = "SELECT tname FROM tables WHERE id = ?";
    $stmt = $conn->prepare($table_query);
    $stmt->bind_param("i", $table_id);
    $stmt->execute();
    $table_result = $stmt->get_result();
    
    if ($table_result->num_rows === 0) {
        throw new Exception('الطاولة غير موجودة');
    }
    
    $table_data = $table_result->fetch_assoc();
    $table_name = $table_data['tname'];
    $stmt->close();
    
    // 2. جلب الطلب النشط للطاولة
    $order_query = "SELECT * FROM ot_head WHERE info LIKE ? AND pro_tybe = 9 ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($order_query);
    $search_term = "%$table_name%";
    $stmt->bind_param("s", $search_term);
    $stmt->execute();
    $order_result = $stmt->get_result();
    
    if ($order_result->num_rows === 0) {
        throw new Exception('لا يوجد طلب نشط لهذه الطاولة');
    }
    
    $order_data = $order_result->fetch_assoc();
    $order_id = $order_data['id'];
    $customer_acc = $order_data['acc1'] ?? 0;
    $emp_id = $order_data['emp_id'] ?? 0;
    $stmt->close();
    
    // 3. تحديث الطلب بالخصم والصافي
    if ($discount > 0) {
        $update_order_disc = "UPDATE ot_head SET fat_disc = ?, fat_net = ? WHERE id = ?";
        $stmt = $conn->prepare($update_order_disc);
        $stmt->bind_param("ddi", $discount, $net, $order_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // حساب المبلغ الفعلي الداخل للصندوق (المدفوع - الباقي)
    $change = max(0, $paid - $net); // الباقي (المرتجع للعميل)
    $actual_paid = max(0, $paid - $change); // المبلغ الفعلي الداخل
    
    error_log('=== PAYMENT CALCULATION (TABLES) ===');
    error_log('Paid: ' . $paid);
    error_log('Net: ' . $net);
    error_log('Change (return): ' . $change);
    error_log('Actual paid (received): ' . $actual_paid);
    error_log('====================================');
    
    // 4. تحديث حالة الطلب إلى مسدد (type 2)
    $update_order = "UPDATE ot_head SET pro_tybe = 2 WHERE id = ?";
    $stmt = $conn->prepare($update_order);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();
    
    // 5. إنشاء سند قبض (Receipt Voucher) - فقط إذا كان هناك مبلغ فعلي داخل
    if ($actual_paid > 0) {
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
        
        $info_text = "سند قبض - سداد طاولة: " . $table_name . " - فاتورة رقم " . $order_id;
        $stmt->bind_param("ssiiiddii", 
            $info_text, $date, $emp_id, $safe_acc, $customer_acc, 
            $actual_paid, $actual_paid, $usid, $order_id
        );
        $stmt->execute();
        $receipt_id = $conn->insert_id;
        $stmt->close();
    
    // 6. إنشاء قيد يومية (Journal Entry)
    // الحصول على رقم القيد التالي
    $res_jid = $conn->query("SELECT MAX(journal_id) as max_id FROM journal_heads");
    $row_jid = $res_jid->fetch_assoc();
    $journal_id = ($row_jid['max_id'] ?? 0) + 1;
    
        
        // رأس القيد
        $stmt = $conn->prepare("INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $j_details = "سند قبض - سداد طاولة " . $table_name;
        $stmt->bind_param("idsssii", $journal_id, $receipt_id, $actual_paid, $date, $j_details, $usid, $order_id);
        $stmt->execute();
        $j_head_id = $conn->insert_id;
        $stmt->close();
        
        // مدين: الخزينة (من ح/ الخزينة)
        $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, ?, 0, 0, ?)");
        $stmt->bind_param("iidi", $j_head_id, $safe_acc, $actual_paid, $order_id);
        $stmt->execute();
        $stmt->close();
        
        // دائن: العميل (إلى ح/ العميل)
        if ($customer_acc > 0) {
            $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, 0, ?, 1, ?)");
            $stmt->bind_param("iidi", $j_head_id, $customer_acc, $actual_paid, $order_id);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        error_log('No actual payment received (change >= paid), skipping receipt voucher creation');
    }
    
    // 7. تحديث حالة الطاولة إلى فارغة
    $update_table = "UPDATE tables SET table_case = 0 WHERE id = ?";
    $stmt = $conn->prepare($update_table);
    $stmt->bind_param("i", $table_id);
    $stmt->execute();
    $stmt->close();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'تم السداد بنجاح وإنشاء سند القبض',
        'receipt_id' => $receipt_id,
        'order_id' => $order_id
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>