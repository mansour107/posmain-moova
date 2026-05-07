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
$user_query = $conn->query("SELECT aname FROM acc_head WHERE id = $user_id");
$user_data = $user_query->fetch_assoc();
$cashier_name = $user_data['aname'] ?? 'الكاشير';

// جلب إجمالي مبيعات اليوم للمستخدم المسجل فقط
$sales_query = $conn->query("SELECT 
    COUNT(*) as total_invoices,
    COALESCE(SUM(fat_total), 0) as total_sales,
    COALESCE(SUM(fat_disc), 0) as total_discounts,
    COALESCE(SUM(fat_net), 0) as net_sales
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

// إضافة بيانات وهمية للاختبار إذا لم توجد بيانات
if ($items_query->num_rows == 0) {
    $dummy_items = [
        ['iname' => 'لا توجد مبيعات لك اليوم', 'total_qty' => 0, 'price' => 0, 'total_value' => 0]
    ];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير مبيعات اليوم</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
        }
        body { font-family: 'Arial', sans-serif; }
        .receipt-container { width: 72mm; margin: 0 auto; }
    </style>
</head>
<body>

<div class="card receipt-container" id="printed">
<div class="card-body">

<?php 
$logo_path = '../assets/logo/logo.jpg';
if (file_exists($logo_path)) {
    echo '<img src="' . $logo_path . '" alt="" class="img-fluid">';
} else {
    echo '<div class="text-center p-2">لوجو الشركة</div>';
}
?>
<h1 class="text-center p-3 p-0 font-bold text-xl">
<?= $rowstg['company_name'] ?? 'اسم الشركة' ?></h1>

<div class="row">
    <div class="col-12">
<p style="font-size:12px;text-align:center">تقرير مبيعات اليوم</p>
<div class="row invoice-info font-thin border-1 border-indigo-300 m-0">

<div class="col-sm-12 invoice-col">
<address>
<b>التاريخ:</b> <?= date('Y-m-d') ?><br>
<b>الوقت:</b> <?= date('H:i:s') ?><br>
<b>الكاشير:</b> <?= $cashier_name ?><br>
<b>رقم الشيفت:</b> <?= date('Ymd') . '_' . $user_id ?><br>
</address>
</div>

</div>

<p class="text-center">************</p>
<div class="row">

<table class="table col-md-12 table-bordered table-lg text-center">
<thead>
<tr class="bg-slate-100 border-2 border-slate-900" style="font-size:x-small">
<th class="border-3 border-slate-900">الصــــنـــف</th>
<th class="border-3 border-slate-900">الكمية</th>
<th class="border-3 border-slate-900">السعر</th>
<th class="border-3 border-slate-900">القيمة</th>
</tr>
</thead>
<tbody>
    <?php 
    if ($items_query->num_rows > 0) {
        while ($item = $items_query->fetch_assoc()) {
    ?>
<tr class="border-2 border-slate-900">
<td class="p-1" style="font-size:small"><?= $item['iname'] ?></td>
<td><?= $item['total_qty'] ?></td>
<td><?= $item['price'] ?></td>
<td><?= $item['total_value'] ?></td>
</tr>
    <?php 
        }
    } else {
        foreach ($dummy_items as $item) {
    ?>
<tr class="border-2 border-slate-900">
<td class="p-1" style="font-size:small"><?= $item['iname'] ?></td>
<td><?= $item['total_qty'] ?></td>
<td><?= $item['price'] ?></td>
<td><?= $item['total_value'] ?></td>
</tr>
    <?php 
        }
    }
    ?>
</tbody>
</table>

</div>
<p class="text-center">************</p>

<div class="row">
<div class="col-12">
<div class="table-responsive">
<table class="table table-bordered table-sm bg-slate-50">
<tbody>
    <tr class="bg-slate-100 border-b-2 border-l-2 border-slate-900">
<th style="width:35%">عدد الفواتير:</th>
<td class="float-right"><?= $sales_data['total_invoices'] ?></td>
</tr>

<tr class="bg-slate-100 border-b-2 border-l-2 border-slate-900">
<th style="width:35%">اجمالي:</th>
<td class="float-right"><?= number_format($sales_data['total_sales'], 2) ?> ج.م</td>
</tr>

<?php if ($sales_data['total_discounts'] > 0) { ?>
<tr class="bg-slate-100 border-b-2 border-l-2 border-slate-900">
<th>خصم:</th>
<td class="float-right"><?= number_format($sales_data['total_discounts'], 2) ?> ج.م</td>
</tr>
<?php } ?>

<tr class="bg-slate-100 border-b-2 border-l-2 border-slate-900">
<th>الصافي:</th>
<td class="float-right"><strong><?= number_format($sales_data['net_sales'], 2) ?> ج.م</strong></td>
</tr>

<tr class="bg-slate-100 border-b-2 border-l-2 border-slate-900">
<th>وقت بداية الشيفت:</th>
<td class="float-right"><?= date('H:i', strtotime('today')) ?></td>
</tr>

<tr class="bg-slate-100 border-b-2 border-l-2 border-slate-900">
<th>وقت إنهاء الشيفت:</th>
<td class="float-right"><?= date('H:i') ?></td>
</tr>
</tbody>
</table>
</div>
</div>
</div>

<p class="text-center">************</p>
<div class="row">
<div class="col">
    <p style="font-size:12px;text-align:center"><?= date('Y-m-d H:i:s') ?></p>
    <p class="text-center">شكراً لكم</p>
    <p class="text-center">هاوس.com</p>
</div>
</div>

</div>
</div>

<div class="row no-print">
<div class="col-12">
    <button id="printButton" class="btn btn-secondary frst">
<i class="fas fa-print"></i> طباعه
</button>
<a href="../pos_barcode.php" id="back">عودة</a>
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
    
    // طباعة تلقائية عند التحميل (اختياري)
    // window.print();
});

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        document.getElementById('back').click();
    }
    if (event.key === "Enter" || event.key === " ") {
        window.print();
    }
});
</script>

</body>
</html>