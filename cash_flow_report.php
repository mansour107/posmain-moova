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

page_guard(null, $conn);
posmain_send_no_store_headers();

$canReport = auth_guard_has_permission('reports.cash_flow', $conn);
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
if (!$canReport && in_array($context['tab'], [$tabOverview, $tabMovements], true)) {
    $context['tab'] = $tabShifts;
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
$returnTo = $workspaceUrl();

$drawerSessionId = (int) ($_GET['drawer_session_id'] ?? 0);
if ($drawerSessionId > 0) {
    header('Location: ' . posmain_cash_shift_detail_url($drawerSessionId, $returnTo));
    exit;
}

$periodFilters = $workspace->periodFilters($context);
$cashFlow = new CashFlowPeriodService();
$summary = $canReport ? $cashFlow->summary($conn, $periodFilters) : [];
$payments = $canReport ? $cashFlow->paymentBreakdown($conn, $periodFilters) : [];
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
    'sale_cash' => 'مبيعات نقدية', 'refund_cash' => 'مرتجعات نقدية', 'paid_in' => 'إيداعات',
    'paid_out' => 'مصروفات', 'safe_drop' => 'تحويل للخزنة', 'opening' => 'افتتاح',
    'closing_adjustment' => 'تسوية إغلاق', 'no_sale' => 'فتح بدون بيع',
];
$statusLabels = ['open' => 'مفتوح', 'closed' => 'مغلق', 'forced_closed' => 'مغلق قسرياً'];
$paymentTypeLabels = ['cash' => 'نقدي', 'card' => 'بطاقة', 'wallet' => 'محفظة', 'bank' => 'بنك', 'gift_card' => 'بطاقة هدايا', 'other' => 'أخرى'];
$varianceTypeLabels = ['opening' => 'افتتاح', 'closing' => 'إغلاق', 'both' => 'افتتاح + إغلاق', 'force_close' => 'إغلاق قسري', 'none' => '—'];
$formatTime = static function (?string $value): string {
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp ? date('H:i', $timestamp) : '—';
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
    <main class="premium-report cash-shift-workspace" id="cashShiftWorkspace">
      <header class="pr-hero cash-shift-hero">
        <div class="pr-hero-text">
          <p class="pr-eyebrow">إدارة النقد والتشغيل</p>
          <h1>النقد والورديات</h1>
          <p class="pr-hero-sub">مساحة واحدة لمتابعة الدرج، مراجعة الورديات وحركات النقد</p>
        </div>
        <?php if ($canViewShifts): ?>
        <a class="cash-shift-backlog" href="<?= htmlspecialchars($workspaceUrl(['tab' => $tabShifts, 'status' => 'needs_review', 'scope' => 'backlog', 'page' => null]), ENT_QUOTES, 'UTF-8') ?>">
          <span>يحتاج مراجعة</span><strong><?= (int) $backlogCount ?></strong><small>كل الفترات</small>
        </a>
        <?php endif; ?>
      </header>

      <?php if ($flashSuccess !== ''): ?><div class="pr-callout pr-callout--success" role="status"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>
      <?php if ($flashError !== ''): ?><div class="pr-callout pr-callout--danger" role="alert"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

      <nav class="cash-shift-tabs" aria-label="أقسام النقد والورديات">
        <?php if ($canReport): ?><a href="<?= htmlspecialchars($workspaceUrl(['tab' => $tabOverview, 'page' => null]), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabOverview ? 'is-active' : '' ?>" <?= $context['tab'] === $tabOverview ? 'aria-current="page"' : '' ?>><i class="fas fa-chart-line"></i> نظرة عامة</a><?php endif; ?>
        <?php if ($canViewShifts): ?><a href="<?= htmlspecialchars($workspaceUrl(['tab' => $tabShifts, 'page' => null]), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabShifts ? 'is-active' : '' ?>" <?= $context['tab'] === $tabShifts ? 'aria-current="page"' : '' ?>><i class="fas fa-cash-register"></i> الشيفتات</a><?php endif; ?>
        <?php if ($canReport): ?><a href="<?= htmlspecialchars($workspaceUrl(['tab' => $tabMovements, 'page' => null]), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabMovements ? 'is-active' : '' ?>" <?= $context['tab'] === $tabMovements ? 'aria-current="page"' : '' ?>><i class="fas fa-exchange-alt"></i> حركات النقد</a><?php endif; ?>
        <?php if ($canConfigureBusinessDay || $canSetBaselinePermission): ?><a href="<?= htmlspecialchars($workspaceUrl(['tab' => $tabSettings, 'page' => null]), ENT_QUOTES, 'UTF-8') ?>" class="cash-shift-tab <?= $context['tab'] === $tabSettings ? 'is-active' : '' ?>" <?= $context['tab'] === $tabSettings ? 'aria-current="page"' : '' ?>><i class="fas fa-sliders-h"></i> الإعدادات</a><?php endif; ?>
      </nav>

      <?php if ($context['tab'] !== $tabSettings): ?>
      <section class="pr-panel pr-no-print cash-shift-filter-panel">
        <?php if ($context['scope'] !== 'backlog'): ?>
        <nav class="cash-shift-date-presets" aria-label="اختيار سريع للفترة">
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
          <?php if ($context['tab'] === $tabShifts && $context['status'] !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($context['status']) ?>"><?php endif; ?>
          <?php if ($context['tab'] === $tabShifts && $context['scope'] === 'backlog'): ?><input type="hidden" name="scope" value="backlog"><?php endif; ?>
          <div class="pr-field"><label for="date_from">من يوم عمل</label><input type="date" id="date_from" name="date_from" class="form-control" value="<?= htmlspecialchars($context['date_from']) ?>" <?= $context['scope'] === 'backlog' ? 'disabled' : '' ?>></div>
          <div class="pr-field"><label for="date_to">إلى يوم عمل</label><input type="date" id="date_to" name="date_to" class="form-control" value="<?= htmlspecialchars($context['date_to']) ?>" <?= $context['scope'] === 'backlog' ? 'disabled' : '' ?>></div>
          <div class="pr-field"><label for="cashier_id">الكاشير</label><select id="cashier_id" name="cashier_id" class="form-control"><option value="0">الكل</option><?php foreach ($cashiers as $cashier): ?><option value="<?= (int) $cashier['id'] ?>" <?= $context['cashier_id'] === (int) $cashier['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($cashier['display_name'] ?: $cashier['uname'])) ?></option><?php endforeach; ?></select></div>
          <?php if ($context['tab'] === $tabShifts): ?><div class="pr-field"><label for="override_operator_id">مشغّل مؤقت</label><select id="override_operator_id" name="override_operator_id" class="form-control"><option value="0">الكل</option><?php foreach ($cashiers as $cashier): ?><option value="<?= (int) $cashier['id'] ?>" <?= $context['override_operator_id'] === (int) $cashier['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($cashier['display_name'] ?: $cashier['uname'])) ?></option><?php endforeach; ?></select></div><?php endif; ?>
          <div class="pr-field pr-field--submit"><label>&nbsp;</label><button type="submit" class="pr-btn pr-btn-primary w-100"><i class="fas fa-filter"></i> تطبيق الفلاتر</button></div>
        </form>
      </section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabOverview && $canReport): ?>
      <div class="pr-verdict cash-shift-kpis">
        <div class="pr-verdict-card pr-verdict-card--hero pr-verdict-card--<?= $varianceClass ?>"><div class="pr-verdict-label"><?= $varianceLabel ?></div><div class="pr-verdict-value pr-verdict-value--<?= $varianceClass ?>"><?= number_format($varianceRollup, 2) ?></div></div>
        <div class="pr-verdict-card"><div class="pr-verdict-label">المتوقع في الأدراج</div><div class="pr-verdict-value"><?= number_format($expectedRollup, 2) ?></div></div>
        <div class="pr-verdict-card"><div class="pr-verdict-label">المعدود</div><div class="pr-verdict-value"><?= number_format($countedRollup, 2) ?></div></div>
        <div class="pr-verdict-card"><div class="pr-verdict-label">الورديات في الفترة</div><div class="pr-verdict-value"><?= $sessionCount ?></div></div>
      </div>
      <p class="pr-verdict-sub">الفترة من <?= htmlspecialchars($context['date_from']) ?> إلى <?= htmlspecialchars($context['date_to']) ?> · نفس النطاق المستخدم في تبويب الشيفتات</p>

      <?php $byType = $payments['by_type'] ?? []; ?>
      <?php if (array_sum(array_map('floatval', $byType)) != 0.0): ?><div class="pr-mix"><?php foreach ($byType as $type => $amount): if ((float) $amount == 0.0) continue; ?><div class="pr-mix-chip"><span><?= htmlspecialchars($paymentTypeLabels[$type] ?? $type) ?></span><strong><?= number_format((float) $amount, 2) ?></strong></div><?php endforeach; ?></div><?php endif; ?>

      <section class="pr-panel cash-walk-panel">
        <div class="pr-panel-head"><div><p class="pr-eyebrow">مسار الرصيد</p><h2 class="pr-panel-title">كيف تحرك النقد؟</h2></div><a class="pr-btn pr-btn-ghost" href="<?= htmlspecialchars($workspaceUrl(['tab' => $tabMovements, 'page' => null]), ENT_QUOTES, 'UTF-8') ?>">عرض كل الحركات</a></div>
        <div class="pr-panel-body cash-walk-grid">
          <?php foreach ([['opening','+'],['sale_cash','+'],['refund_cash','−'],['paid_in','+'],['paid_out','−'],['safe_drop','−']] as [$type,$sign]): $amount=(float)($movementTotals[$type]??0); ?>
          <div class="cash-walk-step"><span><?= htmlspecialchars($movementLabels[$type]) ?></span><strong class="<?= $sign === '+' ? 'is-add' : 'is-sub' ?>"><?= $sign ?> <?= number_format($amount, 2) ?></strong></div>
          <?php endforeach; ?>
          <div class="cash-walk-step is-result"><span>المتوقع</span><strong><?= number_format($expectedRollup, 2) ?></strong></div>
        </div>
      </section>
      <?php if ($backlogCount > 0): ?><div class="pr-callout pr-callout--warn"><strong><?= $backlogCount ?> وردية تحتاج مراجعة عبر كل الفترات.</strong> هذا العدد لا يتقيد بتواريخ النظرة العامة. <a href="<?= htmlspecialchars($workspaceUrl(['tab'=>$tabShifts,'status'=>'needs_review','scope'=>'backlog','page'=>null]), ENT_QUOTES, 'UTF-8') ?>">فتح قائمة المراجعة</a></div><?php endif; ?>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabShifts && $canViewShifts): ?>
      <?php if ($context['scope'] === 'backlog'): ?><div class="pr-callout pr-callout--warn cash-shift-scope-note" role="status"><strong>نطاق المراجعة: كل الفترات.</strong> حقول التاريخ معطلة عمداً حتى لا يبدو هذا العدد جزءاً من الفترة المحددة. <a href="<?= htmlspecialchars($workspaceUrl(['scope'=>null,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>">العودة لنطاق الفترة</a></div><?php endif; ?>
      <section class="pr-panel">
        <div class="pr-panel-head"><div><p class="pr-eyebrow"><?= $context['scope'] === 'backlog' ? 'كل الفترات' : 'الفترة المحددة' ?></p><h2 class="pr-panel-title">جلسات الدرج</h2></div><span class="pr-pill pr-pill--muted"><?= (int) $shiftsPage['total'] ?> جلسة</span></div>
        <div class="pr-panel-body">
          <div class="pr-session-toolbar"><div class="pr-chip-filters" role="group" aria-label="تصفية حالة الشيفت">
            <?php foreach (['all'=>'الكل','open'=>'مفتوح','closed'=>'مغلق','needs_review'=>'يحتاج مراجعة','forced_closed'=>'مغلق قسرياً'] as $key=>$label): ?>
            <a href="<?= htmlspecialchars($workspaceUrl(['status'=>$key==='all'?null:$key,'scope'=>$key==='needs_review'&&$context['scope']==='backlog'?'backlog':null,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>" class="pr-chip-filter <?= $context['status'] === $key ? 'is-active' : '' ?>"><?= htmlspecialchars($label) ?><?= $key === 'needs_review' ? ' ('.$backlogCount.')' : '' ?></a>
            <?php endforeach; ?>
            <a href="<?= htmlspecialchars($workspaceUrl(['has_override'=>$context['has_override']?null:1,'page'=>null]), ENT_QUOTES, 'UTF-8') ?>" class="pr-chip-filter <?= $context['has_override'] ? 'is-active' : '' ?>"><i class="fas fa-user-clock"></i> تشغيل مؤقت</a>
          </div><div class="pr-session-meta">صفحة <?= (int) $shiftsPage['page'] ?> من <?= (int) $shiftsPage['pages'] ?></div></div>
          <div class="pr-table-wrap pr-table-scroll"><table class="pr-table pr-table--compact cash-shift-table"><thead><tr><th>الكاشير</th><th>يوم العمل</th><th>الفترة</th><th>المتوقع</th><th>الفرق</th><th>المساءلة والعهدة</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
          <?php foreach ($shiftsPage['rows'] as $session):
              $isOpen = ($session['status'] ?? '') === 'open';
              $variance = $session['close_variance'] ?? $session['difference'] ?? null;
              $expected = (float) ($session['close_expected_snapshot'] ?? $session['expected_cash'] ?? 0);
              $needsReview = ($session['variance_status'] ?? '') === 'unresolved';
          ?>
          <tr class="<?= $needsReview ? 'pr-row-variance' : '' ?>">
            <td><span class="pr-pill pr-pill--user"><?= htmlspecialchars((string) ($session['user_name'] ?? '')) ?></span><?php if (!empty($session['has_override'])): ?> <span class="pr-pill pr-pill--warn">تشغيل مؤقت</span><?php endif; ?></td>
            <td><?= htmlspecialchars((string) ($session['business_day'] ?? '—')) ?></td>
            <td><?= htmlspecialchars($formatTime($session['opened_at'] ?? null)) ?> — <?= $isOpen ? 'الآن' : htmlspecialchars($formatTime($session['closed_at'] ?? null)) ?></td>
            <td><?= number_format($expected, 2) ?></td>
            <td><?php if ($isOpen || !empty($session['count_pending'])): ?><span class="pr-pill pr-pill--muted"><?= $isOpen ? 'لم يغلق' : 'بانتظار العد' ?></span><?php else: ?><span class="pr-pill <?= (float)$variance < 0 ? 'pr-pill--expense' : ((float)$variance > 0 ? 'pr-pill--money' : 'pr-pill--muted') ?>"><?= number_format((float)$variance, 2) ?></span><?php endif; ?></td>
            <td data-testid="cash-shift-accountability">
              <div><small>العد:</small> <?= htmlspecialchars((string) ($session['counted_by_name'] ?? '') ?: '—') ?></div>
              <div><small>الإغلاق:</small> <?= htmlspecialchars((string) ($session['closed_by_name'] ?? '') ?: '—') ?></div>
              <?php if (!empty($session['takeover_authorized_by_user_id'])): ?><div><small>الاعتماد:</small> <?= htmlspecialchars((string) ($session['takeover_authorized_by_name'] ?? '') ?: ('مستخدم #' . (int) $session['takeover_authorized_by_user_id'])) ?></div><?php endif; ?>
              <?php if (!empty($session['succeeding_session_id'])): ?><div><small>انتقلت إلى:</small> <a href="<?= htmlspecialchars(posmain_cash_shift_detail_url((int) $session['succeeding_session_id'], $returnTo), ENT_QUOTES, 'UTF-8') ?>">#<?= (int) $session['succeeding_session_id'] ?> <?= htmlspecialchars((string) ($session['succeeding_shift_owner_name'] ?? '')) ?></a></div><?php endif; ?>
              <?php if (!empty($session['preceding_session_id'])): ?><div><small>استلمت من:</small> <a href="<?= htmlspecialchars(posmain_cash_shift_detail_url((int) $session['preceding_session_id'], $returnTo), ENT_QUOTES, 'UTF-8') ?>">#<?= (int) $session['preceding_session_id'] ?> <?= htmlspecialchars((string) ($session['preceding_shift_owner_name'] ?? '')) ?></a></div><?php endif; ?>
            </td>
            <td><span class="pr-pill <?= $isOpen ? 'pr-pill--status-open pr-pill--pulse' : 'pr-pill--status-closed' ?>"><?= htmlspecialchars($statusLabels[$session['status']] ?? $session['status']) ?></span><?php if ($needsReview): ?> <span class="pr-pill pr-pill--warn"><?= htmlspecialchars($varianceTypeLabels[$session['variance_type'] ?? 'none'] ?? '') ?></span><?php endif; ?></td>
            <td><div class="pr-actions cash-shift-actions"><a class="pr-btn pr-btn-ghost" data-testid="session-detail-link" href="<?= htmlspecialchars(posmain_cash_shift_detail_url((int)$session['id'], $returnTo), ENT_QUOTES, 'UTF-8') ?>">عرض التفاصيل</a><?php if (!$isOpen): ?><a class="pr-btn pr-btn-ghost" target="_blank" href="print/closed_session_receipt.php?id=<?= (int)$session['id'] ?>">طباعة Z</a><?php endif; ?><?php if ($isOpen && $canForceClose): ?><button type="button" class="pr-btn pr-btn-danger" data-bs-toggle="modal" data-bs-target="#forceCloseDrawerModal" data-session-id="<?= (int)$session['id'] ?>" data-user-name="<?= htmlspecialchars((string)($session['user_name']??''), ENT_QUOTES, 'UTF-8') ?>">إغلاق قسري</button><?php endif; ?><?php if ($needsReview && $canResolveVariance): ?><button type="button" class="pr-btn pr-btn-primary" data-bs-toggle="modal" data-bs-target="#resolveDrawerModal" data-session-id="<?= (int)$session['id'] ?>" data-variance-type="<?= htmlspecialchars((string)($session['variance_type']??'closing'), ENT_QUOTES, 'UTF-8') ?>" data-variance-amount="<?= htmlspecialchars(number_format((float)$variance,3,'.',''), ENT_QUOTES, 'UTF-8') ?>">حل الفرق</button><?php endif; ?></div></td>
          </tr>
          <?php endforeach; ?>
          <?php if ($shiftsPage['rows'] === []): ?><tr><td colspan="8"><div class="cash-shift-empty"><i class="fas fa-inbox"></i><strong>لا توجد جلسات مطابقة</strong><span>جرّب تغيير الحالة أو نطاق التاريخ.</span></div></td></tr><?php endif; ?>
          </tbody></table></div>
          <?php if ($shiftsPage['pages'] > 1): ?><nav class="pr-pagination" aria-label="صفحات الشيفتات"><a class="pr-btn pr-btn-ghost <?= $shiftsPage['page']<=1?'disabled':'' ?>" href="<?= htmlspecialchars($workspaceUrl(['page'=>max(1,$shiftsPage['page']-1)]), ENT_QUOTES, 'UTF-8') ?>">السابق</a><span>صفحة <?= (int)$shiftsPage['page'] ?> من <?= (int)$shiftsPage['pages'] ?></span><a class="pr-btn pr-btn-ghost <?= $shiftsPage['page']>=$shiftsPage['pages']?'disabled':'' ?>" href="<?= htmlspecialchars($workspaceUrl(['page'=>min($shiftsPage['pages'],$shiftsPage['page']+1)]), ENT_QUOTES, 'UTF-8') ?>">التالي</a></nav><?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabMovements && $canReport): ?>
      <section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">دفتر الدرج</p><h2 class="pr-panel-title">حركات النقد</h2></div><span class="pr-pill pr-pill--muted"><?= (int)($movements['total']??0) ?> حركة</span></div><div class="pr-panel-body"><form method="get" class="pr-collapse-filters"><input type="hidden" name="tab" value="movements"><input type="hidden" name="date_from" value="<?= htmlspecialchars($context['date_from']) ?>"><input type="hidden" name="date_to" value="<?= htmlspecialchars($context['date_to']) ?>"><input type="hidden" name="cashier_id" value="<?= (int)$context['cashier_id'] ?>"><div class="pr-field"><label for="movement_type">نوع الحركة</label><select id="movement_type" name="movement_type" class="form-control"><option value="">الكل</option><?php foreach($movementLabels as $key=>$label): ?><option value="<?= htmlspecialchars($key) ?>" <?= $context['movement_type']===$key?'selected':'' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div><button class="pr-btn pr-btn-primary" type="submit">تصفية الحركات</button></form><div class="pr-table-wrap pr-table-scroll"><table class="pr-table pr-table--compact"><thead><tr><th>الوقت</th><th>النوع</th><th>المبلغ</th><th>الجلسة</th><th>الطلب</th><th>المستخدم</th><th>اعتماد المدير</th><th>البيان</th></tr></thead><tbody><?php foreach(($movements['rows']??[]) as $row): ?><tr class="<?= !empty($row['is_unassigned'])?'pr-row-warn':'' ?>"><td><?= htmlspecialchars((string)$row['created_at']) ?></td><td><?= htmlspecialchars($movementLabels[$row['movement_type']]??$row['movement_type']) ?></td><td><span class="pr-pill pr-pill--money"><?= number_format((float)$row['amount'],2) ?></span></td><td><?php if($row['drawer_session_id']!==null): ?><a href="<?= htmlspecialchars(posmain_cash_shift_detail_url((int)$row['drawer_session_id'],$returnTo), ENT_QUOTES, 'UTF-8') ?>">جلسة #<?= (int)$row['drawer_session_id'] ?></a><?php else: ?><span class="pr-pill pr-pill--warn">غير مربوطة</span><?php endif; ?></td><td><?= $row['order_id']!==null?(int)$row['order_id']:'—' ?></td><td><?= htmlspecialchars((string)($row['created_by_name']??'')) ?></td><td><?php if(!empty($row['manager_approval_id'])): ?><span class="pr-pill pr-pill--status-closed">#<?= (int)$row['manager_approval_id'] ?></span> <?= htmlspecialchars((string)($row['manager_approved_by_name']??'') ?: ('مستخدم #' . (int)($row['manager_approved_by_user_id']??0))) ?><?php else: ?>—<?php endif; ?></td><td><?= htmlspecialchars((string)($row['reason']??'—')) ?></td></tr><?php endforeach; ?><?php if(($movements['rows']??[])===[]): ?><tr><td colspan="8" class="text-center text-muted py-4">لا توجد حركات في هذه الفترة</td></tr><?php endif; ?></tbody></table></div></div></section>
      <?php endif; ?>

      <?php if ($context['tab'] === $tabSettings): ?>
      <div class="cash-shift-settings-grid">
        <?php if ($canConfigureBusinessDay): ?><section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">التوقيت المحاسبي</p><h2 class="pr-panel-title">يوم العمل</h2></div><span class="pr-pill pr-pill--muted">اليوم <?= htmlspecialchars((string)$businessDayContext['current_business_day']) ?></span></div><div class="pr-panel-body"><p class="text-muted">العمليات قبل ساعة القطع تُحتسب ضمن يوم العمل السابق.</p><form id="businessDayCutoffForm" class="cash-shift-setting-form"><?= csrf_input('business_day_cutoff') ?><input type="hidden" name="pos_tenant" value="<?= $tenant ?>"><input type="hidden" name="pos_branch" value="<?= $branch ?>"><div class="pr-field"><label for="businessDayCutoffHour">ساعة القطع (0–23)</label><input type="number" class="form-control" name="business_day_cutoff_hour" id="businessDayCutoffHour" min="0" max="23" value="<?= (int)$businessDayContext['cutoff_hour'] ?>" required></div><button class="pr-btn pr-btn-primary" type="submit">حفظ ساعة القطع</button><div id="businessDayCutoffMessage" class="text-danger d-none"></div></form></div></section><?php endif; ?>
        <section class="pr-panel"><div class="pr-panel-head"><div><p class="pr-eyebrow">عهدة البداية</p><h2 class="pr-panel-title">الرصيد الافتتاحي</h2></div><?php if(!$needsBaselineInit): ?><span class="pr-pill pr-pill--status-closed">مهيأ</span><?php endif; ?></div><div class="pr-panel-body"><?php if($needsBaselineInit && $canSetBaseline): ?><p>حدّد المبلغ المتوقع قبل بدء أول شيفت في الفرع.</p><button class="pr-btn pr-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#openingBaselineModal">تهيئة الرصيد الافتتاحي</button><?php elseif($needsBaselineInit): ?><div class="pr-callout pr-callout--warn">يلزم مستخدم لديه صلاحية تهيئة الرصيد الافتتاحي.</div><?php else: ?><p class="text-muted mb-0">يُحسب المتوقع تلقائياً من آخر جلسة مغلقة وحركات العهدة.</p><?php endif; ?><?php if(abs((float)($openingExpectation['unassigned_net']??0))>0.0001): ?><div class="pr-callout pr-callout--warn mt-3">يوجد صافي نقد غير مربوط بجلسة: <?= number_format(abs((float)$openingExpectation['unassigned_net']),2) ?></div><?php endif; ?></div></section>
      </div>
      <?php endif; ?>
    </main>
  </section>
</div>

<?php if ($canForceClose): ?><div class="modal fade" id="forceCloseDrawerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="do/do_force_close_drawer.php" id="forceCloseDrawerForm"><?= csrf_input('shift_close') ?><input type="hidden" name="drawer_session_id" id="forceCloseDrawerSessionId"><input type="hidden" name="idempotency_key" id="forceCloseIdempotencyKey"><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>"><div class="modal-header"><h5 class="modal-title">إغلاق قسري للدرج</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><p id="forceCloseDrawerUserLabel" class="text-muted"></p><label for="forceCloseCountedCash" class="form-label">المبلغ المعدود</label><input type="number" class="form-control" name="counted_cash" id="forceCloseCountedCash" step="0.01" min="0" required><label for="forceCloseReason" class="form-label mt-3">سبب الإغلاق</label><textarea class="form-control" name="reason" id="forceCloseReason" rows="3" minlength="3" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-danger">تأكيد الإغلاق القسري</button></div></form></div></div></div><?php endif; ?>

<?php if ($canResolveVariance): ?><div class="modal fade" id="resolveDrawerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="do/do_resolve_drawer_session.php"><?= csrf_input('shift_resolve') ?><input type="hidden" name="drawer_session_id" id="resolveDrawerSessionId"><input type="hidden" name="variance_type" id="resolveVarianceType"><input type="hidden" name="variance_amount" id="resolveVarianceAmount"><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>"><div class="modal-header"><h5 class="modal-title">حل فرق الوردية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><label for="resolveReasonCode" class="form-label">سبب الفرق</label><select class="form-control" name="resolution_reason_code" id="resolveReasonCode" required><option value="" selected disabled>اختر السبب…</option><?php foreach(ShiftCountService::resolutionReasonCodes() as $code=>$label): ?><option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select><label for="resolveNotes" class="form-label mt-3">تفاصيل إضافية</label><textarea class="form-control" name="resolution_notes" id="resolveNotes" rows="3"></textarea><p class="text-muted small mt-3 mb-0">عند التأكيد يُسجَّل الفرق تلقائياً في الحسابات ضمن حساب فروقات عد الدرج.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary">تأكيد الحل</button></div></form></div></div></div><?php endif; ?>

<?php if ($needsBaselineInit && $canSetBaseline): ?><div class="modal fade" id="openingBaselineModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="openingBaselineForm"><?= csrf_input('shift_baseline') ?><div class="modal-header"><h5 class="modal-title">تهيئة الرصيد الافتتاحي</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><label for="openingBaselineAmount" class="form-label">المبلغ المتوقع في الدرج</label><input type="number" class="form-control" name="opening_float_baseline" id="openingBaselineAmount" step="0.01" min="0" required><div id="openingBaselineMessage" class="text-danger mt-2 d-none"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary">حفظ الرصيد</button></div></form></div></div></div><?php endif; ?>

<script>
(function () {
  const forceModal = document.getElementById('forceCloseDrawerModal');
  forceModal?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const sessionId = button?.getAttribute('data-session-id') || '';
    document.getElementById('forceCloseDrawerSessionId').value = sessionId;
    document.getElementById('forceCloseDrawerUserLabel').textContent = 'جلسة ' + (button?.getAttribute('data-user-name') || '');
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
