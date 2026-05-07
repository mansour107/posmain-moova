<?php
session_start();
include('../includes/connect.php');

// التحقق من المصادقة
if (!isset($_SESSION['userid'])) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pos_clothes.php');
    exit;
}

$usid = $_SESSION['userid'];

// استخراج البيانات
$pro_tybe = 9; // نوع POS
$store_id = intval($_POST['store_id']);
$pro_date = $_POST['pro_date'] ?: date('Y-m-d');
$accural_date = $_POST['accural_date'] ?: date('Y-m-d');
$acc2_id = intval($_POST['acc2_id']);
$emp_id = intval($_POST['emp_id']);
$headtotal = floatval($_POST['headtotal']);
$headdisc = floatval($_POST['headdisc']);
$headnet = floatval($_POST['headnet']);
$fund_id = intval($_POST['fund_id']);
$info = trim($_POST['info']);
$submit = $_POST['submit'] ?: 'save';
$paid = floatval($_POST['paid']);

// إضافة نوع الطلب
$order_type = intval($_POST['age']);
$order_types = [1 => 'بيع مباشر', 2 => 'حجز', 3 => 'توصيل'];
$order_type_text = $order_types[$order_type] ?? 'بيع مباشر';
$info = empty($info) ? "نوع الطلب: $order_type_text" : "$info - نوع الطلب: $order_type_text";

// التحقق من البيانات
if (!$store_id || !$acc2_id || !$emp_id || !$fund_id) {
    echo '<pre>';
    echo "store_id: " . ($store_id ?: 'MISSING') . "\n";
    echo "acc2_id: " . ($acc2_id ?: 'MISSING') . "\n";
    echo "emp_id: " . ($emp_id ?: 'MISSING') . "\n";
    echo "fund_id: " . ($fund_id ?: 'MISSING') . "\n";
    echo "\nPOST Data:\n";
    print_r($_POST);
    echo '</pre>';
    die('خطأ: بيانات مطلوبة مفقودة');
}

if (!isset($_POST['itmname']) || !is_array($_POST['itmname']) || empty(array_filter($_POST['itmname']))) {
    die('خطأ: يجب إضافة صنف واحد على الأقل');
}

