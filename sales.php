<?php 
// بدء Output Buffering وقياس الأداء
$page_start_time = microtime(true);
ob_start();

include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');

// تضمين فئات العناصر مرة واحدة فقط
require_once 'classes/InvoiceElementFactory.php';

// تعريف ثوابت أنواع الفواتير (Cached)
if (!defined('INVOICE_TYPES')) {
    define('INVOICE_TYPES', [
        'sale' => 4, 'buy' => 3, 'resale' => 10, 'rebuy' => 11,
        'po' => 12, 'so' => 13, 'offer' => 14
    ]);
    
    define('INVOICE_NAMES', [
        3 => 'فاتورة مبيعات', 4 => 'فاتورة مشتريات',
        10 => 'فاتورة مردود مشتريات', 11 => 'فاتورة مردود مبيعات',
        12 => 'أمر شراء', 13 => 'أمر بيع', 14 => 'عرض سعر'
    ]);
    
    define('EDIT_NAMES', [
        4 => 'تعديل فاتورة المشتريات', 3 => 'تعديل فاتورة المبيعات',
        10 => 'تعديل فاتورة مردود المشتريات', 11 => 'تعديل فاتورة مردود المبيعات',
        14 => 'تعديل عرض السعر'
    ]);
}

// معالجة نوع الفاتورة بشكل آمن وسريع
$pro_tybe = null;
$invoice_title = 'غير محدد';
$is_edit_mode = false;
$invoice_data = null;
$opid = null;

// تحسين: معالجة GET parameters مرة واحدة
$q_param = $_GET['q'] ?? null;
$e_param = $_GET['e'] ?? $_GET['edit_id'] ?? null; // دعم e و edit_id

if (!empty($q_param) && isset(INVOICE_TYPES[$q_param])) {
    // وضع إضافة فاتورة جديدة
    $pro_tybe = INVOICE_TYPES[$q_param];
    $invoice_title = INVOICE_NAMES[$pro_tybe] ?? 'نوع فاتورة غير معروف';
    
} elseif (!empty($e_param) && is_numeric($e_param)) {
    // وضع التعديل - استعلام محسّن
    $is_edit_mode = true;
    $opid = intval($e_param);
    
    // استخدام Prepared Statement مع تحسين الاستعلام
    $stmt = $conn->prepare("SELECT * FROM ot_head WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $opid);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $invoice_data = $result->fetch_assoc();
            $pro_tybe = (int)$invoice_data['pro_tybe'];
            $invoice_title = EDIT_NAMES[$pro_tybe] ?? 'تعديل فاتورة غير معروفة';
        } else {
            $invoice_title = 'لم يتم العثور على السجل';
        }
        $stmt->close();
    }
} elseif (!empty($q_param)) {
    $invoice_title = 'نوع فاتورة غير صحيح';
    error_log("Invalid invoice type: " . $q_param);
} else {
    $invoice_title = 'يرجى تحديد نوع الفاتورة';
}

// تحديد لون الخلفية (Optimized with array lookup)
$bg_colors = [
    3 => 'bg-teal-500', 4 => 'bg-teal-500',
    10 => 'bg-red-500', 11 => 'bg-red-500',
    12 => 'bg-red-500', 13 => 'bg-red-500', 14 => 'bg-red-500'
];
$background_class = $is_edit_mode ? 'bg-red-500' : ($bg_colors[$pro_tybe] ?? 'bg-gray-500');

// Lazy Loading: إنشاء العناصر فقط عند الحاجة
$invoice_elements = null;
function getInvoiceElements() {
    global $invoice_elements, $pro_tybe, $is_edit_mode, $invoice_data, $conn;
    
    if ($invoice_elements === null && $pro_tybe !== null) {
        try {
            $invoice_elements = InvoiceElementFactory::createAllElements(
                $pro_tybe, 
                $is_edit_mode, 
                $invoice_data, 
                $conn
            );
        } catch (Exception $e) {
            error_log("Error creating invoice elements: " . $e->getMessage());
            $invoice_elements = [];
        }
    }
    
    return $invoice_elements;
}
?>

<!-- Preload Critical CSS -->
<link rel="preload" href="dist/css/sales.css" as="style">

