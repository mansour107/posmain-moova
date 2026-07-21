<?php
require_once __DIR__ . '/includes/auth_guard.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/business_day.php';
require_once __DIR__ . '/includes/cash_shift_navigation.php';
require_once __DIR__ . '/classes/Pos/Service/CashShiftWorkspaceService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/classes/Pos/Service/OperationsReportService.php';

page_guard(null, $conn);
posmain_send_no_store_headers();

$canReport = auth_guard_has_permission('reports.cash_flow', $conn);
$canSalesReports = auth_guard_has_permission('reports.view', $conn) || $canReport;
$canViewShifts = $canReport || auth_guard_has_permission('pos.shift.close', $conn);
$canForceClose = auth_guard_has_permission('pos.shift.force_close', $conn);
$canResolveVariance = auth_guard_has_permission('pos.shift.resolve_variance', $conn);
$canSetBaselinePermission = auth_guard_has_permission('pos.shift.set_opening_baseline', $conn);
$canConfigureBusinessDay = $canReport || auth_guard_has_permission('pos.shift.close', $conn);

$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);
$businessDayContext = posmain_business_day_context($conn, $tenant, $branch);
$tenant = (int) $businessDayContext['tenant'];
$branch = (int) $businessDayContext['branch'];
$tabOverview = CashShiftWorkspaceService::TAB_OVERVIEW;
$tabShifts = CashShiftWorkspaceService::TAB_SHIFTS;
$tabOrders = CashShiftWorkspaceService::TAB_ORDERS;
$tabPayments = CashShiftWorkspaceService::TAB_PAYMENTS;
$tabItems = CashShiftWorkspaceService::TAB_ITEMS;
$tabAttention = CashShiftWorkspaceService::TAB_ATTENTION;
$tabMovements = CashShiftWorkspaceService::TAB_MOVEMENTS;
$tabSettings = CashShiftWorkspaceService::TAB_SETTINGS;

$input = $_GET;
if (!isset($input['tab']) && ($input['view'] ?? '') === 'movements') {
    $input['tab'] = $tabMovements;
}
if (!isset($input['tab']) && isset($input['session_filter'])) {
    $input['tab'] = $tabShifts;
    $input['status'] = match ((string) $input['session_filter']) {
        'open' => 'open',
        'variance', 'pending_count' => 'needs_review',
        default => 'all',
    };
}

$workspace = new CashShiftWorkspaceService();
$context = $workspace->normalizeContext($input, [
    'date_from' => $businessDayContext['current_business_day'],
    'date_to' => $businessDayContext['current_business_day'],
    'tenant' => $tenant,
    'branch' => $branch,
]);
if (!$canSalesReports && in_array($context['tab'], [$tabOverview, $tabOrders, $tabPayments, $tabItems, $tabAttention], true)) {
    $context['tab'] = $tabShifts;
}
if (!$canReport && $context['tab'] === $tabMovements) {
    $context['tab'] = $canViewShifts ? $tabShifts : $tabOverview;
}
if (!$canViewShifts && $context['tab'] === $tabShifts) {
    $context['tab'] = $tabOverview;
}
$datePresets = $workspace->datePresets((string) $businessDayContext['current_business_day']);
$activeDatePreset = null;
foreach ($datePresets as $presetKey => $preset) {
    if ($context['date_from'] === $preset['date_from'] && $context['date_to'] === $preset['date_to']) {
        $activeDatePreset = $presetKey;
        break;
    }
}

$workspaceParams = static function (array $ctx): array {
    return [
        'tab' => $ctx['tab'],
        'date_from' => $ctx['date_from'],
        'date_to' => $ctx['date_to'],
        'cashier_id' => $ctx['cashier_id'],
        'override_operator_id' => $ctx['override_operator_id'],
        'movement_type' => $ctx['movement_type'],
        'focus' => $ctx['focus'],
        'status' => $ctx['status'] !== 'all' ? $ctx['status'] : '',
        'scope' => $ctx['scope'] !== 'period' ? $ctx['scope'] : '',
        'page' => $ctx['page'] > 1 ? $ctx['page'] : '',
        'has_override' => $ctx['has_override'] ? 1 : '',
    ];
};
$workspaceUrl = static function (array $overrides = []) use (&$context, $workspaceParams): string {
    $params = array_merge($workspaceParams($context), $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === false) {
            unset($params[$key]);
        }
    }

    return posmain_cash_shift_workspace_url($params);
};
$tabUrl = static function (string $tab) use ($workspaceUrl): string {
    return $workspaceUrl([
        'tab' => $tab,
        'focus' => null,
        'movement_type' => null,
        'status' => null,
        'scope' => null,
        'page' => null,
        'has_override' => null,
        'override_operator_id' => null,
    ]);
};
$returnTo = $workspaceUrl();

$drawerSessionId = (int) ($_GET['drawer_session_id'] ?? 0);
if ($drawerSessionId > 0) {
    header('Location: ' . posmain_cash_shift_detail_url($drawerSessionId, $returnTo));
    exit;
}

$periodFilters = $workspace->periodFilters($context);
$cashFlow = new CashFlowPeriodService();
$operationsReports = new OperationsReportService(null, $cashFlow);
$summary = $canReport ? $cashFlow->summary($conn, $periodFilters) : [];
$payments = $canSalesReports ? $operationsReports->paymentSummary($conn, $periodFilters) : [];
$salesSummary = $canSalesReports && in_array($context['tab'], [$tabOverview, $tabAttention], true)
    ? $operationsReports->salesSummary($conn, $periodFilters)
    : [];
$orders = $canSalesReports && $context['tab'] === $tabOrders
    ? $operationsReports->orders($conn, $periodFilters, 250)
    : [];
$itemSales = $canSalesReports && $context['tab'] === $tabItems
    ? $operationsReports->itemSales($conn, $periodFilters)
    : [];
$attentionRows = $canSalesReports && in_array($context['tab'], [$tabOverview, $tabAttention], true)
    ? $operationsReports->attention($conn, $periodFilters, $salesSummary, $payments, [
        'cash_controls' => $canReport,
        'shift_controls' => $canViewShifts,
    ])
    : [];
$movements = $canReport && $context['tab'] === $tabMovements
    ? $cashFlow->movements($conn, array_merge($periodFilters, ['limit' => 200, 'offset' => 0]))
    : ['rows' => [], 'total' => 0];
$shiftsPage = $canViewShifts
    ? $workspace->sessionsPage($conn, $context, 12)
    : ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'scope' => 'period'];
$backlogCount = $canViewShifts ? $workspace->backlogCount($conn, $context) : 0;

$cashiers = [];
$cashierRes = $conn->query('SELECT id, uname, display_name FROM users WHERE isdeleted = 0 ORDER BY uname');
if ($cashierRes) {
    while ($row = $cashierRes->fetch_assoc()) {
        $cashiers[] = $row;
    }
}

$floatService = new DrawerFloatExpectationService();
$shiftCountService = new ShiftCountService();
$needsBaselineInit = $shiftCountService->handoverEnabled($conn)
    && $floatService->needsBaselineInitialization($conn, $tenant, $branch);
$canSetBaseline = $canSetBaselinePermission && $floatService->canSetOpeningBaseline($conn, $tenant, $branch);
$openingExpectation = ($shiftCountService->handoverEnabled($conn) && $tenant > 0 && $branch > 0)
    ? $floatService->expectedOpeningFloat($conn, $tenant, $branch)
    : null;

