<?php 
session_start();
include('../includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['userid'];
$today = date('Y-m-d');

// جلب اسم المستخدم
$user_query = $conn->query("SELECT uname FROM users WHERE id = $user_id");
$user_data = $user_query->fetch_assoc();
$cashier_name = $user_data['uname'] ?? 'الكاشير';

// جلب إعدادات الشركة
$settings_query = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_query->fetch_assoc();

// جلب إجمالي مبيعات اليوم للمستخدم المسجل فقط
$sales_query = $conn->query("SELECT 
    COUNT(*) as total_invoices,
    COALESCE(SUM(fat_total), 0) as total_sales,
    COALESCE(SUM(fat_disc), 0) as total_discounts,
    COALESCE(SUM(fat_net), 0) as net_sales,
    MIN(crtime) as first_sale_time,
    MAX(crtime) as last_sale_time,
    SUM(CASE WHEN info LIKE '%نوع الطلب: طاولة%' THEN 1 ELSE 0 END) as table_count,
    SUM(CASE WHEN info LIKE '%نوع الطلب: دليفري%' THEN 1 ELSE 0 END) as delivery_count,
    SUM(CASE WHEN info LIKE '%نوع الطلب: تيك أواي%' THEN 1 ELSE 0 END) as takeaway_count
    FROM ot_head 
    WHERE DATE(pro_date) = '$today'
    AND user = '$user_id'
    AND pro_tybe = 9
    AND isdeleted = 0");
$sales_data = $sales_query->fetch_assoc();

// جلب تفاصيل أصناف اليوم للمستخدم المسجل فقط
$items_query = $conn->query("SELECT 
    mi.iname,
    SUM(fd.qty_out - fd.qty_in) as total_qty,
    fd.price,
    SUM(fd.det_value) as total_value
    FROM ot_head oh
    JOIN fat_details fd ON oh.id = fd.pro_id
    JOIN myitems mi ON fd.item_id = mi.id
    WHERE DATE(oh.pro_date) = '$today'
    AND oh.user = '$user_id'
    AND oh.pro_tybe = 9
    AND oh.isdeleted = 0
    AND fd.isdeleted = 0
    GROUP BY fd.item_id, fd.price
    ORDER BY total_value DESC");

// حساب أوقات الشيفت
$shift_start = $sales_data['first_sale_time'] ? date('H:i', strtotime($sales_data['first_sale_time'])) : date('H:i', strtotime('today'));
$shift_end = $sales_data['last_sale_time'] ? date('H:i', strtotime($sales_data['last_sale_time'])) : date('H:i');
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير مبيعات الشيفت - <?= $cashier_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
            .receipt-container { width: 100% !important; max-width: none !important; }
        }
        body { font-family: 'Arial', sans-serif; }
        .receipt-container { width: 75mm; margin: 0 auto; }
        .company-header { border-bottom: 2px dashed #333; padding-bottom: 10px; margin-bottom: 10px; }
        .section-divider { border-top: 1px dashed #666; margin: 10px 0; padding-top: 10px; }
        .total-section { background: #f8f9fa; padding: 8px; border-radius: 5px; }
    </style>
</head>
<body>

<div class="card receipt-container" id="printed">
<div class="card-body p-2">

<!-- رأس الشركة -->
<div class="company-header text-center">
    <?php 
    $logo_path = '../assets/logo/logo.jpg';
    if (file_exists($logo_path)) {
        echo '<img src="' . $logo_path . '" alt="" class="img-fluid mb-2" style="max-height: 60px;">';
    }
    ?>
    <h4 class="mb-1"><?= $settings['company_name'] ?? 'اسم الشركة' ?></h4>
    <small class="text-muted"><?= $settings['company_address'] ?? '' ?></small>
</div>

<!-- عنوان التقرير -->
<div class="text-center mb-3">
    <h5 class="mb-1">تقرير مبيعات الشيفت</h5>
    <small class="text-primary">مبيعات شخصية</small>
</div>

<!-- معلومات الشيفت -->
<div class="row small mb-3">
    <div class="col-12">
        <div class="border p-2 rounded">
            <div class="row">
                <div class="col-6"><strong>التاريخ:</strong></div>
                <div class="col-6 text-end"><?= date('Y-m-d') ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>الكاشير:</strong></div>
                <div class="col-6 text-end"><?= $cashier_name ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>رقم الشيفت:</strong></div>
                <div class="col-6 text-end"><?= date('Ymd') . '_' . $user_id ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>بداية الشيفت:</strong></div>
                <div class="col-6 text-end"><?= $shift_start ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>نهاية الشيفت:</strong></div>
                <div class="col-6 text-end"><?= $shift_end ?></div>
            </div>
        </div>
    </div>
</div>

<div class="section-divider"></div>

<!-- تفاصيل الأصناف -->
<?php if ($items_query->num_rows > 0): ?>
<div class="mb-3">
    <h6 class="text-center mb-2">تفاصيل الأصناف المباعة</h6>
    <table class="table table-sm table-bordered">
        <thead class="table-dark">
            <tr style="font-size: 10px;">
                <th>الصنف</th>
                <th>الكمية</th>
                <th>السعر</th>
                <th>القيمة</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($item = $items_query->fetch_assoc()): ?>
            <tr style="font-size: 10px;">
                <td class="text-truncate" style="max-width: 80px;" title="<?= $item['iname'] ?>">
                    <?= $item['iname'] ?>
                </td>
                <td class="text-center"><?= number_format($item['total_qty'], 1) ?></td>
                <td class="text-center"><?= number_format($item['price'], 2) ?></td>
                <td class="text-center"><?= number_format($item['total_value'], 2) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="text-center text-muted mb-3">
    <i class="fas fa-info-circle"></i>
    <p>لا توجد مبيعات لك اليوم</p>
</div>
<?php endif; ?>

<div class="section-divider"></div>

<!-- ملخص المبيعات -->
<div class="total-section">
    <h6 class="text-center mb-2">ملخص مبيعاتك اليوم</h6>
    <table class="table table-sm mb-0">
        <tbody>
            <tr>
                <td><strong>عدد الفواتير:</strong></td>
                <td class="text-end"><?= $sales_data['total_invoices'] ?></td>
            </tr>
            <tr>
                <td><strong>عدد الطاولات:</strong></td>
                <td class="text-end"><?= $sales_data['table_count'] ?></td>
            </tr>
            <tr>
                <td><strong>عدد الدليفري:</strong></td>
                <td class="text-end"><?= $sales_data['delivery_count'] ?></td>
            </tr>
            <tr>
                <td><strong>عدد تيك أواي:</strong></td>
                <td class="text-end"><?= $sales_data['takeaway_count'] ?></td>
            </tr>
            <tr>
                <td><strong>الإجمالي:</strong></td>
                <td class="text-end"><?= number_format($sales_data['total_sales'], 2) ?> ج.م</td>
            </tr>
            <?php if ($sales_data['total_discounts'] > 0): ?>
            <tr>
                <td><strong>الخصم:</strong></td>
                <td class="text-end"><?= number_format($sales_data['total_discounts'], 2) ?> ج.م</td>
            </tr>
            <?php endif; ?>
            <tr class="table-success">
                <td><strong>الصافي:</strong></td>
                <td class="text-end"><strong><?= number_format($sales_data['net_sales'], 2) ?> ج.م</strong></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="section-divider"></div>

<!-- تذييل -->
<div class="text-center small">
    <p class="mb-1">وقت الطباعة: <?= date('Y-m-d H:i:s') ?></p>
    <p class="mb-1">شكراً لك على عملك الجاد</p>
    <p class="mb-0 text-muted">نظام نقاط البيع</p>
</div>

</div>
</div>

<!-- أزرار التحكم -->
<div class="row no-print mt-3">
    <div class="col-12 text-center">
        <button id="printButton" class="btn btn-primary me-2">
            <i class="fas fa-print"></i> طباعة
        </button>
        <a href="../z_report.php" class="btn btn-warning me-2">
            <i class="fas fa-file-invoice"></i> Z-Report
        </a>
        <a href="../pos_barcode.php" class="btn btn-secondary" id="back">
            <i class="fas fa-arrow-left"></i> عودة
        </a>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// علّم أن صفحة الطباعة تم فتحها
sessionStorage.setItem('pos_print_page_opened', 'true');
console.log('Print page opened, flag set');

// استخدام JavaScript عادي بدلاً من jQuery
document.addEventListener('DOMContentLoaded', function() {
    var printButton = document.getElementById('printButton');
    
    if (printButton) {
        printButton.addEventListener('click', function() {
            console.log('Print button clicked');
            window.print();
        });
    }
});
</script>

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        document.getElementById('back').click();
    }
    if (event.key === "Enter" || event.key === " ") {
        window.print();
    }
});

// طباعة تلقائية عند التحميل (اختياري)
// window.onload = function() { window.print(); };
</script>

</body>
</html>