<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/classes/Inventory/InventoryReasonCodeService.php';
require_once __DIR__ . '/classes/Inventory/InventoryScopeResolver.php';
require_once __DIR__ . '/classes/Recipe/RecipeWasteAdjustmentReadService.php';

require_permission('inventory.edit', $conn);

$inventoryAdjustmentFlags = new InventoryFeatureFlags();
$inventoryAdjustmentMode = $inventoryAdjustmentFlags->mode();
$inventoryAdjustmentCanPost = $inventoryAdjustmentFlags->canWriteLedger();
$inventoryAdjustmentCanViewCost = auth_guard_has_permission('accounting.view', $conn) || auth_guard_has_permission('reports.view', $conn);
$inventoryAdjustmentScope = (new InventoryScopeResolver($inventoryAdjustmentFlags->appConfig()))->resolve([
    'source' => 'inventory_adjustment',
]);
$inventoryAdjustmentReasonService = new InventoryReasonCodeService();
$inventoryAdjustmentReasonCodes = [
    'waste' => $inventoryAdjustmentReasonService->listForOperation($conn, $inventoryAdjustmentScope, 'waste', 'decrease'),
    'increase' => $inventoryAdjustmentReasonService->listForOperation($conn, $inventoryAdjustmentScope, 'adjustment', 'increase'),
    'decrease' => $inventoryAdjustmentReasonService->listForOperation($conn, $inventoryAdjustmentScope, 'adjustment', 'decrease'),
];
$inventoryAdjustmentStores = inventoryAdjustmentRows($conn, "
    SELECT id, aname
    FROM acc_head
    WHERE isdeleted = 0
      AND is_stock = 1
    ORDER BY aname
    LIMIT 100
");
$inventoryAdjustmentItemCostSelect = $inventoryAdjustmentCanViewCost ? 'cost_price' : '0.000000 AS cost_price';
$inventoryAdjustmentBalanceCostSelect = $inventoryAdjustmentCanViewCost ? 'moving_average_cost' : '0.000000 AS moving_average_cost';
$inventoryAdjustmentItems = inventoryAdjustmentRows($conn, "
    SELECT id, iname, barcode, {$inventoryAdjustmentItemCostSelect}
    FROM myitems
    WHERE COALESCE(isdeleted, 0) = 0
      AND COALESCE(track_stock, 1) = 1
      AND COALESCE(item_type, 'sellable') <> 'service'
    ORDER BY iname
    LIMIT 500
");
$inventoryAdjustmentUnitCostSelect = $inventoryAdjustmentCanViewCost ? 'iu.cost_price' : '0.000000 AS cost_price';
$inventoryAdjustmentUnits = inventoryAdjustmentRows($conn, "
    SELECT iu.item_id, iu.unit_id, iu.u_val, {$inventoryAdjustmentUnitCostSelect}, COALESCE(u.uname, CONCAT('وحدة ', iu.unit_id)) AS uname
    FROM item_units iu
    LEFT JOIN myunits u ON u.id = iu.unit_id
    WHERE COALESCE(iu.isdeleted, 0) = 0
      AND COALESCE(u.isdeleted, 0) = 0
    ORDER BY iu.item_id, u.uname, iu.unit_id
    LIMIT 1000
");
$inventoryAdjustmentBalances = inventoryAdjustmentRows($conn, "
    SELECT item_id, store_id, qty_on_hand, qty_available, {$inventoryAdjustmentBalanceCostSelect}
    FROM inventory_item_balances
    ORDER BY store_id, item_id
    LIMIT 3000
");
$inventoryAdjustmentPreferredUnits = inventoryAdjustmentRows($conn, "
    SELECT item_id, store_id, COALESCE(preferred_count_unit_id, preferred_purchase_unit_id, 0) AS preferred_unit_id
    FROM inventory_item_stock_levels
    WHERE is_active = 1
      AND (preferred_count_unit_id IS NOT NULL OR preferred_purchase_unit_id IS NOT NULL)
    ORDER BY store_id, item_id
    LIMIT 3000
");
$inventoryAdjustmentRows = (new RecipeWasteAdjustmentReadService())->recentMovements($conn, [
    'limit' => 80,
]);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= csrf_meta_tag('inventory_adjustment', 'inventory-adjustment-csrf') ?>

<style>
    html,body{overflow-x:hidden}
    .inventory-adjustment-page{direction:rtl;background:#f6f8fb;min-height:calc(100vh - 57px)}
    .inventory-adjustment-wrap{max-width:1440px;margin:0 auto;padding:18px}
    .inventory-adjustment-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;background:#122033;color:#fff;border-radius:8px;box-shadow:0 12px 28px rgba(16,32,51,.16)}
    .inventory-adjustment-title{margin:0;font-size:24px;font-weight:700;letter-spacing:0}
    .inventory-adjustment-subtitle{margin:6px 0 0;color:#c8d4df;font-size:13px}
    .inventory-adjustment-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:9px 12px;border-radius:8px;white-space:nowrap}
    .inventory-adjustment-grid{display:grid;grid-template-columns:minmax(320px,430px) 1fr;gap:16px;margin-top:16px}
    .inventory-adjustment-panel{background:#fff;border:1px solid #dde5ee;border-radius:8px;box-shadow:0 8px 20px rgba(21,35,50,.07)}
    .inventory-adjustment-panel-header{padding:14px 16px;border-bottom:1px solid #e7edf3;display:flex;justify-content:space-between;align-items:center;gap:10px}
    .inventory-adjustment-panel-title{margin:0;font-size:16px;font-weight:700;color:#102033}
    .inventory-adjustment-panel-body{padding:16px}
    .inventory-adjustment-panel-body .row{margin-left:0;margin-right:0}
    .inventory-adjustment-panel-body .row>[class*="col-"]{padding-left:6px;padding-right:6px}
    .inventory-adjustment-field{margin-bottom:13px}
    .inventory-adjustment-field label{display:block;font-size:12px;color:#4f6175;margin-bottom:6px;font-weight:700}
    .inventory-adjustment-field .form-control{border-radius:8px;border-color:#cfdae5;min-height:40px}
    .inventory-adjustment-combobox{position:relative}
    .inventory-adjustment-results{display:none;position:absolute;top:calc(100% + 4px);right:0;left:0;z-index:1050;max-height:260px;overflow:auto;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 16px 34px rgba(15,23,42,.18)}
    .inventory-adjustment-results.show{display:block}
    .inventory-adjustment-option{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:10px 12px;border:0;background:#fff;color:#102033;text-align:right}
    .inventory-adjustment-option:hover,.inventory-adjustment-option.active{background:#eef6f5}
    .inventory-adjustment-option small{color:#64748b;white-space:nowrap}
    .inventory-adjustment-empty{padding:12px;color:#64748b}
    .inventory-adjustment-segment{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
    .inventory-adjustment-segment button{border:1px solid #cfdae5;background:#f8fafc;color:#102033;border-radius:8px;min-height:40px;font-weight:700}
    .inventory-adjustment-segment button.active{background:#0f766e;border-color:#0f766e;color:#fff}
    .inventory-adjustment-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}
    .inventory-adjustment-cell{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px}
    .inventory-adjustment-cell span{display:block;color:#64748b;font-size:12px}
    .inventory-adjustment-cell strong{display:block;color:#102033;font-size:18px;margin-top:2px}
    .inventory-adjustment-table th{background:#eef3f7;color:#334155;font-size:12px;border-color:#dde5ee;white-space:nowrap}
    .inventory-adjustment-table td{border-color:#e5ebf1;vertical-align:middle}
    .inventory-adjustment-btn{min-height:42px;border-radius:8px;border:0;background:#0f766e;color:#fff;font-weight:800;padding:0 18px}
    .inventory-adjustment-btn:disabled{background:#94a3b8;cursor:not-allowed}
    .inventory-adjustment-toast{display:none;position:fixed;left:22px;bottom:22px;z-index:2000;min-width:280px;max-width:420px;border-radius:8px;padding:13px 16px;color:#fff;background:#102033;box-shadow:0 14px 34px rgba(15,23,42,.22)}
    .inventory-adjustment-toast.error{background:#b91c1c}
    @media(max-width:992px){.inventory-adjustment-grid,.inventory-adjustment-summary{grid-template-columns:1fr}.inventory-adjustment-header{align-items:flex-start;flex-direction:column}}
</style>

<div class="content-wrapper inventory-adjustment-page">
    <section class="content-header">
        <div class="inventory-adjustment-wrap">
            <div class="inventory-adjustment-header">
                <div>
                    <h1 class="inventory-adjustment-title">الهالك والتسويات</h1>
                    <p class="inventory-adjustment-subtitle">تسجيل الهالك وزيادة أو تخفيض المخزون من دفتر المخزون</p>
                </div>
                <div class="inventory-adjustment-pill">
                    <i class="fas fa-sliders-h"></i>
                    <span>وضع المخزون: <?= htmlspecialchars($inventoryAdjustmentMode, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <?php if (!$inventoryAdjustmentCanPost): ?>
                <div class="alert alert-warning mt-3 mb-0">هذه الشاشة جاهزة، لكن التسجيل يحتاج وضع bridge أو live للمخزون.</div>
            <?php endif; ?>

            <div class="inventory-adjustment-grid">
                <div class="inventory-adjustment-panel">
                    <div class="inventory-adjustment-panel-header">
                        <h2 class="inventory-adjustment-panel-title">عملية جديدة</h2>
                    </div>
                    <div class="inventory-adjustment-panel-body">
                        <div class="inventory-adjustment-field">
                            <label>نوع العملية</label>
                            <div class="inventory-adjustment-segment" role="group">
                                <button type="button" class="active" data-adjustment-action="waste"><i class="fas fa-trash"></i> هالك</button>
                                <button type="button" data-adjustment-action="increase"><i class="fas fa-plus"></i> زيادة</button>
                                <button type="button" data-adjustment-action="decrease"><i class="fas fa-minus"></i> تخفيض</button>
                            </div>
                        </div>
                        <div class="inventory-adjustment-field">
                            <label for="inventoryAdjustmentStore">المخزن</label>
                            <select id="inventoryAdjustmentStore" class="form-control">
                                <?php foreach ($inventoryAdjustmentStores as $store): ?>
                                    <option value="<?= (int) $store['id'] ?>"><?= htmlspecialchars($store['aname'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-adjustment-field">
                            <label for="inventoryAdjustmentItemSearch">الصنف</label>
                            <div class="inventory-adjustment-combobox">
                                <input id="inventoryAdjustmentItemSearch" class="form-control" type="text" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="inventoryAdjustmentItemResults" placeholder="ابحث باسم الصنف أو الباركود">
                                <div id="inventoryAdjustmentItemResults" class="inventory-adjustment-results" role="listbox"></div>
                            </div>
                            <select id="inventoryAdjustmentItem" class="form-control d-none" tabindex="-1" aria-hidden="true">
                                <option value="">اختر الصنف</option>
                                <?php foreach ($inventoryAdjustmentItems as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>"<?php if ($inventoryAdjustmentCanViewCost): ?> data-cost="<?= htmlspecialchars((string) ($item['cost_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars(($item['iname'] ?? '') . (($item['barcode'] ?? '') !== '' ? ' - ' . $item['barcode'] : ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-adjustment-field">
                            <label for="inventoryAdjustmentUnit">الوحدة</label>
                            <select id="inventoryAdjustmentUnit" class="form-control">
                                <option value="" data-conversion="1" data-cost="">الوحدة الأساسية</option>
                            </select>
                        </div>
                        <div class="inventory-adjustment-summary">
                            <div class="inventory-adjustment-cell">
                                <span>المتوفر</span>
                                <strong id="inventoryAdjustmentAvailable">0.000</strong>
                            </div>
                            <div class="inventory-adjustment-cell">
                                <span>على اليد</span>
                                <strong id="inventoryAdjustmentOnHand">0.000</strong>
                            </div>
                            <?php if ($inventoryAdjustmentCanViewCost): ?>
                                <div class="inventory-adjustment-cell">
                                    <span>متوسط التكلفة</span>
                                    <strong id="inventoryAdjustmentAverageCost">0.000</strong>
                                </div>
                            <?php else: ?>
                                <div class="inventory-adjustment-cell">
                                    <span>التكلفة</span>
                                    <strong>مخفية</strong>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="row">
                            <div class="col-md-6 inventory-adjustment-field">
                                <label for="inventoryAdjustmentQty">الكمية</label>
                                <input id="inventoryAdjustmentQty" class="form-control" type="number" min="0" step="0.001">
                            </div>
                            <div class="col-md-6 inventory-adjustment-field">
                                <label for="inventoryAdjustmentCost">تكلفة الوحدة</label>
                                <input id="inventoryAdjustmentCost" class="form-control" type="number" min="0" step="0.001" <?= $inventoryAdjustmentCanViewCost ? '' : 'readonly' ?>>
                            </div>
                        </div>
                        <div class="inventory-adjustment-field">
                            <label for="inventoryAdjustmentDate">تاريخ العملية</label>
                            <input id="inventoryAdjustmentDate" class="form-control" type="date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="inventory-adjustment-field">
                            <label for="inventoryAdjustmentReasonCode">سبب جاهز</label>
                            <select id="inventoryAdjustmentReasonCode" class="form-control">
                                <option value="">سبب مخصص / بدون كود</option>
                            </select>
                        </div>
                        <div class="inventory-adjustment-field">
                            <label for="inventoryAdjustmentReason">ملاحظات السبب</label>
                            <textarea id="inventoryAdjustmentReason" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="inventory-adjustment-field" id="inventoryAdjustmentPhotoField">
                            <label for="inventoryAdjustmentPhoto">صورة الهالك</label>
                            <input id="inventoryAdjustmentPhoto" class="form-control" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="button" class="inventory-adjustment-btn" id="postInventoryAdjustment" <?= $inventoryAdjustmentCanPost ? '' : 'disabled' ?>><i class="fas fa-check"></i> تسجيل العملية</button>
                        </div>
                    </div>
                </div>

                <div class="inventory-adjustment-panel">
                    <div class="inventory-adjustment-panel-header">
                        <h2 class="inventory-adjustment-panel-title">آخر العمليات</h2>
                    </div>
                    <div class="inventory-adjustment-panel-body">
                        <div class="table-responsive">
                            <table class="table table-bordered inventory-adjustment-table">
                                <thead>
                                    <tr>
                                        <th>الوقت</th>
                                        <th>النوع</th>
                                        <th>الاتجاه</th>
                                        <th>الصنف</th>
                                        <th>الكمية</th>
                                        <?php if ($inventoryAdjustmentCanViewCost): ?>
                                            <th>التكلفة</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryAdjustmentRows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(inventoryAdjustmentMovementLabel((string) ($row['movement_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(inventoryAdjustmentDirectionLabel((string) ($row['movement_direction'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(($row['item_name'] ?? '') !== '' ? $row['item_name'] : 'صنف غير مسمى', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= number_format((float) ($row['movement_qty'] ?? 0), 3, '.', '') ?></td>
                                            <?php if ($inventoryAdjustmentCanViewCost): ?>
                                                <td><?= number_format((float) ($row['total_cost'] ?? 0), 2, '.', '') ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!$inventoryAdjustmentRows): ?>
                            <div class="alert alert-secondary mb-0">لا توجد عمليات هالك أو تسوية حتى الآن.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="inventory-adjustment-toast" id="inventoryAdjustmentToast"></div>

<script>
const inventoryAdjustmentCanViewCost = <?= $inventoryAdjustmentCanViewCost ? 'true' : 'false' ?>;
const inventoryAdjustmentBalances = <?= json_encode($inventoryAdjustmentBalances, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryAdjustmentUnits = <?= json_encode($inventoryAdjustmentUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryAdjustmentPreferredUnits = <?= json_encode($inventoryAdjustmentPreferredUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryAdjustmentReasonCodes = <?= json_encode($inventoryAdjustmentReasonCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let inventoryAdjustmentAction = 'waste';
let inventoryAdjustmentItemActiveIndex = -1;

const inventoryAdjustmentItemSelect = document.getElementById('inventoryAdjustmentItem');
const inventoryAdjustmentItemSearch = document.getElementById('inventoryAdjustmentItemSearch');
const inventoryAdjustmentItemResults = document.getElementById('inventoryAdjustmentItemResults');
const inventoryAdjustmentItemCatalog = Array.from(inventoryAdjustmentItemSelect.options)
    .filter(option => Number(option.value || 0) > 0)
    .map(option => ({
        id: Number(option.value || 0),
        label: option.textContent.trim(),
        search: `${option.textContent.trim()} ${option.value}`.toLowerCase()
    }));

function inventoryAdjustmentToast(message, isError = false) {
    const toast = document.getElementById('inventoryAdjustmentToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

function inventoryAdjustmentApplyItemChange() {
    refreshAdjustmentUnitOptions();
    document.getElementById('inventoryAdjustmentCost').value = '';
    refreshAdjustmentBalance();
}

function inventoryAdjustmentCloseItemResults() {
    inventoryAdjustmentItemResults.classList.remove('show');
    inventoryAdjustmentItemSearch.setAttribute('aria-expanded', 'false');
    inventoryAdjustmentItemActiveIndex = -1;
}

function inventoryAdjustmentSelectedItemLabel() {
    const selected = inventoryAdjustmentItemSelect.options[inventoryAdjustmentItemSelect.selectedIndex];

    return selected && Number(selected.value || 0) > 0 ? selected.textContent.trim() : '';
}

function selectInventoryAdjustmentItem(itemId, label) {
    inventoryAdjustmentItemSelect.value = String(itemId || '');
    inventoryAdjustmentItemSearch.value = label || inventoryAdjustmentSelectedItemLabel();
    inventoryAdjustmentCloseItemResults();
    inventoryAdjustmentApplyItemChange();
}

function renderInventoryAdjustmentItemResults(query) {
    const needle = query.trim().toLowerCase();
    const rows = inventoryAdjustmentItemCatalog
        .filter(item => needle === '' || item.search.includes(needle))
        .slice(0, 20);
    inventoryAdjustmentItemResults.innerHTML = '';
    inventoryAdjustmentItemActiveIndex = -1;

    if (rows.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'inventory-adjustment-empty';
        empty.textContent = 'لا توجد نتائج مطابقة';
        inventoryAdjustmentItemResults.appendChild(empty);
    } else {
        rows.forEach((item, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'inventory-adjustment-option';
            option.setAttribute('role', 'option');
            option.dataset.itemId = String(item.id);
            option.dataset.itemIndex = String(index);

            const label = document.createElement('span');
            label.textContent = item.label;
            const hint = document.createElement('small');
            hint.textContent = 'صنف مخزني';
            option.appendChild(label);
            option.appendChild(hint);
            option.addEventListener('mousedown', event => {
                event.preventDefault();
                selectInventoryAdjustmentItem(item.id, item.label);
            });
            inventoryAdjustmentItemResults.appendChild(option);
        });
    }

    inventoryAdjustmentItemResults.classList.add('show');
    inventoryAdjustmentItemSearch.setAttribute('aria-expanded', 'true');
}

function inventoryAdjustmentMoveItemActive(delta) {
    const options = Array.from(inventoryAdjustmentItemResults.querySelectorAll('.inventory-adjustment-option'));
    if (options.length === 0) {
        return;
    }
    inventoryAdjustmentItemActiveIndex = (inventoryAdjustmentItemActiveIndex + delta + options.length) % options.length;
    options.forEach((option, index) => option.classList.toggle('active', index === inventoryAdjustmentItemActiveIndex));
    options[inventoryAdjustmentItemActiveIndex].scrollIntoView({block: 'nearest'});
}

function currentAdjustmentBalance() {
    const storeId = Number(document.getElementById('inventoryAdjustmentStore').value || 0);
    const itemId = Number(document.getElementById('inventoryAdjustmentItem').value || 0);
    return inventoryAdjustmentBalances.find(row => Number(row.store_id || 0) === storeId && Number(row.item_id || 0) === itemId) || null;
}

function inventoryAdjustmentUnitOptions(itemId, selectedUnitId = 0) {
    const units = inventoryAdjustmentUnits.filter(unit => Number(unit.item_id || 0) === Number(itemId || 0));
    const options = ['<option value="" data-conversion="1" data-cost="">الوحدة الأساسية</option>'];
    units.forEach(unit => {
        const unitId = Number(unit.unit_id || 0);
        const selected = unitId === Number(selectedUnitId || 0) ? ' selected' : '';
        const label = `${unit.uname || unitId} × ${Number(unit.u_val || 1).toFixed(3)}`;
        options.push(`<option value="${unitId}" data-conversion="${Number(unit.u_val || 1)}" data-cost="${Number(unit.cost_price || 0)}"${selected}>${label.replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]))}</option>`);
    });

    return options.join('');
}

function inventoryAdjustmentPreferredUnit(itemId) {
    const storeId = Number(document.getElementById('inventoryAdjustmentStore').value || 0);
    const preferred = inventoryAdjustmentPreferredUnits.find(row =>
        Number(row.store_id || 0) === storeId
        && Number(row.item_id || 0) === Number(itemId || 0)
        && Number(row.preferred_unit_id || 0) > 0
    );

    return preferred ? Number(preferred.preferred_unit_id || 0) : 0;
}

function refreshAdjustmentUnitOptions() {
    const itemId = Number(document.getElementById('inventoryAdjustmentItem').value || 0);
    const preferredUnitId = inventoryAdjustmentPreferredUnit(itemId);
    document.getElementById('inventoryAdjustmentUnit').innerHTML = inventoryAdjustmentUnitOptions(itemId, preferredUnitId);
}

function inventoryAdjustmentReasonOptions(action) {
    const rows = inventoryAdjustmentReasonCodes[action] || [];
    const options = ['<option value="">سبب مخصص / بدون كود</option>'];
    rows.forEach(row => {
        const suffix = Number(row.requires_approval || 0) === 1 ? ' - يحتاج اعتماد' : '';
        const label = `${row.reason_name || row.reason_code || row.id}${suffix}`;
        options.push(`<option value="${Number(row.id || 0)}">${label.replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]))}</option>`);
    });

    return options.join('');
}

function refreshAdjustmentReasonOptions() {
    document.getElementById('inventoryAdjustmentReasonCode').innerHTML = inventoryAdjustmentReasonOptions(inventoryAdjustmentAction);
}

function refreshAdjustmentPhotoState() {
    const field = document.getElementById('inventoryAdjustmentPhotoField');
    const input = document.getElementById('inventoryAdjustmentPhoto');
    const isWaste = inventoryAdjustmentAction === 'waste';
    field.style.display = isWaste ? 'block' : 'none';
    if (!isWaste) {
        input.value = '';
    }
}

function refreshAdjustmentBalance() {
    const balance = currentAdjustmentBalance();
    const itemSelect = document.getElementById('inventoryAdjustmentItem');
    const unitSelect = document.getElementById('inventoryAdjustmentUnit');
    const selected = itemSelect.options[itemSelect.selectedIndex];
    const selectedUnit = unitSelect.options[unitSelect.selectedIndex];
    const conversion = Number(selectedUnit ? selectedUnit.dataset.conversion || 1 : 1);
    const baseAvgCost = inventoryAdjustmentCanViewCost
        ? (balance ? Number(balance.moving_average_cost || 0) : Number(selected ? selected.dataset.cost || 0 : 0))
        : 0;
    const unitCost = Number(selectedUnit && selectedUnit.dataset.cost ? selectedUnit.dataset.cost : 0) || (baseAvgCost * conversion);
    document.getElementById('inventoryAdjustmentAvailable').textContent = (balance ? Number(balance.qty_available || 0) : 0).toFixed(3);
    document.getElementById('inventoryAdjustmentOnHand').textContent = (balance ? Number(balance.qty_on_hand || 0) : 0).toFixed(3);
    const avgNode = document.getElementById('inventoryAdjustmentAverageCost');
    if (avgNode) {
        avgNode.textContent = baseAvgCost.toFixed(3);
    }
    const costInput = document.getElementById('inventoryAdjustmentCost');
    if (inventoryAdjustmentCanViewCost && (!costInput.value || Number(costInput.value || 0) === 0)) {
        costInput.value = unitCost.toFixed(3);
    }
}

function randomUuid() {
    if (window.crypto && window.crypto.randomUUID) {
        return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const value = Math.random() * 16 | 0;
        const next = character === 'x' ? value : (value & 0x3 | 0x8);
        return next.toString(16);
    });
}

document.querySelectorAll('[data-adjustment-action]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-adjustment-action]').forEach(node => node.classList.remove('active'));
        button.classList.add('active');
        inventoryAdjustmentAction = button.dataset.adjustmentAction;
        refreshAdjustmentReasonOptions();
        refreshAdjustmentPhotoState();
    });
});

inventoryAdjustmentItemSearch.addEventListener('input', () => {
    if (inventoryAdjustmentItemSearch.value.trim() !== inventoryAdjustmentSelectedItemLabel()) {
        inventoryAdjustmentItemSelect.value = '';
        inventoryAdjustmentApplyItemChange();
    }
    renderInventoryAdjustmentItemResults(inventoryAdjustmentItemSearch.value);
});
inventoryAdjustmentItemSearch.addEventListener('focus', () => {
    renderInventoryAdjustmentItemResults(inventoryAdjustmentItemSearch.value);
});
inventoryAdjustmentItemSearch.addEventListener('blur', () => {
    window.setTimeout(() => {
        if (!inventoryAdjustmentItemSelect.value) {
            inventoryAdjustmentItemSearch.value = '';
        }
        inventoryAdjustmentCloseItemResults();
    }, 120);
});
inventoryAdjustmentItemSearch.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!inventoryAdjustmentItemResults.classList.contains('show')) {
            renderInventoryAdjustmentItemResults(inventoryAdjustmentItemSearch.value);
        }
        inventoryAdjustmentMoveItemActive(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (!inventoryAdjustmentItemResults.classList.contains('show')) {
            renderInventoryAdjustmentItemResults(inventoryAdjustmentItemSearch.value);
        }
        inventoryAdjustmentMoveItemActive(-1);
    } else if (event.key === 'Enter') {
        const active = inventoryAdjustmentItemResults.querySelector('.inventory-adjustment-option.active')
            || inventoryAdjustmentItemResults.querySelector('.inventory-adjustment-option');
        if (active) {
            event.preventDefault();
            selectInventoryAdjustmentItem(Number(active.dataset.itemId || 0), active.querySelector('span').textContent);
        }
    } else if (event.key === 'Escape') {
        inventoryAdjustmentCloseItemResults();
    }
});
document.getElementById('inventoryAdjustmentStore').addEventListener('change', () => {
    refreshAdjustmentUnitOptions();
    document.getElementById('inventoryAdjustmentCost').value = '';
    refreshAdjustmentBalance();
});
document.getElementById('inventoryAdjustmentUnit').addEventListener('change', () => {
    document.getElementById('inventoryAdjustmentCost').value = '';
    refreshAdjustmentBalance();
});
document.getElementById('postInventoryAdjustment').addEventListener('click', async () => {
    const qty = Number(document.getElementById('inventoryAdjustmentQty').value || 0);
    const itemId = Number(document.getElementById('inventoryAdjustmentItem').value || 0);
    const unitId = Number(document.getElementById('inventoryAdjustmentUnit').value || 0);
    const storeId = Number(document.getElementById('inventoryAdjustmentStore').value || 0);
    const reasonCodeId = Number(document.getElementById('inventoryAdjustmentReasonCode').value || 0);
    const reason = document.getElementById('inventoryAdjustmentReason').value.trim();
    const photoInput = document.getElementById('inventoryAdjustmentPhoto');
    if (!storeId || !itemId || qty <= 0 || (!reason && !reasonCodeId)) {
        inventoryAdjustmentToast('راجع المخزن والصنف والكمية والسبب قبل التسجيل', true);
        return;
    }
    const payload = {
        action: inventoryAdjustmentAction === 'waste' ? 'waste' : 'adjustment',
        direction: inventoryAdjustmentAction === 'decrease' ? 'decrease' : 'increase',
        store_id: storeId,
        item_id: itemId,
        qty: qty.toFixed(6),
        occurred_at: document.getElementById('inventoryAdjustmentDate').value,
        reason,
        operation_uuid: randomUuid()
    };
    if (reasonCodeId > 0) {
        payload.reason_code_id = reasonCodeId;
    }
    if (unitId > 0) {
        payload.unit_id = unitId;
    }
    if (inventoryAdjustmentCanViewCost) {
        payload.unit_cost = Number(document.getElementById('inventoryAdjustmentCost').value || 0).toFixed(6);
    }
    if (inventoryAdjustmentAction === 'waste') {
        delete payload.direction;
    }
    const headers = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': document.querySelector('meta[name="inventory-adjustment-csrf"]').content
    };
    let body;
    if (inventoryAdjustmentAction === 'waste' && photoInput.files && photoInput.files.length > 0) {
        const formData = new FormData();
        Object.keys(payload).forEach(key => formData.append(key, payload[key]));
        formData.append('waste_photo', photoInput.files[0]);
        body = formData;
    } else {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(payload);
    }
    try {
        const response = await fetch('ajax/inventory_adjustment.php', {
            method: 'POST',
            headers,
            body
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر تسجيل العملية');
        }
        inventoryAdjustmentToast(result.message || 'تم التسجيل');
        window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
        inventoryAdjustmentToast(error.message || 'تعذر تسجيل العملية', true);
    }
});

refreshAdjustmentReasonOptions();
refreshAdjustmentPhotoState();
refreshAdjustmentBalance();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function inventoryAdjustmentRows(mysqli $conn, string $sql): array
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

function inventoryAdjustmentMovementLabel(string $movementType): string
{
    $labels = [
        'waste' => 'هالك',
        'adjustment' => 'تسوية مخزون',
    ];

    return $labels[$movementType] ?? $movementType;
}

function inventoryAdjustmentDirectionLabel(string $direction): string
{
    $labels = [
        'in' => 'إضافة',
        'out' => 'خصم',
    ];

    return $labels[$direction] ?? $direction;
}
