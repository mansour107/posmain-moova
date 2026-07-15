<?php
require_once __DIR__ . '/includes/auth_guard.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/cash_shift_navigation.php';
page_guard(null, $conn);
posmain_send_no_store_headers();

$sessionId = (int) ($_GET['id'] ?? 0);
$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);
$canReport = auth_guard_has_permission('reports.cash_flow', $conn);
$returnTo = posmain_cash_shift_safe_return_to($_GET['return_to'] ?? null);

require_once __DIR__ . '/classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/classes/Pos/Service/BusinessDayService.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerSessionCloseSummaryService.php';

$movementLabels = [
    'sale_cash' => 'مبيعات نقدية',
    'refund_cash' => 'مرتجعات نقدية',
    'paid_in' => 'إيداع نقدي',
    'paid_out' => 'مصروف نقدي',
    'safe_drop' => 'تحويل للخزنة',
    'opening' => 'رصيد البداية',
    'closing_adjustment' => 'تسوية إغلاق',
    'no_sale' => 'فتح الدرج بدون بيع',
];

$movementReasonLabels = [
    'takeaway_cash_payment' => 'مبيعات نقدية',
    'order_update_cash_payment' => 'مبيعات نقدية',
    'shift_opening' => 'افتتاح الوردية',
    'shift_close_variance' => 'فرق الإغلاق',
    'legacy_mixed_payment' => 'مبيعات نقدية',
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

$resolutionReasonLabels = ShiftCountService::resolutionReasonCodes();

$varianceTypeLabels = [
    'opening' => 'افتتاح',
    'closing' => 'إغلاق',
    'both' => 'افتتاح وإغلاق',
    'force_close' => 'إغلاق قسري',
    'none' => 'لا يوجد',
];

$overrideEventLabels = [
    'drawer_override_started' => 'بدء التجاوز',
    'drawer_override_operation' => 'عملية أثناء التجاوز',
    'drawer_override_ended' => 'إنهاء التجاوز',
    'drawer_override_expired' => 'انتهى تلقائياً',
    'drawer_override_denied' => 'محاولة مرفوضة',
];

$overrideEndReasonLabels = [
    'shift_close' => 'إغلاق الوردية',
    'explicit_end' => 'إنهاء يدوي',
    'takeover' => 'تسليم الدرج',
    'expired' => 'انتهاء المهلة',
    'activity_timeout' => 'انتهاء بسبب عدم النشاط',
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

/** @return array{0: string, 1: string} reason label, remaining notes */
$formatResolutionReasonAndNotes = static function (array $resolution) use ($resolutionReasonLabels): array {
    $reasonCode = trim((string) ($resolution['resolution_reason_code'] ?? ''));
    $notes = trim((string) ($resolution['resolution_notes'] ?? ''));
    if ($reasonCode !== '') {
        return [$resolutionReasonLabels[$reasonCode] ?? $reasonCode, $notes];
    }
    // Legacy schema stored the chosen reason inside notes as "Label" or "Label — details".
    foreach ($resolutionReasonLabels as $label) {
        if ($notes === $label) {
            return [$label, ''];
        }
        $prefix = $label . ' — ';
        if (strpos($notes, $prefix) === 0) {
            return [$label, trim(substr($notes, strlen($prefix)))];
        }
    }

    return ['—', $notes];
};

/**
 * Admin-friendly variance amount: signed number + over/under wording.
 *
 * @return array{formatted: string, label: string, class: string}
 */
$formatVarianceAmountDisplay = static function (float $amount): array {
    if (abs($amount) < 0.001) {
        return [
            'formatted' => number_format(0, 2),
            'label' => 'مطابق للمتوقع',
            'class' => '',
        ];
    }
    if ($amount > 0) {
        return [
            'formatted' => '+' . number_format($amount, 2),
            'label' => 'أكثر من المتوقع',
            'class' => 'pr-verdict-value--pos',
        ];
    }

    return [
        'formatted' => number_format($amount, 2),
        'label' => 'أقل من المتوقع',
        'class' => 'pr-verdict-value--neg',
    ];
};

$session = null;
$recon = [];
$breakdown = [];
$movements = ['rows' => []];
$userName = '';
$businessDay = '';
$notFound = false;
$closeSummary = null;

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
    if ($canReport) {
        $movements = $cashFlow->movements($conn, [
            'drawer_session_id' => $sessionId,
            'limit' => 500,
            'offset' => 0,
            'tenant' => (int) ($session['tenant'] ?? 0),
            'branch' => (int) ($session['branch'] ?? 0),
        ]);
    }

    $closeSummary = (new DrawerSessionCloseSummaryService())->findBySessionId($conn, $sessionId);

    $shiftCountService = new ShiftCountService();
    $countAttempts = $shiftCountService->countAttemptsForSession($conn, $sessionId);
    $resolutionHistory = $shiftCountService->resolutionsForSession($conn, $sessionId);

    require_once __DIR__ . '/classes/Pos/Service/DrawerOverrideService.php';
    $overrideService = new DrawerOverrideService();
    $overridePeriods = $overrideService->listPeriods($conn, [
        'drawer_session_id' => $sessionId,
        'tenant' => (int) ($session['tenant'] ?? 0),
        'branch' => (int) ($session['branch'] ?? 0),
    ]);
    $overrideAuditEvents = $cashFlow->overrideAuditEvents($conn, $sessionId);
}

$countAttempts = $countAttempts ?? [];
$resolutionHistory = $resolutionHistory ?? [];
$overridePeriods = $overridePeriods ?? [];
$overrideAuditEvents = $overrideAuditEvents ?? [];
$auditPage = max(1, (int) ($_GET['audit_page'] ?? 1));
$auditPerPage = 25;
$auditTotal = count($overrideAuditEvents);
$auditPages = max(1, (int) ceil($auditTotal / $auditPerPage));
$auditPage = min($auditPage, $auditPages);
$pagedOverrideAuditEvents = array_slice($overrideAuditEvents, ($auditPage - 1) * $auditPerPage, $auditPerPage);
$auditPageUrl = static function (int $page) use ($sessionId, $returnTo): string {
    return 'drawer_session.php?' . http_build_query([
        'id' => $sessionId,
        'return_to' => $returnTo,
        'audit_page' => $page,
    ]);
};

// Resolve all override-related user names in one query instead of per-row lookups.
$overrideUserIds = [];
foreach ($overridePeriods as $period) {
    $overrideUserIds[] = (int) ($period['operator_user_id'] ?? 0);
    $overrideUserIds[] = (int) ($period['original_owner_user_id'] ?? 0);
}
foreach ($overrideAuditEvents as $event) {
    $overrideUserIds[] = (int) ($event['user_id'] ?? 0);
}
$overrideUserIds = array_values(array_unique(array_filter($overrideUserIds)));
$overrideUserNames = [];
if ($overrideUserIds !== []) {
    $idList = implode(',', $overrideUserIds);
    $namesRes = $conn->query("SELECT id, uname, display_name FROM users WHERE id IN ({$idList})");
    while ($namesRes && ($nameRow = $namesRes->fetch_assoc())) {
        $overrideUserNames[(int) $nameRow['id']] = (string) ($nameRow['display_name'] ?: $nameRow['uname']);
    }
}
$overrideUserLabel = static function ($userId) use ($overrideUserNames): string {
    $userId = (int) $userId;
    if ($userId < 1) {
        return '—';
    }
    return $overrideUserNames[$userId] ?? ('#' . $userId);
};
$friendlyOverrideSummary = static function (?string $summary) use ($overrideEndReasonLabels): string {
    $summary = trim((string) $summary);
    if ($summary === '') {
        return '—';
    }
    return strtr($summary, $overrideEndReasonLabels);
};
$openingVarianceRaw = $session['opening_variance'] ?? null;
$openingVariance = $openingVarianceRaw !== null && $openingVarianceRaw !== ''
    ? (float) $openingVarianceRaw
    : null;
$expectedOpeningCash = isset($session['expected_opening_cash']) && $session['expected_opening_cash'] !== null
    ? (float) $session['expected_opening_cash']
    : null;
$varianceStatus = (string) ($session['variance_status'] ?? 'none');
$hasOpeningVariance = $openingVariance !== null && abs($openingVariance) >= 0.001;
$drawer = $recon['drawer'] ?? [];
$movementTotals = $drawer['movement_totals'] ?? [];
$expectedCash = (float) ($breakdown['pre_close_expected_cash'] ?? $drawer['pre_close_expected_cash'] ?? 0);
$countedCash = array_key_exists('counted_cash', $breakdown) && $breakdown['counted_cash'] !== null
    ? (float) $breakdown['counted_cash']
    : null;
$closeVariance = array_key_exists('close_variance', $breakdown) && $breakdown['close_variance'] !== null
    ? (float) $breakdown['close_variance']
    : null;
$isOpen = ($session['status'] ?? '') === 'open';
$countPending = !empty($breakdown['count_pending']);

$openingCounted = (float) ($session['opening_cash'] ?? ($movementTotals['opening'] ?? 0));
$saleCashInDrawer = (float) ($movementTotals['sale_cash'] ?? 0);

// Close summary: expected / counted / difference cards (never rename the result card to session status).
$closeDiffValue = $closeVariance;
$closeDiffClass = 'zero';
$closeDiffHint = '';
if ($closeVariance !== null && abs($closeVariance) >= 0.001) {
    $closeDiffClass = $closeVariance > 0 ? 'pos' : 'neg';
    $closeDiffHint = $closeVariance > 0 ? 'أكثر من المتوقع' : 'أقل من المتوقع';
} elseif ($closeVariance !== null) {
    $closeDiffHint = 'مطابق للمتوقع';
} elseif ($countPending) {
    $closeDiffValue = null;
    $closeDiffClass = 'warn';
    $closeDiffHint = 'الجلسة أُغلقت لكن العد لم يُسجَّل بعد';
} else {
    $closeDiffValue = null;
    $closeDiffClass = 'warn';
    $closeDiffHint = 'يظهر بعد إغلاق وعد الدرج';
}

// Keep $variance* aliases for any leftover references in walk styling.
$variance = $closeDiffValue;
$varianceClass = $closeDiffClass;
$varianceLabel = 'الفرق';
$varianceHint = $closeDiffHint;

$paymentsTotal = (float) ($recon['payments']['total'] ?? 0);
$paymentsCash = (float) ($recon['payments']['cash'] ?? 0);
$paymentsByType = $recon['payments']['by_type'] ?? [];
$reconCashPayments = (float) ($recon['reconciliation']['cash_payments'] ?? $paymentsCash);
$reconDrawerSaleCash = (float) ($recon['reconciliation']['drawer_sale_cash'] ?? $saleCashInDrawer);
$reconCashDifference = (float) ($recon['reconciliation']['cash_difference'] ?? ($reconDrawerSaleCash - $reconCashPayments));
$showCashMismatch = abs($reconCashDifference) >= 0.001;
$hasPaymentMix = false;
foreach ($paymentsByType as $mixAmount) {
    if ((float) $mixAmount != 0.0) {
        $hasPaymentMix = true;
        break;
    }
}

$friendlyReason = static function (?string $reason) use ($movementReasonLabels): string {
    $reason = trim((string) $reason);
    if ($reason === '') {
        return '';
    }
    if (isset($movementReasonLabels[$reason])) {
        return $movementReasonLabels[$reason];
    }
    // Hide opaque technical codes; keep already-Arabic operator notes.
    if (preg_match('/^[a-z0-9_]+$/i', $reason)) {
        return '';
    }
    return $reason;
};

$statusKey = (string) ($session['status'] ?? '');
$statusLabel = $statusLabels[$statusKey] ?? $statusKey;
$canResolveVariance = function_exists('auth_guard_has_permission')
    && auth_guard_has_permission('pos.shift.resolve_variance', $conn);
$openingNeedsReview = $hasOpeningVariance && $varianceStatus === 'unresolved';
$sessionVarianceType = (string) ($session['variance_type'] ?? 'opening');
if (!in_array($sessionVarianceType, ['opening', 'closing', 'both', 'force_close'], true)) {
    $sessionVarianceType = $hasOpeningVariance ? 'opening' : 'closing';
}
$resolveVarianceAmount = $openingVariance !== null ? (float) $openingVariance : 0.0;
if ($sessionVarianceType === 'both' && $closeVariance !== null) {
    $resolveVarianceAmount = round((float) $openingVariance + (float) $closeVariance, 3);
} elseif (in_array($sessionVarianceType, ['closing', 'force_close'], true) && $closeVariance !== null) {
    $resolveVarianceAmount = (float) $closeVariance;
}
$hasResolutionDetails = $resolutionHistory !== [];
$flashSuccess = trim((string) ($_SESSION['success_message'] ?? ''));
$flashError = trim((string) ($_SESSION['error_message'] ?? ''));
unset($_SESSION['success_message'], $_SESSION['error_message']);

$premiumCssVer = is_file(__DIR__ . '/css/premium-report-light.css')
    ? (string) filemtime(__DIR__ . '/css/premium-report-light.css')
    : '1';
?>
<?php
// Mint before header.php session_write_close() so the resolve POST can verify it.
csrf_token('shift_resolve');
include('includes/header.php');
?>
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
          <a href="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>" class="pr-btn pr-btn-ghost">العودة للشيفتات</a>
        </div>
      </div>
      <?php else: ?>

      <nav class="pr-breadcrumb pr-no-print">
        <a href="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">النقد والورديات</a>
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
          <a href="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>" class="pr-btn pr-btn-ghost">← رجوع لنفس القائمة</a>
          <button type="button" class="pr-btn pr-btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> طباعة
          </button>
        </div>
      </header>

      <?php if ($flashSuccess !== ''): ?>
      <div class="pr-callout pr-callout--success" role="status"><?= htmlspecialchars($flashSuccess) ?></div>
      <?php endif; ?>
      <?php if ($flashError !== ''): ?>
      <div class="pr-callout pr-callout--danger" role="alert"><?= htmlspecialchars($flashError) ?></div>
      <?php endif; ?>
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

      <?php if (!$isOpen): ?>
      <section class="pr-close-summary" data-testid="drawer-session-close-summary">
        <div class="pr-close-summary-head">
          <h2 class="pr-close-summary-title">ملخص الإغلاق</h2>
          <?php if ($varianceStatus === 'resolved' && ($closeVariance !== null && abs((float) $closeVariance) >= 0.001 || $hasOpeningVariance) && $hasResolutionDetails): ?>
          <button
            type="button"
            class="pr-pill pr-pill--muted pr-pill--action"
            data-bs-toggle="modal"
            data-bs-target="#reviewedDrawerModal"
            data-testid="drawer-session-reviewed-close"
          >
            تمت المراجعة
          </button>
          <?php elseif ($varianceStatus === 'resolved' && ($closeVariance !== null && abs((float) $closeVariance) >= 0.001 || $hasOpeningVariance)): ?>
          <span class="pr-pill pr-pill--muted">تمت المراجعة</span>
          <?php elseif ($varianceStatus === 'unresolved' && $canResolveVariance && !$openingNeedsReview): ?>
          <button
            type="button"
            class="pr-pill pr-pill--status-open pr-pill--action"
            data-bs-toggle="modal"
            data-bs-target="#resolveDrawerModal"
            data-testid="drawer-session-review-close"
          >
            يحتاج مراجعة
          </button>
          <?php elseif ($varianceStatus === 'unresolved' && !$canResolveVariance): ?>
          <span class="pr-pill pr-pill--status-open">يحتاج مراجعة</span>
          <?php endif; ?>
        </div>
        <div class="pr-verdict pr-verdict--close-story">
          <div class="pr-verdict-card" data-testid="drawer-session-expected">
            <div class="pr-verdict-label">المتوقع في الدرج</div>
            <div class="pr-verdict-value"><?= number_format($expectedCash, 2) ?></div>
            <div class="pr-verdict-sub">حسب الحركات</div>
          </div>
          <div class="pr-verdict-card" data-testid="drawer-session-counted">
            <div class="pr-verdict-label">ما تم عده عند الإغلاق</div>
            <div class="pr-verdict-value"><?= $countedCash !== null ? number_format($countedCash, 2) : '—' ?></div>
            <div class="pr-verdict-sub"><?= $countedCash !== null ? 'العد الفعلي' : 'لم يُعد بعد' ?></div>
          </div>
          <div class="pr-verdict-card pr-verdict-card--hero pr-verdict-card--<?= htmlspecialchars($closeDiffClass, ENT_QUOTES, 'UTF-8') ?>" data-testid="drawer-session-verdict">
            <div class="pr-verdict-label">الفرق</div>
            <div class="pr-verdict-value pr-verdict-value--<?= htmlspecialchars($closeDiffClass, ENT_QUOTES, 'UTF-8') ?>">
              <?= $closeDiffValue !== null ? number_format($closeDiffValue, 2) : '—' ?>
            </div>
            <div class="pr-verdict-sub"><?= htmlspecialchars($closeDiffHint) ?></div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($closeSummary): ?>
      <section class="pr-panel" data-testid="drawer-session-z-summary">
        <div class="pr-panel-head">
          <div>
            <p class="pr-eyebrow">سجل إغلاق ثابت</p>
            <h2 class="pr-panel-title">ملخص تقرير Z</h2>
          </div>
          <div class="pr-detail-actions pr-no-print">
            <a class="pr-btn pr-btn-ghost" href="print/closed_session_receipt.php?id=<?= (int) $sessionId ?>">طباعة الملخص</a>
            <a class="pr-btn pr-btn-ghost" href="print/closed_session_items.php?id=<?= (int) $sessionId ?>">تفاصيل الأصناف</a>
          </div>
        </div>
        <div class="pr-verdict pr-verdict--compact">
          <div class="pr-verdict-card"><div class="pr-verdict-label">إجمالي المبيعات</div><div class="pr-verdict-value pr-verdict-value--sm"><?= number_format((float) ($closeSummary['total_sales'] ?? 0), 2) ?></div></div>
          <div class="pr-verdict-card"><div class="pr-verdict-label">المبيعات النقدية</div><div class="pr-verdict-value pr-verdict-value--sm"><?= number_format((float) ($closeSummary['cash_sales'] ?? 0), 2) ?></div></div>
          <div class="pr-verdict-card"><div class="pr-verdict-label">غير النقدي</div><div class="pr-verdict-value pr-verdict-value--sm"><?= number_format((float) ($closeSummary['non_cash_sales'] ?? 0), 2) ?></div></div>
          <div class="pr-verdict-card"><div class="pr-verdict-label">المصروفات</div><div class="pr-verdict-value pr-verdict-value--sm"><?= number_format((float) ($closeSummary['expense_total'] ?? 0), 2) ?></div></div>
        </div>
      </section>
      <?php endif; ?>

      <section class="pr-panel" data-testid="drawer-session-opening">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">الافتتاح</h2>
          <?php if ($hasOpeningVariance && $varianceStatus === 'resolved' && $hasResolutionDetails): ?>
          <button
            type="button"
            class="pr-pill pr-pill--muted pr-pill--action"
            data-bs-toggle="modal"
            data-bs-target="#reviewedDrawerModal"
            data-testid="drawer-session-reviewed-opening"
          >
            تمت المراجعة
          </button>
          <?php elseif ($hasOpeningVariance && $varianceStatus === 'resolved'): ?>
          <span class="pr-pill pr-pill--muted">تمت المراجعة</span>
          <?php elseif ($openingNeedsReview && $canResolveVariance): ?>
          <button
            type="button"
            class="pr-pill pr-pill--status-open pr-pill--action"
            data-bs-toggle="modal"
            data-bs-target="#resolveDrawerModal"
            data-testid="drawer-session-review-opening"
          >
            يحتاج مراجعة
          </button>
          <?php elseif ($openingNeedsReview): ?>
          <span class="pr-pill pr-pill--status-open">يحتاج مراجعة</span>
          <?php endif; ?>
        </div>
        <div class="pr-panel-body">
          <div class="pr-verdict pr-verdict--compact pr-verdict--close-story">
            <div class="pr-verdict-card">
              <div class="pr-verdict-label">المتوقع عند الافتتاح</div>
              <div class="pr-verdict-value pr-verdict-value--sm"><?= $expectedOpeningCash !== null ? number_format($expectedOpeningCash, 2) : '—' ?></div>
            </div>
            <div class="pr-verdict-card">
              <div class="pr-verdict-label">ما تم عده في الدرج</div>
              <div class="pr-verdict-value pr-verdict-value--sm"><?= number_format($openingCounted, 2) ?></div>
            </div>
            <div class="pr-verdict-card">
              <div class="pr-verdict-label">الفرق</div>
              <div class="pr-verdict-value pr-verdict-value--sm <?= $hasOpeningVariance ? 'pr-verdict-value--' . ($openingVariance > 0 ? 'pos' : 'neg') : '' ?>">
                <?php if ($openingVariance !== null): ?>
                  <?= $openingVariance > 0 ? '+' : '' ?><?= number_format((float) $openingVariance, 2) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </div>
              <?php if ($hasOpeningVariance): ?>
              <div class="pr-verdict-sub"><?= $openingVariance > 0 ? 'أكثر من المتوقع' : 'أقل من المتوقع' ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <div class="pr-walk" data-testid="drawer-session-cash-walk">
        <h2 class="pr-walk-title">مسار النقد في الدرج</h2>
        <p class="pr-walk-hint pr-walk-hint--lead">هنا النقد داخل الدرج فقط. البطاقة والبنك في قسم طرق الدفع.</p>
        <?php
        $walkLines = [
            ['opening', '', (float) ($movementTotals['opening'] ?? $drawer['opening_cash'] ?? 0)],
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
          <span class="pr-walk-amount">
            <?php if ($sign !== ''): ?><?= $sign ?> <?php endif; ?><?= number_format($amount, 2) ?>
          </span>
        </div>
        <?php endforeach; ?>
        <div class="pr-walk-row pr-walk-row--total">
          <span class="pr-walk-label">المتوقع في الدرج</span>
          <span class="pr-walk-amount"><?= number_format($expectedCash, 2) ?></span>
        </div>
        <div class="pr-walk-row">
          <span class="pr-walk-label">ما تم عده عند الإغلاق</span>
          <span class="pr-walk-amount"><?= $countedCash !== null ? number_format($countedCash, 2) : '—' ?></span>
        </div>
        <div class="pr-walk-row pr-walk-row--result" data-testid="drawer-session-close-variance-row">
          <span class="pr-walk-label">فرق الإغلاق</span>
          <span class="pr-walk-amount <?= $closeVariance !== null && abs($closeVariance) >= 0.001 ? 'pr-verdict-value--' . ($closeVariance > 0 ? 'pos' : 'neg') : '' ?>"><?= $closeVariance !== null ? number_format($closeVariance, 2) : '—' ?></span>
        </div>
        <?php if ($countPending || ($isOpen && $closeVariance === null)): ?>
        <p class="pr-walk-hint">فرق الإغلاق يظهر بعد تسجيل عد الإغلاق.</p>
        <?php endif; ?>
      </div>

      <?php
      $paymentMethods = $recon['payments']['methods'] ?? [];
      $hasPayments = $paymentMethods !== [] || $paymentsTotal > 0;
      ?>
      <?php if ($canReport && $hasPayments): ?>
      <section class="pr-panel" data-testid="drawer-session-payments">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">طرق الدفع</h2>
          <span class="pr-pill pr-pill--money"><?= number_format($paymentsTotal, 2) ?> إجمالي</span>
        </div>
        <div class="pr-panel-body">
          <p class="pr-walk-hint pr-walk-hint--lead">
            كيف دفع العملاء: نقدي وبطاقة وبنك. النقدي فقط يدخل الدرج.
          </p>
          <?php if ($hasPaymentMix): ?>
          <div class="pr-mix" data-testid="drawer-session-payment-mix">
            <?php foreach ($paymentsByType as $type => $amount): ?>
              <?php if ((float) $amount == 0.0) continue; ?>
              <div class="pr-mix-chip">
                <span><?= htmlspecialchars($paymentTypeLabels[$type] ?? (string) $type) ?></span>
                <strong><?= number_format((float) $amount, 2) ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($showCashMismatch): ?>
          <div class="pr-callout pr-callout--warn" role="alert" data-testid="drawer-session-cash-mismatch">
            المبيعات النقدية (<?= number_format($reconCashPayments, 2) ?>)
            لا تطابق ما دخل الدرج (<?= number_format($reconDrawerSaleCash, 2) ?>).
            راجع سجل الحركات أدناه.
          </div>
          <?php endif; ?>
          <div class="pr-table-wrap">
            <table class="pr-table">
              <thead>
                <tr>
                  <th>الطريقة</th>
                  <th>عدد العمليات</th>
                  <th>المبلغ</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($paymentMethods as $method): ?>
                <tr>
                  <td><?= htmlspecialchars($paymentTypeLabels[$method['type'] ?? ''] ?? (string) ($method['payment_method'] ?? '')) ?></td>
                  <td><?= (int) ($method['count'] ?? 0) ?></td>
                  <td><span class="pr-pill pr-pill--money"><?= htmlspecialchars((string) ($method['total'] ?? '0')) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($canReport): ?>
      <section class="pr-panel" data-testid="drawer-session-movements">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">سجل حركات الدرج</h2>
          <span class="pr-pill pr-pill--muted"><?= count($movements['rows'] ?? []) ?> حركة</span>
        </div>
        <div class="pr-panel-body">
          <p class="pr-walk-hint pr-walk-hint--lead">
            حركات النقد داخل الدرج فقط. اضغط على الحركة لعرض التفاصيل.
          </p>
          <div class="pr-timeline">
            <?php foreach ($movements['rows'] as $row): ?>
            <?php
              $type = (string) ($row['movement_type'] ?? '');
              $sign = $movementSigns[$type] ?? 0;
              $amount = (float) ($row['amount'] ?? 0);
              $signedAmount = $sign >= 0 ? $amount : -$amount;
              $showSign = $type !== 'opening' && $type !== 'no_sale';
              $displaySign = $signedAmount >= 0 ? '+' : '−';
              $reasonText = $friendlyReason($row['reason'] ?? null);
              $detailBits = [];
              if ($reasonText !== '') {
                  $detailBits[] = $reasonText;
              }
              if ($row['order_id'] !== null) {
                  $detailBits[] = 'طلب #' . (int) $row['order_id'];
              }
              if (!empty($row['created_by_name'])) {
                  $detailBits[] = (string) $row['created_by_name'];
              }
              $hasDetails = $detailBits !== [];
            ?>
            <details class="pr-timeline-item pr-timeline-item--expandable">
              <summary class="pr-timeline-summary">
                <span class="pr-timeline-time"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></span>
                <span class="pr-timeline-type"><?= htmlspecialchars($movementLabels[$type] ?? $type) ?></span>
                <span class="pr-timeline-amount">
                  <?php if ($showSign): ?><?= $displaySign ?> <?php endif; ?><?= number_format(abs($signedAmount), 2) ?>
                </span>
              </summary>
              <?php if ($hasDetails): ?>
              <div class="pr-timeline-meta">
                <?= htmlspecialchars(implode(' · ', $detailBits)) ?>
              </div>
              <?php else: ?>
              <div class="pr-timeline-meta">لا توجد تفاصيل إضافية</div>
              <?php endif; ?>
            </details>
            <?php endforeach; ?>
            <?php if (($movements['rows'] ?? []) === []): ?>
            <p class="text-muted text-center py-3 mb-0">لا توجد حركات مسجلة لهذه الجلسة</p>
            <?php endif; ?>
          </div>
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
                <th>فرق العد</th>
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
                <td>
                  <?php $attemptVarianceDisplay = $formatVarianceAmountDisplay((float) ($attempt['variance'] ?? 0)); ?>
                  <div class="pr-amount-dir <?= htmlspecialchars($attemptVarianceDisplay['class'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($attemptVarianceDisplay['formatted']) ?>
                  </div>
                  <div class="pr-amount-dir-label"><?= htmlspecialchars($attemptVarianceDisplay['label']) ?></div>
                </td>
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
                <th>مبلغ الفرق</th>
                <th>السبب</th>
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
                $resolutionType = (string) ($resolution['variance_type'] ?? '');
                $resolutionTypeLabel = $varianceTypeLabels[$resolutionType] ?? $resolutionType;
                [$resolutionReasonLabel, $resolutionNotesDisplay] = $formatResolutionReasonAndNotes($resolution);
                $resolutionAmountDisplay = $formatVarianceAmountDisplay((float) ($resolution['variance_amount'] ?? 0));
              ?>
              <tr>
                <td><?= htmlspecialchars($resolutionTypeLabel) ?></td>
                <td data-testid="drawer-session-resolution-amount">
                  <div class="pr-amount-dir <?= htmlspecialchars($resolutionAmountDisplay['class'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($resolutionAmountDisplay['formatted']) ?>
                  </div>
                  <div class="pr-amount-dir-label"><?= htmlspecialchars($resolutionAmountDisplay['label']) ?></div>
                </td>
                <td><?= htmlspecialchars($resolutionReasonLabel) ?></td>
                <td><?= htmlspecialchars($resolverName) ?></td>
                <td><?= htmlspecialchars((string) ($resolution['resolved_at'] ?? '')) ?></td>
                <td><?= $resolutionNotesDisplay !== '' ? htmlspecialchars($resolutionNotesDisplay) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($overridePeriods): ?>
      <section class="pr-panel" data-testid="drawer-override-periods">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">فترات التجاوز المؤقت</h2>
          <span class="pr-pill pr-pill--muted"><?= count($overridePeriods) ?> فترة</span>
        </div>
        <div class="pr-panel-body pr-table-wrap">
          <p class="pr-walk-hint pr-walk-hint--lead">
            فترات شغّل فيها مستخدم آخر هذا الدرج بدلاً من صاحبه، بموافقة إدارية.
          </p>
          <table class="pr-table">
            <thead>
              <tr>
                <th>المعرّف</th>
                <th>المشغّل</th>
                <th>صاحب الوردية</th>
                <th>السبب</th>
                <th>البداية</th>
                <th>النهاية</th>
                <th>سبب الإنهاء</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($overridePeriods as $period): ?>
              <tr>
                <td>#<?= (int) ($period['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars($overrideUserLabel($period['operator_user_id'] ?? 0)) ?></td>
                <td><?= htmlspecialchars($overrideUserLabel($period['original_owner_user_id'] ?? 0)) ?></td>
                <td><?= htmlspecialchars((string) ($period['reason'] ?? '')) ?></td>
                <td><?= htmlspecialchars($formatDateTime($period['started_at'] ?? null)) ?></td>
                <td><?= htmlspecialchars($formatDateTime($period['ended_at'] ?? null)) ?></td>
                <?php $endReason = (string) ($period['end_reason'] ?? ''); ?>
                <td><?= htmlspecialchars($period['ended_at'] ? ($overrideEndReasonLabels[$endReason] ?? ($endReason !== '' ? $endReason : '—')) : 'نشط') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($overrideAuditEvents): ?>
        <details class="pr-panel-body pr-panel-body--divided pr-disclosure"<?= $auditPage > 1 ? ' open' : '' ?>>
          <summary class="pr-panel-title pr-panel-subhead">سجل أحداث التشغيل المؤقت (<?= (int) $auditTotal ?>)</summary>
          <div class="pr-table-wrap">
          <table class="pr-table">
            <thead>
              <tr>
                <th>الحدث</th>
                <th>المستخدم</th>
                <th>الوقت</th>
                <th>التفاصيل</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pagedOverrideAuditEvents as $event): ?>
              <?php $eventType = (string) ($event['event_type'] ?? ''); ?>
              <tr>
                <td><?= htmlspecialchars($overrideEventLabels[$eventType] ?? $eventType) ?></td>
                <td><?= htmlspecialchars($overrideUserLabel($event['user_id'] ?? 0)) ?></td>
                <td><?= htmlspecialchars($formatDateTime($event['created_at'] ?? null)) ?></td>
                <td><?= htmlspecialchars($friendlyOverrideSummary($event['summary'] ?? null)) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php if ($auditPages > 1): ?>
          <nav class="pr-pagination pr-no-print" aria-label="صفحات سجل التشغيل المؤقت">
            <?php if ($auditPage > 1): ?><a class="pr-btn pr-btn-ghost" href="<?= htmlspecialchars($auditPageUrl($auditPage - 1), ENT_QUOTES, 'UTF-8') ?>">السابق</a><?php endif; ?>
            <span>صفحة <?= (int) $auditPage ?> من <?= (int) $auditPages ?></span>
            <?php if ($auditPage < $auditPages): ?><a class="pr-btn pr-btn-ghost" href="<?= htmlspecialchars($auditPageUrl($auditPage + 1), ENT_QUOTES, 'UTF-8') ?>">التالي</a><?php endif; ?>
          </nav>
          <?php endif; ?>
        </details>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($hasResolutionDetails): ?>
      <div class="modal fade pr-modal" id="reviewedDrawerModal" tabindex="-1" aria-hidden="true" data-testid="drawer-session-reviewed-modal">
        <div class="modal-dialog modal-dialog-centered pr-modal-dialog">
          <div class="modal-content pr-modal-content">
            <div class="pr-modal-header">
              <div>
                <p class="pr-eyebrow">سجل المراجعة</p>
                <h2 class="pr-modal-title">تفاصيل المراجعة</h2>
              </div>
              <button type="button" class="pr-modal-close" data-bs-dismiss="modal" aria-label="إغلاق">×</button>
            </div>
            <div class="pr-modal-body">
              <p class="pr-modal-lead">
                هذه المراجعة لا تغيّر أرقام العد — تؤكد أن المدير راجع الفرق وسجّل السبب.
              </p>
              <?php foreach ($resolutionHistory as $resolutionIndex => $resolution): ?>
              <?php
                $resolverName = (string) (($resolution['display_name'] ?? '') ?: ($resolution['uname'] ?? ''));
                if ($resolverName === '' && (int) ($resolution['resolved_by'] ?? 0) === 0) {
                    $resolverName = 'نظام / ترحيل';
                }
                $resolutionType = (string) ($resolution['variance_type'] ?? '');
                $resolutionTypeLabel = $varianceTypeLabels[$resolutionType] ?? $resolutionType;
                [$resolutionReasonLabel, $resolutionNotes] = $formatResolutionReasonAndNotes($resolution);
                $resolutionAmountDisplay = $formatVarianceAmountDisplay((float) ($resolution['variance_amount'] ?? 0));
              ?>
              <div class="pr-review-detail<?= $resolutionIndex > 0 ? ' pr-review-detail--next' : '' ?>" data-testid="drawer-session-reviewed-detail">
                <div class="pr-verdict pr-verdict--compact pr-verdict--close-story pr-modal-verdict">
                  <div class="pr-verdict-card">
                    <div class="pr-verdict-label">نوع الفرق</div>
                    <div class="pr-verdict-value pr-verdict-value--sm"><?= htmlspecialchars($resolutionTypeLabel !== '' ? $resolutionTypeLabel : '—') ?></div>
                  </div>
                  <div class="pr-verdict-card">
                    <div class="pr-verdict-label">مبلغ الفرق</div>
                    <div class="pr-verdict-value pr-verdict-value--sm <?= htmlspecialchars($resolutionAmountDisplay['class'], ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($resolutionAmountDisplay['formatted']) ?>
                    </div>
                    <div class="pr-verdict-sub"><?= htmlspecialchars($resolutionAmountDisplay['label']) ?></div>
                  </div>
                  <div class="pr-verdict-card">
                    <div class="pr-verdict-label">الحالة</div>
                    <div class="pr-verdict-value pr-verdict-value--sm">تمت المراجعة</div>
                  </div>
                </div>
                <dl class="pr-review-meta">
                  <div>
                    <dt>السبب</dt>
                    <dd><?= htmlspecialchars($resolutionReasonLabel) ?></dd>
                  </div>
                  <div>
                    <dt>بواسطة</dt>
                    <dd><?= htmlspecialchars($resolverName !== '' ? $resolverName : '—') ?></dd>
                  </div>
                  <div>
                    <dt>التاريخ</dt>
                    <dd><?= htmlspecialchars($formatDateTime($resolution['resolved_at'] ?? null)) ?></dd>
                  </div>
                  <?php if ($resolutionNotes !== ''): ?>
                  <div class="pr-review-meta--notes">
                    <dt>تفاصيل إضافية</dt>
                    <dd><?= nl2br(htmlspecialchars($resolutionNotes)) ?></dd>
                  </div>
                  <?php endif; ?>
                </dl>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="pr-modal-footer">
              <button type="button" class="pr-btn pr-btn-ghost" data-bs-dismiss="modal">إغلاق</button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($canResolveVariance && $varianceStatus === 'unresolved'): ?>
      <div class="modal fade pr-modal" id="resolveDrawerModal" tabindex="-1" aria-hidden="true" data-testid="drawer-session-resolve-modal">
        <div class="modal-dialog modal-dialog-centered pr-modal-dialog">
          <div class="modal-content pr-modal-content">
            <form method="post" action="do/do_resolve_drawer_session.php">
              <?= csrf_input('shift_resolve') ?>
              <input type="hidden" name="drawer_session_id" value="<?= (int) $sessionId ?>">
              <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
              <div class="pr-modal-header">
                <div>
                  <p class="pr-eyebrow">مراجعة</p>
                  <h2 class="pr-modal-title">مراجعة فرق الدرج</h2>
                </div>
                <button type="button" class="pr-modal-close" data-bs-dismiss="modal" aria-label="إغلاق">×</button>
              </div>
              <div class="pr-modal-body">
                <p class="pr-modal-lead">
                  هذا لا يغيّر أرقام العد. يؤكد المدير أنه راجع الفرق وسجّل سبب القبول أو المعالجة.
                </p>
                <div class="pr-verdict pr-verdict--compact pr-verdict--close-story pr-modal-verdict">
                  <div class="pr-verdict-card">
                    <div class="pr-verdict-label">المتوقع</div>
                    <div class="pr-verdict-value pr-verdict-value--sm">
                      <?= number_format(
                          $sessionVarianceType === 'opening'
                              ? (float) ($expectedOpeningCash ?? 0)
                              : $expectedCash,
                          2
                      ) ?>
                    </div>
                  </div>
                  <div class="pr-verdict-card">
                    <div class="pr-verdict-label">ما تم عده</div>
                    <div class="pr-verdict-value pr-verdict-value--sm">
                      <?= number_format(
                          $sessionVarianceType === 'opening'
                              ? $openingCounted
                              : (float) ($countedCash ?? $openingCounted),
                          2
                      ) ?>
                    </div>
                  </div>
                  <div class="pr-verdict-card">
                    <div class="pr-verdict-label">الفرق</div>
                    <div class="pr-verdict-value pr-verdict-value--sm <?= abs($resolveVarianceAmount) >= 0.001 ? 'pr-verdict-value--' . ($resolveVarianceAmount > 0 ? 'pos' : 'neg') : '' ?>">
                      <?= $resolveVarianceAmount > 0 ? '+' : '' ?><?= number_format($resolveVarianceAmount, 2) ?>
                    </div>
                  </div>
                </div>
                <div class="pr-field">
                  <label for="resolveReasonCode">سبب الفرق (مطلوب)</label>
                  <select
                    class="form-control"
                    name="resolution_reason_code"
                    id="resolveReasonCode"
                    required
                    data-testid="drawer-session-resolve-reason"
                  >
                    <option value="" selected disabled>اختر السبب…</option>
                    <?php foreach ($resolutionReasonLabels as $reasonCode => $reasonLabel): ?>
                    <option value="<?= htmlspecialchars($reasonCode, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($reasonLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="pr-field mt-3">
                  <label for="resolveNotes">تفاصيل إضافية <span id="resolveNotesOptional">(اختياري)</span></label>
                  <textarea
                    class="form-control pr-modal-textarea"
                    name="resolution_notes"
                    id="resolveNotes"
                    rows="3"
                    placeholder="أي توضيح يساعد عند مراجعة هذه الجلسة لاحقاً"
                  ></textarea>
                </div>
                <p class="text-muted mt-3 mb-0" style="font-size:0.85rem" data-testid="drawer-session-resolve-ledger-note">
                  عند التأكيد يُسجَّل الفرق تلقائياً في الحسابات (حساب فروقات عد الدرج) حتى يطابق رصيد الصندوق النقد الفعلي.
                </p>
              </div>
              <div class="pr-modal-footer">
                <button type="button" class="pr-btn pr-btn-ghost" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="pr-btn pr-btn-primary" data-testid="drawer-session-resolve-submit">تأكيد المراجعة</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <script>
      (function () {
        var reasonSelect = document.getElementById('resolveReasonCode');
        var notesField = document.getElementById('resolveNotes');
        var optionalTag = document.getElementById('resolveNotesOptional');
        if (!reasonSelect || !notesField) return;
        reasonSelect.addEventListener('change', function () {
          var isOther = reasonSelect.value === 'other';
          notesField.required = isOther;
          if (optionalTag) optionalTag.textContent = isOther ? '(مطلوب — اكتب التفاصيل)' : '(اختياري)';
        });
      })();
      </script>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </section>
</div>
<?php include('includes/footer.php') ?>
