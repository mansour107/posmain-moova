<?php
require_once __DIR__ . '/includes/auth_guard.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
page_guard('reports.cash_flow', $conn);
posmain_send_no_store_headers();

$sessionId = (int) ($_GET['id'] ?? 0);
$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);

require_once __DIR__ . '/classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/classes/Pos/Service/BusinessDayService.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php';

$movementLabels = [
    'sale_cash' => 'مبيعات نقدية',
    'refund_cash' => 'مرتجعات نقدية',
    'paid_in' => 'إيداعات',
    'paid_out' => 'مصروفات',
    'safe_drop' => 'تحويل للخزنة',
    'opening' => 'افتتاح',
    'closing_adjustment' => 'تسوية إغلاق',
    'no_sale' => 'فتح بدون بيع',
];

$movementSigns = [
    'sale_cash' => 1,
    'refund_cash' => -1,
    'paid_in' => 1,
    'paid_out' => -1,
    'safe_drop' => -1,
    'opening' => 1,
    'closing_adjustment' => 1,
    'no_sale' => 0,
];

$paymentTypeLabels = [
    'cash' => 'نقدي',
    'card' => 'بطاقة',
    'wallet' => 'محفظة',
    'bank' => 'بنك',
    'gift_card' => 'بطاقة هدايا',
    'other' => 'أخرى',
];

$statusLabels = [
    'open' => 'مفتوح',
    'closed' => 'مغلق',
    'forced_closed' => 'إغلاق قسري',
];

