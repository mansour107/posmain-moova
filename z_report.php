<?php
session_start();
include('includes/connect.php');
include('classes/ShiftReport.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['userid'];

// تهيئة التقرير
$report = new ShiftReport($conn, $user_id);
$totals = $report->getTotals();
$breakdown = $report->getPaymentBreakdown();
$returns = $report->getReturns();
$expenses = $report->getExpenses();

// حسابات
$total_cash_sys = 0;
$total_visa_sys = 0;

// تجميع المدفوعات (يمكن تحسين المنطق لمعرفة النقدية والفيزا بدقة اذا كانت اسماء الصناديق معروفة)
// سنفترض مؤقتاً هنا للعرض، ولكن في الإغلاق سنرسل كل صندوق على حدة
$breakdown_data = [];
while($row = $breakdown->fetch_assoc()) {
    $breakdown_data[] = $row;
    // منطق تقريبي: "بنك" أو "فيزا" في الاسم يعني فيزا
    if (strpos($row['fund_name'], 'بنك') !== false || strpos($row['fund_name'], 'فيزا') !== false || strpos($row['fund_name'], 'Bank') !== false) {
        $total_visa_sys += $row['total'];
    } else {
        // افتراض الباقي نقدي (خزينة)
        $total_cash_sys += $row['total'];
    }
}

$expenses_total = $expenses['total'];
$net_cash_expected = $total_cash_sys - $expenses_total;

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إغلاق الشيفت Z-Report</title>
    <link href="dist/css/z_report.css" rel="stylesheet">
    <link href="assets/libs/fontawesome.min.css" rel="stylesheet">
</head>
<body class="z-report-page">

<div class="ticket">
    <div class="ticket-header">
        <h4>تقرير إغلاق شيفت (Z-Report)</h4>
        <div><?= date('Y-m-d H:i') ?></div>
    </div>
    
    <div class="ticket-info">
        <div><span>الكاشير:</span> <span><?= $_SESSION['username'] ?? 'User '.$user_id ?></span></div>
        <div><span>رقم الشيفت:</span> <span><?= date('Ymd').'_'.$user_id ?></span></div>
    </div>
    
    <div class="section-title">ملخص المبيعات</div>
    <table class="summary-table">
        <tr>
            <td>عدد الفواتير</td>
            <td class="amount"><?= $totals['total_orders'] ?></td>
        </tr>
        <tr>
            <td>إجمالي المبيعات</td>
            <td class="amount"><?= number_format($totals['total_gross'], 2) ?></td>
        </tr>
        <tr>
            <td>الخصومات</td>
            <td class="amount"><?= number_format($totals['total_discount'], 2) ?></td>
        </tr>
        <tr class="total-row">
            <td>صافي المبيعات</td>
            <td class="amount"><?= number_format($totals['total_net'], 2) ?></td>
        </tr>
    </table>
    
    <?php if($returns['total'] > 0): ?>
    <div class="section-title">المرتجعات</div>
    <table class="summary-table">
        <tr>
            <td>عدد المرتجعات</td>
            <td class="amount"><?= $returns['count'] ?></td>
        </tr>
        <tr>
            <td>قيمة المرتجعات</td>
            <td class="amount"><?= number_format($returns['total'], 2) ?></td>
        </tr>
    </table>
    <?php endif; ?>
    
    <div class="section-title">تفصيل المدفوعات (System)</div>
    <?php foreach($breakdown_data as $row): ?>
    <div class="breakdown-item">
        <span><?= htmlspecialchars($row['fund_name']) ?></span>
        <span><?= number_format($row['total'], 2) ?></span>
    </div>
    <?php endforeach; ?>
    
    <?php if($expenses_total > 0): ?>
    <div class="section-title">المصروفات / المدفوعات</div>
    <div class="breakdown-item">
        <span>إجمالي خارج</span>
        <span><?= number_format($expenses_total, 2) ?></span>
    </div>
    <?php endif; ?>
    
    <div class="section-title" style="border-top: 2px solid #000; margin-top: 10px;">المطلوب في الدرج</div>
    <div class="breakdown-item" style="font-weight: bold; font-size: 1.1rem;">
        <span>صافي النقدية المتوقع</span>
        <span><?= number_format($net_cash_expected, 2) ?></span>
    </div>

    <!-- Input Section for Closing -->
    <div class="input-section no-print">
        <form action="do_close_shift_z.php" method="POST" id="closeForm">
            <!-- Hidden Fields for System Calculations -->
            <input type="hidden" name="sys_total_sales" value="<?= $totals['total_net'] ?>">
            <input type="hidden" name="sys_total_cash" value="<?= $total_cash_sys ?>">
            <input type="hidden" name="sys_total_visa" value="<?= $total_visa_sys ?>">
            <input type="hidden" name="sys_expenses" value="<?= $expenses_total ?>">
            <input type="hidden" name="expected_cash" value="<?= $net_cash_expected ?>">
            
            <div class="form-group">
                <label>النقدية الفعلية (العد)</label>
                <input type="number" step="0.01" name="actual_cash" class="form-control" required placeholder="أدخل المبلغ الموجود في الدرج">
            </div>
            
            <!-- Visa input removed as requested -->
            <input type="hidden" name="actual_visa" value="<?= $total_visa_sys ?>">
            
            <div class="form-group">
                <label>ملاحظات</label>
                <input type="text" name="notes" class="form-control" placeholder="أي ملاحظات للإغلاق">
            </div>
            
            <button type="submit" class="btn-close-shift" onclick="return confirm('هل أنت متأكد من إغلاق الشيفت؟ لا يمكن التراجع.')">
                <i class="fas fa-lock me-2"></i> تأكيد وإغلاق الشيفت
            </button>
            <br><br>
            <a href="pos_barcode.php" style="display:block; text-align:center; color:#666;">رجوع لنقطة البيع</a>
        </form>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">
            <i class="fas fa-print"></i> طباعة مسودة
        </button>
    </div>
</div>

</body>
</html>
