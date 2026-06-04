<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/connect.php';

require_permission('inventory.edit', $conn);

$inventoryStoreRows = [];
$inventoryStoreResult = $conn->query("
    SELECT id, code, aname, address, phone, is_stock
    FROM acc_head
    WHERE isdeleted = 0
      AND is_basic = 0
      AND (is_stock = 1 OR code LIKE '123%')
    ORDER BY aname, code
    LIMIT 300
");
while ($row = $inventoryStoreResult->fetch_assoc()) {
    $inventoryStoreRows[] = $row;
}

$inventoryStoreLegacyRedirect = isset($_GET['legacy_redirect']);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
    .inventory-store-page{direction:rtl;background:#f6f8fb;min-height:calc(100vh - 57px)}
    .inventory-store-wrap{max-width:1280px;width:100%;box-sizing:border-box;margin:0 auto;padding:18px;overflow-x:hidden}
    .inventory-store-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;background:#122033;color:#fff;border-radius:8px;box-shadow:0 12px 28px rgba(16,32,51,.16)}
    .inventory-store-title{margin:0;font-size:24px;font-weight:800;letter-spacing:0}
    .inventory-store-subtitle{margin:6px 0 0;color:#c8d4df;font-size:13px}
    .inventory-store-actions{display:flex;flex-wrap:wrap;gap:8px}
    .inventory-store-btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:8px;padding:10px 13px;background:#fff;color:#122033;font-weight:800;text-decoration:none}
    .inventory-store-btn.dark{background:#1f8f6a;color:#fff}
    .inventory-store-alert{margin-top:14px;border:1px solid #cfe5ff;background:#edf7ff;color:#17446b;border-radius:8px;padding:12px 14px;font-weight:700}
    .inventory-store-panel{margin-top:16px;background:#fff;border:1px solid #dde5ee;border-radius:8px;box-shadow:0 8px 20px rgba(21,35,50,.07);overflow:hidden}
    .inventory-store-table-wrap{width:100%;overflow-x:auto}
    .inventory-store-table{width:100%;min-width:720px;border-collapse:collapse}
    .inventory-store-table th,.inventory-store-table td{padding:13px 14px;border-bottom:1px solid #edf1f5;text-align:right;vertical-align:middle}
    .inventory-store-table th{background:#f8fafc;color:#4c5967;font-size:12px;font-weight:800}
    .inventory-store-table td{color:#142231;font-size:14px}
    .inventory-store-name{font-weight:800}
    .inventory-store-muted{color:#718093;font-size:12px}
    .inventory-store-row-actions{display:flex;gap:8px;flex-wrap:wrap}
    .inventory-store-icon-btn{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;text-decoration:none;border:1px solid #d8e1ea;color:#122033;background:#fff}
    .inventory-store-empty{padding:28px;text-align:center;color:#718093;font-weight:700}
    @media(max-width:768px){.inventory-store-header{align-items:stretch;flex-direction:column}.inventory-store-actions{width:100%}.inventory-store-btn{justify-content:center;flex:1 1 150px}.inventory-store-wrap{padding:12px}.inventory-store-table{min-width:640px}}
</style>

<div class="content-wrapper inventory-store-page">
    <section class="content">
        <div class="inventory-store-wrap">
            <div class="inventory-store-header">
                <div>
                    <h1 class="inventory-store-title">إعداد المخازن</h1>
                    <p class="inventory-store-subtitle">إدارة تعريف المخازن المستخدمة في دفتر المخزون الجديد بدون عرضها كرصيد مخزون قديم.</p>
                </div>
                <div class="inventory-store-actions">
                    <a class="inventory-store-btn dark" href="add_store.php"><i class="fas fa-plus"></i> مخزن جديد</a>
                    <a class="inventory-store-btn" href="inventory_dashboard.php"><i class="fas fa-history"></i> حركات المخزون</a>
                    <a class="inventory-store-btn" href="inventory_stock_levels.php"><i class="fas fa-layer-group"></i> مستويات المخزون</a>
                </div>
            </div>

            <?php if ($inventoryStoreLegacyRedirect) { ?>
                <div class="inventory-store-alert">
                    تم تحويلك من شاشة الحسابات القديمة لأن المخزون يعمل الآن من دفتر المخزون الجديد. هذه الصفحة مخصصة لإعداد المخازن فقط.
                </div>
            <?php } ?>

            <div class="inventory-store-panel">
                <?php if (!$inventoryStoreRows) { ?>
                    <div class="inventory-store-empty">لا توجد مخازن معرفة حاليا.</div>
                <?php } else { ?>
                    <div class="inventory-store-table-wrap">
                        <table class="inventory-store-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المخزن</th>
                                    <th>الكود المحاسبي</th>
                                    <th>العنوان</th>
                                    <th>الهاتف</th>
                                    <th>الحالة</th>
                                    <th>عمليات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventoryStoreRows as $index => $store) { ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <div class="inventory-store-name"><?= htmlspecialchars((string) $store['aname'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="inventory-store-muted">id <?= (int) $store['id'] ?></div>
                                        </td>
                                        <td><?= htmlspecialchars((string) $store['code'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($store['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($store['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) ($store['is_stock'] ?? 0) === 1 ? 'مخزن نشط' : 'حساب مخزن' ?></td>
                                        <td>
                                            <div class="inventory-store-row-actions">
                                                <a class="inventory-store-icon-btn" title="تعديل" href="edit_account.php?id=<?= (int) $store['id'] ?>"><i class="fas fa-pen"></i></a>
                                                <a class="inventory-store-icon-btn" title="الأرصدة" href="inventory_stock_levels.php?store_id=<?= (int) $store['id'] ?>"><i class="fas fa-layer-group"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
