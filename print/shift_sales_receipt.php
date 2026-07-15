<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../classes/ShiftReport.php';

if (!isset($_SESSION['userid'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];
$shiftSessions = new ShiftSessionService();
$reportContext = $shiftSessions->buildShiftReportContext($conn, (int) $user_id);
$reportScope = $reportContext['scope'];
$today = $reportContext['business_day'];
$report = new ShiftReport($conn, (int) $user_id, $today, $reportScope);

$totals = $report->getTotals();
$orderTypes = $report->getOrderTypeCounts();
$timeBounds = $report->getSaleTimeBounds();
$items_query = $report->getItemsBreakdown();

$cashier_name = $report->getCashierUsername() ?: 'الكاشير';
$settings_query = $conn->query('SELECT * FROM settings WHERE id = 1');
$settings = $settings_query ? $settings_query->fetch_assoc() : [];

$sales_data = [
    'total_invoices' => (int) ($totals['total_orders'] ?? 0),
    'total_sales' => (float) ($totals['total_gross'] ?? 0),
    'total_discounts' => (float) ($totals['total_discount'] ?? 0),
    'net_sales' => (float) ($totals['total_net'] ?? 0),
    'table_count' => $orderTypes['table_count'],
    'delivery_count' => $orderTypes['delivery_count'],
    'takeaway_count' => $orderTypes['takeaway_count'],
    'first_sale_time' => $timeBounds['first_sale_time'],
    'last_sale_time' => $timeBounds['last_sale_time'],
];

$shiftOpenedAt = $report->getShiftOpenedAt();
$shift_start = $shiftOpenedAt
    ? date('H:i', strtotime($shiftOpenedAt))
    : ($sales_data['first_sale_time'] ? date('H:i', strtotime((string) $sales_data['first_sale_time'])) : date('H:i'));
$shift_end = $sales_data['last_sale_time']
    ? date('H:i', strtotime((string) $sales_data['last_sale_time']))
    : date('H:i');
$shift_number = ($reportContext['drawer_session']['id'] ?? null)
    ? ((int) $reportContext['drawer_session']['id'] . '_' . str_replace('-', '', $today))
    : (str_replace('-', '', $today) . '_' . (int) $user_id);
$report_day_label = $today;
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير مبيعات الشيفت - <?= htmlspecialchars($cashier_name, ENT_QUOTES, 'UTF-8') ?></title>
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
        .receipt-fixed-table {
            width: 100%;
            table-layout: fixed;
        }
        .receipt-fixed-table th,
        .receipt-fixed-table td {
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
            vertical-align: top;
        }
        .receipt-item-name-cell {
            text-align: right;
            line-height: 1.35;
        }
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
    <h4 class="mb-1"><?= htmlspecialchars((string) ($settings['company_name'] ?? 'اسم الشركة'), ENT_QUOTES, 'UTF-8') ?></h4>
    <small class="text-muted"><?= htmlspecialchars((string) ($settings['company_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
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
                <div class="col-6 text-end"><?= htmlspecialchars($report_day_label, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>الكاشير:</strong></div>
                <div class="col-6 text-end"><?= htmlspecialchars($cashier_name, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>رقم الشيفت:</strong></div>
                <div class="col-6 text-end"><?= htmlspecialchars((string) $shift_number, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>بداية الشيفت:</strong></div>
                <div class="col-6 text-end"><?= htmlspecialchars($shift_start, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="row">
                <div class="col-6"><strong>نهاية الشيفت:</strong></div>
                <div class="col-6 text-end"><?= htmlspecialchars($shift_end, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
</div>

<div class="section-divider"></div>

<!-- تفاصيل الأصناف -->
<?php if ($items_query && $items_query->num_rows > 0): ?>
<div class="mb-3">
    <h6 class="text-center mb-2">تفاصيل الأصناف المباعة</h6>
    <table class="table table-sm table-bordered receipt-fixed-table">
        <colgroup>
            <col style="width: 46%;">
            <col style="width: 18%;">
            <col style="width: 18%;">
            <col style="width: 18%;">
        </colgroup>
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
                <td class="receipt-item-name-cell" title="<?= htmlspecialchars((string) $item['iname'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $item['iname'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td class="text-center"><?= number_format((float) $item['total_qty'], 1) ?></td>
                <td class="text-center"><?= number_format((float) $item['price'], 2) ?></td>
                <td class="text-center"><?= number_format((float) $item['total_value'], 2) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="text-center text-muted mb-3">
    <i class="fas fa-info-circle"></i>
    <p>لا توجد مبيعات في الشيفت الحالي</p>
</div>
<?php endif; ?>

<div class="section-divider"></div>

<!-- ملخص المبيعات -->
<div class="total-section">
    <h6 class="text-center mb-2">ملخص مبيعات الشيفت</h6>
    <table class="table table-sm mb-0">
        <tbody>
            <tr>
                <td><strong>عدد الفواتير:</strong></td>
                <td class="text-end"><?= (int) $sales_data['total_invoices'] ?></td>
            </tr>
            <tr>
                <td><strong>عدد الطاولات:</strong></td>
                <td class="text-end"><?= (int) $sales_data['table_count'] ?></td>
            </tr>
            <tr>
                <td><strong>عدد الدليفري:</strong></td>
                <td class="text-end"><?= (int) $sales_data['delivery_count'] ?></td>
            </tr>
            <tr>
                <td><strong>عدد تيك أواي:</strong></td>
                <td class="text-end"><?= (int) $sales_data['takeaway_count'] ?></td>
            </tr>
            <tr>
                <td><strong>الإجمالي:</strong></td>
                <td class="text-end"><?= number_format((float) $sales_data['total_sales'], 2) ?> ج.م</td>
            </tr>
            <?php if ((float) $sales_data['total_discounts'] > 0): ?>
            <tr>
                <td><strong>الخصم:</strong></td>
                <td class="text-end"><?= number_format((float) $sales_data['total_discounts'], 2) ?> ج.م</td>
            </tr>
            <?php endif; ?>
            <tr class="table-success">
                <td><strong>الصافي:</strong></td>
                <td class="text-end"><strong><?= number_format((float) $sales_data['net_sales'], 2) ?> ج.م</strong></td>
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
document.getElementById('printButton').addEventListener('click', function () {
    window.print();
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.getElementById('back').click();
    }
    if (event.key === 'Enter' || event.key === ' ') {
        window.print();
    }
});
</script>
</body>
</html>