foreach (['shift_resolve', 'shift_close', 'shift_baseline', 'business_day_cutoff'] as $csrfContext) {
    csrf_token($csrfContext);
}
$flashSuccess = isset($_SESSION['success_message']) ? (string) $_SESSION['success_message'] : '';
$flashError = isset($_SESSION['error_message']) ? (string) $_SESSION['error_message'] : '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$movementLabels = [
    'sale_cash' => 'مبيعات نقدية', 'refund_cash' => 'مرتجعات نقدية', 'paid_in' => 'إيداع نقدي',
    'paid_out' => 'مصروف نقدي', 'safe_drop' => 'توريد إلى الخزنة', 'opening' => 'رصيد بداية الدرج',
    'closing_adjustment' => 'تسوية الإغلاق', 'no_sale' => 'فتح الدرج دون بيع',
];
$statusLabels = ['open' => 'مفتوح', 'closed' => 'مغلق', 'forced_closed' => 'إغلاق إجباري'];
$orderStatusLabels = [
    'paid' => 'مدفوع', 'refunded' => 'مسترد', 'unpaid' => 'غير مدفوع',
    'cancelled' => 'ملغي', 'canceled' => 'ملغي', 'voided' => 'ملغي',
    'completed' => 'مكتمل', 'active' => 'نشط', 'unknown' => 'غير محدد',
];
$paymentTypeLabels = ['cash' => 'نقدي', 'card' => 'بطاقة', 'wallet' => 'محفظة إلكترونية', 'bank' => 'تحويل بنكي', 'gift_card' => 'بطاقة هدايا', 'other' => 'أخرى'];
$paymentMethodLabels = [
    'cash' => 'نقدي', 'card' => 'بطاقة', 'wallet' => 'محفظة إلكترونية',
    'bank' => 'تحويل بنكي', 'bank transfer' => 'تحويل بنكي', 'gift card' => 'بطاقة هدايا',
    'unpaid' => 'غير مدفوع', 'not recorded' => 'غير مسجل',
];
$paymentSourceLabels = ['payment' => 'من عمليات الدفع المسجلة', 'fallback' => 'من بيانات الطلبات', 'none' => 'لا توجد بيانات'];
$varianceTypeLabels = ['opening' => 'رصيد البداية', 'closing' => 'رصيد الإغلاق', 'both' => 'البداية والإغلاق', 'force_close' => 'إغلاق إجباري', 'none' => '—'];
$resolutionReasonLabels = [
    'recount_confirmed' => 'أعاد العد تأكيد الفرق',
    'previous_shift' => 'الفرق يخص الشيفت السابق',
    'change_rounding' => 'فرق فكة بسيط أو تقريب',
    'unrecorded_movement' => 'حركة نقدية لم تُسجل في وقتها',
    'under_investigation' => 'الفرق قيد المراجعة',
    'other' => 'سبب آخر',
];
$attentionLabels = [
    'refunds' => 'مرتجعات تمت خلال الفترة',
    'reversals' => 'طلبات أُلغيت بعد إنشائها',
    'discounts' => 'طلبات تم تطبيق خصم عليها',
    'drawer_variance' => 'شيفتات بها فرق نقدي',
    'open_shifts' => 'شيفتات لم تُغلق بعد',
    'unassigned_cash' => 'حركات نقدية بدون شيفت',
    'pending_refunds' => 'مبالغ مرتجعة لم تصل للعميل بعد',
    'cash_mismatch' => 'النقدي في الطلبات لا يساوي النقدي في الدرج',
];
$attentionDescriptions = [
    'refunds' => 'عرض المرتجعات حسب طريقة الدفع',
    'reversals' => 'عرض الطلبات الملغاة فقط',
    'discounts' => 'عرض الطلبات التي عليها خصم فقط',
    'drawer_variance' => 'عرض الشيفتات التي تحتاج مراجعة',
    'open_shifts' => 'عرض الشيفتات المفتوحة فقط',
    'unassigned_cash' => 'عرض الحركات غير المرتبطة فقط',
    'pending_refunds' => 'عرض المبالغ التي ما زالت قيد الإرجاع',
    'cash_mismatch' => 'عرض مقارنة النقدي ومقدار الفرق',
];
$attentionTargetParams = [
    'refunds' => ['tab' => $tabPayments, 'focus' => CashShiftWorkspaceService::FOCUS_PAYMENT_REFUNDS],
    'reversals' => ['tab' => $tabOrders, 'focus' => CashShiftWorkspaceService::FOCUS_ORDER_CANCELLED],
    'discounts' => ['tab' => $tabOrders, 'focus' => CashShiftWorkspaceService::FOCUS_ORDER_DISCOUNTED],
    'drawer_variance' => ['tab' => $tabShifts, 'status' => 'needs_review'],
    'open_shifts' => ['tab' => $tabShifts, 'status' => 'open'],
    'unassigned_cash' => ['tab' => $tabMovements, 'focus' => CashShiftWorkspaceService::FOCUS_MOVEMENT_UNASSIGNED],
    'pending_refunds' => ['tab' => $tabPayments, 'focus' => CashShiftWorkspaceService::FOCUS_PAYMENT_PENDING_REFUNDS],
    'cash_mismatch' => ['tab' => $tabPayments, 'focus' => CashShiftWorkspaceService::FOCUS_PAYMENT_CASH_DIFFERENCE],
];
$attentionUrl = static function (array $alert) use ($workspaceUrl, $attentionTargetParams): string {
    $target = $attentionTargetParams[(string) ($alert['key'] ?? '')] ?? ['tab' => (string) ($alert['tab'] ?? 'overview')];
    return $workspaceUrl(array_merge([
        'focus' => null,
        'movement_type' => null,
        'status' => null,
        'scope' => null,
        'page' => null,
        'has_override' => null,
        'override_operator_id' => null,
    ], $target));
};
$visiblePaymentMethods = array_values(array_filter(
    $payments['by_method'] ?? [],
    static function (array $method) use ($context): bool {
        return match ($context['focus']) {
            CashShiftWorkspaceService::FOCUS_PAYMENT_REFUNDS => (float) ($method['refunded'] ?? 0) > 0,
            CashShiftWorkspaceService::FOCUS_PAYMENT_PENDING_REFUNDS => (float) ($method['pending_refund'] ?? 0) > 0,
            CashShiftWorkspaceService::FOCUS_PAYMENT_CASH_DIFFERENCE => (string) ($method['type'] ?? '') === 'cash',
            default => true,
        };
    }
));
$displayPaymentMethod = static function (string $label) use ($paymentMethodLabels): string {
    $key = strtolower(trim(str_replace('_', ' ', $label)));
    return $paymentMethodLabels[$key] ?? $label;
};
$displayPerson = static function (string $name): string {
    return preg_replace('/^User\s*#/i', 'مستخدم #', $name) ?: $name;
};
$displayItem = static function (string $name): string {
    return preg_replace('/^Item\s*#/i', 'صنف #', $name) ?: $name;
};
$formatTime = static function (?string $value): string {
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp ? date('H:i', $timestamp) : '—';
};
$formatQuantity = static function ($value): string {
    $formatted = number_format((float) $value, 6, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
};

$movementTotals = $summary['movement_totals'] ?? [];
$varianceRollup = (float) ($summary['close_variance_rollup'] ?? 0);
$expectedRollup = (float) ($summary['expected_cash_rollup'] ?? 0);
$countedRollup = (float) ($summary['counted_cash_rollup'] ?? 0);
$sessionCount = (int) ($summary['session_count'] ?? 0);
$varianceClass = abs($varianceRollup) < 0.001 ? 'zero' : ($varianceRollup > 0 ? 'pos' : 'neg');
$varianceLabel = abs($varianceRollup) < 0.001 ? 'متوازن' : ($varianceRollup > 0 ? 'زيادة' : 'عجز');
$premiumCssVer = is_file(__DIR__ . '/css/premium-report-light.css') ? (string) filemtime(__DIR__ . '/css/premium-report-light.css') : '1';
?>
<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<script>document.body.classList.add('premium-report-page', 'cash-shift-workspace-page');</script>
<link rel="stylesheet" href="css/premium-report-light.css?v=<?= htmlspecialchars($premiumCssVer, ENT_QUOTES, 'UTF-8') ?>">

<div class="content-wrapper">
  <section class="content">
    <main class="premium-report cash-shift-workspace" id="cashShiftWorkspace" dir="rtl" lang="ar">
      <header class="pr-hero cash-shift-hero">
        <div class="pr-hero-text">
          <p class="pr-eyebrow">كل تقارير نقطة البيع في مكان واحد</p>
          <h1>تقارير التشغيل</h1>
          <p class="pr-hero-sub">راجع المبيعات والطلبات والمدفوعات والشيفتات والأصناف من مصدر واحد.</p>
        </div>
        <?php if ($canViewShifts): ?>
        <a class="cash-shift-backlog" href="<?= htmlspecialchars($workspaceUrl(['tab' => $tabShifts, 'status' => 'needs_review', 'scope' => 'backlog', 'focus' => null, 'movement_type' => null, 'page' => null]), ENT_QUOTES, 'UTF-8') ?>">
          <span>شيفتات تحتاج مراجعة</span><strong><?= (int) $backlogCount ?></strong><small>من كل الفترات</small>
        </a>
        <?php endif; ?>
      </header>

      <?php if ($flashSuccess !== ''): ?><div class="pr-callout pr-callout--success" role="status"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>
      <?php if ($flashError !== ''): ?><div class="pr-callout pr-callout--danger" role="alert"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

      <nav class="cash-shift-tabs" aria-label="أقسام تقارير التشغيل">
        <?php if ($canSalesReports): ?><a href="<?= htmlspecialchars($tabUrl($tabOverview), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabOverview ? 'is-active' : '' ?>" <?= $context['tab'] === $tabOverview ? 'aria-current="page"' : '' ?>><i class="fas fa-chart-line"></i> نظرة عامة</a><?php endif; ?>
        <?php if ($canViewShifts): ?><a href="<?= htmlspecialchars($tabUrl($tabShifts), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabShifts ? 'is-active' : '' ?>" <?= $context['tab'] === $tabShifts ? 'aria-current="page"' : '' ?>><i class="fas fa-cash-register"></i> الشيفتات</a><?php endif; ?>
        <?php if ($canSalesReports): ?><a href="<?= htmlspecialchars($tabUrl($tabOrders), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabOrders ? 'is-active' : '' ?>" <?= $context['tab'] === $tabOrders ? 'aria-current="page"' : '' ?>><i class="fas fa-receipt"></i> الطلبات</a><?php endif; ?>
        <?php if ($canSalesReports): ?><a href="<?= htmlspecialchars($tabUrl($tabPayments), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabPayments ? 'is-active' : '' ?>" <?= $context['tab'] === $tabPayments ? 'aria-current="page"' : '' ?>><i class="fas fa-credit-card"></i> المدفوعات</a><?php endif; ?>
        <?php if ($canSalesReports): ?><a href="<?= htmlspecialchars($tabUrl($tabItems), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabItems ? 'is-active' : '' ?>" <?= $context['tab'] === $tabItems ? 'aria-current="page"' : '' ?>><i class="fas fa-cubes"></i> مبيعات الأصناف</a><?php endif; ?>
        <?php if ($canSalesReports): ?><a href="<?= htmlspecialchars($tabUrl($tabAttention), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabAttention ? 'is-active' : '' ?>" <?= $context['tab'] === $tabAttention ? 'aria-current="page"' : '' ?>><i class="fas fa-clipboard-check"></i> متابعة الإدارة</a><?php endif; ?>
        <?php if ($canReport): ?><a href="<?= htmlspecialchars($tabUrl($tabMovements), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabMovements ? 'is-active' : '' ?>" <?= $context['tab'] === $tabMovements ? 'aria-current="page"' : '' ?>><i class="fas fa-exchange-alt"></i> سجل النقدية</a><?php endif; ?>
        <?php if ($canConfigureBusinessDay || $canSetBaselinePermission): ?><a href="<?= htmlspecialchars($tabUrl($tabSettings), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabSettings ? 'is-active' : '' ?>" <?= $context['tab'] === $tabSettings ? 'aria-current="page"' : '' ?>><i class="fas fa-sliders-h"></i> الإعدادات</a><?php endif; ?>
      </nav>

      <?php if ($context['tab'] !== $tabSettings): ?>
      <section class="pr-panel pr-no-print cash-shift-filter-panel">
        <?php if ($context['scope'] !== 'backlog'): ?>
        <nav class="cash-shift-date-presets" aria-label="فترة تقرير سريعة">
          <span class="cash-shift-date-presets__label"><i class="far fa-calendar-alt"></i> فترة سريعة</span>
          <div class="cash-shift-date-presets__options">
            <?php foreach ($datePresets as $presetKey => $preset): ?>
            <a
              class="cash-shift-date-preset <?= $activeDatePreset === $presetKey ? 'is-active' : '' ?>"
              href="<?= htmlspecialchars($workspaceUrl(['date_from' => $preset['date_from'], 'date_to' => $preset['date_to'], 'scope' => null, 'page' => null]), ENT_QUOTES, 'UTF-8') ?>"
              <?= $activeDatePreset === $presetKey ? 'aria-current="page"' : '' ?>
            ><?= htmlspecialchars($preset['label'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
          </div>
        </nav>
        <?php endif; ?>
        <form method="get" id="cashFlowFilters" class="pr-filters-grid">
          <input type="hidden" name="tab" value="<?= htmlspecialchars($context['tab']) ?>">
          <?php if ($context['focus'] !== ''): ?><input type="hidden" name="focus" value="<?= htmlspecialchars($context['focus']) ?>"><?php endif; ?>
          <?php if ($context['tab'] === $tabShifts && $context['status'] !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($context['status']) ?>"><?php endif; ?>
          <?php if ($context['tab'] === $tabShifts && $context['scope'] === 'backlog'): ?><input type="hidden" name="scope" value="backlog"><?php endif; ?>
          <div class="pr-field"><label for="date_from">يوم العمل من</label><input type="date" id="date_from" name="date_from" class="form-control" value="<?= htmlspecialchars($context['date_from']) ?>" <?= $context['scope'] === 'backlog' ? 'disabled' : '' ?>></div>
          <div class="pr-field"><label for="date_to">يوم العمل إلى</label><input type="date" id="date_to" name="date_to" class="form-control" value="<?= htmlspecialchars($context['date_to']) ?>" <?= $context['scope'] === 'backlog' ? 'disabled' : '' ?>></div>
          <div class="pr-field"><label for="cashier_id">موظف الكاشير</label><select id="cashier_id" name="cashier_id" class="form-control"><option value="0">جميع موظفي الكاشير</option><?php foreach ($cashiers as $cashier): ?><option value="<?= (int) $cashier['id'] ?>" <?= $context['cashier_id'] === (int) $cashier['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($cashier['display_name'] ?: $cashier['uname'])) ?></option><?php endforeach; ?></select></div>
          <?php if ($context['tab'] === $tabShifts): ?><div class="pr-field"><label for="override_operator_id">مستخدم إضافي على الشيفت</label><select id="override_operator_id" name="override_operator_id" class="form-control"><option value="0">كل المستخدمين</option><?php foreach ($cashiers as $cashier): ?><option value="<?= (int) $cashier['id'] ?>" <?= $context['override_operator_id'] === (int) $cashier['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($cashier['display_name'] ?: $cashier['uname'])) ?></option><?php endforeach; ?></select></div><?php endif; ?>
          <div class="pr-field pr-field--submit"><label>&nbsp;</label><button type="submit" class="pr-btn pr-btn-primary w-100"><i class="fas fa-filter"></i> تطبيق الفلاتر</button></div>
        </form>
      </section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabOverview && $canSalesReports): ?>
      <section class="operations-intro" aria-labelledby="salesSummaryHeading">
        <div>
          <p class="pr-eyebrow">مبيعات اليوم</p>
          <h2 id="salesSummaryHeading">ملخص أداء المحل</h2>
          <p>يعرض هذا الملخص المبيعات المكتملة، ثم يخصم منها الخصومات والمرتجعات المسجلة.</p>
        </div>
        <span class="pr-pill pr-pill--status-closed">حسب يوم العمل</span>
      </section>
      <div class="operations-metrics">
        <article class="operations-metric operations-metric--primary"><span>صافي المبيعات</span><strong><?= number_format((float)($salesSummary['net_sales'] ?? 0), 2) ?></strong><small>المبيعات الفعلية بعد الخصومات والمرتجعات</small></article>
        <article class="operations-metric"><span>إجمالي المبيعات</span><strong><?= number_format((float)($salesSummary['gross_sales'] ?? 0), 2) ?></strong><small>قبل طرح الخصومات والمرتجعات</small></article>
        <article class="operations-metric operations-metric--negative"><span>الخصومات</span><strong>− <?= number_format((float)($salesSummary['discounts'] ?? 0), 2) ?></strong><small><?= (int)($salesSummary['discounted_order_count'] ?? 0) ?> طلب عليه خصم</small></article>
        <article class="operations-metric operations-metric--negative"><span>المرتجعات</span><strong>− <?= number_format((float)($salesSummary['refunds'] ?? 0), 2) ?></strong><small><?= (int)($salesSummary['refund_count'] ?? 0) ?> عملية مرتجع مكتملة</small></article>
        <article class="operations-metric"><span>عدد الطلبات</span><strong><?= number_format((int)($salesSummary['order_count'] ?? 0)) ?></strong><small>طلبات اكتمل تحصيلها أو إرجاعها</small></article>
        <article class="operations-metric"><span>متوسط الطلب</span><strong><?= number_format((float)($salesSummary['average_order_value'] ?? 0), 2) ?></strong><small>متوسط صافي قيمة الطلب الواحد</small></article>
      </div>

      <div class="operations-overview-grid">
        <section class="pr-panel">
          <div class="pr-panel-head"><div><p class="pr-eyebrow">توزيع المدفوعات</p><h2 class="pr-panel-title">المبلغ المحصل حسب طريقة الدفع</h2></div><a class="pr-btn pr-btn-ghost" href="<?= htmlspecialchars($tabUrl($tabPayments), ENT_QUOTES, 'UTF-8') ?>">تفاصيل المدفوعات</a></div>
          <div class="pr-panel-body operations-payment-stack">
            <?php foreach (($payments['net_by_type'] ?? []) as $type => $amount): if (abs((float)$amount) < 0.001) continue; ?>
            <div class="operations-payment-row"><span><i class="fas <?= $type === 'cash' ? 'fa-money-bill-wave' : ($type === 'card' ? 'fa-credit-card' : 'fa-wallet') ?>"></i><?= htmlspecialchars($paymentTypeLabels[$type] ?? ucfirst($type)) ?></span><strong><?= number_format((float)$amount, 2) ?></strong></div>
            <?php endforeach; ?>
            <?php if (array_sum(array_map('floatval', $payments['net_by_type'] ?? [])) == 0.0): ?><div class="cash-shift-empty"><i class="fas fa-receipt"></i><strong>لا توجد مدفوعات في هذه الفترة</strong></div><?php endif; ?>
          </div>
        </section>
        <section class="pr-panel">
          <div class="pr-panel-head"><div><p class="pr-eyebrow">متابعة الإدارة</p><h2 class="pr-panel-title">أمور تحتاج متابعة</h2></div><a class="pr-btn pr-btn-ghost" href="<?= htmlspecialchars($tabUrl($tabAttention), ENT_QUOTES, 'UTF-8') ?>">عرض الكل</a></div>
          <div class="pr-panel-body operations-attention-list">
            <?php foreach (array_slice($attentionRows, 0, 4) as $alert): ?><a href="<?= htmlspecialchars($attentionUrl($alert), ENT_QUOTES, 'UTF-8') ?>" class="operations-attention-row operations-attention-row--<?= htmlspecialchars($alert['severity']) ?>"><span><i class="fas fa-circle"></i><?= htmlspecialchars($attentionLabels[$alert['key']] ?? $alert['label']) ?></span><strong><?= (int)$alert['count'] ?><?= $alert['amount'] !== null ? ' · '.number_format(abs((float)$alert['amount']),2) : '' ?></strong></a><?php endforeach; ?>
            <?php if ($attentionRows === []): ?><div class="operations-clear-state"><i class="fas fa-check"></i><div><strong>لا توجد ملاحظات في هذه الفترة</strong><span>لم تظهر أي ملاحظة ضمن الضوابط المتاحة لصلاحياتك.</span></div></div><?php endif; ?>
          </div>
        </section>
      </div>

      <?php if ($canReport): ?><section class="pr-panel cash-walk-panel">
        <div class="pr-panel-head"><div><p class="pr-eyebrow">عهدة الدرج</p><h2 class="pr-panel-title">حركة النقدية</h2></div><a class="pr-btn pr-btn-ghost" href="<?= htmlspecialchars($tabUrl($tabMovements), ENT_QUOTES, 'UTF-8') ?>">فتح سجل النقدية</a></div>
        <div class="pr-panel-body cash-walk-grid">
          <?php foreach ([['opening','+'],['sale_cash','+'],['refund_cash','−'],['paid_in','+'],['paid_out','−'],['safe_drop','−']] as [$type,$sign]): $amount=(float)($movementTotals[$type]??0); ?>
          <div class="cash-walk-step"><span><?= htmlspecialchars($movementLabels[$type]) ?></span><strong class="<?= $sign === '+' ? 'is-add' : 'is-sub' ?>"><?= $sign ?> <?= number_format($amount, 2) ?></strong></div>
          <?php endforeach; ?>
          <div class="cash-walk-step is-result"><span>الرصيد المتوقع في الأدراج</span><strong><?= number_format($expectedRollup, 2) ?></strong></div>
        </div>
      </section><?php endif; ?>
      <?php if ($backlogCount > 0): ?><div class="pr-callout pr-callout--warn"><strong><?= $backlogCount ?> شيفت يحتاج إلى مراجعة عبر كل الفترات.</strong> قائمة المراجعة مستقلة عن تواريخ التقرير المختارة. <a href="<?= htmlspecialchars($workspaceUrl(['tab'=>$tabShifts,'status'=>'needs_review','scope'=>'backlog','focus'=>null,'movement_type'=>null,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>">فتح قائمة المراجعة</a></div><?php endif; ?>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabShifts && $canViewShifts): ?>
      <?php if ($context['scope'] === 'backlog'): ?><div class="pr-callout pr-callout--warn cash-shift-scope-note" role="status"><strong>نطاق المراجعة: كل الفترات.</strong> حقول التاريخ معطلة لأن قائمة المراجعة غير محدودة بالتواريخ المختارة. <a href="<?= htmlspecialchars($workspaceUrl(['scope'=>null,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>">العودة إلى الفترة المختارة</a></div><?php endif; ?>
      <section class="pr-panel">
        <div class="pr-panel-head"><div><p class="pr-eyebrow"><?= $context['scope'] === 'backlog' ? 'كل الفترات' : 'الفترة المختارة' ?></p><h2 class="pr-panel-title">الشيفتات وجلسات الدرج</h2></div><span class="pr-pill pr-pill--muted"><?= (int) $shiftsPage['total'] ?> جلسة</span></div>
        <div class="pr-panel-body">
          <div class="pr-session-toolbar"><div class="pr-chip-filters" role="group" aria-label="تصفية حالة الشيفت">
            <?php foreach (['all'=>'الكل','open'=>'مفتوح','closed'=>'مغلق','needs_review'=>'تحتاج مراجعة','forced_closed'=>'إغلاق إجباري'] as $key=>$label): ?>
            <a href="<?= htmlspecialchars($workspaceUrl(['status'=>$key==='all'?null:$key,'scope'=>$key==='needs_review'&&$context['scope']==='backlog'?'backlog':null,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>" class="pr-chip-filter <?= $context['status'] === $key ? 'is-active' : '' ?>"><?= htmlspecialchars($label) ?><?= $key === 'needs_review' ? ' ('.$backlogCount.')' : '' ?></a>
            <?php endforeach; ?>
            <a href="<?= htmlspecialchars($workspaceUrl(['has_override'=>$context['has_override']?null:1,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>" class="pr-chip-filter <?= $context['has_override'] ? 'is-active' : '' ?>"><i class="fas fa-user-clock"></i> به مستخدم إضافي</a>
          </div><div class="pr-session-meta">صفحة <?= (int) $shiftsPage['page'] ?> من <?= (int) $shiftsPage['pages'] ?></div></div>
          <p class="operations-source-note"><i class="fas fa-database"></i> كل مبلغ أدناه مصدره سجل الدرج المرتبط بالشيفت. تُعرض المرتجعات والمصروفات والتوريدات إلى الخزنة منفصلة داخل النقدية الخارجة.</p>
          <div class="pr-table-wrap pr-table-scroll"><table class="pr-table pr-table--compact cash-shift-table"><thead><tr><th>الكاشير</th><th>يوم العمل / الوقت</th><th>رصيد البداية</th><th>المبيعات النقدية</th><th>نقدية أخرى داخلة</th><th>النقدية الخارجة</th><th>الرصيد المتوقع</th><th>الفرق</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
          <?php foreach ($shiftsPage['rows'] as $session):
              $isOpen = ($session['status'] ?? '') === 'open';
              $variance = $session['close_variance'] ?? $session['difference'] ?? null;
              $expected = (float) ($session['close_expected_snapshot'] ?? $session['expected_cash'] ?? 0);
              $needsReview = ($session['variance_status'] ?? '') === 'unresolved';
              $sessionMovements = $session['movement_totals'] ?? [];
              $cashSales = (float) ($sessionMovements['sale_cash'] ?? 0);
              $paidIn = (float) ($sessionMovements['paid_in'] ?? 0);
              $cashRefunds = (float) ($sessionMovements['refund_cash'] ?? 0);
              $paidOut = (float) ($sessionMovements['paid_out'] ?? 0);
              $safeDrops = (float) ($sessionMovements['safe_drop'] ?? 0);
          ?>
          <tr class="<?= $needsReview ? 'pr-row-variance' : '' ?>">
            <td data-testid="cash-shift-accountability"><span class="pr-pill pr-pill--user"><?= htmlspecialchars($displayPerson((string) ($session['user_name'] ?? ''))) ?></span><?php if (!empty($session['has_override'])): ?> <span class="pr-pill pr-pill--warn">مستخدم إضافي</span><?php endif; ?><small class="operations-cell-sub">شيفت #<?= (int)$session['id'] ?> · أغلقه <?= htmlspecialchars($displayPerson((string)($session['closed_by_name'] ?? '') ?: '—')) ?></small></td>
            <td><strong><?= htmlspecialchars((string) ($session['business_day'] ?? '—')) ?></strong><small class="operations-cell-sub"><?= htmlspecialchars($formatTime($session['opened_at'] ?? null)) ?> — <?= $isOpen ? 'الآن' : htmlspecialchars($formatTime($session['closed_at'] ?? null)) ?></small></td>
            <td><?= number_format((float)($session['opening_cash'] ?? 0), 2) ?></td>
            <td><?= number_format($cashSales, 2) ?></td>
            <td><?= number_format($paidIn, 2) ?><small class="operations-cell-sub">إيداعات نقدية</small></td>
            <td><strong><?= number_format($cashRefunds + $paidOut + $safeDrops, 2) ?></strong><small class="operations-cell-sub">مرتجعات <?= number_format($cashRefunds,2) ?> · مصروفات <?= number_format($paidOut,2) ?> · توريد للخزنة <?= number_format($safeDrops,2) ?></small></td>
            <td><?= number_format($expected, 2) ?></td>
            <td><?php if ($isOpen || !empty($session['count_pending'])): ?><span class="pr-pill pr-pill--muted"><?= $isOpen ? 'لم يُغلق' : 'العد معلق' ?></span><?php else: ?><span class="pr-pill <?= (float)$variance < 0 ? 'pr-pill--expense' : ((float)$variance > 0 ? 'pr-pill--money' : 'pr-pill--muted') ?>"><?= number_format((float)$variance, 2) ?></span><?php endif; ?></td>
            <td><span class="pr-pill <?= $isOpen ? 'pr-pill--status-open pr-pill--pulse' : 'pr-pill--status-closed' ?>"><?= htmlspecialchars($statusLabels[$session['status']] ?? $session['status']) ?></span><?php if ($needsReview): ?> <span class="pr-pill pr-pill--warn"><?= htmlspecialchars($varianceTypeLabels[$session['variance_type'] ?? 'none'] ?? '') ?></span><?php endif; ?></td>
            <td><div class="pr-actions cash-shift-actions"><a class="pr-btn pr-btn-ghost" data-testid="session-detail-link" href="<?= htmlspecialchars(posmain_cash_shift_detail_url((int)$session['id'], $returnTo), ENT_QUOTES, 'UTF-8') ?>">التفاصيل</a><?php if (!$isOpen): ?><a class="pr-btn pr-btn-ghost" target="_blank" href="print/closed_session_receipt.php?id=<?= (int)$session['id'] ?>">طباعة تقرير Z</a><?php endif; ?><?php if ($isOpen && $canForceClose): ?><button type="button" class="pr-btn pr-btn-danger" data-bs-toggle="modal" data-bs-target="#forceCloseDrawerModal" data-session-id="<?= (int)$session['id'] ?>" data-user-name="<?= htmlspecialchars((string)($session['user_name']??''), ENT_QUOTES, 'UTF-8') ?>">إغلاق إجباري</button><?php endif; ?><?php if ($needsReview && $canResolveVariance): ?><button type="button" class="pr-btn pr-btn-primary" data-bs-toggle="modal" data-bs-target="#resolveDrawerModal" data-session-id="<?= (int)$session['id'] ?>" data-variance-type="<?= htmlspecialchars((string)($session['variance_type']??'closing'), ENT_QUOTES, 'UTF-8') ?>" data-variance-amount="<?= htmlspecialchars(number_format((float)$variance,3,'.',''), ENT_QUOTES, 'UTF-8') ?>">تسجيل سبب الفرق</button><?php endif; ?></div></td>
          </tr>
          <?php endforeach; ?>
          <?php if ($shiftsPage['rows'] === []): ?><tr><td colspan="10"><div class="cash-shift-empty"><i class="fas fa-inbox"></i><strong>لا توجد شيفتات مطابقة</strong><span>جرّب حالة أو فترة زمنية أخرى.</span></div></td></tr><?php endif; ?>
          </tbody></table></div>
          <?php if ($shiftsPage['pages'] > 1): ?><nav class="pr-pagination" aria-label="صفحات الشيفتات"><a class="pr-btn pr-btn-ghost <?= $shiftsPage['page']<=1?'disabled':'' ?>" href="<?= htmlspecialchars($workspaceUrl(['page'=>max(1,$shiftsPage['page']-1)]), ENT_QUOTES, 'UTF-8') ?>">السابق</a><span>صفحة <?= (int)$shiftsPage['page'] ?> من <?= (int)$shiftsPage['pages'] ?></span><a class="pr-btn pr-btn-ghost <?= $shiftsPage['page']>=$shiftsPage['pages']?'disabled':'' ?>" href="<?= htmlspecialchars($workspaceUrl(['page'=>min($shiftsPage['pages'],$shiftsPage['page']+1)]), ENT_QUOTES, 'UTF-8') ?>">التالي</a></nav><?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabOrders && $canSalesReports): ?>
      <section class="pr-panel">
        <div class="pr-panel-head"><div><p class="pr-eyebrow">سجل الطلبات</p><h2 class="pr-panel-title"><?= htmlspecialchars(match ($context['focus']) { CashShiftWorkspaceService::FOCUS_ORDER_CANCELLED => 'الطلبات الملغاة خلال الفترة', CashShiftWorkspaceService::FOCUS_ORDER_DISCOUNTED => 'الطلبات التي تم تطبيق خصم عليها', default => 'كل طلبات نقطة البيع في الفترة المختارة' }) ?></h2></div><span class="pr-pill pr-pill--muted"><?= count($orders) ?> طلب معروض</span></div>
        <div class="pr-panel-body">
          <div class="operations-focus-bar"><div class="pr-chip-filters" role="group" aria-label="اختيار نوع الطلبات"><a class="pr-chip-filter <?= $context['focus'] === '' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>null,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>">كل الطلبات</a><a class="pr-chip-filter <?= $context['focus'] === CashShiftWorkspaceService::FOCUS_ORDER_CANCELLED ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>CashShiftWorkspaceService::FOCUS_ORDER_CANCELLED,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>">الطلبات الملغاة</a><a class="pr-chip-filter <?= $context['focus'] === CashShiftWorkspaceService::FOCUS_ORDER_DISCOUNTED ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>CashShiftWorkspaceService::FOCUS_ORDER_DISCOUNTED,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>">طلبات عليها خصم</a></div><?php if ($context['focus'] !== ''): ?><a class="operations-clear-filter" href="<?= htmlspecialchars($workspaceUrl(['focus'=>null,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-times"></i> إلغاء التصفية</a><?php endif; ?></div>
          <p class="operations-source-note"><i class="fas fa-info-circle"></i> يعرض السجل حالة كل طلب كما هي. أرقام المبيعات في النظرة العامة تشمل فقط الطلبات التي اكتمل تحصيلها أو إرجاعها.</p>
          <div class="pr-table-wrap pr-table-scroll"><table class="pr-table operations-table"><thead><tr><th>رقم الطلب</th><th>الوقت</th><th>الكاشير</th><th>إجمالي الطلب</th><th>الخصم</th><th>طريقة الدفع</th><th>الحالة</th></tr></thead><tbody>
          <?php foreach ($orders as $order):
              $rawPaymentStatus = strtolower((string) ($order['payment_status'] ?? ''));
              $rawOrderStatus = strtolower((string) ($order['order_status'] ?? ''));
              $isCancelledOrder = (int) ($order['isdeleted'] ?? 0) === 1
                  || in_array($rawPaymentStatus, ['cancelled', 'canceled', 'voided'], true)
                  || in_array($rawOrderStatus, ['cancelled', 'canceled', 'voided'], true);
              $orderStatus = $isCancelledOrder
                  ? 'cancelled'
                  : (string) (($order['payment_status'] ?? '') ?: ($order['order_status'] ?? 'unknown'));
              $orderTime = (string) (($order['payment_date'] ?? '') ?: ($order['crtime'] ?? $order['pro_date'] ?? ''));
              $statusClass = in_array($orderStatus, ['paid'], true) ? 'pr-pill--status-closed' : (in_array($orderStatus, ['refunded','voided','cancelled'], true) ? 'pr-pill--expense' : 'pr-pill--warn');
          ?><tr class="<?= (int)$order['isdeleted'] === 1 ? 'pr-row-warn' : '' ?>">
            <td><a href="check_orders.php?id=<?= (int)$order['id'] ?>">#<?= htmlspecialchars((string)$order['public_order_number']) ?></a><small class="operations-cell-sub">المعرّف <?= (int)$order['id'] ?></small></td>
            <td><?= htmlspecialchars($orderTime ?: '—') ?></td>
            <td><?= htmlspecialchars($displayPerson((string)$order['cashier_name'])) ?></td>
            <td><strong><?= number_format((float)$order['fat_net'], 2) ?></strong></td>
            <td><?= (float)$order['fat_disc'] > 0 ? '− '.number_format((float)$order['fat_disc'],2) : '—' ?></td>
            <td><?php foreach ($order['payment_methods'] as $method): ?><span class="pr-pill pr-pill--neutral"><?= htmlspecialchars($displayPaymentMethod((string)$method)) ?></span> <?php endforeach; ?></td>
            <td><span class="pr-pill <?= $statusClass ?>"><?= htmlspecialchars($orderStatusLabels[strtolower($orderStatus)] ?? $orderStatus) ?></span></td>
          </tr><?php endforeach; ?>
          <?php if ($orders === []): ?><tr><td colspan="7"><div class="cash-shift-empty"><i class="fas fa-receipt"></i><strong><?= $context['focus'] === '' ? 'لا توجد طلبات في هذه الفترة' : 'لا توجد طلبات مطابقة لهذا الاختيار' ?></strong><span>جرّب فترة أو كاشيرًا آخر، أو اعرض كل الطلبات.</span></div></td></tr><?php endif; ?>
          </tbody></table></div>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabPayments && $canSalesReports): ?>
      <?php $cashDiff = (float)($payments['cash_reconciliation_diff'] ?? 0); ?>
      <div class="operations-focus-bar operations-focus-bar--standalone"><div><strong><?= htmlspecialchars(match ($context['focus']) { CashShiftWorkspaceService::FOCUS_PAYMENT_REFUNDS => 'عرض طرق الدفع التي حدثت منها مرتجعات', CashShiftWorkspaceService::FOCUS_PAYMENT_PENDING_REFUNDS => 'عرض المبالغ التي ما زالت قيد الإرجاع للعميل', CashShiftWorkspaceService::FOCUS_PAYMENT_CASH_DIFFERENCE => 'مقارنة النقدي في الطلبات بالنقدي المسجل في الدرج', default => 'عرض كل المدفوعات' }) ?></strong><span>يمكنك تغيير العرض دون مغادرة تقرير المدفوعات.</span></div><div class="pr-chip-filters" role="group" aria-label="اختيار تفاصيل المدفوعات"><a class="pr-chip-filter <?= $context['focus'] === '' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>null]), ENT_QUOTES, 'UTF-8') ?>">الكل</a><a class="pr-chip-filter <?= $context['focus'] === CashShiftWorkspaceService::FOCUS_PAYMENT_REFUNDS ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>CashShiftWorkspaceService::FOCUS_PAYMENT_REFUNDS]), ENT_QUOTES, 'UTF-8') ?>">المرتجعات</a><a class="pr-chip-filter <?= $context['focus'] === CashShiftWorkspaceService::FOCUS_PAYMENT_PENDING_REFUNDS ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>CashShiftWorkspaceService::FOCUS_PAYMENT_PENDING_REFUNDS]), ENT_QUOTES, 'UTF-8') ?>">قيد الإرجاع</a><?php if ($canReport): ?><a class="pr-chip-filter <?= $context['focus'] === CashShiftWorkspaceService::FOCUS_PAYMENT_CASH_DIFFERENCE ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>CashShiftWorkspaceService::FOCUS_PAYMENT_CASH_DIFFERENCE]), ENT_QUOTES, 'UTF-8') ?>">مقارنة النقدي</a><?php endif; ?></div></div>
      <div class="operations-metrics operations-metrics--payments">
        <article class="operations-metric operations-metric--primary"><span>المبلغ المحصل بعد المرتجعات</span><strong><?= number_format((float)($payments['net_total'] ?? 0),2) ?></strong><small>كل ما تم تحصيله ناقص ما تم إرجاعه</small></article>
        <article class="operations-metric"><span>إجمالي النقدي</span><strong><?= number_format((float)($payments['cash_collected'] ?? 0),2) ?></strong><small>من مدفوعات الطلبات</small></article>
        <article class="operations-metric"><span>إجمالي البطاقات</span><strong><?= number_format((float)($payments['by_type']['card'] ?? 0),2) ?></strong><small>المحصل بالبطاقات قبل المرتجعات</small></article>
        <article class="operations-metric operations-metric--negative"><span>إجمالي المرتجعات</span><strong>− <?= number_format((float)($payments['refund_total'] ?? 0),2) ?></strong><small>المبلغ الذي أُعيد للعملاء</small></article>
      </div>
      <?php if ($canReport && !empty($payments['cash_reconciliation_available'])): ?><div class="pr-callout <?= abs($cashDiff) < 0.01 ? 'pr-callout--success' : 'pr-callout--danger' ?> operations-reconciliation">
        <div><strong><?= abs($cashDiff) < 0.01 ? 'النقدي متطابق' : 'يوجد فرق في النقدي' ?></strong><span>النقدي من الطلبات <?= number_format((float)($payments['cash_net'] ?? 0),2) ?> · النقدي المسجل في الدرج <?= number_format((float)($payments['drawer_cash_net'] ?? 0),2) ?></span></div><div class="operations-reconciliation__difference"><small>الفرق</small><strong><?= number_format(abs($cashDiff),2) ?></strong></div>
      </div><?php endif; ?>
      <section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">تفاصيل التحصيل</p><h2 class="pr-panel-title">المبالغ حسب طريقة الدفع</h2></div><span class="pr-pill pr-pill--muted"><?= htmlspecialchars($paymentSourceLabels[(string)($payments['source'] ?? 'none')] ?? (string)($payments['source'] ?? '')) ?></span></div><div class="pr-panel-body"><div class="pr-table-wrap"><table class="pr-table operations-table"><thead><tr><th>طريقة الدفع</th><th>الفئة</th><th>تم تحصيله</th><th>تم إرجاعه</th><th>قيد الإرجاع</th><th>المتبقي بعد المرتجعات</th></tr></thead><tbody>
      <?php foreach ($visiblePaymentMethods as $method): ?><tr><td><strong><?= htmlspecialchars($displayPaymentMethod((string)$method['label'])) ?></strong></td><td><?= htmlspecialchars($paymentTypeLabels[$method['type']] ?? (string)$method['type']) ?></td><td><?= number_format((float)$method['collected'],2) ?></td><td class="operations-negative"><?= (float)$method['refunded'] > 0 ? '− '.number_format((float)$method['refunded'],2) : '—' ?></td><td><?= (float)$method['pending_refund'] > 0 ? number_format((float)$method['pending_refund'],2) : '—' ?></td><td><strong><?= number_format((float)$method['net'],2) ?></strong></td></tr><?php endforeach; ?>
      <?php if ($visiblePaymentMethods === []): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= $context['focus'] === '' ? 'لا توجد مدفوعات في هذه الفترة' : 'لا توجد مدفوعات مطابقة لهذا الاختيار' ?></td></tr><?php endif; ?>
      </tbody></table></div></div></section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabItems && $canSalesReports): ?>
      <section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">أداء الأصناف</p><h2 class="pr-panel-title">كل ما بيع وما تم إرجاعه بدقة</h2></div><span class="pr-pill pr-pill--muted"><?= count($itemSales) ?> صنف</span></div><div class="pr-panel-body">
        <p class="operations-source-note"><i class="fas fa-info-circle"></i> تعرض الكمية المباعة والكمية التي أُعيدت كلٌّ على حدة، ثم تحسب صافي الكمية.</p>
        <div class="pr-table-wrap pr-table-scroll"><table class="pr-table operations-table operations-items-table"><thead><tr><th>الصنف</th><th>الكمية المباعة</th><th>الكمية المرتجعة</th><th>صافي الكمية</th><th>عدد الطلبات</th><th>صافي قيمة المبيعات</th></tr></thead><tbody>
        <?php foreach ($itemSales as $index => $item): ?><tr><td><span class="operations-rank"><?= $index + 1 ?></span><strong><?= htmlspecialchars($displayItem((string)$item['item_name'])) ?></strong></td><td><?= htmlspecialchars($formatQuantity($item['sold_qty'])) ?></td><td class="operations-negative"><?= (float)$item['returned_qty'] > 0 ? '− '.htmlspecialchars($formatQuantity($item['returned_qty'])) : '—' ?></td><td><strong><?= htmlspecialchars($formatQuantity($item['net_qty'])) ?></strong></td><td><?= (int)$item['order_count'] ?></td><td><?= number_format((float)$item['net_value'],2) ?></td></tr><?php endforeach; ?>
        <?php if ($itemSales === []): ?><tr><td colspan="6"><div class="cash-shift-empty"><i class="fas fa-cubes"></i><strong>لا توجد مبيعات أصناف في هذه الفترة</strong><span>تُحتسب فقط طلبات نقطة البيع المكتملة المدفوعة أو المستردة.</span></div></td></tr><?php endif; ?>
        </tbody></table></div>
      </div></section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabAttention && $canSalesReports): ?>
      <section class="operations-intro"><div><p class="pr-eyebrow">متابعة الإدارة</p><h2>أمور تحتاج متابعة</h2><p>اضغط على أي بطاقة لعرض السجلات المرتبطة بها فقط. وجود البطاقة لا يعني وجود خطأ؛ بل يعني أن هناك شيئًا يستحق المراجعة.</p></div><span class="pr-pill <?= $attentionRows === [] ? 'pr-pill--status-closed' : 'pr-pill--warn' ?>"><?= count($attentionRows) ?> بند</span></section>
      <div class="operations-attention-grid">
        <?php foreach ($attentionRows as $alert): ?><a href="<?= htmlspecialchars($attentionUrl($alert), ENT_QUOTES, 'UTF-8') ?>" class="operations-alert-card operations-alert-card--<?= htmlspecialchars($alert['severity']) ?>"><div class="operations-alert-icon"><i class="fas <?= $alert['severity']==='critical'?'fa-exclamation':'fa-eye' ?>"></i></div><div><span><?= htmlspecialchars($attentionLabels[$alert['key']] ?? $alert['label']) ?></span><strong><?= (int)$alert['count'] ?></strong><?php if ($alert['amount'] !== null): ?><small>إجمالي المبلغ: <?= number_format(abs((float)$alert['amount']),2) ?></small><?php endif; ?><em><?= htmlspecialchars($attentionDescriptions[$alert['key']] ?? 'عرض التفاصيل') ?></em></div><i class="fas fa-arrow-left" aria-hidden="true"></i></a><?php endforeach; ?>
        <?php if ($attentionRows === []): ?><div class="operations-all-clear"><div><i class="fas fa-check"></i></div><h3>لا توجد أمور تحتاج متابعة</h3><p>لم يظهر أي بند يحتاج إلى مراجعة خلال هذه الفترة.</p></div><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabMovements && $canReport): ?>
      <section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">سجل الدرج</p><h2 class="pr-panel-title"><?= $context['focus'] === CashShiftWorkspaceService::FOCUS_MOVEMENT_UNASSIGNED ? 'حركات نقدية بدون شيفت' : 'كل الحركات النقدية' ?></h2></div><span class="pr-pill pr-pill--muted"><?= (int)($movements['total']??0) ?> حركة</span></div><div class="pr-panel-body"><div class="operations-focus-bar"><div class="pr-chip-filters" role="group" aria-label="اختيار ارتباط الحركات"><a class="pr-chip-filter <?= $context['focus'] === '' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>null]), ENT_QUOTES, 'UTF-8') ?>">كل الحركات</a><a class="pr-chip-filter <?= $context['focus'] === CashShiftWorkspaceService::FOCUS_MOVEMENT_UNASSIGNED ? 'is-active' : '' ?>" href="<?= htmlspecialchars($workspaceUrl(['focus'=>CashShiftWorkspaceService::FOCUS_MOVEMENT_UNASSIGNED]), ENT_QUOTES, 'UTF-8') ?>">بدون شيفت</a></div></div><form method="get" class="pr-collapse-filters"><input type="hidden" name="tab" value="movements"><?php if ($context['focus'] !== ''): ?><input type="hidden" name="focus" value="<?= htmlspecialchars($context['focus']) ?>"><?php endif; ?><input type="hidden" name="date_from" value="<?= htmlspecialchars($context['date_from']) ?>"><input type="hidden" name="date_to" value="<?= htmlspecialchars($context['date_to']) ?>"><input type="hidden" name="cashier_id" value="<?= (int)$context['cashier_id'] ?>"><div class="pr-field"><label for="movement_type">نوع الحركة</label><select id="movement_type" name="movement_type" class="form-control"><option value="">كل الحركات</option><?php foreach($movementLabels as $key=>$label): ?><option value="<?= htmlspecialchars($key) ?>" <?= $context['movement_type']===$key?'selected':'' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div><button class="pr-btn pr-btn-primary" type="submit">تطبيق</button></form><div class="pr-table-wrap pr-table-scroll"><table class="pr-table pr-table--compact"><thead><tr><th>الوقت</th><th>النوع</th><th>المبلغ</th><th>الشيفت</th><th>الطلب</th><th>المشغّل</th><th>اعتماد المدير</th><th>السبب</th></tr></thead><tbody><?php foreach(($movements['rows']??[]) as $row): ?><tr class="<?= !empty($row['is_unassigned'])?'pr-row-warn':'' ?>"><td><?= htmlspecialchars((string)$row['created_at']) ?></td><td><?= htmlspecialchars($movementLabels[$row['movement_type']]??$row['movement_type']) ?></td><td><span class="pr-pill pr-pill--money"><?= number_format((float)$row['amount'],2) ?></span></td><td><?php if($row['drawer_session_id']!==null): ?><a href="<?= htmlspecialchars(posmain_cash_shift_detail_url((int)$row['drawer_session_id'],$returnTo), ENT_QUOTES, 'UTF-8') ?>">شيفت #<?= (int)$row['drawer_session_id'] ?></a><?php else: ?><span class="pr-pill pr-pill--warn">بدون شيفت</span><?php endif; ?></td><td><?= $row['order_id']!==null?(int)$row['order_id']:'—' ?></td><td><?= htmlspecialchars($displayPerson((string)($row['created_by_name']??''))) ?></td><td><?php if(!empty($row['manager_approval_id'])): ?><span class="pr-pill pr-pill--status-closed">#<?= (int)$row['manager_approval_id'] ?></span> <?= htmlspecialchars($displayPerson((string)($row['manager_approved_by_name']??'') ?: ('مستخدم #' . (int)($row['manager_approved_by_user_id']??0)))) ?><?php else: ?>—<?php endif; ?></td><td><?= htmlspecialchars((string)($row['reason']??'—')) ?></td></tr><?php endforeach; ?><?php if(($movements['rows']??[])===[]): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= $context['focus'] === '' ? 'لا توجد حركات نقدية في هذه الفترة' : 'لا توجد حركات نقدية بدون شيفت في هذه الفترة' ?></td></tr><?php endif; ?></tbody></table></div></div></section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabSettings): ?>
      <div class="cash-shift-settings-grid">
        <?php if ($canConfigureBusinessDay): ?><section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">تنظيم التقارير اليومية</p><h2 class="pr-panel-title">بداية يوم العمل الجديد</h2></div><span class="pr-pill pr-pill--muted">يوم العمل الحالي <?= htmlspecialchars((string)$businessDayContext['current_business_day']) ?></span></div><div class="pr-panel-body"><p class="text-muted">أي عملية تتم قبل هذه الساعة تُحسب ضمن يوم العمل السابق.</p><form id="businessDayCutoffForm" class="cash-shift-setting-form"><?= csrf_input('business_day_cutoff') ?><input type="hidden" name="pos_tenant" value="<?= $tenant ?>"><input type="hidden" name="pos_branch" value="<?= $branch ?>"><div class="pr-field"><label for="businessDayCutoffHour">ساعة بداية اليوم الجديد (0–23)</label><input type="number" class="form-control" name="business_day_cutoff_hour" id="businessDayCutoffHour" min="0" max="23" value="<?= (int)$businessDayContext['cutoff_hour'] ?>" required></div><button class="pr-btn pr-btn-primary" type="submit">حفظ الساعة</button><div id="businessDayCutoffMessage" class="text-danger d-none"></div></form></div></section><?php endif; ?>
        <section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">عهدة البداية</p><h2 class="pr-panel-title">الرصيد الأساسي لبداية الدرج</h2></div><?php if(!$needsBaselineInit): ?><span class="pr-pill pr-pill--status-closed">تم الإعداد</span><?php endif; ?></div><div class="pr-panel-body"><?php if($needsBaselineInit && $canSetBaseline): ?><p>حدّد النقدية المتوقعة قبل فتح أول شيفت في الفرع.</p><button class="pr-btn pr-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#openingBaselineModal">تحديد رصيد البداية</button><?php elseif($needsBaselineInit): ?><div class="pr-callout pr-callout--warn">يجب أن يضبط هذا الفرع مستخدم لديه صلاحية تحديد رصيد البداية.</div><?php else: ?><p class="text-muted mb-0">تُشتق النقدية المتوقعة من آخر شيفت مغلق وحركات العهدة.</p><?php endif; ?><?php if(abs((float)($openingExpectation['unassigned_net']??0))>0.0001): ?><div class="pr-callout pr-callout--warn mt-3">صافي النقدية غير المرتبطة: <?= number_format(abs((float)$openingExpectation['unassigned_net']),2) ?></div><?php endif; ?></div></section>
      </div>
      <?php endif; ?>
    </main>
  </section>