<!-- Load Critical CSS Inline (for faster First Paint) -->
<style>
    /* Critical CSS - يتم تحميله مباشرة */
    .content-wrapper { background: #f0fdfa; }
    .card { border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); background: #fff; }
    .card-body { padding: 1rem; }
    .bg-teal-50 { background-color: #f0fdfa; }
    .bg-teal-500 { background-color: #14b8a6; }
    .bg-red-500 { background-color: #ef4444; }
    .bg-gray-500 { background-color: #6b7280; }
    .text-teal-50 { color: #f0fdfa; }
    .hadi-wonder { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
</style>

<!-- Load Non-Critical CSS Async -->
<link rel="stylesheet" href="dist/css/sales.css" media="print" onload="this.media='all'; this.onload=null;">
<link rel="stylesheet" href="dist/css/keyboard-hints.css" media="print" onload="this.media='all'; this.onload=null;">
<noscript>
    <link rel="stylesheet" href="dist/css/sales.css">
    <link rel="stylesheet" href="dist/css/keyboard-hints.css">
</noscript>

<div class="content-wrapper bg-teal-50">
<section class="content-header">
<div class="container-fluid p-0 m-0">

<input type="hidden" name="pro_tybe" value="<?php echo htmlspecialchars($pro_tybe ?? ''); ?>">

<center>
<h4 class="font-thin text-md <?php echo $background_class; ?> text-teal-50 hadi-wonder" style="font-size:2em;padding:10px">
    <?php echo htmlspecialchars($invoice_title); ?>
</h4>
</center>

<?php
// Lazy Loading: تحميل Modal فقط عند الحاجة
$elements = getInvoiceElements();
if (!empty($elements['add_item_modal'])) {
    echo $elements['add_item_modal']->render();
}
?>

<div class="card">
  <div class="card-body p-0 m-0">
          <?php
          // صف الإدخال موجود داخل render() مباشرة تحت هيدر الجدول
          ?>

            <?php 
            // تحديد action الفورم بناءً على الوضع
            $form_action = $is_edit_mode ? 'do/doedit_invoice.php' : 'do/doadd_invoice.php';
            ?>
        
            <form action="<?php echo $form_action; ?>" method="post" id="myForm2">
                <input type="hidden" value="<?php echo $opid ?? ''; ?>" name="ot_id">
                
                <?php
                // Lazy Loading: عرض العناصر فقط عند الحاجة
                if (!empty($elements['header'])) {
                    echo $elements['header']->render();
                }
                
                if (!empty($elements['details'])) {
                    echo $elements['details']->render();
                }
                
                if (!empty($elements['footer'])) {
                    echo $elements['footer']->render();
                }
                ?>
            </form>
        </div>    
    </div>    

    <?php include('elements/sales/ops.php'); ?>

</div>
</section>
</div>

<!-- زر إظهار اختصارات الكيبورد -->
<button class="show-keyboard-help" id="showKeyboardHelp" title="اختصارات الكيبورد">
    ⌨️
</button>

<!-- نافذة المساعدة -->
<div class="keyboard-help" id="keyboardHelp" style="display: none;">
    <button class="close-btn" onclick="document.getElementById('keyboardHelp').style.display='none'">×</button>
    <h4>⌨️ اختصارات الكيبورد</h4>
    <ul style="direction: rtl; text-align: right;">
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">التنقل لأعلى</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">↑</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">التنقل لأسفل</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">↓</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">التنقل لليمين</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">→</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">التنقل لليسار</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">←</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">الحقل التالي</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">Tab</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">الحقل السابق</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">Shift+Tab</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">تأكيد/التالي</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">Enter</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">إلغاء/إغلاق</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">Esc</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">إضافة صف جديد</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">Alt+N</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">حقل الخصم</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">F6</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">حقل المدفوع</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">F7</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">حفظ وطباعة</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">F11</kbd> أو <kbd style="direction: ltr; unicode-bidi: isolate;">Alt+P</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">حفظ</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">F12</kbd> أو <kbd style="direction: ltr; unicode-bidi: isolate;">Alt+S</kbd>
        </li>
        <li style="display: flex; justify-content: space-between; direction: rtl;">
            <span style="direction: rtl;">إظهار/إخفاء المساعدة</span> 
            <kbd style="direction: ltr; unicode-bidi: isolate;">Alt+H</kbd>
        </li>
    </ul>
</div>

<script>
<script>
// إظهار/إخفاء نافذة المساعدة بالضغط على الزر
document.getElementById('showKeyboardHelp')?.addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const helpDiv = document.getElementById('keyboardHelp');
    if (helpDiv.style.display === 'none') {
        helpDiv.style.display = 'block';
    } else {
        helpDiv.style.display = 'none';
    }
});

// تم نقل معالج Alt+H إلى keyboard_navigation.js
</script>

<?php 
include('includes/footer.php');

// قياس الأداء (في وضع التطوير فقط)
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    $page_end_time = microtime(true);
    $page_load_time = ($page_end_time - $page_start_time) * 1000;
    error_log("Sales page load time: " . number_format($page_load_time, 2) . " ms");
}

// إنهاء Output Buffering وإرسال المحتوى
ob_end_flush();
?>

<!-- Preload JavaScript Files -->
<link rel="preload" href="js/sales.js" as="script">
<link rel="preload" href="js/sales0.js" as="script">
<link rel="preload" href="js/keyboard_navigation.js" as="script">

<!-- Load JavaScript Async with defer -->
<script src="js/sales.js" defer></script>
<script src="js/sales0.js" defer></script>
<script src="js/keyboard_navigation.js" defer></script>

<!-- Prefetch للصفحات المحتملة -->
<link rel="prefetch" href="do/doadd_invoice.php">
<link rel="prefetch" href="do/doedit_invoice.php">