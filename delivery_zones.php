<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';

require_permission('delivery.zones.manage', $conn);

$tenant = max(0, (int) ($_SESSION['pos_tenant'] ?? 0));
$branch = max(0, (int) ($_SESSION['pos_branch'] ?? 0));
$canDispatch = auth_guard_has_permission('delivery.dispatch', $conn);
$canManageDelivery = auth_guard_has_permission('delivery.workers.manage', $conn)
    || auth_guard_has_permission('delivery.compensation.manage', $conn)
    || auth_guard_has_permission('delivery.settlements.manage', $conn)
    || auth_guard_has_permission('delivery.reports.view', $conn);
$deliveryZonesCsrfInput = csrf_input('delivery_zones_write');
$zones = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'delivery_zones'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $conn->prepare('SELECT * FROM delivery_zones WHERE tenant = ? AND branch = ? ORDER BY sort_order ASC, name ASC');
    $stmt->bind_param('ii', $tenant, $branch);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $zones[] = $row;
        }
    }
    $stmt->close();
}
$activeCount = count(array_filter($zones, static fn(array $zone): bool => !empty($zone['is_active'])));
$inactiveCount = count($zones) - $activeCount;
$activeFees = array_map(
    static fn(array $zone): float => (float) $zone['fee'],
    array_filter($zones, static fn(array $zone): bool => !empty($zone['is_active']))
);
$averageFee = $activeFees ? array_sum($activeFees) / count($activeFees) : 0;
?>
<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>
<link rel="stylesheet" href="css/delivery-operations.css?v=<?= (int) (@filemtime(__DIR__ . '/css/delivery-operations.css') ?: 1) ?>">
<div class="content-wrapper delivery-shell">
  <section class="content">
    <div class="container-fluid">
      <div class="delivery-hero">
        <div>
          <div class="delivery-eyebrow">نطاق الخدمة والتسعير</div>
          <h1>مناطق التوصيل</h1>
          <p>حدّد المناطق المتاحة ورسوم كل منطقة كما تظهر للكاشير.</p>
        </div>
        <div class="delivery-hero__actions">
          <?php if ($canDispatch): ?><a class="delivery-button" href="delivery_board.php"><i class="fas fa-motorcycle"></i> طلبات التوصيل</a><?php endif; ?>
          <?php if ($canManageDelivery): ?><a class="delivery-button" href="delivery_management.php"><i class="fas fa-users-cog"></i> العمال والحسابات</a><?php endif; ?>
        </div>
      </div>

      <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="delivery-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars((string) $_SESSION['success_message'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>

      <div class="delivery-zone-layout">
        <aside class="delivery-card delivery-zone-editor" id="deliveryZoneEditor">
          <div class="delivery-card-head">
            <div><span class="delivery-section-kicker">بيانات المنطقة</span><h2 id="zoneFormTitle">إضافة منطقة جديدة</h2><p>الرسوم المسجلة هنا هي القيمة المعتمدة عند إنشاء الطلب.</p></div>
          </div>
          <form class="delivery-zone-form" method="post" action="do/doedit_delivery_zone.php" id="deliveryZoneForm">
            <?= $deliveryZonesCsrfInput ?>
            <input type="hidden" name="id" id="zone_id" value="0">
            <label for="zone_name">اسم المنطقة
              <input type="text" name="name" id="zone_name" placeholder="مثال: المعادي" required>
            </label>
            <div class="delivery-zone-form__row">
              <label for="zone_fee">رسوم التوصيل
                <input type="number" step="0.01" min="0" name="fee" id="zone_fee" placeholder="0.00" required>
              </label>
              <label for="zone_sort">ترتيب الظهور
                <input type="number" min="0" name="sort_order" id="zone_sort" value="0">
              </label>
            </div>
            <label class="delivery-switch" for="zone_active">
              <span><strong>متاحة للطلبات</strong><small class="d-block">تظهر للكاشير عند اختيار المنطقة</small></span>
              <input type="checkbox" name="is_active" id="zone_active" value="1" checked>
              <span class="delivery-switch-track" aria-hidden="true"></span>
            </label>
            <div class="delivery-zone-actions">
              <button type="submit" class="delivery-button delivery-button--primary" id="zoneSaveButton"><i class="fas fa-check"></i> حفظ المنطقة</button>
              <button type="button" class="delivery-button delivery-button--quiet d-none" id="zoneResetButton">إلغاء التعديل</button>
            </div>
          </form>
        </aside>

        <section class="delivery-card">
          <div class="delivery-card-head">
            <div><span class="delivery-section-kicker">التغطية الحالية</span><h2>المناطق والرسوم</h2><p>التعطيل يخفي المنطقة من الطلبات الجديدة ويحافظ على تاريخ الطلبات السابقة.</p></div>
          </div>
          <div class="delivery-zone-overview">
            <div class="delivery-zone-stat"><span>مناطق متاحة</span><strong><?= $activeCount ?></strong></div>
            <div class="delivery-zone-stat"><span>مناطق معطلة</span><strong><?= $inactiveCount ?></strong></div>
            <div class="delivery-zone-stat"><span>متوسط الرسوم</span><strong><?= number_format($averageFee, 2) ?> ج.م</strong></div>
          </div>

          <div class="delivery-zone-grid">
            <?php if (!$zones): ?>
              <div class="delivery-empty-state">
                <i class="fas fa-map-marked-alt"></i><h3>أضف أول منطقة توصيل</h3><p>بعد الحفظ ستظهر المنطقة مباشرة في شاشة إنشاء الطلب.</p>
              </div>
            <?php else: foreach ($zones as $zone): ?>
              <?php $isActive = !empty($zone['is_active']); ?>
              <article class="delivery-zone-item<?= $isActive ? '' : ' is-inactive' ?>">
                <div class="delivery-zone-item__top">
                  <div><h3><?= htmlspecialchars((string) $zone['name'], ENT_QUOTES, 'UTF-8') ?></h3><span class="delivery-zone-order">ترتيب الظهور <?= (int) $zone['sort_order'] ?></span></div>
                  <span class="delivery-zone-status<?= $isActive ? '' : ' is-inactive' ?>"><?= $isActive ? 'متاحة' : 'معطلة' ?></span>
                </div>
                <div class="delivery-zone-fee"><strong><?= number_format((float) $zone['fee'], 2) ?></strong><span>ج.م / طلب</span></div>
                <div class="delivery-zone-item__actions">
                  <button type="button" class="delivery-button zone-edit-btn"
                    data-id="<?= (int) $zone['id'] ?>"
                    data-name="<?= htmlspecialchars((string) $zone['name'], ENT_QUOTES, 'UTF-8') ?>"
                    data-fee="<?= htmlspecialchars((string) $zone['fee'], ENT_QUOTES, 'UTF-8') ?>"
                    data-sort="<?= (int) $zone['sort_order'] ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"><i class="fas fa-pen"></i> تعديل</button>
                  <?php if ($isActive): ?>
                    <form method="post" action="do/doedit_delivery_zone.php" onsubmit="return confirm('تعطيل هذه المنطقة؟ لن تظهر في الطلبات الجديدة.');">
                      <?= $deliveryZonesCsrfInput ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $zone['id'] ?>">
                      <button type="submit" class="delivery-button delivery-button--danger"><i class="fas fa-pause"></i> تعطيل</button>
                    </form>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; endif; ?>
          </div>
        </section>
      </div>
    </div>
  </section>
