<?php
session_start();
include('../includes/connect.php');

// التحقق من المصادقة والصلاحيات
if (!isset($_SESSION['userid'])) {
    header('Location: ../login.php');
    exit;
}

// التحقق من صحة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../sales.php');
    exit;
}

$usid = $_SESSION['userid'];

// إزالة عرض البيानات الحساسة في الإنتاج
// echo "<pre>";
// print_r($_POST);
// echo "</pre>";
// die;

// تضمين فئات النظام الجديد
require_once('../classes/InvoiceElementFactory.php');

// تعريف ثوابت أنواع الفواتير
define('INVOICE_TYPES', [
    'PURCHASE' => 4,    // مشتريات
    'SALES' => 3,       // مبيعات  
    'POS' => 9,         // كاشير
    'PURCHASE_RETURN' => 10,  // مردود مشتريات
    'SALES_RETURN' => 11,     // مردود مبيعات
    'OFFER' => 14             // عرض سعر
]);

// تعريف أنواع العمليات المحاسبية
define('ACCOUNTING_TYPES', [
    'RECEIPT' => 1,     // سند قبض
    'PAYMENT' => 2,     // سند دفع
    'SALES_DISC' => 7,  // خصم مبيعات
    'PURCHASE_DISC' => 6 // خصم مشتريات
]);

// استخراج وتنظيف البيانات المدخلة
$ot_id = isset($_POST['ot_id']) ? intval($_POST['ot_id']) : 0;
$acc2_id = isset($_POST['acc2_id']) ? intval($_POST['acc2_id']) : 0;
$store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
$emp_id = isset($_POST['emp_id']) ? intval($_POST['emp_id']) : 0;
$pro_date = isset($_POST['pro_date']) ? htmlspecialchars($_POST['pro_date'], ENT_QUOTES, 'UTF-8') : date('Y-m-d');
$accural_date = isset($_POST['accural_date']) ? htmlspecialchars($_POST['accural_date'], ENT_QUOTES, 'UTF-8') : '';
$pro_id = isset($_POST['pro_id']) ? htmlspecialchars($_POST['pro_id'], ENT_QUOTES, 'UTF-8') : '';
$pro_serial = isset($_POST['pro_serial']) ? htmlspecialchars(trim($_POST['pro_serial']), ENT_QUOTES, 'UTF-8') : '';
$headtotal = isset($_POST['headtotal']) ? floatval($_POST['headtotal']) : 0;
$headdisc = isset($_POST['headdisc']) ? floatval($_POST['headdisc']) : 0;
$headplus = isset($_POST['headplus']) ? floatval($_POST['headplus']) : 0;
$headnet = isset($_POST['headnet']) ? floatval($_POST['headnet']) : 0;

// تحديد المبلغ المدفوع - أوامر الشراء والبيع وعروض الأسعار لا تحتاج مدفوعات
if(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE_ORDER'], INVOICE_TYPES['SALES_ORDER'], INVOICE_TYPES['OFFER']])) {
    $paid = 0;
} else {
    $paid = isset($_POST['paid']) ? floatval($_POST['paid']) : 0;
}

$fund_id = isset($_POST['fund_id']) ? intval($_POST['fund_id']) : 0;
$submit = isset($_POST['submit']) ? htmlspecialchars($_POST['submit'], ENT_QUOTES, 'UTF-8') : 'save';
$info = isset($_POST['info']) ? htmlspecialchars(trim($_POST['info']), ENT_QUOTES, 'UTF-8') : '';

// التحقق من صحة البيانات الأساسية
if ($ot_id == 0) {
    die('خطأ: معرف الفاتورة مطلوب');
}

if ($acc2_id == 0 || $store_id == 0 || $emp_id == 0) {
    die('خطأ: بيانات مطلوبة مفقودة');
}

// التحقق من وجود أصناف
if (!isset($_POST['itmname']) || !is_array($_POST['itmname']) || empty(array_filter($_POST['itmname']))) {
    die('خطأ: يجب إضافة صنف واحد على الأقل');
}

// الحصول على بيانات الفاتورة الأصلية باستخدام Prepared Statement
$stmt = $conn->prepare("SELECT * FROM ot_head WHERE id = ?");
if (!$stmt) {
    die('خطأ في تحضير الاستعلام: ' . $conn->error);
}

$stmt->bind_param("i", $ot_id);
$stmt->execute();
$result = $stmt->get_result();
$rowop = $result->fetch_assoc();
$stmt->close();

if (!$rowop) {
    die('خطأ: الفاتورة غير موجودة');
}

// إزالة عرض البيانات الحساسة
// echo "<pre>";
// print_r($rowop);
// echo "</pre>";

