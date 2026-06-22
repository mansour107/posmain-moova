<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';

require_permission('delivery.zones.manage', $conn);

$zoneCsrf = csrf_token('delivery_zones_write');
$zones = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'delivery_zones'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $result = $conn->query("SELECT * FROM delivery_zones ORDER BY sort_order ASC, name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $zones[] = $row;
        }
    }
}
?>
<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <h1><i class="fas fa-map-marked-alt me-2"></i>مناطق التوصيل</h1>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars((string) $_SESSION['success_message'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>
      <div class="row">
        <div class="col-lg-4">
          <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">إضافة / تعديل منطقة</h3></div>
            <form class="card-body" method="post" action="do/doedit_delivery_zone.php">
              <?= csrf_input('delivery_zones_write') ?>
              <input type="hidden" name="id" id="zone_id" value="0">
              <div class="form-group mb-3">
                <label for="zone_name">اسم المنطقة</label>
                <input type="text" class="form-control" name="name" id="zone_name" required>
              </div>
              <div class="form-group mb-3">
                <label for="zone_fee">رسوم التوصيل (ج.م)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="fee" id="zone_fee" required>
              </div>
              <div class="form-group mb-3">
                <label for="zone_sort">ترتيب العرض</label>
                <input type="number" class="form-control" name="sort_order" id="zone_sort" value="0">
              </div>
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" id="zone_active" value="1" checked>
                <label class="form-check-label" for="zone_active">نشطة</label>
              </div>
              <button type="submit" class="btn btn-primary">حفظ المنطقة</button>
            </form>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card">
            <div class="card-header"><h3 class="card-title">المناطق الحالية</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead>
                  <tr>
                    <th>الاسم</th>
                    <th>الرسوم</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$zones): ?>
                  <tr><td colspan="5" class="text-center text-muted p-4">لا توجد مناطق بعد — أضف أول منطقة</td></tr>
                <?php else: foreach ($zones as $zone): ?>
                  <tr>
                    <td><?= htmlspecialchars($zone['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format((float) $zone['fee'], 2) ?> ج.م</td>
                    <td><?= (int) $zone['sort_order'] ?></td>
                    <td><?= !empty($zone['is_active']) ? 'نشطة' : 'معطلة' ?></td>
                    <td class="text-nowrap">
                      <button type="button" class="btn btn-sm btn-outline-primary zone-edit-btn"
                        data-id="<?= (int) $zone['id'] ?>"
                        data-name="<?= htmlspecialchars($zone['name'], ENT_QUOTES, 'UTF-8') ?>"
                        data-fee="<?= htmlspecialchars((string) $zone['fee'], ENT_QUOTES, 'UTF-8') ?>"
                        data-sort="<?= (int) $zone['sort_order'] ?>"
                        data-active="<?= !empty($zone['is_active']) ? '1' : '0' ?>">تعديل</button>
                      <form method="post" action="do/doedit_delivery_zone.php" class="d-inline" onsubmit="return confirm('حذف المنطقة؟');">
                        <?= csrf_input('delivery_zones_write') ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $zone['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>
<script>
$(document).on('click', '.zone-edit-btn', function () {
  $('#zone_id').val($(this).data('id'));
  $('#zone_name').val($(this).data('name'));
  $('#zone_fee').val($(this).data('fee'));
  $('#zone_sort').val($(this).data('sort'));
  $('#zone_active').prop('checked', String($(this).data('active')) === '1');
});
</script>
<?php include('includes/footer.php'); ?>
