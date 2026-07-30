<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
page_guard('pos.shift.close', $conn);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/includes/print_client_bootstrap.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/classes/ShiftReport.php';

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
    header('Location: pos_barcode.php?logout=1');
    exit;
}

$user_id = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];
$shiftCountService = new ShiftCountService();
$handoverEnabled = $shiftCountService->handoverEnabled($conn);
$canSeeExpectedCash = auth_guard_has_permission('reports.cash_flow', $conn);
$showExpectedCashOnZ = !$handoverEnabled || $canSeeExpectedCash;
$shiftCloseCountCsrf = csrf_token('shift_close_count');

require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/includes/business_day.php';
$shiftSessions = new ShiftSessionService();
$reportContext = $shiftSessions->buildShiftReportContext($conn, (int) $user_id);
$reportScope = $reportContext['scope'];
$activeDrawerSession = $reportContext['drawer_session'];
$reportBusinessDay = $reportContext['business_day'];

// تهيئة التقرير — scoped to the active drawer session when handover is in use
$report = new ShiftReport($conn, $user_id, $reportBusinessDay, $reportScope);
$totals = $report->getTotals();
$breakdown = $report->getPaymentBreakdown();
$drawer_reconciliation = $report->getDrawerReconciliation($reportScope);
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
    <?= posmain_render_print_client_bootstrap('') ?>
</head>
<body class="z-report-page" data-print-job-type="z_report" data-print-content-selector=".ticket">

