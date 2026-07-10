<?php
require_once __DIR__ . '/includes/auth_guard.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
page_guard('reports.cash_flow', $conn);
posmain_send_no_store_headers();

$drawerSessionId = (int) ($_GET['drawer_session_id'] ?? 0);
if ($drawerSessionId > 0) {
    header('Location: drawer_session.php?id=' . $drawerSessionId);
    exit;
}

require_once __DIR__ . '/includes/business_day.php';
$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);
$businessDayContext = posmain_business_day_context($conn, $tenant, $branch);
$dateFrom = $_GET['date_from'] ?? $businessDayContext['current_business_day'];
$dateTo = $_GET['date_to'] ?? $dateFrom;
$cashierId = (int) ($_GET['cashier_id'] ?? 0);
$movementType = trim((string) ($_GET['movement_type'] ?? ''));
$activeTab = ($_GET['view'] ?? 'sessions') === 'movements' ? 'movements' : 'sessions';
$sessionFilter = (string) ($_GET['session_filter'] ?? 'all');
if (!in_array($sessionFilter, ['all', 'variance', 'open', 'pending_count'], true)) {
    $sessionFilter = 'all';
}
$sessionsPerPage = 10;
$sessionPage = max(1, (int) ($_GET['session_page'] ?? 1));

require_once __DIR__ . '/classes/Pos/Service/CashFlowPeriodService.php';
$service = new CashFlowPeriodService();
$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'cashier_id' => $cashierId,
    'movement_type' => $movementType,
    'drawer_session_id' => 0,
    'include_unassigned' => true,
    'tenant' => $tenant,
    'branch' => $branch,
];
$summary = $service->summary($conn, $filters);
$sessions = $service->sessions($conn, $filters);
$movements = $service->movements($conn, array_merge($filters, ['limit' => 200, 'offset' => 0]));
$payments = $service->paymentBreakdown($conn, $filters);

$cashiers = [];
$cashierRes = $conn->query('SELECT id, uname, display_name FROM users WHERE isdeleted = 0 ORDER BY uname');
if ($cashierRes) {
    while ($row = $cashierRes->fetch_assoc()) {
        $cashiers[] = $row;
    }
}

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

