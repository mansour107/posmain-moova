<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/page_guard.php';
include 'includes/connect.php';

page_guard_from_manifest('team.php', $conn);

$canUsers = auth_guard_has_permission('users.manage', $conn) || auth_guard_is_admin_session();
$canRoles = auth_guard_has_permission('roles.manage', $conn) || auth_guard_is_admin_session();

require_once __DIR__ . '/classes/Security/TeamHubService.php';
require_once __DIR__ . '/classes/Security/RolePermissionSyncService.php';
require_once __DIR__ . '/classes/Security/SecurityAuditLogger.php';

$hub = new TeamHubService($conn);
$isAdminSession = auth_guard_is_admin_session();

$requestedTab = (string) ($_GET['tab'] ?? 'staff');
$initialTab = 'staff';
if ($requestedTab === 'roles' && $canRoles) {
    $initialTab = 'roles';
} elseif ($requestedTab === 'logins' && $canUsers) {
    $initialTab = 'logins';
} elseif ($requestedTab === 'staff' && $canUsers) {
    $initialTab = 'staff';
} elseif ($canUsers) {
    $initialTab = 'staff';
} elseif ($canRoles) {
    $initialTab = 'roles';
}

$showDeactivated = isset($_GET['show_deactivated']);
$staffList = $canUsers ? $hub->staffList($showDeactivated, $isAdminSession) : [];
if ($isAdminSession && $canUsers && $staffList !== []) {
    (new SecurityAuditLogger())->record($conn, 'staff_pins_viewed', [
        'target_type' => 'team_hub',
        'metadata' => ['staff_count' => count($staffList)],
    ]);
}
$rolesList = $canRoles ? $hub->loadRoles() : [];
$stats = $hub->stats();
$loginSummary = $canUsers ? $hub->loginActivitySummary() : ['total' => 0, 'available' => false];
$recentLogins = $canUsers ? $hub->recentLogins(50) : [];

$defaultRoleId = 0;
foreach ($rolesList as $r) {
    if (($r['role_key'] ?? '') === 'cashier') {
        $defaultRoleId = (int) $r['id'];
        break;
    }
}
if ($defaultRoleId < 1 && $rolesList !== []) {
    $defaultRoleId = (int) $rolesList[0]['id'];
}

$pinReveal = null;
if (isset($_GET['pin_reveal'])) {
    $sessionReveal = $_SESSION['posmain_one_time_pin_reveal'] ?? null;
    if (is_array($sessionReveal) && (int) ($sessionReveal['expires'] ?? 0) > time()) {
        $pinReveal = (string) ($sessionReveal['pin'] ?? '');
        unset($_SESSION['posmain_one_time_pin_reveal']);
    }
}

$hubConfig = [
    'initialTab' => $initialTab,
    'canUsers' => $canUsers,
    'canRoles' => $canRoles,
    'csrfUsers' => csrf_token('users_write'),
    'csrfRoles' => csrf_token('roles_write'),
    'staff' => $staffList,
    'roles' => $rolesList,
    'defaultRoleId' => $defaultRoleId,
    'pinReveal' => $pinReveal,
    'loginTotal' => (int) ($loginSummary['total'] ?? 0),
    'loginAvailable' => !empty($loginSummary['available']),
    'recentLogins' => $recentLogins,
];

$assetVer = is_file(__DIR__ . '/css/team-hub.css') ? (string) filemtime(__DIR__ . '/css/team-hub.css') : '1';

include 'includes/header.php';

$teamHubUserName = '';
if (isset($up) && is_array($up)) {
    $teamHubUserName = trim((string) ($up['display_name'] ?? ''));
    if ($teamHubUserName === '') {
        $teamHubUserName = trim((string) ($up['uname'] ?? ''));
    }
}
if ($teamHubUserName === '') {
    $teamHubUserName = trim((string) ($_SESSION['login'] ?? 'الموظف'));
}
$teamHubUserInitial = function_exists('mb_substr')
    ? mb_substr($teamHubUserName, 0, 1, 'UTF-8')
    : substr($teamHubUserName, 0, 1);
$teamHubUserInitial = $teamHubUserInitial !== '' ? $teamHubUserInitial : 'م';
?>
<script>document.body.classList.add('team-hub-page');</script>
<link rel="stylesheet" href="css/team-hub.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">

