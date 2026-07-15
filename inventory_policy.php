<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/NegativeStockSalePolicyService.php';

require_permission('inventory.policy.manage', $conn);

$inventoryPolicy = (new NegativeStockSalePolicyService($appConfig ?? []))->resolve($conn);
$inventoryPolicyFlash = (string) ($_SESSION['inventory_policy_flash'] ?? '');
unset($_SESSION['inventory_policy_flash']);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper" dir="rtl">
  <section class="content">
    <div class="container-fluid py-4" style="max-width:900px">
      <div class="card card-outline card-info shadow-sm">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-boxes ml-2"></i>سياسة البيع والمخزون</h3>
        </div>
        <form method="post" action="do/doedit_inventory_policy.php">
          <div class="card-body">
            <?php if ($inventoryPolicyFlash !== ''): ?>
              <div class="alert alert-success"><?= htmlspecialchars($inventoryPolicyFlash, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?= csrf_input('inventory_policy_write') ?>
            <div class="form-group">
              <label for="negative_stock_sale_policy">التصرف عند عدم كفاية المخزون المحسوب</label>
              <select class="form-control" id="negative_stock_sale_policy" name="negative_stock_sale_policy" required>
                <option value="block" <?= $inventoryPolicy === 'block' ? 'selected' : '' ?>>منع البيع</option>
                <option value="allow_with_warning" <?= $inventoryPolicy === 'allow_with_warning' ? 'selected' : '' ?>>السماح مع تحذير وتسجيل الحدث</option>
              </select>
              <small class="form-text text-muted">العناصر المعطلة يدويا تظل ممنوعة في الحالتين.</small>
            </div>
          </div>
          <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-info"><i class="fas fa-save ml-1"></i>حفظ السياسة</button>
          </div>
        </form>
      </div>
    </div>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