<div class="ticket">
    <div class="ticket-header">
        <h4>تقرير إغلاق شيفت (Z-Report)</h4>
        <div><?= date('Y-m-d H:i') ?></div>
    </div>

    <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="z-report-flash z-report-flash--error" role="alert">
        <?= htmlspecialchars((string) $_SESSION['error_message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>

    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="z-report-flash z-report-flash--success" role="status">
        <?= htmlspecialchars((string) $_SESSION['success_message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php unset($_SESSION['success_message']); endif; ?>
    
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
        <tr>
            <td>المبيعات بعد الخصم وقبل المرتجعات</td>
            <td class="amount"><?= number_format($totals['total_sales_after_discount'] ?? 0, 2) ?></td>
        </tr>
        <tr>
            <td>المرتجعات المنشورة</td>
            <td class="amount">- <?= number_format($totals['total_refunds'] ?? 0, 2) ?></td>
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
    
    <?php if ($showExpectedCashOnZ): ?>
    <div class="section-title" style="border-top: 2px solid #000; margin-top: 10px;">المطلوب في الدرج</div>
    <div class="breakdown-item" style="font-weight: bold; font-size: 1.1rem;">
        <span>صافي النقدية المتوقع</span>
        <span><?= number_format($net_cash_expected, 2) ?></span>
    </div>
    <?php elseif ($handoverEnabled): ?>
    <div class="section-title" style="border-top: 2px solid #000; margin-top: 10px;">عد الدرج (أعمى)</div>
    <div class="breakdown-item" style="font-weight: bold; font-size: 1.0rem; color:#555;">
        <span>أدخل المبلغ الموجود في الدرج دون الاطلاع على المتوقع</span>
        <span>—</span>
    </div>
    <?php endif; ?>

    <!-- Input Section for Closing -->
    <div class="input-section no-print">
        <form action="do_close_shift_z.php" method="POST" id="closeForm"
              data-handover-enabled="<?= $handoverEnabled ? '1' : '0' ?>"
              data-close-count-csrf="<?= htmlspecialchars($shiftCloseCountCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <?= csrf_input('shift_close_z') ?>
            <!-- Hidden Fields for System Calculations -->
            <input type="hidden" name="sys_total_sales" value="<?= $totals['total_net'] ?>">
            <input type="hidden" name="sys_total_cash" value="<?= $total_cash_sys ?>">
            <input type="hidden" name="sys_total_visa" value="<?= $total_visa_sys ?>">
            <input type="hidden" name="sys_expenses" value="<?= $expenses_total ?>">
            <?php if ($showExpectedCashOnZ): ?>
            <input type="hidden" name="expected_cash" value="<?= $net_cash_expected ?>">
            <input type="hidden" name="drawer_expected_cash" value="<?= htmlspecialchars((string) $net_cash_expected, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="drawer_cash_difference" value="<?= htmlspecialchars((string) $drawer_cash_difference, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <input type="hidden" name="drawer_session_id" value="<?= (int) ($drawer_reconciliation['drawer_session']['id'] ?? 0) ?>">
            <input type="hidden" name="close_token" id="zCloseToken" value="">
            <input type="hidden" name="matched" id="zCloseMatched" value="0">
            <input type="hidden" name="idempotency_key" id="zCloseIdempotencyKey" value="">
            
            <div class="form-group">
                <label>النقدية الفعلية (العد)</label>
                <input type="number" step="0.01" name="actual_cash" id="zActualCash" class="form-control" required placeholder="أدخل المبلغ الموجود في الدرج">
            </div>
            
            <!-- Visa input removed as requested -->
            <input type="hidden" name="actual_visa" value="<?= $total_visa_sys ?>">
            
            <div class="form-group">
                <label>ملاحظات</label>
                <input type="text" name="notes" class="form-control" placeholder="أي ملاحظات للإغلاق">
            </div>

            <div id="zCloseCountMessage" class="z-report-flash z-report-flash--error" style="display:none;" role="alert"></div>
            
            <button type="submit" class="btn-close-shift" id="zCloseSubmitBtn">
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

<script>
(function () {
    var form = document.getElementById('closeForm');
    if (!form) {
        return;
    }

    var handoverEnabled = form.getAttribute('data-handover-enabled') === '1';
    var closeCountCsrf = form.getAttribute('data-close-count-csrf') || '';
    var submitting = false;

    function createIdempotencyKey(scope) {
        var suffix = (typeof crypto !== 'undefined' && crypto.randomUUID)
            ? crypto.randomUUID()
            : (Date.now().toString(36) + ':' + Math.random().toString(36).slice(2));
        return scope + ':' + suffix;
    }

    function showMessage(text) {
        var box = document.getElementById('zCloseCountMessage');
        if (!box) {
            return;
        }
        box.style.display = text ? 'block' : 'none';
        box.textContent = text || '';
    }

    function parseJson(response) {
        return response.json().then(function (payload) {
            return { ok: response.ok, payload: payload || {} };
        });
    }

    function submitCloseCount(amount, attempt) {
        return fetch('do/do_submit_shift_close_count.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
                counted_amount: amount,
                csrf_token: closeCountCsrf,
                idempotency_key: createIdempotencyKey('pos.shift.submit_close_count'),
            }).toString(),
            credentials: 'same-origin',
        }).then(parseJson).then(function (result) {
            var data = (result.payload && result.payload.data) || {};
            if (!result.payload.success) {
                var err = (result.payload && result.payload.error) || 'تعذر التحقق من العد';
                if (err === 'CLOSE_EXPECTED_DRIFTED') {
                    throw new Error('تغيّر الرصيد — أعد المحاولة');
                }
                throw new Error(err);
            }
            if (data.status === 'recount') {
                if (attempt >= 2) {
                    throw new Error(data.message || 'الرجاء إعادة العد بعناية');
                }
                return submitCloseCount(amount, attempt + 1);
            }
            return data;
        });
    }

    form.addEventListener('submit', function (event) {
        if (submitting) {
            event.preventDefault();
            return;
        }

        if (!handoverEnabled) {
            if (!window.confirm('هل أنت متأكد من إغلاق الشيفت؟ لا يمكن التراجع.')) {
                event.preventDefault();
            }
            return;
        }

        var drawerSessionId = (form.querySelector('[name="drawer_session_id"]') || {}).value || '0';
        if (drawerSessionId === '0') {
            return;
        }

        var tokenField = document.getElementById('zCloseToken');
        if (tokenField && tokenField.value) {
            return;
        }

        event.preventDefault();
        var amountInput = document.getElementById('zActualCash');
        var amount = amountInput ? String(amountInput.value || '').trim() : '';
        if (amount === '') {
            showMessage('أدخل المبلغ الموجود في الدرج');
            return;
        }

        if (!window.confirm('هل أنت متأكد من إغلاق الشيفت؟ لا يمكن التراجع.')) {
            return;
        }

        submitting = true;
        showMessage('');
        var submitBtn = document.getElementById('zCloseSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
        }

        fetch('do/do_begin_shift_close_count.php', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(parseJson).then(function (beginResult) {
            if (!beginResult.payload.success) {
                throw new Error((beginResult.payload && beginResult.payload.error) || 'تعذر بدء عد الإغلاق');
            }
            return submitCloseCount(amount, 1);
        }).then(function (data) {
            document.getElementById('zCloseToken').value = data.close_token || '';
            document.getElementById('zCloseMatched').value = data.matched ? '1' : '0';
            document.getElementById('zCloseIdempotencyKey').value = createIdempotencyKey('pos.shift.close');
            if (!document.getElementById('zCloseToken').value) {
                throw new Error('تعذر إصدار رمز إغلاق الدرج');
            }
            form.submit();
        }).catch(function (error) {
            submitting = false;
            if (submitBtn) {
                submitBtn.disabled = false;
            }
            showMessage(error && error.message ? error.message : 'تعذر إغلاق الشيفت');
        });
    });
})();
</script>

</body>
</html>
