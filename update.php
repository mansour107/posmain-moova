<?php
require_once __DIR__ . '/includes/auth_guard.php';
include __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/page_guard.php';

page_guard('system.tools.run', $conn);
http_response_code(410);
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="content-wrapper">
  <section class="content p-4">
    <div class="alert alert-info" role="status">
      <h1 class="h4">تم إيقاف أداة التحديث القديمة</h1>
      <p class="mb-0">استخدم مسار تحديث قاعدة البيانات المعتمد الذي ينفذ النسخ الاحتياطي والفحص المسبق قبل التطبيق.</p>
    </div>
  </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