</div>

<?php if ($canForceClose): ?><div class="modal fade" id="forceCloseDrawerModal" dir="rtl" lang="ar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="do/do_force_close_drawer.php" id="forceCloseDrawerForm"><?= csrf_input('shift_close') ?><input type="hidden" name="drawer_session_id" id="forceCloseDrawerSessionId"><input type="hidden" name="idempotency_key" id="forceCloseIdempotencyKey"><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>"><div class="modal-header"><h5 class="modal-title">إغلاق الدرج إجباريًا</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><p id="forceCloseDrawerUserLabel" class="text-muted"></p><label for="forceCloseCountedCash" class="form-label">النقدية المعدودة</label><input type="number" class="form-control" name="counted_cash" id="forceCloseCountedCash" step="0.01" min="0" required><label for="forceCloseReason" class="form-label mt-3">سبب الإغلاق الإجباري</label><textarea class="form-control" name="reason" id="forceCloseReason" rows="3" minlength="3" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-danger">تأكيد الإغلاق الإجباري</button></div></form></div></div></div><?php endif; ?>

<?php if ($canResolveVariance): ?><div class="modal fade" id="resolveDrawerModal" dir="rtl" lang="ar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="do/do_resolve_drawer_session.php"><?= csrf_input('shift_resolve') ?><input type="hidden" name="drawer_session_id" id="resolveDrawerSessionId"><input type="hidden" name="variance_type" id="resolveVarianceType"><input type="hidden" name="variance_amount" id="resolveVarianceAmount"><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>"><div class="modal-header"><h5 class="modal-title">تسجيل سبب فرق الشيفت</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><label for="resolveReasonCode" class="form-label">سبب الفرق</label><select class="form-control" name="resolution_reason_code" id="resolveReasonCode" required><option value="" selected disabled>اختر السبب…</option><?php foreach($resolutionReasonLabels as $code=>$label): ?><option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select><label for="resolveNotes" class="form-label mt-3">تفاصيل إضافية</label><textarea class="form-control" name="resolution_notes" id="resolveNotes" rows="3"></textarea><p class="text-muted small mt-3 mb-0">سيُحفظ السبب والملاحظات مع الشيفت للرجوع إليهما لاحقًا.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary">حفظ المراجعة</button></div></form></div></div></div><?php endif; ?>