</div>
<script>
(function () {
  'use strict';
  var form = document.getElementById('deliveryZoneForm');
  var editor = document.getElementById('deliveryZoneEditor');
  var resetButton = document.getElementById('zoneResetButton');
  var title = document.getElementById('zoneFormTitle');
  var saveButton = document.getElementById('zoneSaveButton');

  function resetForm() {
    form.reset();
    document.getElementById('zone_id').value = '0';
    document.getElementById('zone_sort').value = '0';
    document.getElementById('zone_active').checked = true;
    title.textContent = 'إضافة منطقة جديدة';
    saveButton.innerHTML = '<i class="fas fa-check"></i> حفظ المنطقة';
    resetButton.classList.add('d-none');
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.zone-edit-btn');
    if (!button) return;
    document.getElementById('zone_id').value = button.dataset.id;
    document.getElementById('zone_name').value = button.dataset.name;
    document.getElementById('zone_fee').value = button.dataset.fee;
    document.getElementById('zone_sort').value = button.dataset.sort;
    document.getElementById('zone_active').checked = String(button.dataset.active) === '1';
    title.textContent = 'تعديل ' + button.dataset.name;
    saveButton.innerHTML = '<i class="fas fa-check"></i> حفظ التعديلات';
    resetButton.classList.remove('d-none');
    editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('zone_name').focus({ preventScroll: true });
  });
  resetButton.addEventListener('click', resetForm);
})();
</script>
<?php include('includes/footer.php'); ?>
