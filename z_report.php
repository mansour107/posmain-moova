<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
page_guard('pos.shift.close', $conn);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
include('classes/ShiftReport.php');

// امنع تخزين الصفحة في ذاكرة المتصفح حتى لا تُعرض شاشة إغلاق شيفت مغلق عند الرجوع.
posmain_send_no_store_headers();

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

// إذا أُغلق الشيفت مسبقاً لهذه الجلسة، لا تسمح بإعادة عرض/إرسال نموذج الإغلاق.
if (!empty($_SESSION['pos_shift_closed_for_session']) || !auth_guard_is_pos_barcode_unlocked()) {
    $_SESSION['error_message'] = $_SESSION['error_message']
        ?? 'تم إغلاق هذا الشيفت. أعد فتح نقطة البيع لبدء شيفت جديد.';
    header('Location: closed_sessions.php');
    exit;
}

$user_id = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];

// تهيئة التقرير
$report = new ShiftReport($conn, $user_id);
$totals = $report->getTotals();
$breakdown = $report->getPaymentBreakdown();
$drawer_reconciliation = $report->getDrawerReconciliation();
$returns = $report->getReturns();
$expenses = $report->getExpenses();

// حسابات
$total_cash_sys = 0;
$total_visa_sys = 0;

// تجميع المدفوعات من خدمة التوافق الجديدة عند توفرها، مع إبقاء الرجوع القديم كاحتياط
$breakdown_data = [];
$reconciled_methods = $drawer_reconciliation['payments']['methods'] ?? [];
if ($reconciled_methods) {
    foreach ($reconciled_methods as $method_row) {
        $breakdown_data[] = [
            'fund_name' => $method_row['payment_method'] !== '' ? $method_row['payment_method'] : $method_row['type'],
            'fund_id' => null,
            'count' => $method_row['count'],
            'total' => (float) $method_row['total'],
            'type' => $method_row['type'],
        ];
    }
    $total_cash_sys = (float) ($drawer_reconciliation['payments']['cash'] ?? 0);
    $total_visa_sys = (float) ($drawer_reconciliation['payments']['non_cash'] ?? 0);
} else {
    while($row = $breakdown->fetch_assoc()) {
        $breakdown_data[] = $row;
        if (strpos($row['fund_name'], 'بنك') !== false || strpos($row['fund_name'], 'فيزا') !== false || strpos($row['fund_name'], 'Bank') !== false) {
            $total_visa_sys += $row['total'];
        } else {
            $total_cash_sys += $row['total'];
        }
    }
}

$expenses_total = (float) $expenses['total'];
$has_drawer_session = !empty($drawer_reconciliation['drawer_session']);
$drawer_paid_out = (float) ($drawer_reconciliation['drawer']['movement_totals']['paid_out'] ?? 0);
$drawer_paid_in = (float) ($drawer_reconciliation['drawer']['movement_totals']['paid_in'] ?? 0);
if ($has_drawer_session) {
    $expenses_total = $drawer_paid_out;
}
$drawer_expected_cash = (float) ($drawer_reconciliation['reconciliation']['expected_cash'] ?? 0);
$drawer_cash_difference = (float) ($drawer_reconciliation['reconciliation']['cash_difference'] ?? 0);
$net_cash_expected = $has_drawer_session ? $drawer_expected_cash : $total_cash_sys - $expenses_total;

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

    <?php if($has_drawer_session && $drawer_paid_in > 0): ?>
    <div class="section-title">الإيداعات النقدية</div>
    <div class="breakdown-item">
        <span>إجمالي داخل (إيداعات)</span>
        <span><?= number_format($drawer_paid_in, 2) ?></span>
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
            <?= csrf_input('shift_close_z') ?>
            <!-- Hidden Fields for System Calculations -->
            <input type="hidden" name="sys_total_sales" value="<?= $totals['total_net'] ?>">
            <input type="hidden" name="sys_total_cash" value="<?= $total_cash_sys ?>">
            <input type="hidden" name="sys_total_visa" value="<?= $total_visa_sys ?>">
            <input type="hidden" name="sys_expenses" value="<?= $expenses_total ?>">
            <input type="hidden" name="expected_cash" value="<?= $net_cash_expected ?>">
            <input type="hidden" name="drawer_session_id" value="<?= (int) ($drawer_reconciliation['drawer_session']['id'] ?? 0) ?>">
            <input type="hidden" name="drawer_expected_cash" value="<?= htmlspecialchars((string) $net_cash_expected, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="drawer_cash_difference" value="<?= htmlspecialchars((string) $drawer_cash_difference, ENT_QUOTES, 'UTF-8') ?>">
            
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