<?php if ($needsBaselineInit && $canSetBaseline): ?><div class="modal fade" id="openingBaselineModal" dir="rtl" lang="ar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="openingBaselineForm"><?= csrf_input('shift_baseline') ?><div class="modal-header"><h5 class="modal-title">تحديد الرصيد الأساسي لبداية الدرج</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><label for="openingBaselineAmount" class="form-label">النقدية المتوقعة في الدرج</label><input type="number" class="form-control" name="opening_float_baseline" id="openingBaselineAmount" step="0.01" min="0" required><div id="openingBaselineMessage" class="text-danger mt-2 d-none"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary">حفظ رصيد البداية</button></div></form></div></div></div><?php endif; ?>

<script>
(function () {
  const forceModal = document.getElementById('forceCloseDrawerModal');
  forceModal?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const sessionId = button?.getAttribute('data-session-id') || '';
    document.getElementById('forceCloseDrawerSessionId').value = sessionId;
    document.getElementById('forceCloseDrawerUserLabel').textContent = 'الشيفت الخاص بـ ' + (button?.getAttribute('data-user-name') || '');
    document.getElementById('forceCloseIdempotencyKey').value = 'force-close:' + sessionId + ':' + ((crypto.randomUUID && crypto.randomUUID()) || Date.now());
  });
  document.getElementById('resolveDrawerModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    document.getElementById('resolveDrawerSessionId').value = button?.getAttribute('data-session-id') || '';
    document.getElementById('resolveVarianceType').value = button?.getAttribute('data-variance-type') || '';
    document.getElementById('resolveVarianceAmount').value = button?.getAttribute('data-variance-amount') || '';
  });
  function postForm(form, url, messageId) {
    const message = document.getElementById(messageId);
    fetch(url, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:new URLSearchParams(new FormData(form)).toString()})
      .then(function (response) { return response.json(); })
      .then(function (payload) { if (!payload?.success) throw new Error(payload?.error || 'تعذر الحفظ'); window.location.reload(); })
      .catch(function (error) { if (message) { message.textContent = error.message; message.classList.remove('d-none'); } });
  }
  document.getElementById('businessDayCutoffForm')?.addEventListener('submit', function (event) { event.preventDefault(); postForm(event.target, 'do/do_set_business_day_cutoff.php', 'businessDayCutoffMessage'); });
  document.getElementById('openingBaselineForm')?.addEventListener('submit', function (event) {
    event.preventDefault();
    const key = document.createElement('input'); key.type='hidden'; key.name='idempotency_key'; key.value='opening-baseline:' + ((crypto.randomUUID && crypto.randomUUID()) || Date.now()); event.target.appendChild(key);
    postForm(event.target, 'do/do_set_opening_float_baseline.php', 'openingBaselineMessage');
  });
})();
</script>
<?php include('includes/footer.php') ?>