<div id="teamHubRoot" class="team-hub" dir="rtl">
  <script type="application/json" id="teamHubConfig"><?= json_encode($hubConfig, JSON_UNESCAPED_UNICODE) ?></script>
  <?php if ($canUsers): ?>
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token('users_write'), ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <?php if ($canRoles): ?>
  <input type="hidden" name="csrf_token_roles" value="<?= htmlspecialchars(csrf_token('roles_write'), ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <div class="team-hub-top">
    <div class="team-hub-breadcrumb">
      <a href="index.php">الإعدادات</a> › <strong>الفريق</strong>
    </div>
    <?php if ($canUsers || $canRoles): ?>
    <div class="team-hub-tabs">
      <?php if ($canUsers): ?>
      <button type="button" class="team-hub-tab" id="tabStaff">الموظفون</button>
      <button type="button" class="team-hub-tab" id="tabLogins">نشاط الدخول</button>
      <?php endif; ?>
      <?php if ($canRoles): ?>
      <button type="button" class="team-hub-tab" id="tabRoles">الأدوار</button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="team-hub-top-actions">
      <div class="team-hub-identity" role="status" aria-label="المستخدم الحالي: <?= htmlspecialchars($teamHubUserName, ENT_QUOTES, 'UTF-8') ?>">
        <span class="team-hub-identity-avatar" aria-hidden="true"><?= htmlspecialchars($teamHubUserInitial, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="team-hub-identity-copy">
          <span class="team-hub-identity-label">مرحباً</span>
          <strong class="team-hub-identity-name"><?= htmlspecialchars($teamHubUserName, ENT_QUOTES, 'UTF-8') ?></strong>
        </span>
      </div>
      <a href="index.php" class="team-hub-btn team-hub-btn-ghost">← رجوع</a>
    </div>
  </div>

  <div class="team-hub-stats">
    <?php if ($canRoles): ?>
    <div class="team-hub-stat">
      <div class="team-hub-stat-label">الأدوار</div>
      <div class="team-hub-stat-value" id="statRoles"><?= (int) $stats['role_count'] ?></div>
    </div>
    <?php endif; ?>
    <?php if ($canUsers): ?>
    <div class="team-hub-stat">
      <div class="team-hub-stat-label">الموظفون النشطون</div>
      <div class="team-hub-stat-value" id="statStaff"><?= (int) $stats['staff_count'] ?></div>
    </div>
    <div class="team-hub-stat">
      <div class="team-hub-stat-label">مرات الدخول</div>
      <div class="team-hub-stat-value" id="statLogins"><?= !empty($loginSummary['available']) ? (int) $loginSummary['total'] : '—' ?></div>
    </div>
    <?php endif; ?>
    <div class="team-hub-stat">
      <div class="team-hub-stat-label">أدوار جاهزة</div>
      <div class="team-hub-stat-value"><?= (int) $stats['preset_count'] ?></div>
    </div>
  </div>

  <section id="sectionStaff" class="<?= $initialTab !== 'staff' ? 'team-hub-hidden' : '' ?>">
    <div class="team-hub-toolbar">
      <input type="search" class="team-hub-search" id="staffSearch" placeholder="ابحث عن موظف...">
      <?php if ($canUsers): ?>
      <a href="?tab=staff&amp;<?= $showDeactivated ? '' : 'show_deactivated=1' ?>" class="team-hub-btn team-hub-btn-ghost">
        <?= $showDeactivated ? 'النشطون فقط' : 'الموقوفون مؤقتاً' ?>
      </a>
      <button type="button" class="team-hub-btn team-hub-btn-primary" id="btnAddStaff">+ موظف</button>
      <?php endif; ?>
    </div>
    <div class="team-hub-grid" id="staffGrid"></div>
  </section>

  <section id="sectionRoles" class="<?= $initialTab !== 'roles' ? 'team-hub-hidden' : '' ?>">
    <div class="team-hub-toolbar">
      <input type="search" class="team-hub-search" id="rolesSearch" placeholder="ابحث عن دور...">
      <?php if ($canRoles): ?>
      <button type="button" class="team-hub-btn team-hub-btn-primary" id="btnAddRole">+ دور</button>
      <?php endif; ?>
    </div>
    <div class="team-hub-grid" id="rolesGrid"></div>
  </section>

  <?php if ($canUsers): ?>
  <section id="sectionLogins" class="<?= $initialTab !== 'logins' ? 'team-hub-hidden' : '' ?>" data-testid="team-login-activity">
    <div class="team-hub-toolbar">
      <input type="search" class="team-hub-search" id="loginsSearch" placeholder="ابحث عن مستخدم...">
      <span class="team-hub-muted">آخر <?= count($recentLogins) ?> تسجيل دخول</span>
    </div>
    <div class="team-hub-login-table-wrap">
      <table class="team-hub-login-table">
        <thead>
          <tr>
            <th>#</th>
            <th>المستخدم</th>
            <th>وقت الدخول</th>
          </tr>
        </thead>
        <tbody id="loginsTableBody">
          <?php if ($recentLogins === []): ?>
          <tr>
            <td colspan="3"><?= !empty($loginSummary['available']) ? 'لا توجد تسجيلات دخول بعد' : 'غير متاح' ?></td>
          </tr>
          <?php else: ?>
          <?php foreach ($recentLogins as $i => $login): ?>
          <tr data-uname="<?= htmlspecialchars((string) $login['uname'], ENT_QUOTES, 'UTF-8') ?>">
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars((string) $login['uname'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $login['crtime'], ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <div class="team-hub-panel-backdrop" id="panelBackdrop"></div>
  <aside class="team-hub-panel" id="teamPanel" aria-hidden="true">
    <div class="team-hub-panel-header">
      <h2 class="team-hub-panel-title" id="panelTitle"></h2>
      <button type="button" class="team-hub-panel-close" id="panelCloseBtn" aria-label="إغلاق">×</button>
    </div>
    <div class="team-hub-panel-body" id="panelBody"></div>
    <div class="team-hub-panel-footer" id="panelFooter"></div>
  </aside>

  <div class="team-hub-toast" id="teamToast">
    <div id="teamToastMsg"></div>
    <div class="team-hub-toast-pin team-hub-hidden" id="teamToastPin"></div>
  </div>

  <div class="team-hub-confirm" id="teamHubConfirm" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="team-hub-confirm-backdrop" id="teamConfirmBackdrop"></div>
    <div class="team-hub-confirm-card">
      <h3 class="team-hub-confirm-title" id="teamConfirmTitle"></h3>
      <p class="team-hub-confirm-msg" id="teamConfirmMsg"></p>
      <div class="team-hub-confirm-actions">
        <button type="button" class="team-hub-btn team-hub-btn-ghost" id="teamConfirmCancel">إلغاء</button>
        <button type="button" class="team-hub-btn team-hub-btn-primary" id="teamConfirmOk"></button>
      </div>
    </div>
  </div>
</div>

<script src="js/team-hub.js?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
  document.getElementById('panelCloseBtn')?.addEventListener('click', function () {
    if (typeof window.teamHubClosePanel === 'function') {
      window.teamHubClosePanel();
    }
  });
</script>
<?php include 'includes/footer.php'; ?>
