<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
page_guard(null);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>لا توجد صلاحية</title>
  <link rel="stylesheet" href="assets/fonts/fonts.css">
  <?php include __DIR__ . '/includes/pin_pad_styles.php'; ?>
</head>
<body class="ppm-page">
  <div class="ppm-shell">
    <div class="ppm-card" style="text-align:center;">
      <h1 class="ppm-title">لا توجد مساحة عمل متاحة</h1>
      <p class="ppm-sub">حسابك مسجّل الدخول لكن لا يملك صلاحية لأي لوحة أو نقطة بيع أو مطبخ.</p>
      <p class="ppm-sub">اطلب من المدير منحك الصلاحيات المناسبة ثم أعد المحاولة.</p>
      <div style="margin-top:1.25rem;display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;">
        <a class="ppm-key enter" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;min-width:140px;" href="do/do_logout.php">تسجيل الخروج</a>
        <a class="ppm-key action" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;min-width:140px;" href="index.php">الشاشة الرئيسية</a>
      </div>
    </div>
  </div>
</body>
</html>