$pro_tybe = intval($rowop['pro_tybe']);

/**
 * دالة الحصول على إعدادات نوع الفاتورة
 * Get invoice type configuration
 */
function getInvoiceConfig($pro_tybe) {
    $configs = [
        INVOICE_TYPES['PURCHASE'] => [
            'note' => 'فاتورة مشتريات',
            'paid_note' => 'سند دفع',
            'disc_type' => ACCOUNTING_TYPES['PURCHASE_DISC'],
            'paid_type' => ACCOUNTING_TYPES['PAYMENT'],
            'cost_account' => 97
        ],
        INVOICE_TYPES['SALES'] => [
            'note' => 'فاتورة مبيعات',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 91
        ],
        INVOICE_TYPES['POS'] => [
            'note' => 'فاتورة ريسيت',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 91
        ],
        INVOICE_TYPES['OFFER'] => [
            'note' => 'عرض سعر',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 91
        ]
    ];
    
    return isset($configs[$pro_tybe]) ? $configs[$pro_tybe] : null;
}

/**
 * دالة تحديد الحسابات المحاسبية
 * Get accounting accounts based on invoice type
 */
function getAccountingAccounts($pro_tybe, $store_id, $acc2_id, $fund_id) {
    switch($pro_tybe) {
        case INVOICE_TYPES['PURCHASE']:
            return [
                'acc1' => $store_id,
                'acc2' => $acc2_id,
                'acc3' => $acc2_id,
                'acc4' => 97,
                'acc5' => $acc2_id,
                'acc6' => $fund_id
            ];
            
        case INVOICE_TYPES['SALES']:
        case INVOICE_TYPES['POS']:
        case INVOICE_TYPES['OFFER']:
            return [
                'acc1' => $acc2_id,
                'acc2' => $store_id,
                'acc3' => 91,
                'acc4' => $acc2_id,
                'acc5' => $fund_id,
                'acc6' => $acc2_id
            ];
            
        default:
            throw new InvalidArgumentException('نوع فاتورة غير مدعوم');
    }
}

// الحصول على إعدادات الفاتورة
$config = getInvoiceConfig($pro_tybe);
if (!$config) {
    die('خطأ: نوع فاتورة غير صحيح');
}

// تحديد الحسابات المحاسبية
$accounts = getAccountingAccounts($pro_tybe, $store_id, $acc2_id, $fund_id);

