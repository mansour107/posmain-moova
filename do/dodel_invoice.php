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

// تضمين فئات النظام الجديد
require_once('../classes/InvoiceElementFactory.php');

// تعريف ثوابت أنواع الفواتير
define('INVOICE_TYPES', [
    'PURCHASE' => 4,    // مشتريات
    'SALES' => 3,       // مبيعات  
    'POS' => 9,         // كاشير
    'PURCHASE_RETURN' => 10,  // مردود مشتريات
    'SALES_RETURN' => 11      // مردود مبيعات
]);

// تعريف أنواع العمليات المحاسبية
define('ACCOUNTING_TYPES', [
    'RECEIPT' => 1,     // سند قبض
    'PAYMENT' => 2,     // سند دفع
    'SALES_DISC' => 7,  // خصم مبيعات
    'PURCHASE_DISC' => 6 // خصم مشتريات
]);

// استخراج وتنظيف البيانات المدخلة
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$pass = isset($_POST['pass']) ? htmlspecialchars($_POST['pass'], ENT_QUOTES, 'UTF-8') : '';
$q = isset($_POST['q']) ? htmlspecialchars($_POST['q'], ENT_QUOTES, 'UTF-8') : '';

// التحقق من صحة البيانات الأساسية
if ($id == 0) {
    header('Location: ../warning.php?error=invalid_id');
    exit;
}

if (empty($pass)) {
    header('Location: ../warning.php?error=missing_password');
    exit;
}

// الحصول على إعدادات النظام باستخدام Prepared Statement
$stmt = $conn->prepare("SELECT edit_pass FROM settings LIMIT 1");
if (!$stmt) {
    die('خطأ في تحضير الاستعلام: ' . $conn->error);
}

$stmt->execute();
$result = $stmt->get_result();
$rowstg = $result->fetch_assoc();
$stmt->close();

if (!$rowstg) {
    header('Location: ../warning.php?error=settings_not_found');
    exit;
}

// التحقق من كلمة المرور
if ($pass !== $rowstg['edit_pass']) {
    header('Location: ../warning.php?q=' . urlencode($q) . '&error=invalid_password');
    exit;
}

// الحصول على بيانات الفاتورة للتحقق من وجودها ونوعها
$stmt = $conn->prepare("SELECT * FROM ot_head WHERE id = ? AND isdeleted = 0");
if (!$stmt) {
    die('خطأ في تحضير الاستعلام: ' . $conn->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$invoice = $result->fetch_assoc();
$stmt->close();

if (!$invoice) {
    header('Location: ../warning.php?q=' . urlencode($q) . '&error=invoice_not_found');
    exit;
}

$pro_tybe = intval($invoice['pro_tybe']);

/**
 * دالة الحصول على إعدادات نوع الفاتورة
 * Get invoice type configuration
 */
function getInvoiceConfig($pro_tybe) {
    $configs = [
        INVOICE_TYPES['PURCHASE'] => [
            'note' => 'حذف فاتورة مشتريات',
            'process_type' => 'delete buy'
        ],
        INVOICE_TYPES['SALES'] => [
            'note' => 'حذف فاتورة مبيعات',
            'process_type' => 'delete sales'
        ],
        INVOICE_TYPES['POS'] => [
            'note' => 'حذف فاتورة ريسيت',
            'process_type' => 'delete cash'
        ]
    ];
    
    return isset($configs[$pro_tybe]) ? $configs[$pro_tybe] : [
        'note' => 'حذف فاتورة',
        'process_type' => 'delete invoice'
    ];
}

// الحصول على إعدادات الفاتورة
$config = getInvoiceConfig($pro_tybe);

// بدء المعاملة لضمان تماسك البيانات
try {
    $conn->begin_transaction();
    
    // حذف تفاصيل الفاتورة
    $conn->query("UPDATE fat_details SET isdeleted = 1 WHERE pro_id = $id");
    
    // حذف رأس الفاتورة
    $conn->query("UPDATE ot_head SET isdeleted = 1 WHERE id = $id");
    
    // حذف القيود المحاسبية
    $conn->query("UPDATE journal_entries SET isdeleted = 1 WHERE op_id = $id");
    $conn->query("UPDATE journal_heads SET isdeleted = 1 WHERE op_id = $id");
    

    
    // إتمام المعاملة
    $conn->commit();
    
} catch (Exception $e) {
    $conn->rollback();
    $error_msg = $e->getMessage();
    error_log('Delete Error - Invoice ID: ' . $id . ' - Error: ' . $error_msg);
    
    // عرض الخطأ الفعلي للمطور
    header('Location: ../warning.php?q=' . urlencode($q) . '&error=delete_failed&id=' . $id . '&msg=' . urlencode($error_msg));
    exit;
}

// إعادة التوجيه حسب نوع نظام POS
$stmt = $conn->prepare("SELECT pos_type FROM settings LIMIT 1");
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();
$stmt->close();

$pos_type = $settings['pos_type'] ?? 'barcode';

// تحديد صفحة POS حسب النوع
$pos_page = ($pos_type === 'clothes') ? '../pos_clothes.php' : '../pos_barcode.php';

// إعادة التوجيه حسب نوع العملية
$redirects = [
    INVOICE_TYPES['PURCHASE'] => '../operations_summary.php?q=sale',
    INVOICE_TYPES['SALES'] => '../operations_summary.php?q=buy',
    INVOICE_TYPES['POS'] => $pos_page
];

$redirect = $redirects[$pro_tybe] ?? '../operations_summary.php?q=' . urlencode($q);
$separator = strpos($redirect, '?') !== false ? '&' : '?';
header("Location: $redirect{$separator}success=deleted");
exit;
?>