<?php
/**
 * معالج الفواتير للويترز - مع إعادة توجيه خاصة
 * Waiter Invoice Handler with Auto-logout Flow
 */

session_start();

// التحقق من تسجيل دخول الويتر
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['is_waiter']) || $_SESSION['is_waiter'] != 1) {
    header('Location: ../waiter_login.php');
    exit;
}

// حفظ معلومات الويتر قبل معالجة الفاتورة
$waiter_id = $_SESSION['waiter_id'];
$waiter_name = $_SESSION['waiter_name'];

// إضافة معرف الويتر للـ POST data
$_POST['waiter_id'] = $waiter_id;

// حفظ نوع الإرسال (submit type)
$submit_type = isset($_POST['submit']) ? $_POST['submit'] : 'save';

// تضمين ملف الاتصال بقاعدة البيانات
include('../includes/connect.php');

// معالجة الفاتورة باستخدام نفس منطق doadd_invoice.php
// لكن مع تخصيص للويترز

// استخراج البيانات الأساسية
$pro_tybe = isset($_POST['pro_tybe']) ? intval($_POST['pro_tybe']) : 9; // POS type
$store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
$pro_date = isset($_POST['pro_date']) ? $_POST['pro_date'] : date('Y-m-d');
$acc2_id = isset($_POST['acc2_id']) ? intval($_POST['acc2_id']) : 0;
$headtotal = isset($_POST['headtotal']) ? floatval($_POST['headtotal']) : 0;
$headdisc = isset($_POST['headdisc']) ? floatval($_POST['headdisc']) : 0;
$headplus = isset($_POST['headplus']) ? floatval($_POST['headplus']) : 0;
$headnet = isset($_POST['headnet']) ? floatval($_POST['headnet']) : 0;
$info = isset($_POST['info']) ? $_POST['info'] : '';
$order_type = isset($_POST['age']) ? intval($_POST['age']) : 1;

// إضافة اسم الويتر إلى معلومات الطلب
$info .= " | الويتر: " . $waiter_name;

// الحصول على رقم الفاتورة التالي
$pro_id_query = $conn->query("SELECT COALESCE(MAX(pro_id), 0) + 1 as next_id FROM ot_head WHERE pro_tybe = $pro_tybe");
$pro_id = $pro_id_query->fetch_assoc()['next_id'];

// إدراج رأس الفاتورة
$insert_head = "INSERT INTO ot_head (
    pro_tybe, pro_id, pro_date, store_id, acc2_id, 
    total, disc, plus, net, info, waiter_id, age
) VALUES (
    $pro_tybe, $pro_id, '$pro_date', $store_id, $acc2_id,
    $headtotal, $headdisc, $headplus, $headnet, '$info', 
    $waiter_id, $order_type
)";

if ($conn->query($insert_head)) {
    $last_invoice_id = $conn->insert_id;
    
    // إدراج تفاصيل الفاتورة (الأصناف)
    if (isset($_POST['itmname']) && is_array($_POST['itmname'])) {
        $items = $_POST['itmname'];
        $quantities = $_POST['quantity'];
        $prices = $_POST['price'];
        $totals = $_POST['total'];
        
        for ($i = 0; $i < count($items); $i++) {
            $item_id = intval($items[$i]);
            $quantity = floatval($quantities[$i]);
            $price = floatval($prices[$i]);
            $total = floatval($totals[$i]);
            
            // الحصول على اسم الصنف
            $item_query = $conn->query("SELECT iname FROM items WHERE id = $item_id");
            $item_name = $item_query->fetch_assoc()['iname'];
            
            $insert_detail = "INSERT INTO ot_details (
                fat_id, item_id, item_name, quantity, price, total
            ) VALUES (
                $last_invoice_id, $item_id, '$item_name', $quantity, $price, $total
            )";
            
            $conn->query($insert_detail);
            
            // تحديث المخزون
            $conn->query("UPDATE items SET quantity = quantity - $quantity WHERE id = $item_id");
        }
    }
    
    // معالجة الطاولة إذا كان نوع الطلب "طاولة"
    if ($order_type == 2 && isset($_POST['table_id'])) {
        $table_id = intval($_POST['table_id']);
        $conn->query("UPDATE tables SET table_case = 1, current_order_id = $last_invoice_id WHERE id = $table_id");
    }
    
    // معالجة الدليفري إذا كان نوع الطلب "دليفري"
    if ($order_type == 3 && isset($_POST['delivery_client_id'])) {
        $delivery_client_id = intval($_POST['delivery_client_id']);
        $conn->query("UPDATE ot_head SET delivery_client_id = $delivery_client_id WHERE id = $last_invoice_id");
    }
    
    // إعادة التوجيه حسب نوع الإرسال
    if ($submit_type == 'cash') {
        // طباعة وخروج تلقائي
        header("Location: ../print/receipt_waiter.php?id=$last_invoice_id");
    } else {
        // حفظ فقط - العودة لـ POS
        $_SESSION['success_message'] = 'تم حفظ الطلب بنجاح';
        header("Location: ../pos_waiter.php");
    }
    exit;
    
} else {
    // خطأ في الحفظ
    $_SESSION['error_message'] = 'حدث خطأ أثناء حفظ الطلب: ' . $conn->error;
    header("Location: ../pos_waiter.php");
    exit;
}
?>