// بدء المعاملة لضمان تماسك البيانات
try {
    $conn->begin_transaction();
    
    // حساب النسب المئوية
    $fat_disc_per = ($headtotal > 0 && $headdisc > 0) ? number_format($headdisc/$headtotal*100, 2) : 0;
    $fat_plus_per = ($headtotal > 0 && $headplus > 0) ? number_format($headplus/$headtotal*100, 2) : 0;
    
    // تحديث رأس الفاتورة باستخدام Prepared Statement
    $stmt = $conn->prepare(
        "UPDATE ot_head SET 
            info = ?, pro_date = ?, accural_date = ?, pro_serial = ?, store_id = ?, 
            emp_id = ?, acc1 = ?, acc2 = ?, pro_value = ?, fat_cost = 0, 
            fat_total = ?, fat_disc = ?, fat_disc_per = ?, fat_plus = ?, fat_plus_per = ?, 
            fat_net = ?, acc_fund = ?
         WHERE id = ?"
    );
    
    if (!$stmt) {
        throw new Exception('فشل في تحضير استعلام تحديث الفاتورة: ' . $conn->error);
    }
    
    $stmt->bind_param(
        "ssssiiiirrrrrrii",
        $info, $pro_date, $accural_date, $pro_serial, $store_id, $emp_id,
        $accounts['acc1'], $accounts['acc2'], $headtotal, $headtotal, 
        $headdisc, $fat_disc_per, $headplus, $fat_plus_per, $headnet, $fund_id, $ot_id
    );
    
    if (!$stmt->execute()) {
        throw new Exception('فشل في تحديث الفاتورة: ' . $stmt->error);
    }
    $stmt->close();
    // تحديث القيود المحاسبية إذا لزم الأمر
    if(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE'], INVOICE_TYPES['SALES']])) {
        // تحديث رأس القيد
        $stmt = $conn->prepare(
            "UPDATE journal_heads SET total = ?, jdate = ?, details = ? WHERE op_id = ?"
        );
        
        $details = $config['note'] . " _ " . $ot_id;
        $stmt->bind_param("rssi", $headnet, $pro_date, $details, $ot_id);
        
        if (!$stmt->execute()) {
            throw new Exception('فشل في تحديث رأس القيد: ' . $stmt->error);
        }
        $stmt->close();
        
        // الحصول على معرف القيد
        $stmt = $conn->prepare("SELECT id FROM journal_heads WHERE op_id = ?");
        $stmt->bind_param("i", $ot_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row_journal = $result->fetch_assoc();
        $stmt->close();
        
        if ($row_journal) {
            $op_journal = $row_journal['id'];
            
            // تحديث قيد المدين
            $stmt = $conn->prepare(
                "UPDATE journal_entries SET account_id = ?, debit = ?, credit = 0 
                 WHERE journal_id = ? AND tybe = 0"
            );
            $stmt->bind_param("iri", $accounts['acc1'], $headnet, $op_journal);
            $stmt->execute();
            $stmt->close();
            
            // تحديث قيد الدائن
            $stmt = $conn->prepare(
                "UPDATE journal_entries SET account_id = ?, debit = 0, credit = ? 
                 WHERE journal_id = ? AND tybe = 1"
            );
            $stmt->bind_param("iri", $accounts['acc2'], $headnet, $op_journal);
            $stmt->execute();
            $stmt->close();
        }
    }





    // معالجة المدفوعات
    $paid = isset($_POST['paid']) ? floatval($_POST['paid']) : 0;
    
    // البحث عن دفعة موجودة مسبقاً
    $stmt = $conn->prepare("SELECT * FROM ot_head WHERE op2 = ? AND pro_tybe IN (1, 2)");
    $stmt->bind_param("i", $ot_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rowpaid = $result->fetch_assoc();
    $stmt->close();
    
    // تحديد نوع الدفع حسب نوع الفاتورة
    $paid_type = ($pro_tybe == INVOICE_TYPES['SALES']) ? ACCOUNTING_TYPES['RECEIPT'] : ACCOUNTING_TYPES['PAYMENT'];
    
    if ($paid > 0 && $rowpaid == null) {
        // إضافة دفعة جديدة
        $stmt = $conn->prepare(
            "INSERT INTO ot_head (
                pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date, 
                emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
            ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)"
        );
        
        $stmt->bind_param(
            "sissssiidiii", 
            $paid_type, $paid_type, $paid_type, $info, $pro_date, $emp_id,
            $accounts['acc5'], $accounts['acc6'], $paid, $usid, $ot_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception('فشل في إضافة الدفعة: ' . $stmt->error);
        }
        $last_paid = $conn->insert_id;
        $stmt->close();
        
        // إضافة قيد الدفعة
        $stmt = $conn->prepare("SELECT MAX(journal_id) as max_id FROM journal_heads");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $journal_id = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;
        $stmt->close();
        
        $stmt = $conn->prepare(
            "INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        $paid_details = $config['paid_note'] . " _ " . $ot_id;
        $stmt->bind_param("iidsiii", $journal_id, $last_paid, $paid, $pro_date, $paid_details, $usid, $ot_id);
        $stmt->execute();
        $journal_lastid = $conn->insert_id;
        $stmt->close();
        
        // قيد المدين
        $stmt = $conn->prepare(
            "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) 
             VALUES (?, ?, ?, 0, 0, ?)"
        );
        $stmt->bind_param("iidi", $journal_lastid, $accounts['acc5'], $paid, $ot_id);
        $stmt->execute();
        $stmt->close();
        
        // قيد الدائن
        $stmt = $conn->prepare(
            "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) 
             VALUES (?, ?, 0, ?, 1, ?)"
        );
        $stmt->bind_param("iidi", $journal_lastid, $accounts['acc6'], $paid, $ot_id);
        $stmt->execute();
        $stmt->close();
        
    } elseif ($paid > 0 && $rowpaid !== null) {
        // تحديث دفعة موجودة
        $stmt = $conn->prepare(
            "UPDATE ot_head SET info = ?, pro_date = ?, emp_id = ?, acc1 = ?, acc2 = ?, pro_value = ? 
             WHERE op2 = ? AND pro_tybe = ?"
        );
        $stmt->bind_param("ssiiiiii", $info, $pro_date, $emp_id, $accounts['acc5'], $accounts['acc6'], $paid, $ot_id, $paid_type);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare(
            "UPDATE journal_heads SET total = ?, jdate = ? WHERE op2 = ? AND op_id IN (
                SELECT id FROM ot_head WHERE op2 = ? AND pro_tybe = ?
            )"
        );
        $stmt->bind_param("dsiii", $paid, $pro_date, $ot_id, $ot_id, $paid_type);
        $stmt->execute();
        $stmt->close();
        
        // تحديث قيود الدفعة
        $stmt = $conn->prepare("SELECT id FROM journal_heads WHERE op2 = ?");
        $stmt->bind_param("i", $ot_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            $paid_jr = $row['id'];
            
            $stmt = $conn->prepare(
                "UPDATE journal_entries SET account_id = ?, debit = ?, credit = 0 
                 WHERE journal_id = ? AND tybe = 0"
            );
            $stmt->bind_param("idi", $accounts['acc5'], $paid, $paid_jr);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $conn->prepare(
                "UPDATE journal_entries SET account_id = ?, debit = 0, credit = ? 
                 WHERE journal_id = ? AND tybe = 1"
            );
            $stmt->bind_param("idi", $accounts['acc6'], $paid, $paid_jr);
            $stmt->execute();
            $stmt->close();
        }
        
    } elseif ($paid == 0 && $rowpaid !== null) {
        // حذف الدفعة
        $stmt = $conn->prepare("UPDATE ot_head SET isdeleted = 1 WHERE op2 = ? AND pro_tybe = ?");
        $stmt->bind_param("ii", $ot_id, $paid_type);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("UPDATE journal_heads SET isdeleted = 1 WHERE op2 = ?");
        $stmt->bind_param("i", $ot_id);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("SELECT id FROM journal_heads WHERE op2 = ?");
        $stmt->bind_param("i", $ot_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            $paid_jr = $row['id'];
            
            $stmt = $conn->prepare("UPDATE journal_entries SET isdeleted = 1 WHERE journal_id = ?");
            $stmt->bind_param("i", $paid_jr);
            $stmt->execute();
            $stmt->close();
        }
    }

    // تحديث تفاصيل الفاتورة
    $stmt = $conn->prepare("UPDATE fat_details SET isdeleted = 1 WHERE pro_id = ?");
    $stmt->bind_param("i", $ot_id);
    $stmt->execute();
    $stmt->close();

    // معالجة تفاصيل الفواتير باستخدام Prepared Statements
    if (isset($_POST['itmname'], $_POST['itmqty'], $_POST['itmprice'], $_POST['itmdisc'])) {
        // تحضير استعلام إدخال تفاصيل الفاتورة
        $stmt_details = $conn->prepare(
            "INSERT INTO fat_details (
                pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price, 
                discount, det_value, fatid, fat_tybe, det_store, cost_price, profit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        if (!$stmt_details) {
            throw new Exception('فشل في تحضير استعلام تفاصيل الفاتورة: ' . $conn->error);
        }
        
        // تحضير استعلام الحصول على بيانات الصنف
        $stmt_item = $conn->prepare("SELECT cost_price, itmqty FROM myitems WHERE id = ?");
        if (!$stmt_item) {
            throw new Exception('فشل في تحضير استعلام بيانات الصنف: ' . $conn->error);
        }
        
        // تحضير استعلام تحديث بيانات الصنف
        $stmt_update = $conn->prepare("UPDATE myitems SET last_price = ?, cost_price = ? WHERE id = ?");
        if (!$stmt_update) {
            throw new Exception('فشل في تحضير استعلام تحديث الصنف: ' . $conn->error);
        }
        
        // معالجة كل صنف
        foreach ($_POST['itmname'] as $index => $itmname) {
            if (empty($itmname)) continue;
            
            $itmname = intval($itmname);
            $itmqty = floatval($_POST['itmqty'][$index]);
            $itmprice = floatval($_POST['itmprice'][$index]);
            $itmdisc = floatval($_POST['itmdisc'][$index]);
            $u_val = floatval($_POST['u_val'][$index]);
            
            // تحديد الكميات حسب نوع الفاتورة
            // أوامر الشراء (12) وأوامر البيع (13) وعروض الأسعار (14) لا تؤثر على المخزون
            if(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE_ORDER'], INVOICE_TYPES['SALES_ORDER'], INVOICE_TYPES['OFFER']])) {
                // أوامر الشراء والبيع وعروض الأسعار → لا تؤثر على المخزون
                $qty_in = 0;
                $qty_out = 0;
            } elseif(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE'], INVOICE_TYPES['SALES_RETURN']])) {
                // مشتريات، مردود مبيعات → كمية واردة
                $qty_in = $itmqty * $u_val;
                $qty_out = 0;
            } elseif(in_array($pro_tybe, [INVOICE_TYPES['SALES'], INVOICE_TYPES['POS'], INVOICE_TYPES['PURCHASE_RETURN']])) {
                // مبيعات، كاشير، مردود مشتريات → كمية منصرفة
                $qty_in = 0;
                $qty_out = $itmqty * $u_val;
            } else {
                $qty_in = 0;
                $qty_out = 0;
            }
            
            $det_value = $itmqty * ($itmprice - $itmdisc);
            
            // الحصول على بيانات الصنف الحالية
            $stmt_item->bind_param("i", $itmname);
            $stmt_item->execute();
            $result = $stmt_item->get_result();
            $rowbl = $result->fetch_assoc();
            
            if (!$rowbl) {
                throw new Exception('صنف غير موجود: ' . $itmname);
            }
            
            $oldprice = floatval($rowbl['cost_price']);
            $oldqty = intval($rowbl['itmqty']);
            $cost_price = $oldprice;
            $itmprofit = 0;
            
            // حساب التكلفة والربح
            if($pro_tybe == INVOICE_TYPES['PURCHASE']) {
                // حساب سعر التكلفة المتوسط
                $unit_price = $itmprice / $u_val;
                $oldbalance = $oldprice * $oldqty;
                $newbalance = $qty_in * $unit_price;
                $total_balance = $oldbalance + $newbalance;
                $total_qty = $oldqty + $qty_in;
                
                if($total_qty > 0) {
                    $cost_price = $total_balance / $total_qty;
                }
                
                // تحديث بيانات الصنف
                $stmt_update->bind_param("ddi", $unit_price, $cost_price, $itmname);
                if (!$stmt_update->execute()) {
                    throw new Exception('فشل في تحديث بيانات الصنف ' . $itmname);
                }
                
                $itmprice = $unit_price;
                
            } elseif (in_array($pro_tybe, [INVOICE_TYPES['SALES'], INVOICE_TYPES['POS'], INVOICE_TYPES['OFFER']])) {
                // حساب الربح للمبيعات
                $unit_price = $itmprice / $u_val;
                $itmprofit = $itmqty * $u_val * ($unit_price - $oldprice);
                $itmprice = $unit_price;
            }
            
            // إدخال تفاصيل الفاتورة
            $stmt_details->bind_param(
                "iiiiddddiiiidd",
                $pro_tybe, $ot_id, $itmname, $u_val, $qty_in, $qty_out,
                $itmprice, $itmdisc, $det_value, $ot_id, $pro_tybe,
                $store_id, $cost_price, $itmprofit
            );
            
            if (!$stmt_details->execute()) {
                throw new Exception('فشل في إدخال تفاصيل الصنف ' . $itmname);
            }
        }
        
        // إغلاق الاستعلامات
        $stmt_details->close();
        $stmt_item->close();
        $stmt_update->close();
    }
    
    // تحديث إجمالي الأرباح للمبيعات
    if(in_array($pro_tybe, [INVOICE_TYPES['SALES'], INVOICE_TYPES['POS'], INVOICE_TYPES['OFFER']])) {
        $stmt = $conn->prepare("SELECT SUM(profit) AS tprofit FROM fat_details WHERE fatid = ?");
        $stmt->bind_param("i", $ot_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rowprofit = $result->fetch_assoc();
        $ot_profit = $rowprofit['tprofit'] ?? 0;
        $stmt->close();
        
        // تحديث رقم الربح في رأس الفاتورة
        $stmt = $conn->prepare("UPDATE ot_head SET profit = ? WHERE id = ?");
        $stmt->bind_param("di", $ot_profit, $ot_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // إتمام المعاملة
    $conn->commit();
    
    // تسجيل العملية
    $process_types = [
        INVOICE_TYPES['PURCHASE'] => 'edit buy',
        INVOICE_TYPES['SALES'] => 'edit sales',
        INVOICE_TYPES['POS'] => 'edit cash'
    ];
    
    $process_type = $process_types[$pro_tybe] ?? 'edit invoice';
    $stmt = $conn->prepare("INSERT INTO process (type) VALUES (?)");
    $stmt->bind_param("s", $process_type);
    $stmt->execute();
    $stmt->close();
    
} catch (Exception $e) {
    // إلغاء المعاملة في حالة الخطأ
    $conn->rollback();
    error_log('خطأ في تعديل الفاتورة: ' . $e->getMessage());
    die('حدث خطأ أثناء تعديل الفاتورة: ' . $e->getMessage());
}

// إعادة التوجيه حسب نوع العملية
$redirects = [
    INVOICE_TYPES['PURCHASE'] => '../sales.php?q=sale',
    INVOICE_TYPES['SALES'] => '../sales.php?q=buy',
    INVOICE_TYPES['POS'] => '../pos_barcode.php'
];

$redirect = $redirects[$pro_tybe] ?? '../sales.php';
header("Location: $redirect");
exit;
?>