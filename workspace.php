<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/classes/Security/PostLoginRouteService.php';
require_once __DIR__ . '/classes/Security/MainAuthenticationService.php';

require_once __DIR__ . '/includes/page_guard.php';
page_guard(null, $conn);

$auth = new MainAuthenticationService();
if ($auth->isBootstrapRestrictedSession()) {
    header('Location: change_pin.php?bootstrap=1');
    exit;
}

$userId = (int) ($_SESSION['userid'] ?? 0);
$resolved = (new PostLoginRouteService())->resolve($conn, $userId);
$choices = $resolved['choices'] ?? [];
if (($resolved['workspace'] ?? '') !== PostLoginRouteService::WORKSPACE_CHOOSER || count($choices) < 2) {
    header('Location: ' . ($resolved['url'] ?? 'dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>اختر مساحة العمل</title>
  <link rel="stylesheet" href="assets/fonts/fonts.css">
  <link rel="stylesheet" href="assets/libs/fontawesome.min.css">
  <?php include __DIR__ . '/includes/pin_pad_styles.php'; ?>
</head>
<body class="ppm-page">
  <div class="ppm-shell" style="width:min(720px,94vw);">
    <div class="ppm-card">
      <h1 class="ppm-title">اختر مساحة العمل</h1>
      <p class="ppm-sub">يمكنك فتح أي مساحة مصرح لك بها</p>
      <div class="ppm-workspace-grid" style="margin-top:1.5rem;">
        <?php foreach ($choices as $choice):
          $icon = match ((string) ($choice['key'] ?? '')) {
              'pos' => 'fa-cash-register',
              'kds' => 'fa-utensils',
              'dashboard' => 'fa-tachometer-alt',
              default => 'fa-th-large',
          };
        ?>
          <a class="ppm-workspace-card" href="<?= htmlspecialchars($choice['url'], ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas <?= $icon ?>" aria-hidden="true"></i>
            <div style="font-weight:700;font-size:1.1rem;"><?= htmlspecialchars($choice['label'], ENT_QUOTES, 'UTF-8') ?></div>
          </a>
        <?php endforeach; ?>
      </div>
      <p class="ppm-sub" style="margin-top:1.25rem;">
        <a href="do/do_logout.php" style="color:#64748b;">قفل / تسجيل الخروج</a>
      </p>
    </div>
  </div>
</body>
</html>
