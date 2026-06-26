<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/pos_default_accounts.php';

require_permission('inventory.edit', $conn);

$inventoryStockLevelHasDefaultSupplierColumn = inventoryStockLevelColumnExists($conn, 'inventory_item_stock_levels', 'default_supplier_account_id');
$inventoryStockLevelCanTechnicalImport = auth_guard_has_permission('system.tools.run', $conn);

if (isset($_GET['stock_level_export'])) {
    if (!$inventoryStockLevelCanTechnicalImport) {
        http_response_code(403);
        echo 'FORBIDDEN';
        exit;
    }
    inventoryStockLevelExportCsv($conn, (string) $_GET['stock_level_export']);
    exit;
}

$inventoryStockLevelStores = posmain_inventory_store_select_options($conn);
$inventoryStockLevelCategories = inventoryStockLevelRows($conn, "
    SELECT id, gname
    FROM item_group
    WHERE isdeleted = 0
    ORDER BY gname
    LIMIT 300
");
$inventoryStockLevelItems = inventoryStockLevelRows($conn, "
    SELECT id, iname, barcode
    FROM myitems
    WHERE COALESCE(isdeleted, 0) = 0
      AND COALESCE(track_stock, 1) = 1
      AND COALESCE(item_type, 'sellable') <> 'service'
    ORDER BY iname
    LIMIT 700
");
$inventoryStockLevelUnits = inventoryStockLevelRows($conn, "
    SELECT iu.item_id, iu.unit_id, iu.u_val, COALESCE(u.uname, CONCAT('وحدة ', iu.unit_id)) AS uname
    FROM item_units iu
    LEFT JOIN myunits u ON u.id = iu.unit_id
    WHERE COALESCE(iu.isdeleted, 0) = 0
      AND COALESCE(u.isdeleted, 0) = 0
    ORDER BY iu.item_id, u.uname, iu.unit_id
    LIMIT 1200
");
$inventoryStockLevelSuppliers = inventoryStockLevelRows($conn, "
    SELECT id, aname, code
    FROM acc_head
    WHERE isdeleted = 0
      AND is_basic = 0
      AND code LIKE '211%'
    ORDER BY aname
    LIMIT 200
");
$inventoryStockLevelRows = inventoryStockLevelRows($conn, "
    SELECT
        levels.id,
        levels.store_id,
        store.aname AS store_name,
        levels.item_id,
        item.iname AS item_name,
        item.barcode,
        COALESCE(balance.qty_on_hand, 0) AS qty_on_hand,
        COALESCE(balance.qty_available, 0) AS qty_available,
        levels.minimum_level,
        levels.reorder_level,
        levels.par_level,
        levels.maximum_level,
        levels.safety_stock_qty,
        levels.preferred_count_unit_id,
        levels.preferred_purchase_unit_id,
        " . ($inventoryStockLevelHasDefaultSupplierColumn ? "levels.default_supplier_account_id" : "NULL") . " AS default_supplier_account_id,
        count_unit.uname AS preferred_count_unit_name,
        purchase_unit.uname AS preferred_purchase_unit_name,
        " . ($inventoryStockLevelHasDefaultSupplierColumn ? "supplier.aname" : "NULL") . " AS default_supplier_name,
        levels.is_active,
        levels.updated_at
    FROM inventory_item_stock_levels levels
    LEFT JOIN myitems item ON item.id = levels.item_id
    LEFT JOIN acc_head store ON store.id = levels.store_id
    LEFT JOIN inventory_item_balances balance
      ON balance.pos_tenant = levels.pos_tenant
     AND balance.pos_branch = levels.pos_branch
     AND balance.store_id = levels.store_id
     AND balance.item_id = levels.item_id
    LEFT JOIN myunits count_unit ON count_unit.id = levels.preferred_count_unit_id
    LEFT JOIN myunits purchase_unit ON purchase_unit.id = levels.preferred_purchase_unit_id
    " . ($inventoryStockLevelHasDefaultSupplierColumn ? "LEFT JOIN acc_head supplier ON supplier.id = levels.default_supplier_account_id" : "") . "
    ORDER BY levels.updated_at DESC, item.iname
    LIMIT 160
");

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= csrf_meta_tag('inventory_stock_level', 'inventory-stock-level-csrf') ?>

<style>
    .inventory-stock-level-page{direction:rtl;background:#f6f8fb;min-height:calc(100vh - 57px)}
    .inventory-stock-level-wrap{max-width:1440px;width:100%;box-sizing:border-box;margin:0 auto;padding:18px;overflow-x:hidden}
    .inventory-stock-level-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;background:#122033;color:#fff;border-radius:8px;box-shadow:0 12px 28px rgba(16,32,51,.16)}
    .inventory-stock-level-title{margin:0;font-size:24px;font-weight:800;letter-spacing:0}
    .inventory-stock-level-subtitle{margin:6px 0 0;color:#c8d4df;font-size:13px}
    .inventory-stock-level-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:9px 12px;border-radius:8px;white-space:nowrap}
    .inventory-stock-level-grid{display:grid;grid-template-columns:minmax(320px,430px) 1fr;gap:16px;margin-top:16px}
    .inventory-stock-level-panel{min-width:0;background:#fff;border:1px solid #dde5ee;border-radius:8px;box-shadow:0 8px 20px rgba(21,35,50,.07)}
    .inventory-stock-level-panel-header{padding:14px 16px;border-bottom:1px solid #e7edf3;display:flex;justify-content:space-between;align-items:center;gap:10px}
    .inventory-stock-level-panel-title{margin:0;font-size:16px;font-weight:800;color:#102033}
    .inventory-stock-level-panel-body{min-width:0;padding:16px}
    .inventory-stock-level-field{margin-bottom:13px}
    .inventory-stock-level-field label{display:block;font-size:12px;color:#4f6175;margin-bottom:6px;font-weight:800}
    .inventory-stock-level-field .form-control{border-radius:8px;border-color:#cfdae5;min-height:40px}
    .inventory-stock-level-search{margin-bottom:8px}
    .inventory-stock-level-filter-note{margin-top:6px;color:#64748b;font-size:12px}
    .inventory-stock-level-numbers{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .inventory-stock-level-table{min-width:980px}
    .inventory-stock-level-table th{background:#eef3f7;color:#334155;font-size:12px;border-color:#dde5ee;white-space:nowrap}
    .inventory-stock-level-table td{border-color:#e5ebf1;vertical-align:middle;font-size:13px}
    .inventory-stock-level-btn{min-height:42px;border-radius:8px;border:0;background:#0f766e;color:#fff;font-weight:800;padding:0 18px}
    .inventory-stock-level-status{display:inline-flex;align-items:center;border-radius:8px;padding:5px 8px;font-size:12px;font-weight:800;background:#ecfdf5;color:#047857}
    .inventory-stock-level-status.inactive{background:#f1f5f9;color:#64748b}
    .inventory-stock-level-tools{display:grid;gap:10px;margin-bottom:14px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
    .inventory-stock-level-tool-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .inventory-stock-level-link-btn{display:inline-flex;align-items:center;gap:7px;min-height:38px;border-radius:8px;border:1px solid #cfdae5;background:#fff;color:#102033;font-weight:800;padding:0 12px;text-decoration:none}
    .inventory-stock-level-link-btn:hover{color:#0f766e;text-decoration:none}
    .inventory-stock-level-row-action{border:1px solid #cbd7e4;background:#fff;color:#102033;border-radius:8px;min-width:38px;min-height:34px}
    .inventory-stock-level-import{min-height:86px;resize:vertical;direction:ltr;text-align:left;font-family:monospace;font-size:12px}
    .inventory-stock-level-toast{display:none;position:fixed;left:22px;bottom:22px;z-index:2000;min-width:280px;max-width:420px;border-radius:8px;padding:13px 16px;color:#fff;background:#102033;box-shadow:0 14px 34px rgba(15,23,42,.22)}
    .inventory-stock-level-toast.error{background:#b91c1c}
    @media(max-width:992px){.inventory-stock-level-grid{grid-template-columns:1fr}.inventory-stock-level-header{align-items:flex-start;flex-direction:column}}
</style>

<div class="content-wrapper inventory-stock-level-page">
    <section class="content-header">
        <div class="inventory-stock-level-wrap">
            <div class="inventory-stock-level-header">
                <div>
                    <h1 class="inventory-stock-level-title">مستويات المخزون</h1>
                    <p class="inventory-stock-level-subtitle">تحديد الحد الأدنى ونقطة الطلب والمستهدف لكل صنف ومخزن</p>
                </div>
                <div class="inventory-stock-level-pill">
                    <i class="fas fa-layer-group"></i>
                    <span>تغذي تقارير النقص واقتراحات الشراء</span>
                </div>
            </div>

            <div class="inventory-stock-level-grid">
                <div class="inventory-stock-level-panel">
                    <div class="inventory-stock-level-panel-header">
                        <h2 class="inventory-stock-level-panel-title">إعداد مستوى صنف</h2>
                    </div>
                    <div class="inventory-stock-level-panel-body">
                        <div class="inventory-stock-level-field">
                            <label for="inventoryStockLevelStore">المخزن</label>
                            <select id="inventoryStockLevelStore" class="form-control">
                                <?php foreach ($inventoryStockLevelStores as $store): ?>
                                    <option value="<?= (int) $store['id'] ?>"><?= htmlspecialchars($store['aname'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-stock-level-field">
                            <label for="inventoryStockLevelItem">الصنف</label>
                            <input id="inventoryStockLevelItemSearch" class="form-control inventory-stock-level-search" type="search" autocomplete="off" placeholder="ابحث باسم الصنف أو الباركود">
                            <select id="inventoryStockLevelItem" class="form-control">
                                <option value="">اختر الصنف</option>
                                <?php foreach ($inventoryStockLevelItems as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>"><?= htmlspecialchars(($item['iname'] ?? '') . (($item['barcode'] ?? '') !== '' ? ' - ' . $item['barcode'] : ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-stock-level-field">
                            <label for="inventoryStockLevelCategory">تطبيق على تصنيف كامل</label>
                            <select id="inventoryStockLevelCategory" class="form-control">
                                <option value="">اختر التصنيف عند الحاجة</option>
                                <?php foreach ($inventoryStockLevelCategories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars(($category['gname'] ?? '') !== '' ? $category['gname'] : 'تصنيف غير مسمى', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-stock-level-numbers">
                            <div class="inventory-stock-level-field">
                                <label for="inventoryStockLevelMinimum">الحد الأدنى</label>
                                <input id="inventoryStockLevelMinimum" class="form-control" type="number" min="0" step="0.001" value="0">
                            </div>
                            <div class="inventory-stock-level-field">
                                <label for="inventoryStockLevelReorder">نقطة الطلب</label>
                                <input id="inventoryStockLevelReorder" class="form-control" type="number" min="0" step="0.001" value="0">
                            </div>
                            <div class="inventory-stock-level-field">
                                <label for="inventoryStockLevelPar">المستهدف</label>
                                <input id="inventoryStockLevelPar" class="form-control" type="number" min="0" step="0.001" value="0">
                            </div>
                            <div class="inventory-stock-level-field">
                                <label for="inventoryStockLevelMaximum">الحد الأعلى</label>
                                <input id="inventoryStockLevelMaximum" class="form-control" type="number" min="0" step="0.001" value="0">
                            </div>
                        </div>
                        <div class="inventory-stock-level-field">
                            <label for="inventoryStockLevelSafety">مخزون الأمان</label>
                            <input id="inventoryStockLevelSafety" class="form-control" type="number" min="0" step="0.001" value="0">
                        </div>
                        <div class="inventory-stock-level-field">
                            <label for="inventoryStockLevelSupplier">المورد الافتراضي</label>
                            <select id="inventoryStockLevelSupplier" class="form-control" <?= $inventoryStockLevelHasDefaultSupplierColumn ? '' : 'disabled' ?>>
                                <option value="">بدون مورد افتراضي</option>
                                <?php foreach ($inventoryStockLevelSuppliers as $supplier): ?>
                                    <option value="<?= (int) $supplier['id'] ?>"><?= htmlspecialchars(($supplier['aname'] ?? '') . (($supplier['code'] ?? '') !== '' ? ' - ' . $supplier['code'] : ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$inventoryStockLevelHasDefaultSupplierColumn): ?>
                                <small class="form-text text-muted">سيظهر اختيار المورد الافتراضي بعد تطبيق تحديث قاعدة البيانات الخاص بمستويات المخزون.</small>
                            <?php endif; ?>
                        </div>
                        <div class="inventory-stock-level-numbers">
                            <div class="inventory-stock-level-field">
                                <label for="inventoryStockLevelCountUnit">وحدة العد المفضلة</label>
                                <select id="inventoryStockLevelCountUnit" class="form-control">
                                    <option value="">الوحدة الأساسية</option>
                                </select>
                            </div>
                            <div class="inventory-stock-level-field">
                                <label for="inventoryStockLevelPurchaseUnit">وحدة الشراء المفضلة</label>
                                <select id="inventoryStockLevelPurchaseUnit" class="form-control">
                                    <option value="">الوحدة الأساسية</option>
                                </select>
                            </div>
                        </div>
                        <div class="inventory-stock-level-field">
                            <label>
                                <input type="checkbox" id="inventoryStockLevelActive" checked>
                                نشط في تقارير النقص
                            </label>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="button" class="inventory-stock-level-link-btn ml-2" id="applyInventoryStockLevelCategory"><i class="fas fa-layer-group"></i> تطبيق على التصنيف</button>
                            <button type="button" class="inventory-stock-level-btn" id="saveInventoryStockLevel"><i class="fas fa-save"></i> حفظ المستوى</button>
                        </div>
                    </div>
                </div>

                <div class="inventory-stock-level-panel">
                    <div class="inventory-stock-level-panel-header">
                        <h2 class="inventory-stock-level-panel-title">المستويات الحالية</h2>
                    </div>
                    <div class="inventory-stock-level-panel-body">
                        <?php if ($inventoryStockLevelCanTechnicalImport): ?>
                            <div class="inventory-stock-level-tools">
                                <div class="inventory-stock-level-field mb-0">
                                    <label for="inventoryStockLevelBulkCsv">استيراد تقني CSV</label>
                                    <textarea id="inventoryStockLevelBulkCsv" class="form-control inventory-stock-level-import" rows="4" placeholder="store_id,item_id,minimum_level,reorder_level,par_level,maximum_level,safety_stock_qty,preferred_count_unit_id,preferred_purchase_unit_id,default_supplier_account_id,is_active"></textarea>
                                </div>
                                <div class="inventory-stock-level-tool-actions">
                                    <button type="button" class="inventory-stock-level-btn" id="importInventoryStockLevels"><i class="fas fa-file-import"></i> استيراد المستويات</button>
                                    <a class="inventory-stock-level-link-btn" href="inventory_stock_levels.php?stock_level_export=template"><i class="fas fa-download"></i> قالب CSV</a>
                                    <a class="inventory-stock-level-link-btn" href="inventory_stock_levels.php?stock_level_export=current"><i class="fas fa-file-export"></i> تصدير الحالي</a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-bordered inventory-stock-level-table">
                                <thead>
                                    <tr>
                                        <th>المخزن</th>
                                        <th>الصنف</th>
                                        <th>المتاح</th>
                                        <th>الحد الأدنى</th>
                                        <th>نقطة الطلب</th>
                                        <th>المستهدف</th>
                                        <th>الحد الأعلى</th>
                                        <th>وحدة العد</th>
                                        <th>وحدة الشراء</th>
                                        <th>المورد الافتراضي</th>
                                        <th>الحالة</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryStockLevelRows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(($row['store_name'] ?? '') !== '' ? $row['store_name'] : 'مخزن غير مسمى', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((($row['item_name'] ?? '') !== '' ? $row['item_name'] : 'صنف غير مسمى') . (($row['barcode'] ?? '') !== '' ? ' - ' . $row['barcode'] : ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= number_format((float) ($row['qty_available'] ?? 0), 3, '.', '') ?></td>
                                            <td><?= number_format((float) ($row['minimum_level'] ?? 0), 3, '.', '') ?></td>
                                            <td><?= number_format((float) ($row['reorder_level'] ?? 0), 3, '.', '') ?></td>
                                            <td><?= number_format((float) ($row['par_level'] ?? 0), 3, '.', '') ?></td>
                                            <td><?= number_format((float) ($row['maximum_level'] ?? 0), 3, '.', '') ?></td>
                                            <td><?= htmlspecialchars($row['preferred_count_unit_name'] ?? 'الوحدة الأساسية', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($row['preferred_purchase_unit_name'] ?? 'الوحدة الأساسية', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($row['default_supplier_name'] ?? 'بدون مورد افتراضي', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="inventory-stock-level-status <?= (int) ($row['is_active'] ?? 0) === 1 ? '' : 'inactive' ?>"><?= (int) ($row['is_active'] ?? 0) === 1 ? 'نشط' : 'متوقف' ?></span></td>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="inventory-stock-level-row-action"
                                                    title="تحميل للتعديل"
                                                    data-stock-level-load
                                                    data-store-id="<?= (int) ($row['store_id'] ?? 0) ?>"
                                                    data-store-name="<?= htmlspecialchars((string) ($row['store_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-item-id="<?= (int) ($row['item_id'] ?? 0) ?>"
                                                    data-item-name="<?= htmlspecialchars((string) (($row['item_name'] ?? '') . (($row['barcode'] ?? '') !== '' ? ' - ' . $row['barcode'] : '')), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-minimum-level="<?= htmlspecialchars((string) ($row['minimum_level'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-reorder-level="<?= htmlspecialchars((string) ($row['reorder_level'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-par-level="<?= htmlspecialchars((string) ($row['par_level'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-maximum-level="<?= htmlspecialchars((string) ($row['maximum_level'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-safety-stock="<?= htmlspecialchars((string) ($row['safety_stock_qty'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-count-unit-id="<?= (int) ($row['preferred_count_unit_id'] ?? 0) ?>"
                                                    data-purchase-unit-id="<?= (int) ($row['preferred_purchase_unit_id'] ?? 0) ?>"
                                                    data-default-supplier-id="<?= (int) ($row['default_supplier_account_id'] ?? 0) ?>"
                                                    data-is-active="<?= (int) ($row['is_active'] ?? 0) ?>"
                                                ><i class="fas fa-pen"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!$inventoryStockLevelRows): ?>
                            <div class="alert alert-info mb-0">
                                لم يتم ضبط مستويات مخزون بعد. تأكد من وجود <a href="add_acc.php">مخزن</a> و<a href="myitems.php">أصناف</a>،
                                ثم استلم أول كمية من <a href="inventory_purchasing.php">شاشة استلام المشتريات</a>، أو أضِف مستوى مخزون يدويًا من النموذج أعلاه.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="inventory-stock-level-toast" id="inventoryStockLevelToast"></div>

<script>
const inventoryStockLevelUnits = <?= json_encode($inventoryStockLevelUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryStockLevelHasDefaultSupplierColumn = <?= $inventoryStockLevelHasDefaultSupplierColumn ? 'true' : 'false' ?>;

function inventoryStockLevelEscapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
}

function inventoryStockLevelToast(message, isError = false) {
    const toast = document.getElementById('inventoryStockLevelToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

function inventoryStockLevelDecimal(id) {
    return Number(document.getElementById(id).value || 0);
}

function inventoryStockLevelUnitOptions(itemId) {
    const units = inventoryStockLevelUnits.filter(unit => Number(unit.item_id || 0) === Number(itemId || 0));
    const options = ['<option value="">الوحدة الأساسية</option>'];
    units.forEach(unit => {
        const label = `${unit.uname || unit.unit_id} × ${Number(unit.u_val || 1).toFixed(3)}`;
        options.push(`<option value="${Number(unit.unit_id || 0)}">${inventoryStockLevelEscapeHtml(label)}</option>`);
    });
    return options.join('');
}

function refreshInventoryStockLevelUnitSelectors() {
    const itemId = Number(document.getElementById('inventoryStockLevelItem').value || 0);
    const options = inventoryStockLevelUnitOptions(itemId);
    document.getElementById('inventoryStockLevelCountUnit').innerHTML = options;
    document.getElementById('inventoryStockLevelPurchaseUnit').innerHTML = options;
}

function inventoryStockLevelEnsureOption(select, value, label) {
    if (!value) {
        return;
    }
    const exists = Array.from(select.options).some(option => Number(option.value || 0) === Number(value || 0));
    if (exists) {
        return;
    }
    const option = document.createElement('option');
    option.value = String(value);
    option.textContent = label || value;
    select.appendChild(option);
}

function inventoryStockLevelApplyItemSearch() {
    const term = document.getElementById('inventoryStockLevelItemSearch').value.trim().toLowerCase();
    const select = document.getElementById('inventoryStockLevelItem');
    let visibleCount = 0;
    Array.from(select.options).forEach(option => {
        if (!option.value) {
            option.hidden = false;
            return;
        }
        const matches = term === '' || option.textContent.toLowerCase().includes(term);
        option.hidden = !matches;
        if (matches) {
            visibleCount += 1;
        }
    });
    let note = document.getElementById('inventoryStockLevelItemFilterNote');
    if (!note) {
        note = document.createElement('div');
        note.id = 'inventoryStockLevelItemFilterNote';
        note.className = 'inventory-stock-level-filter-note';
        select.insertAdjacentElement('afterend', note);
    }
    note.textContent = term === '' ? '' : 'نتائج مطابقة: ' + visibleCount;
}

function inventoryStockLevelLoadRow(button) {
    const storeSelect = document.getElementById('inventoryStockLevelStore');
    const itemSelect = document.getElementById('inventoryStockLevelItem');
    inventoryStockLevelEnsureOption(storeSelect, button.dataset.storeId, button.dataset.storeName);
    inventoryStockLevelEnsureOption(itemSelect, button.dataset.itemId, button.dataset.itemName);
    storeSelect.value = button.dataset.storeId || '';
    itemSelect.value = button.dataset.itemId || '';
    document.getElementById('inventoryStockLevelItemSearch').value = button.dataset.itemName || '';
    inventoryStockLevelApplyItemSearch();
    refreshInventoryStockLevelUnitSelectors();
    document.getElementById('inventoryStockLevelMinimum').value = button.dataset.minimumLevel || '0';
    document.getElementById('inventoryStockLevelReorder').value = button.dataset.reorderLevel || '0';
    document.getElementById('inventoryStockLevelPar').value = button.dataset.parLevel || '0';
    document.getElementById('inventoryStockLevelMaximum').value = button.dataset.maximumLevel || '0';
    document.getElementById('inventoryStockLevelSafety').value = button.dataset.safetyStock || '0';
    document.getElementById('inventoryStockLevelCountUnit').value = button.dataset.countUnitId || '';
    document.getElementById('inventoryStockLevelPurchaseUnit').value = button.dataset.purchaseUnitId || '';
    if (inventoryStockLevelHasDefaultSupplierColumn) {
        document.getElementById('inventoryStockLevelSupplier').value = button.dataset.defaultSupplierId || '';
    }
    document.getElementById('inventoryStockLevelActive').checked = Number(button.dataset.isActive || 0) === 1;
    inventoryStockLevelToast('تم تحميل المستوى للتعديل');
    document.getElementById('inventoryStockLevelMinimum').focus();
}

document.getElementById('inventoryStockLevelItem').addEventListener('change', refreshInventoryStockLevelUnitSelectors);
document.getElementById('inventoryStockLevelItemSearch').addEventListener('input', inventoryStockLevelApplyItemSearch);
document.querySelectorAll('[data-stock-level-load]').forEach(button => {
    button.addEventListener('click', () => inventoryStockLevelLoadRow(button));
});

document.getElementById('saveInventoryStockLevel').addEventListener('click', async () => {
    const payload = {
        store_id: Number(document.getElementById('inventoryStockLevelStore').value || 0),
        item_id: Number(document.getElementById('inventoryStockLevelItem').value || 0),
        minimum_level: inventoryStockLevelDecimal('inventoryStockLevelMinimum').toFixed(6),
        reorder_level: inventoryStockLevelDecimal('inventoryStockLevelReorder').toFixed(6),
        par_level: inventoryStockLevelDecimal('inventoryStockLevelPar').toFixed(6),
        maximum_level: inventoryStockLevelDecimal('inventoryStockLevelMaximum').toFixed(6),
        safety_stock_qty: inventoryStockLevelDecimal('inventoryStockLevelSafety').toFixed(6),
        preferred_count_unit_id: Number(document.getElementById('inventoryStockLevelCountUnit').value || 0),
        preferred_purchase_unit_id: Number(document.getElementById('inventoryStockLevelPurchaseUnit').value || 0),
        default_supplier_account_id: inventoryStockLevelHasDefaultSupplierColumn ? Number(document.getElementById('inventoryStockLevelSupplier').value || 0) : 0,
        is_active: document.getElementById('inventoryStockLevelActive').checked ? 1 : 0
    };
    if (!payload.store_id || !payload.item_id) {
        inventoryStockLevelToast('اختر المخزن والصنف قبل الحفظ', true);
        return;
    }
    try {
        const response = await fetch('ajax/inventory_stock_level_save.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="inventory-stock-level-csrf"]').content
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر حفظ مستويات المخزون');
        }
        inventoryStockLevelToast(result.message || 'تم الحفظ');
        window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
        inventoryStockLevelToast(error.message || 'تعذر حفظ مستويات المخزون', true);
    }
});

document.getElementById('applyInventoryStockLevelCategory').addEventListener('click', async () => {
    const payload = {
        action: 'category_update',
        store_id: Number(document.getElementById('inventoryStockLevelStore').value || 0),
        category_id: Number(document.getElementById('inventoryStockLevelCategory').value || 0),
        minimum_level: inventoryStockLevelDecimal('inventoryStockLevelMinimum').toFixed(6),
        reorder_level: inventoryStockLevelDecimal('inventoryStockLevelReorder').toFixed(6),
        par_level: inventoryStockLevelDecimal('inventoryStockLevelPar').toFixed(6),
        maximum_level: inventoryStockLevelDecimal('inventoryStockLevelMaximum').toFixed(6),
        safety_stock_qty: inventoryStockLevelDecimal('inventoryStockLevelSafety').toFixed(6),
        is_active: document.getElementById('inventoryStockLevelActive').checked ? 1 : 0
    };
    if (!payload.store_id || !payload.category_id) {
        inventoryStockLevelToast('اختر المخزن والتصنيف قبل التطبيق', true);
        return;
    }
    try {
        const response = await fetch('ajax/inventory_stock_level_bulk.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="inventory-stock-level-csrf"]').content
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر تطبيق مستويات التصنيف');
        }
        inventoryStockLevelToast(result.message || 'تم تطبيق مستويات التصنيف');
        window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
        inventoryStockLevelToast(error.message || 'تعذر تطبيق مستويات التصنيف', true);
    }
});

const inventoryStockLevelImportButton = document.getElementById('importInventoryStockLevels');
if (inventoryStockLevelImportButton) {
    inventoryStockLevelImportButton.addEventListener('click', async () => {
        const csv = document.getElementById('inventoryStockLevelBulkCsv').value.trim();
        if (!csv) {
            inventoryStockLevelToast('ألصق بيانات CSV قبل الاستيراد', true);
            return;
        }
        try {
            const response = await fetch('ajax/inventory_stock_level_bulk.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': document.querySelector('meta[name="inventory-stock-level-csrf"]').content
                },
                body: JSON.stringify({ action: 'import_csv', csv })
            });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'تعذر استيراد مستويات المخزون');
            }
            inventoryStockLevelToast(result.message || 'تم الاستيراد');
            window.setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            inventoryStockLevelToast(error.message || 'تعذر استيراد مستويات المخزون', true);
        }
    });
}

refreshInventoryStockLevelUnitSelectors();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function inventoryStockLevelRows(mysqli $conn, string $sql): array
{
    $rows = [];
    try {
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $rows;
}

function inventoryStockLevelColumnExists(mysqli $conn, string $table, string $column): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) ($row['column_count'] ?? 0) > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function inventoryStockLevelExportCsv(mysqli $conn, string $mode): void
{
    $mode = $mode === 'current' ? 'current' : 'template';
    $filename = $mode === 'current' ? 'inventory_stock_levels.csv' : 'inventory_stock_level_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if (!$output) {
        return;
    }
    fputcsv($output, [
        'store_id',
        'store_name',
        'item_id',
        'item_name',
        'minimum_level',
        'reorder_level',
        'par_level',
        'maximum_level',
        'safety_stock_qty',
        'preferred_count_unit_id',
        'preferred_purchase_unit_id',
        'default_supplier_account_id',
        'is_active',
    ]);

    if ($mode === 'current') {
        $hasDefaultSupplierColumn = inventoryStockLevelColumnExists($conn, 'inventory_item_stock_levels', 'default_supplier_account_id');
        $rows = inventoryStockLevelRows($conn, "
            SELECT
                levels.store_id,
                store.aname AS store_name,
                levels.item_id,
                item.iname AS item_name,
                levels.minimum_level,
                levels.reorder_level,
                levels.par_level,
                levels.maximum_level,
                levels.safety_stock_qty,
                COALESCE(levels.preferred_count_unit_id, 0) AS preferred_count_unit_id,
                COALESCE(levels.preferred_purchase_unit_id, 0) AS preferred_purchase_unit_id,
                " . ($hasDefaultSupplierColumn ? "COALESCE(levels.default_supplier_account_id, 0)" : "0") . " AS default_supplier_account_id,
                levels.is_active
            FROM inventory_item_stock_levels levels
            LEFT JOIN myitems item ON item.id = levels.item_id
            LEFT JOIN acc_head store ON store.id = levels.store_id
            ORDER BY store.aname, item.iname, levels.store_id, levels.item_id
            LIMIT 5000
        ");
        foreach ($rows as $row) {
            fputcsv($output, [
                (int) ($row['store_id'] ?? 0),
                (string) ($row['store_name'] ?? ''),
                (int) ($row['item_id'] ?? 0),
                (string) ($row['item_name'] ?? ''),
                (string) ($row['minimum_level'] ?? '0.000000'),
                (string) ($row['reorder_level'] ?? '0.000000'),
                (string) ($row['par_level'] ?? '0.000000'),
                (string) ($row['maximum_level'] ?? '0.000000'),
                (string) ($row['safety_stock_qty'] ?? '0.000000'),
                (int) ($row['preferred_count_unit_id'] ?? 0),
                (int) ($row['preferred_purchase_unit_id'] ?? 0),
                (int) ($row['default_supplier_account_id'] ?? 0),
                (int) ($row['is_active'] ?? 1),
            ]);
        }
    }

    fclose($output);
}