$formatDateTime = static function (?string $datetime): string {
    if ($datetime === null || trim($datetime) === '') {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date('Y-m-d H:i', $ts) : $datetime;
};

$session = null;
$recon = [];
$breakdown = [];
$movements = ['rows' => []];
$userName = '';
$businessDay = '';
$notFound = false;

if ($sessionId < 1) {
    $notFound = true;
} else {
    try {
        $drawerService = new DrawerSessionService();
        $session = $drawerService->sessionById($conn, $sessionId);

        if ($tenant > 0 && (int) ($session['tenant'] ?? 0) !== $tenant) {
            $notFound = true;
            $session = null;
        }
        if ($branch > 0 && (int) ($session['branch'] ?? 0) !== $branch) {
            $notFound = true;
            $session = null;
        }
    } catch (Throwable $e) {
        $notFound = true;
        $session = null;
    }
}

if ($session) {
    $userId = (int) ($session['user_id'] ?? 0);
    $userRes = $conn->query('SELECT uname, display_name FROM users WHERE id = ' . $userId . ' LIMIT 1');
    if ($userRes && $userRes->num_rows > 0) {
        $userRow = $userRes->fetch_assoc();
        $userName = (string) ($userRow['display_name'] ?: $userRow['uname'] ?: '');
    }

    $businessDays = new BusinessDayService();
    $cutoffHour = $businessDays->cutoffHourForBranch($conn, (int) ($session['tenant'] ?? 0), (int) ($session['branch'] ?? 0));
    $businessDay = trim((string) ($session['business_day'] ?? ''));
    if ($businessDay === '') {
        $businessDay = $businessDays->businessDayForTimestamp((string) ($session['opened_at'] ?? ''), $cutoffHour);
    }

    $reconService = new ShiftDrawerReconciliationService();
    $recon = $reconService->buildForUser($conn, [
        'user_id' => $userId,
        'tenant' => (int) ($session['tenant'] ?? 0),
        'branch' => (int) ($session['branch'] ?? 0),
        'date' => $businessDay,
        'drawer_session_id' => $sessionId,
    ]);

    $breakdown = $drawerService->sessionCashBreakdown($conn, $sessionId);

    $cashFlow = new CashFlowPeriodService();
    $movements = $cashFlow->movements($conn, [
        'drawer_session_id' => $sessionId,
        'limit' => 500,
        'offset' => 0,
        'tenant' => (int) ($session['tenant'] ?? 0),
        'branch' => (int) ($session['branch'] ?? 0),
    ]);

    $shiftCountService = new ShiftCountService();
    $countAttempts = $shiftCountService->countAttemptsForSession($conn, $sessionId);
    $resolutionHistory = $shiftCountService->resolutionsForSession($conn, $sessionId);
}

$countAttempts = $countAttempts ?? [];
$resolutionHistory = $resolutionHistory ?? [];
$openingVariance = (float) ($session['opening_variance'] ?? 0);
$varianceStatus = (string) ($session['variance_status'] ?? 'none');
$drawer = $recon['drawer'] ?? [];
$movementTotals = $drawer['movement_totals'] ?? [];
$expectedCash = (float) ($breakdown['pre_close_expected_cash'] ?? $drawer['pre_close_expected_cash'] ?? 0);
$countedCash = array_key_exists('counted_cash', $breakdown) && $breakdown['counted_cash'] !== null
    ? (float) $breakdown['counted_cash']
    : null;
$variance = array_key_exists('close_variance', $breakdown) && $breakdown['close_variance'] !== null
    ? (float) $breakdown['close_variance']
    : null;
$isOpen = ($session['status'] ?? '') === 'open';
$countPending = !empty($breakdown['count_pending']);

$varianceClass = 'zero';
$varianceLabel = 'متوازن';
if ($countPending) {
    $varianceClass = 'warn';
    $varianceLabel = 'بانتظار العد';
} elseif ($variance !== null && abs($variance) >= 0.001) {
    $varianceClass = $variance > 0 ? 'pos' : 'neg';
    $varianceLabel = $variance > 0 ? 'زيادة' : 'عجز';
} elseif ($isOpen) {
    $varianceClass = 'warn';
    $varianceLabel = 'جلسة مفتوحة';
}

$statusKey = (string) ($session['status'] ?? '');
$statusLabel = $statusLabels[$statusKey] ?? $statusKey;

$premiumCssVer = is_file(__DIR__ . '/css/premium-report-light.css')
    ? (string) filemtime(__DIR__ . '/css/premium-report-light.css')
    : '1';
?>
<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<script>document.body.classList.add('premium-report-page');</script>
<link rel="stylesheet" href="css/premium-report-light.css?v=<?= htmlspecialchars($premiumCssVer, ENT_QUOTES, 'UTF-8') ?>">

<div class="content-wrapper">
  <section class="content">
    <div class="premium-report">
      <?php if ($notFound): ?>
      <div class="pr-callout pr-callout--danger">
        <strong>الجلسة غير موجودة</strong> — تحقق من رقم الجلسة أو صلاحيات الفرع.
        <div class="mt-2">
          <a href="cash_flow_report.php" class="pr-btn pr-btn-ghost">العودة للتقرير</a>
        </div>
      </div>
      <?php else: ?>

      <nav class="pr-breadcrumb pr-no-print">
        <a href="cash_flow_report.php">تقرير التدفق النقدي</a>
        <span>›</span>
        <strong>جلسة #<?= (int) $sessionId ?></strong>
      </nav>

      <header class="pr-hero">
        <div class="pr-hero-text">
          <p class="pr-eyebrow">تفاصيل الجلسة</p>
          <h1>جلسة درج #<?= (int) $sessionId ?></h1>
          <div class="pr-detail-meta">
            <span class="pr-pill pr-pill--user"><?= htmlspecialchars($userName) ?></span>
            <span class="pr-pill <?= $isOpen ? 'pr-pill--status-open pr-pill--pulse' : 'pr-pill--status-closed' ?>"><?= htmlspecialchars($statusLabel) ?></span>
            <span class="pr-pill pr-pill--muted">يوم العمل: <?= htmlspecialchars($businessDay) ?></span>
          </div>
          <p class="pr-hero-sub">
            من <?= htmlspecialchars($formatDateTime($session['opened_at'] ?? null)) ?>
            إلى <?= htmlspecialchars($formatDateTime($session['closed_at'] ?? null)) ?>
          </p>
        </div>
        <div class="pr-detail-actions pr-no-print">
          <a href="cash_flow_report.php" class="pr-btn pr-btn-ghost">← رجوع</a>
          <button type="button" class="pr-btn pr-btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> طباعة
          </button>
        </div>
      </header>

      <?php if ($isOpen): ?>
      <div class="pr-callout pr-callout--warn">
        الجلسة ما زالت مفتوحة — الأرقام أدناه تمثل المتوقع حتى الآن وليست إغلاقاً نهائياً.
      </div>
      <?php endif; ?>
      <?php if ($countPending): ?>
      <div class="pr-callout pr-callout--warn" role="alert" data-testid="drawer-session-pending-count-banner">
        هذه الجلسة مغلقة لكن لم يُسجَّل عد الإغلاق بعد. فرق الدرج غير معروف، وليس صفراً أو حالة توازن.
      </div>
      <?php endif; ?>

      <div class="pr-verdict">
        <div class="pr-verdict-card pr-verdict-card--hero pr-verdict-card--<?= htmlspecialchars($varianceClass, ENT_QUOTES, 'UTF-8') ?>">
          <div class="pr-verdict-label"><?= htmlspecialchars($varianceLabel) ?></div>
          <div class="pr-verdict-value pr-verdict-value--<?= htmlspecialchars($varianceClass, ENT_QUOTES, 'UTF-8') ?>">
            <?= $variance !== null ? number_format($variance, 2) : '—' ?>
          </div>
        </div>
        <div class="pr-verdict-card">
          <div class="pr-verdict-label">المتوقع في الدرج</div>
          <div class="pr-verdict-value"><?= number_format($expectedCash, 2) ?></div>
        </div>
        <div class="pr-verdict-card">
          <div class="pr-verdict-label">ما تم عده في الدرج</div>
          <div class="pr-verdict-value"><?= $countedCash !== null ? number_format($countedCash, 2) : '—' ?></div>
        </div>
        <div class="pr-verdict-card">
          <div class="pr-verdict-label">مبيعات نقدية</div>
          <div class="pr-verdict-value"><?= number_format((float) ($movementTotals['sale_cash'] ?? 0), 2) ?></div>
        </div>
      </div>

      <div class="pr-walk">
        <h2 class="pr-walk-title">مسار النقد في هذه الجلسة</h2>
        <?php
        $walkLines = [
            ['opening', '+', (float) ($movementTotals['opening'] ?? $drawer['opening_cash'] ?? 0)],
            ['sale_cash', '+', (float) ($movementTotals['sale_cash'] ?? 0)],
            ['refund_cash', '−', (float) ($movementTotals['refund_cash'] ?? 0)],
            ['paid_in', '+', (float) ($movementTotals['paid_in'] ?? 0)],
            ['paid_out', '−', (float) ($movementTotals['paid_out'] ?? 0)],
            ['safe_drop', '−', (float) ($movementTotals['safe_drop'] ?? 0)],
        ];
        foreach ($walkLines as [$type, $sign, $amount]):
            if ($amount == 0.0 && !in_array($type, ['opening', 'sale_cash'], true)) {
                continue;
            }
        ?>
        <div class="pr-walk-row">
          <span class="pr-walk-label"><?= htmlspecialchars($movementLabels[$type] ?? $type) ?></span>
          <span class="pr-walk-amount <?= $sign === '+' ? 'pr-walk-amount--add' : 'pr-walk-amount--sub' ?>">
            <?= $sign ?> <?= number_format($amount, 2) ?>
          </span>
        </div>
        <?php endforeach; ?>
        <div class="pr-walk-row pr-walk-row--total">
          <span class="pr-walk-label">متوقع قبل الإغلاق</span>
          <span class="pr-walk-amount"><?= number_format($expectedCash, 2) ?></span>
        </div>
        <div class="pr-walk-row">
          <span class="pr-walk-label">ما تم عده في الدرج</span>
          <span class="pr-walk-amount"><?= $countedCash !== null ? number_format($countedCash, 2) : '—' ?></span>
        </div>
        <div class="pr-walk-row pr-walk-row--result">
          <span class="pr-walk-label">عجز / زيادة</span>
          <span class="pr-walk-amount pr-verdict-value--<?= htmlspecialchars($varianceClass, ENT_QUOTES, 'UTF-8') ?>"><?= $variance !== null ? number_format($variance, 2) : '—' ?></span>
        </div>
        <?php if ($countPending): ?>
        <p class="pr-walk-hint">لا يمكن احتساب فرق الدرج قبل تسجيل عد الإغلاق.</p>
        <?php endif; ?>
        <?php if (!empty($recon['reconciliation'])): ?>
        <p class="pr-walk-hint">
          مدفوعات المبيعات (نقدي) <?= htmlspecialchars((string) ($recon['reconciliation']['cash_payments'] ?? '0')) ?>
          · نقد الدرج من الحركات <?= htmlspecialchars((string) ($recon['reconciliation']['drawer_sale_cash'] ?? '0')) ?>
          · فرق التسوية <?= htmlspecialchars((string) ($recon['reconciliation']['cash_difference'] ?? '0')) ?>
        </p>
        <?php endif; ?>
      </div>

      <?php
      $paymentMethods = $recon['payments']['methods'] ?? [];
      $hasPayments = $paymentMethods !== [] || (float) ($recon['payments']['total'] ?? 0) > 0;
      ?>
      <?php if ($hasPayments): ?>
      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">طرق الدفع في الجلسة</h2>
          <span class="pr-pill pr-pill--money"><?= htmlspecialchars((string) ($recon['payments']['total'] ?? '0')) ?> إجمالي</span>
        </div>
        <div class="pr-panel-body pr-table-wrap">
          <table class="pr-table">
            <thead>
              <tr>
                <th>طريقة الدفع</th>
                <th>النوع</th>
                <th>عدد العمليات</th>
                <th>المبلغ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($paymentMethods as $method): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($method['payment_method'] ?? '')) ?></td>
                <td><span class="pr-pill pr-pill--muted"><?= htmlspecialchars($paymentTypeLabels[$method['type'] ?? ''] ?? ($method['type'] ?? '')) ?></span></td>
                <td><?= (int) ($method['count'] ?? 0) ?></td>
                <td><span class="pr-pill pr-pill--money"><?= htmlspecialchars((string) ($method['total'] ?? '0')) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="pr-walk-hint mb-0">
            نقدي: <?= htmlspecialchars((string) ($recon['payments']['cash'] ?? '0')) ?>
            · غير نقدي: <?= htmlspecialchars((string) ($recon['payments']['non_cash'] ?? '0')) ?>
          </p>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($countAttempts): ?>
      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">محاولات العد</h2>
          <span class="pr-pill pr-pill--muted"><?= count($countAttempts) ?> محاولة</span>
        </div>
        <div class="pr-panel-body pr-table-wrap">
          <table class="pr-table">
            <thead>
              <tr>
                <th>المرحلة</th>
                <th>المحاولة</th>
                <th>المعدود</th>
                <th>المتوقع</th>
                <th>الفرق</th>
                <th>مطابق</th>
                <th>الوقت</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($countAttempts as $attempt): ?>
              <tr>
                <td><?= ($attempt['count_phase'] ?? '') === 'open' ? 'افتتاح' : 'إغلاق' ?></td>
                <td><?= (int) ($attempt['attempt_number'] ?? 0) ?></td>
                <td><?= number_format((float) ($attempt['counted_amount'] ?? 0), 2) ?></td>
                <td><?= number_format((float) ($attempt['expected_amount'] ?? 0), 2) ?></td>
                <td><?= number_format((float) ($attempt['variance'] ?? 0), 2) ?></td>
                <td><?= !empty($attempt['matched']) ? 'نعم' : 'لا' ?></td>
                <td><?= htmlspecialchars((string) ($attempt['created_at'] ?? '')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($resolutionHistory): ?>
      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">سجل الحلول</h2>
        </div>
        <div class="pr-panel-body pr-table-wrap">
          <table class="pr-table">
            <thead>
              <tr>
                <th>النوع</th>
                <th>المبلغ</th>
                <th>بواسطة</th>
                <th>التاريخ</th>
                <th>ملاحظات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($resolutionHistory as $resolution): ?>
              <?php
                $resolverName = (string) (($resolution['display_name'] ?? '') ?: ($resolution['uname'] ?? ''));
                if ($resolverName === '' && (int) ($resolution['resolved_by'] ?? 0) === 0) {
                    $resolverName = 'نظام / ترحيل';
                }
              ?>
              <tr>
                <td><?= htmlspecialchars((string) ($resolution['variance_type'] ?? '')) ?></td>
                <td><?= number_format((float) ($resolution['variance_amount'] ?? 0), 2) ?></td>
                <td><?= htmlspecialchars($resolverName) ?></td>
                <td><?= htmlspecialchars((string) ($resolution['resolved_at'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($resolution['resolution_notes'] ?? '')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">سجل الحركات</h2>
          <span class="pr-pill pr-pill--muted"><?= count($movements['rows'] ?? []) ?> حركة</span>
        </div>
        <div class="pr-panel-body">
          <div class="pr-timeline">
            <?php foreach ($movements['rows'] as $row): ?>
            <?php
              $type = (string) ($row['movement_type'] ?? '');
              $sign = $movementSigns[$type] ?? 0;
              $amount = (float) ($row['amount'] ?? 0);
              $signedAmount = $sign >= 0 ? $amount : -$amount;
              $amountClass = $signedAmount >= 0 ? 'pos' : 'neg';
              $displaySign = $signedAmount >= 0 ? '+' : '−';
            ?>
            <div class="pr-timeline-item">
              <div class="pr-timeline-time"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></div>
              <div class="pr-timeline-body">
                <div class="pr-timeline-type"><?= htmlspecialchars($movementLabels[$type] ?? $type) ?></div>
                <div class="pr-timeline-meta">
                  <?php if (!empty($row['reason'])): ?>سبب: <?= htmlspecialchars((string) $row['reason']) ?><br><?php endif; ?>
                  <?php if ($row['order_id'] !== null): ?>طلب #<?= (int) $row['order_id'] ?> · <?php endif; ?>
                  <?php if (!empty($row['ref_ot_head_id'])): ?>سند #<?= (int) $row['ref_ot_head_id'] ?> · <?php endif; ?>
                  <?= htmlspecialchars((string) ($row['created_by_name'] ?? '')) ?>
                </div>
              </div>
              <div class="pr-timeline-amount pr-timeline-amount--<?= $amountClass ?>">
                <?= $displaySign ?> <?= number_format(abs($signedAmount), 2) ?>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (($movements['rows'] ?? []) === []): ?>
            <p class="text-muted text-center py-3 mb-0">لا توجد حركات مسجلة لهذه الجلسة</p>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <?php endif; ?>
    </div>
  </section>
</div>
<?php include('includes/footer.php') ?>
