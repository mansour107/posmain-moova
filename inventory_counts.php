<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/InventoryFeatureFlags.php';

require_permission('inventory.edit', $conn);

$inventoryCountFlags = new InventoryFeatureFlags();
$inventoryCountMode = $inventoryCountFlags->mode();
$inventoryCountCanClose = $inventoryCountFlags->canWriteLedger();
$inventoryCountStores = inventoryCountRows($conn, "
    SELECT id, aname
    FROM acc_head
    WHERE isdeleted = 0
      AND is_stock = 1
    ORDER BY aname
    LIMIT 100
");
$inventoryCountItems = inventoryCountRows($conn, "
    SELECT id, iname, barcode
    FROM myitems
    WHERE COALESCE(isdeleted, 0) = 0
      AND COALESCE(track_stock, 1) = 1
      AND COALESCE(item_type, 'sellable') <> 'service'
    ORDER BY iname
    LIMIT 500
");
$inventoryCountCategories = inventoryCountRows($conn, "
    SELECT id, gname
    FROM item_group
    WHERE COALESCE(isdeleted, 0) = 0
    ORDER BY gname
    LIMIT 200
");
$inventoryCountUnits = inventoryCountRows($conn, "
    SELECT iu.item_id, iu.unit_id, iu.u_val, COALESCE(u.uname, CONCAT('وحدة ', iu.unit_id)) AS uname
    FROM item_units iu
    LEFT JOIN myunits u ON u.id = iu.unit_id
    WHERE COALESCE(iu.isdeleted, 0) = 0
      AND COALESCE(u.isdeleted, 0) = 0
    ORDER BY iu.item_id, u.uname, iu.unit_id
    LIMIT 1000
");
$inventoryCounts = inventoryCountRows($conn, "
    SELECT c.id, c.store_id, c.status, c.count_type, c.created_at, c.submitted_at, c.approved_at, c.closed_at,
           s.aname AS store_name,
           COUNT(l.id) AS line_count,
           SUM(CASE WHEN l.counted_qty IS NOT NULL THEN 1 ELSE 0 END) AS counted_lines
    FROM inventory_counts c
    LEFT JOIN inventory_count_lines l ON l.count_id = c.id
    LEFT JOIN acc_head s ON s.id = c.store_id
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 120
");

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= csrf_meta_tag('inventory_count', 'inventory-count-csrf') ?>

<style>
    .inventory-count-page {
        direction: rtl;
        background: #f6f8fb;
        min-height: calc(100vh - 57px);
    }
    .inventory-count-wrap {
        max-width: 1440px;
        margin: 0 auto;
        padding: 18px;
    }
    .inventory-count-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        background: #122033;
        color: #fff;
        border-radius: 8px;
        box-shadow: 0 12px 28px rgba(16, 32, 51, 0.16);
    }
    .inventory-count-title {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 0;
    }
    .inventory-count-subtitle {
        margin: 6px 0 0;
        color: #c8d4df;
        font-size: 13px;
    }
    .inventory-count-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.2);
        color: #fff;
        padding: 9px 12px;
        border-radius: 8px;
        white-space: nowrap;
    }
    .inventory-count-grid {
        display: grid;
        grid-template-columns: minmax(300px, 400px) 1fr;
        gap: 16px;
        margin-top: 16px;
        min-width: 0;
    }
    .inventory-count-panel {
        background: #fff;
        border: 1px solid #dde5ee;
        border-radius: 8px;
        box-shadow: 0 8px 20px rgba(21, 35, 50, 0.07);
        min-width: 0;
        overflow: hidden;
    }
    .inventory-count-panel-header {
        padding: 14px 16px;
        border-bottom: 1px solid #e7edf3;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    .inventory-count-panel-title {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: #102033;
    }
    .inventory-count-panel-body {
        padding: 16px;
    }
    .inventory-count-field {
        margin-bottom: 13px;
        min-width: 0;
    }
    .inventory-count-field label {
        display: block;
        font-size: 12px;
        color: #4f6175;
        margin-bottom: 6px;
        font-weight: 700;
    }
    .inventory-count-field .form-control {
        border-radius: 8px;
        border-color: #cfdae5;
        min-height: 40px;
        max-width: 100%;
        min-width: 0;
    }
    .inventory-count-search {
        margin-bottom: 8px;
    }
    .inventory-count-filter-note {
        margin-top: 6px;
        color: #64748b;
        font-size: 12px;
    }
    .inventory-count-check {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        background: #f8fafc;
        border: 1px solid #dde5ee;
        border-radius: 8px;
        color: #334155;
        font-size: 12px;
        font-weight: 700;
    }
    .inventory-count-check input {
        margin: 0;
    }
    .inventory-count-help {
        margin-top: -4px;
        margin-bottom: 12px;
        color: #64748b;
        font-size: 12px;
        line-height: 1.6;
    }
    .inventory-count-selected-strip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 8px;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
    }
    .inventory-count-table th {
        background: #eef3f7;
        color: #334155;
        font-size: 12px;
        border-color: #dde5ee;
        white-space: nowrap;
    }
    .inventory-count-table td {
        border-color: #e5ebf1;
        vertical-align: middle;
    }
    .inventory-count-btn {
        min-height: 42px;
        border-radius: 8px;
        border: 0;
        background: #0f766e;
        color: #fff;
        font-weight: 800;
        padding: 0 18px;
    }
    .inventory-count-icon-btn {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        border: 1px solid #cfdae5;
        background: #fff;
        color: #102033;
    }
    .inventory-count-toast {
        display: none;
        position: fixed;
        left: 22px;
        bottom: 22px;
        z-index: 2000;
        min-width: 280px;
        max-width: 420px;
        border-radius: 8px;
        padding: 13px 16px;
        color: #fff;
        background: #102033;
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.22);
    }
    .inventory-count-toast.error {
        background: #b91c1c;
    }
    @media (max-width: 992px) {
        .inventory-count-grid {
            grid-template-columns: minmax(0, 1fr);
        }
        .inventory-count-header {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="content-wrapper inventory-count-page">
    <section class="content-header">
        <div class="inventory-count-wrap">
            <div class="inventory-count-header">
                <div>
                    <h1 class="inventory-count-title">جرد المخزون</h1>
                    <p class="inventory-count-subtitle">مطابقة الكمية الفعلية مع دفتر المخزون</p>
                </div>
                <div class="inventory-count-pill">
                    <i class="fas fa-clipboard-check"></i>
                    <span>وضع المخزون: <?= htmlspecialchars($inventoryCountMode, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <?php if (!$inventoryCountCanClose): ?>
                <div class="alert alert-warning mt-3 mb-0">يمكن إنشاء الجرد، لكن الإغلاق يحتاج وضع bridge أو live للمخزون.</div>
            <?php endif; ?>

            <div class="inventory-count-grid">
                <div class="inventory-count-panel">
                    <div class="inventory-count-panel-header">
                        <h2 class="inventory-count-panel-title">جرد جديد</h2>
                    </div>
                    <div class="inventory-count-panel-body">
                        <div class="inventory-count-field">
                            <label for="inventoryCountStore">المخزن</label>
                            <select id="inventoryCountStore" class="form-control">
                                <?php foreach ($inventoryCountStores as $store): ?>
                                    <option value="<?= (int) $store['id'] ?>"><?= htmlspecialchars($store['aname'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-count-field">
                            <label for="inventoryCountType">نوع الجرد</label>
                            <select id="inventoryCountType" class="form-control">
                                <option value="selected">أصناف محددة</option>
                                <option value="spot">جرد سريع</option>
                                <option value="category">تصنيف كامل</option>
                                <option value="full">جرد كامل</option>
                            </select>
                        </div>
                        <div class="inventory-count-field" id="inventoryCountCategoryField" style="display:none;">
                            <label for="inventoryCountCategory">التصنيف</label>
                            <select id="inventoryCountCategory" class="form-control">
                                <option value="">اختر التصنيف</option>
                                <?php foreach ($inventoryCountCategories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars(($category['gname'] ?? '') !== '' ? $category['gname'] : 'تصنيف غير مسمى', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-count-field">
                            <label class="inventory-count-check" for="inventoryCountLowStockOnly">
                                <input id="inventoryCountLowStockOnly" type="checkbox">
                                <span>الأصناف تحت حد إعادة الطلب فقط</span>
                            </label>
                        </div>
                        <div class="inventory-count-help" id="inventoryCountScopeHelp">اختر الأصناف يدوياً أو استخدم الجرد الكامل/التصنيف ليتم فتح البنود تلقائياً من النظام.</div>
                        <div class="inventory-count-field">
                            <label for="inventoryCountItem">الصنف</label>
                            <input id="inventoryCountItemSearch" class="form-control inventory-count-search" type="search" autocomplete="off" placeholder="ابحث باسم الصنف أو الباركود">
                            <div class="d-flex" style="gap:8px;">
                                <select id="inventoryCountItem" class="form-control">
                                    <option value="">اختر الصنف</option>
                                    <?php foreach ($inventoryCountItems as $item): ?>
                                        <option value="<?= (int) $item['id'] ?>"><?= htmlspecialchars(($item['iname'] ?? '') . (($item['barcode'] ?? '') !== '' ? ' - ' . $item['barcode'] : ''), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="inventory-count-icon-btn" id="addInventoryCountItem" title="إضافة"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div class="inventory-count-field">
                            <label for="inventoryCountUnit">الوحدة</label>
                            <select id="inventoryCountUnit" class="form-control">
                                <option value="">الوحدة الأساسية</option>
                            </select>
                        </div>
                        <div class="inventory-count-field">
                            <label for="inventoryCountNotes">ملاحظات</label>
                            <textarea id="inventoryCountNotes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="table-responsive">
                            <div class="inventory-count-selected-strip">
                                <span>الأصناف المختارة</span>
                                <strong id="inventoryCountSelectedTotal">0</strong>
                            </div>
                            <table class="table table-bordered inventory-count-table">
                                <thead>
                                    <tr>
                                        <th>الأصناف المختارة</th>
                                        <th style="width:60px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="inventoryCountItemsBody"></tbody>
                            </table>
                        </div>
                        <div class="mt-3 d-flex justify-content-end">
                            <button type="button" class="inventory-count-btn" id="createInventoryCount"><i class="fas fa-check"></i> فتح الجرد</button>
                        </div>
                    </div>
                </div>

                <div class="inventory-count-panel">
                    <div class="inventory-count-panel-header">
                        <h2 class="inventory-count-panel-title">الجرد الحالي</h2>
                    </div>
                    <div class="inventory-count-panel-body">
                        <div class="table-responsive">
                            <table class="table table-bordered inventory-count-table">
                                <thead>
                                    <tr>
                                        <th>المستند</th>
                                        <th>المخزن</th>
                                        <th>الحالة</th>
                                        <th>الأصناف</th>
                                        <th>تاريخ الفتح</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryCounts as $count): ?>
                                        <tr>
                                            <td>COUNT-<?= (int) $count['id'] ?></td>
                                            <td><?= htmlspecialchars((($count['store_name'] ?? '') !== '') ? (string) $count['store_name'] : 'مخزن غير مسمى', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(inventoryCountStatusLabel((string) ($count['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int) $count['counted_lines'] ?> / <?= (int) $count['line_count'] ?></td>
                                            <td><?= htmlspecialchars($count['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><a class="btn btn-sm btn-outline-primary" href="inventory_count_detail.php?id=<?= (int) $count['id'] ?>"><i class="fas fa-eye"></i></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="inventory-count-toast" id="inventoryCountToast"></div>

<script>
const inventoryCountItems = <?= json_encode($inventoryCountItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryCountUnits = <?= json_encode($inventoryCountUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const selectedInventoryCountItems = new Map();

function inventoryCountEscapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[character]));
}

function inventoryCountToast(message, isError = false) {
    const toast = document.getElementById('inventoryCountToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

function renderInventoryCountItems() {
    const body = document.getElementById('inventoryCountItemsBody');
    body.innerHTML = '';
    selectedInventoryCountItems.forEach((entry, id) => {
        const row = document.createElement('tr');
        row.innerHTML = `<td>${inventoryCountEscapeHtml(entry.label)}<br><small>${inventoryCountEscapeHtml(entry.unit_label)}</small></td><td><button type="button" class="inventory-count-icon-btn" data-remove-count-item="${id}" title="حذف"><i class="fas fa-trash"></i></button></td>`;
        body.appendChild(row);
    });
    document.querySelectorAll('[data-remove-count-item]').forEach(button => {
        button.addEventListener('click', () => {
            selectedInventoryCountItems.delete(Number(button.dataset.removeCountItem || 0));
            renderInventoryCountItems();
        });
    });
    document.getElementById('inventoryCountSelectedTotal').textContent = String(selectedInventoryCountItems.size);
}

function inventoryCountUnitOptions(itemId, selectedUnitId = 0) {
    const units = inventoryCountUnits.filter(unit => Number(unit.item_id || 0) === Number(itemId || 0));
    const options = ['<option value="">الوحدة الأساسية</option>'];
    units.forEach(unit => {
        const unitId = Number(unit.unit_id || 0);
        const selected = unitId === Number(selectedUnitId || 0) ? ' selected' : '';
        const label = `${unit.uname || unitId} × ${Number(unit.u_val || 1).toFixed(3)}`;
        options.push(`<option value="${unitId}"${selected}>${inventoryCountEscapeHtml(label)}</option>`);
    });

    return options.join('');
}

function refreshInventoryCountScopeControls() {
    const type = document.getElementById('inventoryCountType').value;
    const lowStockOnly = document.getElementById('inventoryCountLowStockOnly').checked;
    const autoFill = type === 'full' || type === 'category' || lowStockOnly;
    document.getElementById('inventoryCountCategoryField').style.display = type === 'category' ? 'block' : 'none';
    document.getElementById('inventoryCountScopeHelp').textContent = autoFill
        ? 'سيتم إنشاء بنود الجرد تلقائياً عند فتح الجرد. يمكنك إضافة أصناف يدوياً عند الحاجة.'
        : 'أضف صنفاً واحداً أو أكثر قبل فتح الجرد.';
}

function applyInventoryCountItemSearch() {
    const term = document.getElementById('inventoryCountItemSearch').value.trim().toLowerCase();
    const select = document.getElementById('inventoryCountItem');
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
    let note = document.getElementById('inventoryCountItemFilterNote');
    if (!note) {
        note = document.createElement('div');
        note.id = 'inventoryCountItemFilterNote';
        note.className = 'inventory-count-filter-note';
        select.parentElement.insertAdjacentElement('afterend', note);
    }
    note.textContent = term === '' ? '' : 'نتائج مطابقة: ' + visibleCount;
}

document.getElementById('addInventoryCountItem').addEventListener('click', () => {
    const select = document.getElementById('inventoryCountItem');
    const unitSelect = document.getElementById('inventoryCountUnit');
    const id = Number(select.value || 0);
    if (!id) {
        inventoryCountToast('اختر الصنف', true);
        return;
    }
    selectedInventoryCountItems.set(id, {
        item_id: id,
        unit_id: Number(unitSelect.value || 0),
        label: select.options[select.selectedIndex].textContent,
        unit_label: unitSelect.options[unitSelect.selectedIndex] ? unitSelect.options[unitSelect.selectedIndex].textContent : 'الوحدة الأساسية'
    });
    select.value = '';
    document.getElementById('inventoryCountItemSearch').value = '';
    applyInventoryCountItemSearch();
    unitSelect.innerHTML = inventoryCountUnitOptions(0);
    renderInventoryCountItems();
});

document.getElementById('inventoryCountItem').addEventListener('change', event => {
    document.getElementById('inventoryCountUnit').innerHTML = inventoryCountUnitOptions(Number(event.target.value || 0));
});
document.getElementById('inventoryCountItemSearch').addEventListener('input', applyInventoryCountItemSearch);
document.getElementById('inventoryCountType').addEventListener('change', refreshInventoryCountScopeControls);
document.getElementById('inventoryCountLowStockOnly').addEventListener('change', refreshInventoryCountScopeControls);

document.getElementById('createInventoryCount').addEventListener('click', async () => {
    const countType = document.getElementById('inventoryCountType').value;
    const lowStockOnly = document.getElementById('inventoryCountLowStockOnly').checked;
    const lines = Array.from(selectedInventoryCountItems.values()).map(entry => {
        const line = { item_id: Number(entry.item_id || 0) };
        if (Number(entry.unit_id || 0) > 0) {
            line.unit_id = Number(entry.unit_id);
        }
        return line;
    });
    const autoFill = countType === 'full' || countType === 'category' || lowStockOnly;
    if (!Number(document.getElementById('inventoryCountStore').value || 0) || (!autoFill && lines.length === 0)) {
        inventoryCountToast('اختر المخزن وصنفاً واحداً على الأقل', true);
        return;
    }
    if (countType === 'category' && !Number(document.getElementById('inventoryCountCategory').value || 0)) {
        inventoryCountToast('اختر التصنيف', true);
        return;
    }
    const payload = {
        store_id: Number(document.getElementById('inventoryCountStore').value || 0),
        count_type: countType,
        low_stock_only: lowStockOnly ? 1 : 0,
        notes: document.getElementById('inventoryCountNotes').value.trim(),
        lines
    };
    if (countType === 'category') {
        payload.category_id = Number(document.getElementById('inventoryCountCategory').value || 0);
    }
    try {
        const response = await fetch('ajax/inventory_count_create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="inventory-count-csrf"]').content
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر إنشاء الجرد');
        }
        window.location.href = `inventory_count_detail.php?id=${Number(result.count_id || 0)}`;
    } catch (error) {
        inventoryCountToast(error.message || 'تعذر إنشاء الجرد', true);
    }
});

refreshInventoryCountScopeControls();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function inventoryCountRows(mysqli $conn, string $sql): array
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

function inventoryCountStatusLabel(string $status): string
{
    $labels = [
        'draft' => 'مسودة',
        'submitted' => 'بانتظار الاعتماد',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض',
        'closed' => 'مغلق',
        'cancelled' => 'ملغي',
    ];

    return $labels[$status] ?? 'حالة غير معروفة';
}
