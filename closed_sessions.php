<?php
require_once __DIR__ . '/includes/auth_guard.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/csrf.php';
page_guard('pos.shift.close', $conn);
?>
<?php require_once __DIR__ . '/includes/pos_cache_control.php'; posmain_send_no_store_headers(); ?>
<?php
$premiumCssVer = is_file(__DIR__ . '/css/premium-report-light.css')
    ? (string) filemtime(__DIR__ . '/css/premium-report-light.css')
    : '1';
?>
<?php
// Mint before header.php session_write_close() so modal POSTs can verify tokens.
csrf_token('shift_resolve');
csrf_token('shift_close');
csrf_token('shift_baseline');
csrf_token('business_day_cutoff');
include('includes/header.php');
?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<script>document.body.classList.add('premium-report-page');</script>
<link rel="stylesheet" href="css/premium-report-light.css?v=<?= htmlspecialchars($premiumCssVer, ENT_QUOTES, 'UTF-8') ?>">

<div class="content-wrapper">
  <section class="content">
    <div class="premium-report">
      <header class="pr-hero">
        <div class="pr-hero-text">
          <p class="pr-eyebrow">إدارة الورديات</p>
          <h1>الشيفتات المغلقة</h1>
          <p class="pr-hero-sub">سجل إغلاق الورديات والمبيعات والتسليم النقدي</p>
        </div>
      </header>

      <?php if (isset($_SESSION['success_message'])): ?>
      <div class="pr-callout pr-callout--success" role="alert">
        <i class="fas fa-check-circle"></i>
        <?= htmlspecialchars((string) $_SESSION['success_message']) ?>
      </div>
      <?php unset($_SESSION['success_message']); endif; ?>

      <?php if (isset($_SESSION['error_message'])): ?>
      <div class="pr-callout pr-callout--danger" role="alert">
        <i class="fas fa-exclamation-triangle"></i>
        <?= htmlspecialchars((string) $_SESSION['error_message']) ?>
      </div>
      <?php unset($_SESSION['error_message']); endif; ?>

      <?php
      require_once __DIR__ . '/classes/Pos/Service/DrawerFloatExpectationService.php';
      require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php';
      require_once __DIR__ . '/includes/business_day.php';
      $floatService = new DrawerFloatExpectationService();
      $shiftCountService = new ShiftCountService();
      $businessDayContext = posmain_business_day_context($conn);
      $branchTenant = (int) $businessDayContext['tenant'];
      $branchId = (int) $businessDayContext['branch'];
      if ($branchTenant > 0 && empty($_SESSION['pos_tenant'])) {
          $_SESSION['pos_tenant'] = $branchTenant;
      }
      if ($branchId > 0 && empty($_SESSION['pos_branch'])) {
          $_SESSION['pos_branch'] = $branchId;
      }
      $canConfigureBusinessDay = auth_guard_has_permission('reports.cash_flow', $conn)
          || auth_guard_has_permission('pos.shift.close', $conn);
      $needsBaselineInit = $shiftCountService->handoverEnabled($conn)
          && $floatService->needsBaselineInitialization($conn, $branchTenant, $branchId);
      $canSetBaseline = auth_guard_has_permission('pos.shift.set_opening_baseline', $conn)
          && $floatService->canSetOpeningBaseline($conn, $branchTenant, $branchId);
      $openingExpectation = ($shiftCountService->handoverEnabled($conn) && $branchTenant > 0 && $branchId > 0)
          ? $floatService->expectedOpeningFloat($conn, $branchTenant, $branchId)
          : null;
      $unassignedNetPending = abs((float) ($openingExpectation['unassigned_net'] ?? 0));
      ?>

      <?php if ($unassignedNetPending > 0.0001): ?>
      <div class="pr-callout pr-callout--warn" role="alert">
        <i class="fas fa-exclamation-triangle"></i>
        يوجد نقد غير مربوط بجلسة درج (صافي <?= number_format($unassignedNetPending, 2) ?>)
        سيدخل في المتوقع عند فتح الشيفت التالي — راجع تقرير التدفق النقدي.
        <a href="cash_flow_report.php">فتح التقرير</a>
      </div>
      <?php endif; ?>

      <?php if ($canConfigureBusinessDay): ?>
      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">يوم العمل</h2>
          <span class="pr-pill pr-pill--muted">اليوم: <?= htmlspecialchars((string) $businessDayContext['current_business_day']) ?></span>
        </div>
        <div class="pr-panel-body">
          <p class="text-muted mb-3">
            أي عملية قبل ساعة القطع تُحسب ضمن يوم العمل السابق (مناسب للمطاعم بعد منتصف الليل).
            لا يفتح/يغلق يوم العمل يدوياً — فقط حد القطع.
          </p>
          <form id="businessDayCutoffForm" class="row g-2 align-items-end">
            <?= csrf_input('business_day_cutoff') ?>
            <input type="hidden" name="pos_tenant" value="<?= (int) $branchTenant ?>">
            <input type="hidden" name="pos_branch" value="<?= (int) $branchId ?>">
            <div class="col-md-4">
              <label class="form-label" for="businessDayCutoffHour">ساعة القطع (0–23)</label>
              <input
                type="number"
                class="form-control"
                name="business_day_cutoff_hour"
                id="businessDayCutoffHour"
                min="0"
                max="23"
                step="1"
                value="<?= (int) $businessDayContext['cutoff_hour'] ?>"
                required
              >
            </div>
            <div class="col-md-4">
              <button type="submit" class="pr-btn pr-btn-primary">حفظ حد يوم العمل</button>
            </div>
            <div class="col-12">
              <div id="businessDayCutoffMessage" class="text-danger mt-2 d-none"></div>
            </div>
          </form>
        </div>
      </section>
      <script>
      document.getElementById('businessDayCutoffForm')?.addEventListener('submit', function (event) {
        event.preventDefault();
        const form = event.target;
        const message = document.getElementById('businessDayCutoffMessage');
        const cutoff = form.business_day_cutoff_hour.value;
        const csrf = form.querySelector('[name="csrf_token"]')?.value || '';
        const tenant = form.pos_tenant?.value || '0';
        const branch = form.pos_branch?.value || '0';
        message.classList.add('d-none');
        fetch('do/do_set_business_day_cutoff.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: new URLSearchParams({
            business_day_cutoff_hour: cutoff,
            pos_tenant: tenant,
            pos_branch: branch,
            csrf_token: csrf,
          }).toString(),
        }).then(function (response) { return response.json(); })
          .then(function (payload) {
            if (!payload || !payload.success) {
              message.textContent = (payload && payload.error) || 'تعذر حفظ حد يوم العمل';
              message.classList.remove('d-none');
              return;
            }
            window.location.reload();
          })
          .catch(function () {
            message.textContent = 'خطأ في الاتصال';
            message.classList.remove('d-none');
          });
      });
      </script>
      <?php endif; ?>

      <?php if ($needsBaselineInit && $canSetBaseline): ?>
      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">تهيئة عهد الافتتاح</h2>
          <span class="pr-pill pr-pill--warn">مطلوب قبل أول شيفت</span>
        </div>
        <div class="pr-panel-body">
          <p class="text-muted mb-3">لا توجد جلسة مغلقة سابقة في هذا الفرع. حدّد المبلغ المتوقع في الدرج قبل بدء أول شيفت.</p>
          <button type="button" class="pr-btn pr-btn-primary" data-bs-toggle="modal" data-bs-target="#openingBaselineModal">
            تهيئة عهد الافتتاح
          </button>
        </div>
      </section>
      <?php elseif ($needsBaselineInit): ?>
      <div class="pr-callout pr-callout--warn" role="alert">
        <i class="fas fa-info-circle"></i>
        يتطلب المدير تهيئة عهد الافتتاح قبل أن يتمكن الكاشير من بدء أول شيفت.
      </div>
      <?php endif; ?>

      <?php
      $openDrawers = [];
      if (function_exists('posmain_drawer_sessions_table_exists') && posmain_drawer_sessions_table_exists($conn)) {
          // Match unresolvedSessions scope: include tenant/branch 0 (single-shop / unset POS scope).
          $openTenant = (int) ($_SESSION['pos_tenant'] ?? 0);
          $openBranch = (int) ($_SESSION['pos_branch'] ?? 0);
          $openStmt = $conn->prepare("SELECT ds.id, ds.user_id, ds.opened_at, u.uname, u.display_name
              FROM drawer_sessions ds
              INNER JOIN users u ON u.id = ds.user_id
              WHERE ds.status = 'open'
                AND ds.tenant = ?
                AND ds.branch = ?
              ORDER BY ds.opened_at DESC");
          if ($openStmt) {
              $openStmt->bind_param('ii', $openTenant, $openBranch);
              $openStmt->execute();
              $openRes = $openStmt->get_result();
              while ($dr = $openRes->fetch_assoc()) {
                  $openDrawers[] = $dr;
              }
              $openStmt->close();
          }
      }
      ?>

      <?php if ($openDrawers): ?>
      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">جلسات درج مفتوحة</h2>
        </div>
        <div class="pr-panel-body pr-table-wrap">
          <table class="pr-table">
            <thead>
              <tr><th>المستخدم</th><th>منذ</th><th>إجراء</th></tr>
            </thead>
            <tbody>
              <?php foreach ($openDrawers as $dr): ?>
              <tr>
                <td><span class="pr-pill pr-pill--user"><?= htmlspecialchars((string) (($dr['display_name'] ?? '') !== '' ? $dr['display_name'] : $dr['uname'])) ?></span></td>
                <td><?= htmlspecialchars((string) $dr['opened_at']) ?></td>
                <td class="d-flex gap-2 flex-wrap">
                  <a href="drawer_session.php?id=<?= (int) $dr['id'] ?>" class="pr-btn pr-btn-secondary">التفاصيل</a>
                  <?php if (auth_guard_has_permission('pos.shift.force_close', $conn)): ?>
                  <button type="button" class="pr-btn pr-btn-danger" data-bs-toggle="modal"
                          data-bs-target="#forceCloseDrawerModal"
                          data-session-id="<?= (int) $dr['id'] ?>"
                          data-user-name="<?= htmlspecialchars((string) (($dr['display_name'] ?? '') !== '' ? $dr['display_name'] : $dr['uname']), ENT_QUOTES, 'UTF-8') ?>">
                    إغلاق قسري
                  </button>
                  <?php else: ?>
                  <span class="pr-pill pr-pill--muted">يتطلب صلاحية مدير</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if (auth_guard_has_permission('pos.shift.force_close', $conn)): ?>
      <div class="modal fade" id="forceCloseDrawerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post" action="do/do_force_close_drawer.php" id="forceCloseDrawerForm">
              <?= csrf_input('shift_close') ?>
              <input type="hidden" name="drawer_session_id" id="forceCloseDrawerSessionId" value="">
              <input type="hidden" name="idempotency_key" id="forceCloseIdempotencyKey" value="">
              <div class="modal-header">
                <h5 class="modal-title">إغلاق قسري للدرج</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <p class="text-muted" id="forceCloseDrawerUserLabel"></p>
                <label class="form-label" for="forceCloseCountedCash">المبلغ المعدود في الدرج</label>
                <input type="number" class="form-control" name="counted_cash" id="forceCloseCountedCash" step="0.01" min="0" required>
                <label class="form-label mt-3" for="forceCloseReason">سبب الإغلاق</label>
                <textarea class="form-control" name="reason" id="forceCloseReason" rows="2" required minlength="3"></textarea>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-danger">إغلاق قسري</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <script>
      (function () {
        function newIdempotencyKey(sessionId) {
          const suffix = (window.crypto && crypto.randomUUID)
            ? crypto.randomUUID()
            : (Date.now().toString(36) + ':' + Math.random().toString(36).slice(2));
          return 'force-close:' + (sessionId || '0') + ':' + suffix;
        }
        document.getElementById('forceCloseDrawerModal')?.addEventListener('show.bs.modal', function (event) {
          const btn = event.relatedTarget;
          if (!btn) return;
          const sessionId = btn.getAttribute('data-session-id') || '';
          document.getElementById('forceCloseDrawerSessionId').value = sessionId;
          document.getElementById('forceCloseIdempotencyKey').value = newIdempotencyKey(sessionId);
          document.getElementById('forceCloseDrawerUserLabel').textContent =
            'جلسة: ' + (btn.getAttribute('data-user-name') || '');
          document.getElementById('forceCloseCountedCash').value = '';
          document.getElementById('forceCloseReason').value = '';
        });
      })();
      </script>
      <?php endif; ?>

      <?php if ($needsBaselineInit && $canSetBaseline): ?>
      <div class="modal fade" id="openingBaselineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form id="openingBaselineForm">
              <?= csrf_input('shift_baseline') ?>
              <div class="modal-header">
                <h5 class="modal-title">تهيئة عهد الافتتاح</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <label class="form-label" for="openingBaselineAmount">المبلغ المتوقع في الدرج (ج.م)</label>
                <input type="number" class="form-control" name="opening_float_baseline" id="openingBaselineAmount" step="0.01" min="0" required>
                <div id="openingBaselineMessage" class="text-danger mt-2 d-none"></div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <script>
      document.getElementById('openingBaselineForm')?.addEventListener('submit', function (event) {
        event.preventDefault();
        const form = event.target;
        const message = document.getElementById('openingBaselineMessage');
        const amount = form.opening_float_baseline.value;
        const csrf = form.querySelector('[name="csrf_token"]')?.value || '';
        const idempotencyKey = 'pos.shift.set_opening_baseline:' + (crypto.randomUUID ? crypto.randomUUID() : Date.now());

        message.classList.add('d-none');
        fetch('do/do_set_opening_float_baseline.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: new URLSearchParams({
            opening_float_baseline: amount,
            csrf_token: csrf,
            idempotency_key: idempotencyKey,
          }).toString(),
        }).then(function (response) { return response.json(); })
          .then(function (payload) {
            if (!payload || !payload.success) {
              message.textContent = (payload && payload.error) || 'تعذر حفظ العهد';
              message.classList.remove('d-none');
              return;
            }
            window.location.reload();
          })
          .catch(function () {
            message.textContent = 'خطأ في الاتصال';
            message.classList.remove('d-none');
          });
      });
      </script>
      <?php endif; ?>

      <?php
      $unresolvedTenant = (int) ($_SESSION['pos_tenant'] ?? 0);
      $unresolvedBranch = (int) ($_SESSION['pos_branch'] ?? 0);
      $unresolvedFilter = strtolower(trim((string) ($_GET['unresolved_type'] ?? 'all')));
      $allowedUnresolvedFilters = ['all', 'opening', 'closing', 'both', 'force_close'];
      if (!in_array($unresolvedFilter, $allowedUnresolvedFilters, true)) {
          $unresolvedFilter = 'all';
      }
      $unresolvedPage = max(1, (int) ($_GET['unresolved_page'] ?? 1));
      $unresolvedPerPage = 10;
      $unresolvedFilterOptions = $unresolvedFilter === 'all'
          ? []
          : ['variance_type' => $unresolvedFilter];
      $unresolvedTotal = 0;
      $unresolvedSessions = [];
      if ($shiftCountService->handoverEnabled($conn)) {
          $unresolvedTotal = $shiftCountService->countUnresolvedSessions(
              $conn,
              $unresolvedTenant,
              $unresolvedBranch,
              $unresolvedFilterOptions
          );
          $unresolvedTotalPages = max(1, (int) ceil($unresolvedTotal / $unresolvedPerPage));
          if ($unresolvedPage > $unresolvedTotalPages) {
              $unresolvedPage = $unresolvedTotalPages;
          }
          $unresolvedSessions = $shiftCountService->unresolvedSessions(
              $conn,
              $unresolvedTenant,
              $unresolvedBranch,
              $unresolvedPerPage,
              array_merge($unresolvedFilterOptions, [
                  'offset' => ($unresolvedPage - 1) * $unresolvedPerPage,
              ])
          );
      } else {
          $unresolvedTotalPages = 1;
      }
      $canResolveVariance = auth_guard_has_permission('pos.shift.resolve_variance', $conn);
      $closedSessionsHref = static function (array $overrides = []) use ($unresolvedFilter, $unresolvedPage): string {
          $params = [];
          $type = array_key_exists('unresolved_type', $overrides)
              ? $overrides['unresolved_type']
              : $unresolvedFilter;
          $page = array_key_exists('unresolved_page', $overrides)
              ? $overrides['unresolved_page']
              : $unresolvedPage;
          if ($type && $type !== 'all') {
              $params['unresolved_type'] = $type;
          }
          if ((int) $page > 1) {
              $params['unresolved_page'] = (int) $page;
          }
          $query = http_build_query($params);
          return 'closed_sessions.php' . ($query !== '' ? '?' . $query : '');
      };
      ?>

      <?php if ($unresolvedTotal > 0 || $unresolvedFilter !== 'all'): ?>
      <section class="pr-panel" data-testid="unresolved-queue-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">حالات تحتاج مراجعة</h2>
          <span class="pr-pill pr-pill--warn" data-testid="unresolved-queue-count"><?= (int) $unresolvedTotal ?> حالة</span>
        </div>
        <div class="pr-panel-body">
          <div class="pr-session-toolbar">
            <div class="pr-chip-filters" data-testid="unresolved-type-filters" role="group" aria-label="تصفية نوع الفرق">
              <a href="<?= htmlspecialchars($closedSessionsHref(['unresolved_type' => 'all', 'unresolved_page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $unresolvedFilter === 'all' ? 'is-active' : '' ?>"
                 data-testid="unresolved-filter-all">الكل</a>
              <a href="<?= htmlspecialchars($closedSessionsHref(['unresolved_type' => 'opening', 'unresolved_page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $unresolvedFilter === 'opening' ? 'is-active' : '' ?>"
                 data-testid="unresolved-filter-opening">افتتاح</a>
              <a href="<?= htmlspecialchars($closedSessionsHref(['unresolved_type' => 'closing', 'unresolved_page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $unresolvedFilter === 'closing' ? 'is-active' : '' ?>"
                 data-testid="unresolved-filter-closing">إغلاق</a>
              <a href="<?= htmlspecialchars($closedSessionsHref(['unresolved_type' => 'both', 'unresolved_page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $unresolvedFilter === 'both' ? 'is-active' : '' ?>"
                 data-testid="unresolved-filter-both">افتتاح + إغلاق</a>
              <a href="<?= htmlspecialchars($closedSessionsHref(['unresolved_type' => 'force_close', 'unresolved_page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                 class="pr-chip-filter <?= $unresolvedFilter === 'force_close' ? 'is-active' : '' ?>"
                 data-testid="unresolved-filter-force-close">إغلاق قسري</a>
            </div>
            <div class="pr-session-meta" data-testid="unresolved-queue-meta">
              <?= (int) $unresolvedTotal ?> حالة
              <?php if ($unresolvedTotalPages > 1): ?>
                · الصفحة <?= (int) $unresolvedPage ?> من <?= (int) $unresolvedTotalPages ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$unresolvedSessions): ?>
          <p class="text-muted mb-0" data-testid="unresolved-queue-empty">لا توجد حالات بهذا التصفية.</p>
          <?php else: ?>
          <div class="pr-table-wrap">
            <table class="pr-table">
              <thead>
                <tr>
                  <th>الكاشير</th>
                  <th>النوع</th>
                  <th>المتوقع</th>
                  <th>المعدود</th>
                  <th>الفرق</th>
                  <th>التاريخ</th>
                  <th>إجراء</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $varianceTypeLabels = [
                    'opening' => 'افتتاح',
                    'closing' => 'إغلاق',
                    'both' => 'افتتاح + إغلاق',
                    'force_close' => 'إغلاق قسري',
                    'none' => '—',
                ];
                foreach ($unresolvedSessions as $unresolved):
                    $type = (string) ($unresolved['variance_type'] ?? 'closing');
                    if ($type === 'opening') {
                        $varianceAmount = (float) ($unresolved['opening_variance'] ?? 0);
                        $expected = (float) ($unresolved['expected_opening_cash'] ?? 0);
                        $counted = (float) ($unresolved['opening_cash'] ?? 0);
                    } elseif ($type === 'both') {
                        $varianceAmount = round(
                            (float) ($unresolved['opening_variance'] ?? 0) + (float) ($unresolved['difference'] ?? 0),
                            3
                        );
                        $expected = (float) ($unresolved['close_expected_snapshot']
                            ?? $unresolved['expected_opening_cash']
                            ?? $unresolved['expected_cash']
                            ?? 0);
                        $counted = (float) ($unresolved['counted_cash'] ?? $unresolved['opening_cash'] ?? 0);
                    } else {
                        // closing / force_close: difference is pre-close over/short (never post-adjustment zero)
                        $varianceAmount = (float) ($unresolved['difference'] ?? 0);
                        $expected = (float) ($unresolved['close_expected_snapshot'] ?? $unresolved['expected_cash'] ?? 0);
                        $counted = (float) ($unresolved['counted_cash'] ?? 0);
                    }
                ?>
                <tr class="pr-row-variance">
                  <td><span class="pr-pill pr-pill--user"><?= htmlspecialchars((string) $unresolved['user_name']) ?></span></td>
                  <td><span class="pr-pill pr-pill--muted"><?= htmlspecialchars($varianceTypeLabels[$type] ?? $type) ?></span></td>
                  <td><?= number_format($expected, 2) ?></td>
                  <td><?= number_format($counted, 2) ?></td>
                  <td>
                    <span class="pr-pill <?= $varianceAmount >= 0 ? 'pr-pill--money' : 'pr-pill--expense' ?>">
                      <?= number_format($varianceAmount, 2) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars((string) ($unresolved['closed_at'] ?: $unresolved['opened_at'])) ?></td>
                  <td>
                    <div class="pr-actions">
                      <a href="drawer_session.php?id=<?= (int) $unresolved['id'] ?>" class="pr-icon-btn" title="التفاصيل">
                        <i class="fas fa-eye"></i>
                      </a>
                      <?php if ($canResolveVariance): ?>
                      <button type="button" class="pr-btn pr-btn-sm" data-bs-toggle="modal"
                              data-bs-target="#resolveDrawerModal"
                              data-session-id="<?= (int) $unresolved['id'] ?>"
                              data-variance-type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                              data-variance-amount="<?= htmlspecialchars(number_format($varianceAmount, 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                        حل
                      </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

          <?php if ($unresolvedTotalPages > 1): ?>
          <div class="pr-pagination" data-testid="unresolved-queue-pagination">
            <ul class="pagination mb-0">
              <li class="page-item <?= $unresolvedPage <= 1 ? 'disabled' : '' ?>">
                <a class="page-link"
                   href="<?= htmlspecialchars($closedSessionsHref(['unresolved_page' => max(1, $unresolvedPage - 1)]), ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="السابق">السابق</a>
              </li>
              <?php
              $windowStart = max(1, $unresolvedPage - 2);
              $windowEnd = min($unresolvedTotalPages, $unresolvedPage + 2);
              if ($windowStart > 1) {
                  echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($closedSessionsHref(['unresolved_page' => 1]), ENT_QUOTES, 'UTF-8') . '">1</a></li>';
                  if ($windowStart > 2) {
                      echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                  }
              }
              for ($i = $windowStart; $i <= $windowEnd; $i++) {
                  $active = $i === $unresolvedPage ? 'active' : '';
                  echo '<li class="page-item ' . $active . '"><a class="page-link" href="'
                      . htmlspecialchars($closedSessionsHref(['unresolved_page' => $i]), ENT_QUOTES, 'UTF-8')
                      . '">' . $i . '</a></li>';
              }
              if ($windowEnd < $unresolvedTotalPages) {
                  if ($windowEnd < $unresolvedTotalPages - 1) {
                      echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                  }
                  echo '<li class="page-item"><a class="page-link" href="'
                      . htmlspecialchars($closedSessionsHref(['unresolved_page' => $unresolvedTotalPages]), ENT_QUOTES, 'UTF-8')
                      . '">' . $unresolvedTotalPages . '</a></li>';
              }
              ?>
              <li class="page-item <?= $unresolvedPage >= $unresolvedTotalPages ? 'disabled' : '' ?>">
                <a class="page-link"
                   href="<?= htmlspecialchars($closedSessionsHref(['unresolved_page' => min($unresolvedTotalPages, $unresolvedPage + 1)]), ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="التالي">التالي</a>
              </li>
            </ul>
          </div>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($canResolveVariance): ?>
      <div class="modal fade" id="resolveDrawerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post" action="do/do_resolve_drawer_session.php">
              <?= csrf_input('shift_resolve') ?>
              <input type="hidden" name="drawer_session_id" id="resolveDrawerSessionId" value="">
              <input type="hidden" name="variance_type" id="resolveVarianceType" value="">
              <input type="hidden" name="variance_amount" id="resolveVarianceAmount" value="">
              <div class="modal-header">
                <h5 class="modal-title">حل حالة فرق</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <?php require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php'; ?>
                <label class="form-label" for="resolveReasonCode">سبب الفرق (مطلوب)</label>
                <select class="form-control mb-3" name="resolution_reason_code" id="resolveReasonCode" required>
                  <option value="" selected disabled>اختر السبب…</option>
                  <?php foreach (ShiftCountService::resolutionReasonCodes() as $resolveReasonCode => $resolveReasonLabel): ?>
                  <option value="<?= htmlspecialchars($resolveReasonCode, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($resolveReasonLabel) ?></option>
                  <?php endforeach; ?>
                </select>
                <label class="form-label" for="resolveNotes">تفاصيل إضافية <span id="resolveNotesOptional">(اختياري)</span></label>
                <textarea class="form-control" name="resolution_notes" id="resolveNotes" rows="3" placeholder="أي توضيح يساعد عند مراجعة هذه الجلسة لاحقاً"></textarea>
                <p class="text-muted mt-3 mb-0" style="font-size:0.85rem">
                  عند التأكيد يُسجَّل الفرق تلقائياً في الحسابات (حساب فروقات عد الدرج) حتى يطابق رصيد الصندوق النقد الفعلي.
                </p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">تأكيد الحل</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <script>
      document.getElementById('resolveDrawerModal')?.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        if (!btn) return;
        document.getElementById('resolveDrawerSessionId').value = btn.getAttribute('data-session-id') || '';
        document.getElementById('resolveVarianceType').value = btn.getAttribute('data-variance-type') || '';
        document.getElementById('resolveVarianceAmount').value = btn.getAttribute('data-variance-amount') || '';
        document.getElementById('resolveNotes').value = '';
        const reasonSelect = document.getElementById('resolveReasonCode');
        if (reasonSelect) reasonSelect.selectedIndex = 0;
      });
      (function () {
        const reasonSelect = document.getElementById('resolveReasonCode');
        const notesField = document.getElementById('resolveNotes');
        const optionalTag = document.getElementById('resolveNotesOptional');
        if (!reasonSelect || !notesField) return;
        reasonSelect.addEventListener('change', function () {
          const isOther = reasonSelect.value === 'other';
          notesField.required = isOther;
          if (optionalTag) optionalTag.textContent = isOther ? '(مطلوب — اكتب التفاصيل)' : '(اختياري)';
        });
      })();
      </script>
      <?php endif; ?>

      <?php
      $records_per_page = 20;
      $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
      $page = max(1, $page);
      $offset = ($page - 1) * $records_per_page;

      $total_count = (int) $conn->query('SELECT COUNT(*) as count FROM closed_orders')->fetch_assoc()['count'];
      $total_pages = (int) ceil($total_count / $records_per_page);

      $x = $total_count - $offset;
      $res_closed = $conn->query("SELECT * FROM closed_orders ORDER BY id DESC LIMIT $offset, $records_per_page");
      ?>

      <section class="pr-panel">
        <div class="pr-panel-head">
          <h2 class="pr-panel-title">سجل الشيفتات</h2>
          <span class="pr-pill pr-pill--muted"><?= (int) $total_count ?> شيفت</span>
        </div>
        <div class="pr-panel-body pr-table-wrap">
          <table class="pr-table">
            <thead>
              <tr>
                <th>الشيفت</th>
                <th>التاريخ</th>
                <th>المستخدم</th>
                <th>وقت الانتهاء</th>
                <th>إجمالي المبيعات</th>
                <th>المصاريف</th>
                <th>بيان المصاريف</th>
                <th>تسليم الكاش</th>
                <th>نهاية الدرج</th>
                <th>ملاحظات</th>
                <th>الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($rowcl = $res_closed->fetch_assoc()): ?>
              <tr>
                <td><strong><?= (int) $x ?></strong></td>
                <td><?= htmlspecialchars((string) $rowcl['date']) ?></td>
                <td><span class="pr-pill pr-pill--user"><?= htmlspecialchars((string) $rowcl['user']) ?></span></td>
                <td><?= htmlspecialchars((string) $rowcl['endtime']) ?></td>
                <td><span class="pr-pill pr-pill--money"><?= htmlspecialchars((string) $rowcl['total_sales']) ?></span></td>
                <td><span class="pr-pill pr-pill--expense"><?= htmlspecialchars((string) $rowcl['expenses']) ?></span></td>
                <td><?= htmlspecialchars((string) $rowcl['exp_notes']) ?></td>
                <td><span class="pr-pill pr-pill--neutral"><?= htmlspecialchars((string) $rowcl['cash']) ?></span></td>
                <td><span class="pr-pill pr-pill--muted"><?= htmlspecialchars((string) $rowcl['fund_after']) ?></span></td>
                <td><?= htmlspecialchars((string) $rowcl['info']) ?></td>
                <td>
                  <div class="pr-actions">
                    <a href="print/closed_session_receipt.php?id=<?= (int) $rowcl['id'] ?>" class="pr-icon-btn pr-icon-btn--print" target="_blank" title="طباعة ملخص الشيفت">
                      <i class="fas fa-print"></i>
                    </a>
                    <a href="print/closed_session_items.php?id=<?= (int) $rowcl['id'] ?>" class="pr-icon-btn pr-icon-btn--list" target="_blank" title="طباعة الأصناف المباعة">
                      <i class="fas fa-list"></i>
                    </a>
                  </div>
                </td>
              </tr>
              <?php $x--; endwhile; ?>
            </tbody>
          </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pr-pagination">
          <nav aria-label="ترقيم الصفحات">
            <ul class="pagination mb-0">
              <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>" aria-label="السابق">
                  <span aria-hidden="true">&raquo;</span>
                </a>
              </li>
              <?php
              $start_page = max(1, $page - 2);
              $end_page = min($total_pages, $page + 2);

              if ($start_page > 1) {
                  echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                  if ($start_page > 2) {
                      echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                  }
              }

              for ($i = $start_page; $i <= $end_page; $i++) {
                  $active = ($i === $page) ? 'active' : '';
                  echo '<li class="page-item ' . $active . '"><a class="page-link" href="?page=' . $i . '">' . $i . '</a></li>';
              }

              if ($end_page < $total_pages) {
                  if ($end_page < $total_pages - 1) {
                      echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                  }
                  echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '">' . $total_pages . '</a></li>';
              }
              ?>
              <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>" aria-label="التالي">
                  <span aria-hidden="true">&laquo;</span>
                </a>
              </li>
            </ul>
          </nav>
          <div class="pr-pagination-meta">
            الصفحة <?= (int) $page ?> من <?= (int) $total_pages ?> — إجمالي <?= (int) $total_count ?> شيفت
          </div>
        </div>
        <?php endif; ?>
      </section>
    </div>
  </section>
</div>

<?php include('includes/footer.php') ?>
