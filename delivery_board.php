<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';

require_permission('delivery.dispatch', $conn);

$deliveryDispatchCsrf = csrf_token('delivery_dispatch');
$deliveryOperationsCsrf = csrf_token('delivery_operations');
$canManageDelivery = auth_guard_has_permission('delivery.workers.manage', $conn)
    || auth_guard_has_permission('delivery.settlements.manage', $conn);
$canManageZones = auth_guard_has_permission('delivery.zones.manage', $conn);
$statusColumns = [
    'pending' => 'قيد الانتظار',
    'accepted' => 'مقبول',
    'preparing' => 'قيد التحضير',
    'ready' => 'جاهز للتسليم',
    'picked_up' => 'مع السائق',
    'delivered' => 'تم التسليم',
];
$nextStatus = [
    'pending' => 'accepted',
    'accepted' => 'preparing',
    'preparing' => 'ready',
    'ready' => 'picked_up',
    'picked_up' => 'delivered',
];
?>
<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>
<link rel="stylesheet" href="css/delivery-operations.css?v=<?= (int) (@filemtime(__DIR__ . '/css/delivery-operations.css') ?: 1) ?>">
<div class="content-wrapper delivery-shell">
  <section class="content">
    <div class="container-fluid">
      <div class="delivery-hero">
        <div><div class="delivery-eyebrow">متابعة مباشرة</div><h1>طلبات التوصيل</h1><p>تابع الطلب، عيّن العامل، وأكّد التحصيل من مكان واحد.</p></div>
        <div class="delivery-hero__actions">
          <?php if ($canManageDelivery): ?><a class="delivery-button" href="delivery_management.php"><i class="fas fa-users-cog"></i> العمال والحسابات</a><?php endif; ?>
          <?php if ($canManageZones): ?><a class="delivery-button" href="delivery_zones.php"><i class="fas fa-map-marked-alt"></i> مناطق التوصيل</a><?php endif; ?>
          <button type="button" class="delivery-button delivery-button--primary" id="deliveryBoardRefresh"><i class="fas fa-sync-alt"></i> تحديث</button>
        </div>
      </div>
      <div id="deliveryBoardAlert" class="delivery-alert d-none"></div>
      <section class="delivery-board-shell" aria-labelledby="deliveryQueueTitle">
        <div class="delivery-board-toolbar">
          <div>
            <span class="delivery-section-kicker">قائمة العمل</span>
            <h2 id="deliveryQueueTitle">الطلبات النشطة</h2>
          </div>
          <label class="delivery-search" for="deliveryBoardSearch">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input id="deliveryBoardSearch" type="search" autocomplete="off" placeholder="ابحث برقم الطلب أو العميل أو المنطقة">
          </label>
        </div>
        <div id="deliveryBoardSummary" class="delivery-board-summary" aria-live="polite"></div>
        <div id="deliveryBoardFilters" class="delivery-board-filters" role="tablist" aria-label="تصفية الطلبات حسب المرحلة"></div>
        <div id="deliveryBoardColumns" class="delivery-order-grid" aria-live="polite"></div>
        <nav id="deliveryBoardPagination" class="delivery-pagination" aria-label="صفحات طلبات التوصيل"></nav>
      </section>
    </div>
  </section>
</div>
<script>
window.DELIVERY_DISPATCH_CSRF = <?= json_encode($deliveryDispatchCsrf) ?>;
window.DELIVERY_OPERATIONS_CSRF = <?= json_encode($deliveryOperationsCsrf) ?>;
window.DELIVERY_STATUS_COLUMNS = <?= json_encode($statusColumns, JSON_UNESCAPED_UNICODE) ?>;
window.DELIVERY_NEXT_STATUS = <?= json_encode($nextStatus, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/delivery_board.js?v=<?= (int) (@filemtime(__DIR__ . '/js/delivery_board.js') ?: 1) ?>"></script>
<?php include('includes/footer.php'); ?>