$formatTime = static function (?string $datetime): string {
    if ($datetime === null || trim($datetime) === '') {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date('H:i', $ts) : $datetime;
};

$formatSessionTime = static function (?string $openedAt, ?string $closedAt, bool $isOpen) use ($formatTime): string {
    $opened = $formatTime($openedAt);
    if ($isOpen) {
        return $opened . ' — الآن';
    }
    return $opened . ' → ' . $formatTime($closedAt);
};

$filteredSessions = array_values(array_filter($sessions, static function (array $session) use ($sessionFilter): bool {
    if ($sessionFilter === 'open') {
        return ($session['status'] ?? '') === 'open';
    }
    if ($sessionFilter === 'pending_count') {
        return !empty($session['count_pending']);
    }
    if ($sessionFilter === 'variance') {
        return abs((float) ($session['close_variance'] ?? $session['difference'] ?? 0)) >= 0.001;
    }
    return true;
}));
$filteredSessionCount = count($filteredSessions);
$sessionTotalPages = max(1, (int) ceil($filteredSessionCount / $sessionsPerPage));
$sessionPage = min($sessionPage, $sessionTotalPages);
$sessionOffset = ($sessionPage - 1) * $sessionsPerPage;
$pagedSessions = array_slice($filteredSessions, $sessionOffset, $sessionsPerPage);

$cashFlowHref = static function (array $overrides = []) use (
    $dateFrom,
    $dateTo,
    $cashierId,
    $movementType,
    $activeTab,
    $sessionFilter,
    $sessionPage
): string {
    $params = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
    if ($cashierId > 0) {
        $params['cashier_id'] = $cashierId;
    }
    if ($movementType !== '') {
        $params['movement_type'] = $movementType;
    }
    if ($activeTab === 'movements') {
        $params['view'] = 'movements';
    }
    if ($sessionFilter !== 'all') {
        $params['session_filter'] = $sessionFilter;
    }
    if ($sessionPage > 1) {
        $params['session_page'] = $sessionPage;
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '' || $value === 0) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return '?' . http_build_query($params);
};

$movementTotals = $summary['movement_totals'] ?? [];
$varianceRollup = (float) ($summary['close_variance_rollup'] ?? $summary['difference_rollup'] ?? 0);
$expectedRollup = (float) ($summary['expected_cash_rollup'] ?? 0);
$countedRollup = (float) ($summary['counted_cash_rollup'] ?? 0);
$saleCash = (float) ($movementTotals['sale_cash'] ?? 0);
$sessionCount = (int) ($summary['session_count'] ?? count($sessions));
$pendingCountSessionCount = (int) ($summary['count_pending_session_count'] ?? 0);
$pendingCountExpectedCash = (float) ($summary['count_pending_expected_cash'] ?? 0);

$varianceClass = 'zero';
$varianceLabel = 'متوازن';
if (abs($varianceRollup) >= 0.001) {
    if ($varianceRollup > 0) {
        $varianceClass = 'pos';
        $varianceLabel = 'زيادة';
    } else {
        $varianceClass = 'neg';
        $varianceLabel = 'عجز';
    }
} elseif ($pendingCountSessionCount > 0) {
    $varianceClass = 'warn';
    $varianceLabel = 'بانتظار العد';
}

$hasUnassigned = (float) ($summary['unassigned_total'] ?? 0) != 0.0 || (int) ($summary['unassigned_count'] ?? 0) > 0;
$cashReconciliationDiff = (float) ($payments['cash_reconciliation_diff'] ?? 0);
$showReconciliationHint = abs($cashReconciliationDiff) >= 0.001;
if ($hasUnassigned && !isset($_GET['view'])) {
    $activeTab = 'movements';
}

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
      <header class="pr-hero">
        <div class="pr-hero-text">
          <p class="pr-eyebrow">التقارير المالية</p>
          <h1>تقرير التدفق النقدي</h1>
          <p class="pr-hero-sub">ملخص النقد وفرق الدرج للفترة المحددة</p>
        </div>
      </header>

      <section class="pr-panel pr-no-print">
        <div class="pr-panel-body">
          <div class="pr-presets">
            <button type="button" class="pr-preset-btn" data-preset="today">اليوم</button>
            <button type="button" class="pr-preset-btn" data-preset="yesterday">أمس</button>
            <button type="button" class="pr-preset-btn" data-preset="last7">آخر ٧ أيام</button>
          </div>
          <form method="get" id="cashFlowFilters" class="pr-filters-grid">
            <div class="pr-field">
              <label for="date_from">من تاريخ</label>
              <input type="date" id="date_from" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="pr-field">
              <label for="date_to">إلى تاريخ</label>
              <input type="date" id="date_to" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="pr-field">
              <label for="cashier_id">الكاشير</label>
              <select id="cashier_id" name="cashier_id" class="form-control">
                <option value="0">الكل</option>
                <?php foreach ($cashiers as $cashier): ?>
                  <option value="<?= (int) $cashier['id'] ?>" <?= $cashierId === (int) $cashier['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($cashier['display_name'] ?: $cashier['uname'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="pr-field pr-field--submit">
              <label>&nbsp;</label>
              <button type="submit" class="pr-btn pr-btn-primary w-100">
                <i class="fas fa-search"></i> عرض التقرير
              </button>
            </div>
          </form>
        </div>
      </section>

      <div class="pr-verdict">
        <div class="pr-verdict-card pr-verdict-card--hero pr-verdict-card--<?= htmlspecialchars($varianceClass, ENT_QUOTES, 'UTF-8') ?>">
          <div class="pr-verdict-label"><?= htmlspecialchars($varianceLabel) ?></div>
          <div class="pr-verdict-value pr-verdict-value--<?= htmlspecialchars($varianceClass, ENT_QUOTES, 'UTF-8') ?>">
            <?= number_format($varianceRollup, 2) ?>
          </div>
        </div>
        <div class="pr-verdict-card">
          <div class="pr-verdict-label">المتوقع في الدرج</div>
          <div class="pr-verdict-value"><?= number_format($expectedRollup, 2) ?></div>
        </div>
        <div class="pr-verdict-card">
          <div class="pr-verdict-label">ما تم عده في الدرج<?= $pendingCountSessionCount > 0 ? ' (المسجل فقط)' : '' ?></div>
          <div class="pr-verdict-value"><?= number_format($countedRollup, 2) ?></div>
        </div>
        <div class="pr-verdict-card">
          <div class="pr-verdict-label">مبيعات نقدية</div>
          <div class="pr-verdict-value"><?= number_format($saleCash, 2) ?></div>
        </div>
      </div>
      <p class="pr-verdict-sub">
        <?= (int) $sessionCount ?> جلسة · الفترة من <?= htmlspecialchars($dateFrom) ?> إلى <?= htmlspecialchars($dateTo) ?>
      </p>

      <?php if ($pendingCountSessionCount > 0): ?>
      <div class="pr-callout pr-callout--warn" role="alert" data-testid="cash-flow-pending-count-banner">
        <strong>جلسات مغلقة بانتظار العد:</strong>
        <?= (int) $pendingCountSessionCount ?> جلسة
        (متوقع فيها <?= number_format($pendingCountExpectedCash, 2) ?>).
        لا يظهر لها فرق ولا تدخل في إجمالي الفروقات حتى يُسجَّل عد الإغلاق.
        <a href="<?= htmlspecialchars($cashFlowHref(['session_filter' => 'pending_count', 'session_page' => null, 'view' => null]), ENT_QUOTES, 'UTF-8') ?>">مراجعة الجلسات</a>
      </div>
      <?php endif; ?>

      <?php
      $byType = $payments['by_type'] ?? [];
      $hasPaymentMix = false;
      foreach ($byType as $amount) {
          if ((float) $amount != 0.0) {
              $hasPaymentMix = true;
              break;
          }
      }
      ?>
      <?php if ($hasPaymentMix): ?>
      <div class="pr-mix">
        <?php foreach ($byType as $type => $amount): ?>
          <?php if ((float) $amount == 0.0) continue; ?>
          <div class="pr-mix-chip">
            <span><?= htmlspecialchars($paymentTypeLabels[$type] ?? $type) ?></span>
            <strong><?= number_format((float) $amount, 2) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <details class="pr-walk pr-walk-collapse">
        <summary>تفاصيل مسار النقد</summary>
        <?php
        $walkLines = [
            ['opening', '+', (float) ($movementTotals['opening'] ?? 0)],
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
          <span class="pr-walk-label">المتوقع في الدرج</span>
          <span class="pr-walk-amount"><?= number_format($expectedRollup, 2) ?></span>
        </div>
        <div class="pr-walk-row">
          <span class="pr-walk-label">ما تم عده في الدرج</span>
          <span class="pr-walk-amount"><?= number_format($countedRollup, 2) ?></span>
        </div>
        <div class="pr-walk-row pr-walk-row--result">
          <span class="pr-walk-label">الفرق</span>
          <span class="pr-walk-amount pr-verdict-value--<?= htmlspecialchars($varianceClass, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($varianceRollup, 2) ?></span>
        </div>
        <?php if ($showReconciliationHint): ?>
        <p class="pr-walk-hint">
          فرق بين مدفوعات المبيعات النقدية (<?= number_format((float) ($payments['cash_net'] ?? 0), 2) ?>)
          ونقد الدرج من الحركات (<?= number_format((float) ($payments['drawer_cash_net'] ?? 0), 2) ?>):
          <strong><?= number_format($cashReconciliationDiff, 2) ?></strong>
        </p>
        <?php endif; ?>
      </details>

      <?php if ($hasUnassigned): ?>
      <div class="pr-callout pr-callout--warn" role="alert" data-testid="cash-flow-unassigned-banner">
        <strong>تنبيه محاسبة نقدية:</strong>
        <?= (int) ($summary['unassigned_count'] ?? 0) ?> حركة غير مربوطة بجلسة درج
        (صافي <?= number_format((float) ($summary['unassigned_total'] ?? 0), 2) ?>).
        لا تمنع البيع أو الإغلاق، لكنها تؤثر على عهد الافتتاح التالي ويجب مراجعتها.
        <a href="<?= htmlspecialchars($cashFlowHref(['view' => 'movements']), ENT_QUOTES, 'UTF-8') ?>">عرض في سجل الحركات</a>
      </div>
      <?php endif; ?>

      <?php $movementTotal = (int) ($movements['total'] ?? count($movements['rows'] ?? [])); ?>
      <section class="pr-tabs" id="cashFlowTabs">
        <nav class="pr-tabs-nav" aria-label="أقسام التقرير">
          <a href="<?= htmlspecialchars($cashFlowHref(['view' => null, 'session_page' => $sessionPage > 1 ? $sessionPage : null]), ENT_QUOTES, 'UTF-8') ?>"
             class="pr-tab-btn <?= $activeTab === 'sessions' ? 'is-active' : '' ?>"
             data-testid="cash-flow-tab-sessions">
            جلسات الدرج (<?= (int) $sessionCount ?>)
          </a>
          <a href="<?= htmlspecialchars($cashFlowHref(['view' => 'movements']), ENT_QUOTES, 'UTF-8') ?>"
             class="pr-tab-btn <?= $activeTab === 'movements' ? 'is-active' : '' ?>"
             data-testid="cash-flow-tab-movements">
            سجل الحركات (<?= $movementTotal ?>)
          </a>
        </nav>

        <div class="pr-tab-panel <?= $activeTab === 'sessions' ? 'is-active' : '' ?>" data-testid="cash-flow-panel-sessions">
          <div class="pr-session-toolbar">
            <div class="pr-chip-filters">
              <a href="<?= htmlspecialchars($cashFlowHref(['session_filter' => null, 'session_page' => null, 'view' => null]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $sessionFilter === 'all' ? 'is-active' : '' ?>">الكل</a>
              <a href="<?= htmlspecialchars($cashFlowHref(['session_filter' => 'variance', 'session_page' => null, 'view' => null]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $sessionFilter === 'variance' ? 'is-active' : '' ?>">بها فرق</a>
              <a href="<?= htmlspecialchars($cashFlowHref(['session_filter' => 'open', 'session_page' => null, 'view' => null]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $sessionFilter === 'open' ? 'is-active' : '' ?>">مفتوحة</a>
              <a href="<?= htmlspecialchars($cashFlowHref(['session_filter' => 'pending_count', 'session_page' => null, 'view' => null]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $sessionFilter === 'pending_count' ? 'is-active' : '' ?>"
                 data-testid="cash-flow-filter-pending-count">بانتظار العد</a>
            </div>
            <div class="pr-session-meta" data-testid="cash-flow-session-meta">
              <?= (int) $filteredSessionCount ?> جلسة
              <?php if ($sessionTotalPages > 1): ?>
                · الصفحة <?= (int) $sessionPage ?> من <?= (int) $sessionTotalPages ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="pr-panel-body pr-table-wrap pr-table-scroll">
            <table class="pr-table pr-table--compact">
              <thead>
                <tr>
                  <th>كاشير</th>
                  <th>اليوم</th>
                  <th>الوقت</th>
                  <th>المتوقع</th>
                  <th>الفرق</th>
                  <th>الحالة</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pagedSessions as $session): ?>
                <?php
                  $isOpen = ($session['status'] ?? '') === 'open';
                  $sessionVariance = $session['close_variance'] ?? $session['difference'] ?? null;
                  $sessionExpected = (float) ($session['expected_cash'] ?? 0);
                  $statusKey = (string) ($session['status'] ?? '');
                  $statusLabel = $statusLabels[$statusKey] ?? $statusKey;
                  $rowClass = $sessionVariance !== null && abs((float) $sessionVariance) >= 0.001 ? 'pr-row-variance' : '';
                  $pendingCount = !empty($session['count_pending']);
                ?>
                <tr class="<?= $rowClass ?>">
                  <td><span class="pr-pill pr-pill--user"><?= htmlspecialchars((string) $session['user_name']) ?></span></td>
                  <td><?= htmlspecialchars((string) ($session['business_day'] ?? '—')) ?></td>
                  <td><?= htmlspecialchars($formatSessionTime($session['opened_at'] ?? null, $session['closed_at'] ?? null, $isOpen)) ?></td>
                  <td><span class="pr-pill pr-pill--money"><?= number_format($sessionExpected, 2) ?></span></td>
                  <td>
                    <?php if ($pendingCount): ?>
                      <span class="pr-pill pr-pill--status-open">بانتظار العد</span>
                    <?php else: ?>
                      <span class="pr-pill <?= (float) $sessionVariance < 0 ? 'pr-pill--expense' : ((float) $sessionVariance > 0 ? 'pr-pill--money' : 'pr-pill--muted') ?>">
                        <?= number_format((float) $sessionVariance, 2) ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="pr-pill <?= $isOpen ? 'pr-pill--status-open pr-pill--pulse' : 'pr-pill--status-closed' ?>">
                      <?= htmlspecialchars($statusLabel) ?>
                    </span>
                  </td>
                  <td>
                    <a href="drawer_session.php?id=<?= (int) $session['id'] ?>" class="pr-btn pr-btn-ghost" data-testid="session-detail-link">تفاصيل</a>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($pagedSessions === []): ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">لا توجد جلسات مطابقة للتصفية</td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if ($sessionTotalPages > 1): ?>
          <div class="pr-pagination" data-testid="cash-flow-session-pagination">
            <nav aria-label="ترقيم جلسات الدرج">
              <ul class="pagination mb-0">
                <li class="page-item <?= ($sessionPage <= 1) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars($cashFlowHref(['session_page' => max(1, $sessionPage - 1), 'view' => null]), ENT_QUOTES, 'UTF-8') ?>" aria-label="السابق">
                    <span aria-hidden="true">&raquo;</span>
                  </a>
                </li>
                <?php
                $startPage = max(1, $sessionPage - 2);
                $endPage = min($sessionTotalPages, $sessionPage + 2);
                if ($startPage > 1) {
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($cashFlowHref(['session_page' => 1, 'view' => null]), ENT_QUOTES, 'UTF-8') . '">1</a></li>';
                    if ($startPage > 2) {
                        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                }
                for ($i = $startPage; $i <= $endPage; $i++) {
                    $active = ($i === $sessionPage) ? 'active' : '';
                    echo '<li class="page-item ' . $active . '"><a class="page-link" href="' . htmlspecialchars($cashFlowHref(['session_page' => $i, 'view' => null]), ENT_QUOTES, 'UTF-8') . '">' . $i . '</a></li>';
                }
                if ($endPage < $sessionTotalPages) {
                    if ($endPage < $sessionTotalPages - 1) {
                        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($cashFlowHref(['session_page' => $sessionTotalPages, 'view' => null]), ENT_QUOTES, 'UTF-8') . '">' . $sessionTotalPages . '</a></li>';
                }
                ?>
                <li class="page-item <?= ($sessionPage >= $sessionTotalPages) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars($cashFlowHref(['session_page' => min($sessionTotalPages, $sessionPage + 1), 'view' => null]), ENT_QUOTES, 'UTF-8') ?>" aria-label="التالي">
                    <span aria-hidden="true">&laquo;</span>
                  </a>
                </li>
              </ul>
            </nav>
            <div class="pr-pagination-meta">
              عرض <?= count($pagedSessions) ?> من <?= (int) $filteredSessionCount ?> جلسة
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="pr-tab-panel <?= $activeTab === 'movements' ? 'is-active' : '' ?>" id="movementsLedger" data-testid="cash-flow-panel-movements">
          <div class="pr-panel-body">
            <form method="get" class="pr-collapse-filters pr-no-print">
              <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
              <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
              <input type="hidden" name="cashier_id" value="<?= (int) $cashierId ?>">
              <input type="hidden" name="view" value="movements">
              <?php if ($sessionFilter !== 'all'): ?>
                <input type="hidden" name="session_filter" value="<?= htmlspecialchars($sessionFilter) ?>">
              <?php endif; ?>
              <div class="pr-field">
                <label for="movement_type">نوع الحركة</label>
                <select id="movement_type" name="movement_type" class="form-control">
                  <option value="">الكل</option>
                  <?php foreach ($movementLabels as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $movementType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="pr-btn pr-btn-primary">تصفية</button>
            </form>
            <div class="pr-table-wrap pr-table-scroll">
              <table class="pr-table pr-table--compact">
                <thead>
                  <tr>
                    <th>الوقت</th>
                    <th>النوع</th>
                    <th>المبلغ</th>
                    <th>الجلسة</th>
                    <th>الطلب</th>
                    <th>الكاشير</th>
                    <th>ملاحظة</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($movements['rows'] as $row): ?>
                  <?php
                    $note = trim((string) ($row['reason'] ?? ''));
                    if ($note === '' && !empty($row['is_unassigned'])) {
                        $note = 'غير مربوطة بجلسة';
                    }
                  ?>
                  <tr class="<?= !empty($row['is_unassigned']) ? 'pr-row-warn' : '' ?>">
                    <td><?= htmlspecialchars((string) $row['created_at']) ?></td>
                    <td><?= htmlspecialchars($movementLabels[$row['movement_type']] ?? $row['movement_type']) ?></td>
                    <td><span class="pr-pill pr-pill--money"><?= number_format((float) $row['amount'], 2) ?></span></td>
                    <td>
                      <?php if ($row['drawer_session_id'] !== null): ?>
                        <a href="drawer_session.php?id=<?= (int) $row['drawer_session_id'] ?>"><?= (int) $row['drawer_session_id'] ?></a>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= $row['order_id'] !== null ? (int) $row['order_id'] : '—' ?></td>
                    <td><?= htmlspecialchars((string) ($row['created_by_name'] ?? '')) ?></td>
                    <td><?= $note !== '' ? htmlspecialchars($note) : '—' ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if (($movements['rows'] ?? []) === []): ?>
                  <tr>
                    <td colspan="7" class="text-center text-muted py-4">لا توجد حركات في هذه الفترة</td>
                  </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </div>
  </section>
</div>
<script>
(function () {
  const form = document.getElementById('cashFlowFilters');
  if (!form) return;
  const fromInput = document.getElementById('date_from');
  const toInput = document.getElementById('date_to');
  const currentBusinessDay = <?= json_encode($businessDayContext['current_business_day'], JSON_UNESCAPED_UNICODE) ?>;
  const previousBusinessDay = <?= json_encode($businessDayContext['previous_business_day'], JSON_UNESCAPED_UNICODE) ?>;
  const pad = (n) => String(n).padStart(2, '0');
  const parseDay = (value) => {
    const parts = String(value || '').split('-').map((part) => parseInt(part, 10));
    if (parts.length !== 3 || parts.some((part) => Number.isNaN(part))) {
      return new Date();
    }
    return new Date(parts[0], parts[1] - 1, parts[2]);
  };
  const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  document.querySelectorAll('.pr-preset-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const preset = btn.getAttribute('data-preset');
      if (preset === 'today') {
        fromInput.value = currentBusinessDay;
        toInput.value = currentBusinessDay;
      } else if (preset === 'yesterday') {
        fromInput.value = previousBusinessDay;
        toInput.value = previousBusinessDay;
      } else if (preset === 'last7') {
        const end = parseDay(currentBusinessDay);
        const start = new Date(end);
        start.setDate(start.getDate() - 6);
        fromInput.value = fmt(start);
        toInput.value = currentBusinessDay;
      }
      form.submit();
    });
  });
})();
</script>
<?php include('includes/footer.php') ?>
