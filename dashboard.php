<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/classes/Security/PostLoginRouteService.php';
require_once __DIR__ . '/classes/Dashboard/DashboardOverviewService.php';

page_guard(null, $conn);

$hasDashboardWidgets = auth_guard_has_permission('erp.dashboard.main_cards', $conn)
    || auth_guard_has_permission('erp.dashboard.main_elements', $conn)
    || auth_guard_has_permission('erp.dashboard.main_tables', $conn);

if (!$hasDashboardWidgets) {
    $userId = (int) ($_SESSION['userid'] ?? 0);
    $redirect = (new PostLoginRouteService())->resolveRedirect($conn, $userId);
    if ($redirect === '' || $redirect === 'dashboard.php') {
        $redirect = 'no_access.php';
    }
    header('Location: ' . $redirect);
    exit;
}

$dashboardFlags = [
    'is_admin' => auth_guard_is_admin_session(),
    'sid_rents' => auth_guard_has_legacy_flag('sid_rents', $conn),
    'sid_hr' => auth_guard_has_legacy_flag('sid_hr', $conn),
    'clinic.enabled' => (int) ($rowstg['showclinc'] ?? 0) === 1,
    'sid_clinics' => auth_guard_has_legacy_flag('sid_clinics', $conn),
    'reports.cash_flow' => auth_guard_has_permission('reports.cash_flow', $conn),
    'menu.edit' => auth_guard_has_permission('menu.edit', $conn),
    'reports.view' => auth_guard_has_permission('reports.view', $conn),
    'accounting.view' => auth_guard_has_permission('accounting.view', $conn),
];

$dashboardOverview = (new DashboardOverviewService())->build($conn, $dashboardFlags);
$dashboardContext = $dashboardOverview['context'];

$premiumCssVer = is_file(__DIR__ . '/css/premium-report-light.css')
    ? (string) filemtime(__DIR__ . '/css/premium-report-light.css')
    : '1';

include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
?>
<script>document.body.classList.add('premium-report-page');</script>
<link rel="stylesheet" href="css/premium-report-light.css?v=<?= htmlspecialchars($premiumCssVer, ENT_QUOTES, 'UTF-8') ?>">

<div class="content-wrapper">
  <section class="content">
    <div class="premium-report premium-dashboard">
      <header class="pr-hero">
        <div class="pr-hero-text">
          <p class="pr-eyebrow">لوحة التحكم</p>
          <h1 class="dashboard-page-title">الرئيسية</h1>
          <p class="pr-hero-sub">
            تاريخ العمل:
            <strong><?= htmlspecialchars((string) $dashboardContext['business_date'], ENT_QUOTES, 'UTF-8') ?></strong>
            · آخر تحديث:
            <strong><?= htmlspecialchars((string) $dashboardContext['generated_at'], ENT_QUOTES, 'UTF-8') ?></strong>
          </p>
        </div>
      </header>

<?php if (auth_guard_has_permission('erp.dashboard.main_cards', $conn)) { include('elements/main/main_cards.php'); } ?>
<?php if (auth_guard_has_permission('erp.dashboard.main_elements', $conn)) { include('elements/main/main_element.php'); } ?>
<?php if (auth_guard_has_permission('erp.dashboard.main_tables', $conn)) { include('elements/main/main_tables.php'); } ?>

    </div>
  </section>
</div>
<?php include('includes/footer.php') ?>
