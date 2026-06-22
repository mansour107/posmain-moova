<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>
<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_permission('delivery.dispatch', $conn);
require_once __DIR__ . '/includes/csrf.php';
$deliveryDispatchCsrf = csrf_token('delivery_dispatch');
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
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1><i class="fas fa-shipping-fast me-2"></i>لوحة توصيل الطلبات</h1>
        </div>
        <div class="col-sm-6 text-end">
          <button type="button" class="btn btn-outline-primary btn-sm" id="deliveryBoardRefresh">
            <i class="fas fa-sync-alt"></i> تحديث
          </button>
        </div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <div id="deliveryBoardColumns" class="delivery-board-columns row g-3"></div>
    </div>
  </section>
</div>
<script>
window.DELIVERY_DISPATCH_CSRF = <?= json_encode($deliveryDispatchCsrf) ?>;
window.DELIVERY_STATUS_COLUMNS = <?= json_encode($statusColumns, JSON_UNESCAPED_UNICODE) ?>;
window.DELIVERY_NEXT_STATUS = <?= json_encode($nextStatus, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/delivery_board.js?v=<?= (int) (@filemtime(__DIR__ . '/js/delivery_board.js') ?: 1) ?>"></script>
<?php include('includes/footer.php'); ?>