try {
    $conn->begin_transaction();
    
    // الحصول على رقم الفاتورة التالي
    $stmt = $conn->prepare("SELECT MAX(CAST(pro_id AS UNSIGNED)) as max_id FROM ot_head WHERE pro_tybe = ?");
    $stmt->bind_param("i", $pro_tybe);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $pro_id = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;
    $stmt->close();
    
    // تحديد حساب المبيعات (91 هو حساب المبيعات في النظام)
    $sales_account = 91;
    
    // إدخال رأس الفاتورة مع قيد محاسبي (is_journal = 1)
    $stmt = $conn->prepare("
        INSERT INTO ot_head (
            pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date, 
            accural_date, pro_serial, store_id, emp_id, emp2_id, acc1, acc2, 
            pro_value, fat_total, fat_disc, fat_plus, fat_net, user
        ) VALUES (
            ?, ?, 1, 1, ?, ?, ?, ?, '0', ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?
        )
    ");
    
    $stmt->bind_param(
        "iiissssiiiiidddi",
        $pro_id, $pro_tybe, $pro_tybe, $info, $pro_date, $accural_date,
        $store_id, $emp_id, $emp_id, $acc2_id, $sales_account, $headtotal,
        $headtotal, $headdisc, $headnet, $usid
    );
    
    if (!$stmt->execute()) {
        throw new Exception('فشل في إدخال الفاتورة: ' . $stmt->error);
    }
    
    $last_op = $conn->insert_id;
    $stmt->close();
    
    // إدخال تفاصيل الفاتورة
    $stmt_details = $conn->prepare("
        INSERT INTO fat_details (
            pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price, 
            discount, det_value, fatid, fat_tybe, det_store
        ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($_POST['itmname'] as $index => $itmname) {
        if (empty($itmname)) continue;
        
        $itmname = intval($itmname);
        $itmqty = floatval($_POST['itmqty'][$index]);
        $itmprice = floatval($_POST['itmprice'][$index]);
        $itmdisc = floatval($_POST['itmdisc'][$index]);
        $u_val = floatval($_POST['u_val'][$index]);
        $det_value = $itmqty * ($itmprice - $itmdisc);
        $qty_out = $itmqty * $u_val;
        
        $stmt_details->bind_param(
            "iiididdiiii",
            $pro_tybe, $last_op, $itmname, $u_val, $qty_out,
            $itmprice, $itmdisc, $det_value, $last_op, $pro_tybe, $store_id
        );
        
        if (!$stmt_details->execute()) {
            throw new Exception('فشل في إدخال تفاصيل الصنف ' . $itmname);
        }
    }
    
    $stmt_details->close();
    
    // إنشاء القيد المحاسبي للفاتورة
    // الحصول على رقم القيد التالي
    $stmt = $conn->prepare("SELECT MAX(journal_id) as max_id FROM journal_heads");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $journal_id = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;
    $stmt->close();
    
    // إدخال رأس القيد
    $stmt = $conn->prepare("
        INSERT INTO journal_heads (journal_id, total, jdate, details, user, op_id) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $journal_details = "فاتورة مبيعات POS رقم " . $pro_id;
    $stmt->bind_param("idssii", $journal_id, $headnet, $pro_date, $journal_details, $usid, $last_op);
    
    if (!$stmt->execute()) {
        throw new Exception('فشل في إدخال رأس القيد: ' . $stmt->error);
    }
    
    $journal_lastid = $conn->insert_id;
    $stmt->close();
    
    // إدخال تفاصيل القيد (المدين - العميل)
    $stmt = $conn->prepare("
        INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id) 
        VALUES (?, ?, ?, 0, 0, ?)
    ");
    $stmt->bind_param("iidi", $journal_lastid, $acc2_id, $headnet, $last_op);
    
    if (!$stmt->execute()) {
        throw new Exception('فشل في إدخال القيد المدين: ' . $stmt->error);
    }
    $stmt->close();
    
    // إدخال تفاصيل القيد (الدائن - المبيعات)
    $stmt = $conn->prepare("
        INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id) 
        VALUES (?, ?, 0, ?, 1, ?)
    ");
    $stmt->bind_param("iidi", $journal_lastid, $sales_account, $headnet, $last_op);
    
    if (!$stmt->execute()) {
        throw new Exception('فشل في إدخال القيد الدائن: ' . $stmt->error);
    }
    $stmt->close();
    
    // إنشاء قيد الدفع إذا كان هناك مبلغ مدفوع
    if ($paid > 0) {
        // إدخال عملية الدفع
        $stmt = $conn->prepare("
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date, 
                emp_id, acc1, acc2, pro_value, user, op2
            ) VALUES (?, 7, 1, 7, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $paid_info = "سند قبض من فاتورة POS رقم " . $pro_id;
        $paid_pro_id = $pro_id . '-P';
        
        $stmt->bind_param(
            "sssiiidii",
            $paid_pro_id, $paid_info, $pro_date, $emp_id, 
            $fund_id, $acc2_id, $paid, $usid, $last_op
        );
        
        if (!$stmt->execute()) {
            throw new Exception('فشل في إدخال عملية الدفع: ' . $stmt->error);
        }
        
        $last_paid = $conn->insert_id;
        $stmt->close();
        
        // الحصول على رقم قيد الدفع
        $stmt = $conn->prepare("SELECT MAX(journal_id) as max_id FROM journal_heads");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $journal_id = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;
        $stmt->close();
        
        // رأس قيد الدفع
        $stmt = $conn->prepare("
            INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $paid_details = "سند قبض من فاتورة POS رقم " . $pro_id;
        $stmt->bind_param("iidssii", $journal_id, $last_paid, $paid, $pro_date, $paid_details, $usid, $last_op);
        $stmt->execute();
        $journal_lastid = $conn->insert_id;
        $stmt->close();
        
        // تفاصيل قيد الدفع (مدين - الصندوق)
        $stmt = $conn->prepare("
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) 
            VALUES (?, ?, ?, 0, 0, ?)
        ");
        $stmt->bind_param("iidi", $journal_lastid, $fund_id, $paid, $last_op);
        $stmt->execute();
        $stmt->close();
        
        // تفاصيل قيد الدفع (دائن - العميل)
        $stmt = $conn->prepare("
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) 
            VALUES (?, ?, 0, ?, 1, ?)
        ");
        $stmt->bind_param("iidi", $journal_lastid, $acc2_id, $paid, $last_op);
        $stmt->execute();
        $stmt->close();
    }
    
    $conn->commit();
    
    $_SESSION['success_message'] = 'تم حفظ الفاتورة وإنشاء القيد المحاسبي بنجاح - رقم الفاتورة: ' . $pro_id;
    
} catch (Exception $e) {
    $conn->rollback();
    die('حدث خطأ أثناء معالجة الفاتورة: ' . $e->getMessage());
}

// إعادة التوجيه
if ($submit == 'cash') {
    // حفظ وطباعة — redirect مباشر للطباعة
    header("Location: ../print/receipt.php?id=$last_op");
} else {
    // حفظ فقط — رجوع للـ POS
    $stmt = $conn->prepare("SELECT pos_type FROM settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $pos_type = $settings['pos_type'] ?? 'barcode';
    $pos_page = ($pos_type === 'clothes') ? '../pos_clothes.php' : '../pos_barcode.php';
    
    header("Location: $pos_page?r=" . time());
}
exit;
?>