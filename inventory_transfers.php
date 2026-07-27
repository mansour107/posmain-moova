<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/pos_default_accounts.php';
require_once __DIR__ . '/classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/classes/Items/ItemUnitCatalogLabel.php';
require_once __DIR__ . '/classes/Items/ItemUnitColumnSupport.php';

require_permission('inventory.edit', $conn);

$inventoryTransferFlags = new InventoryFeatureFlags();
$inventoryTransferMode = $inventoryTransferFlags->mode();
$inventoryTransferCanPost = $inventoryTransferFlags->canWriteLedger();
$inventoryTransferBranchConfig = function_exists('posmain_app_config') && is_array(posmain_app_config()['branch'] ?? null)
    ? posmain_app_config()['branch']
    : [];
$inventoryTransferCurrentBranch = [
    'pos_tenant' => (int) ($inventoryTransferBranchConfig['pos_tenant'] ?? 0),
    'pos_branch' => (int) ($inventoryTransferBranchConfig['pos_branch'] ?? 0),
    'branch_uuid' => (string) ($inventoryTransferBranchConfig['uuid'] ?? ''),
    'branch_name' => trim((string) ($inventoryTransferBranchConfig['name'] ?? '')) ?: 'الفرع الحالي',
];
$inventoryTransferBranches = inventoryTransferBranchRows($conn, $inventoryTransferCurrentBranch);
$inventoryTransfersAllowed = posmain_inventory_transfers_allowed();
$inventoryTransferStores = posmain_inventory_store_select_options($conn);
$inventoryTransferItems = inventoryTransferRows($conn, "
    SELECT id, iname, barcode
    FROM myitems
    WHERE COALESCE(isdeleted, 0) = 0
      AND COALESCE(track_stock, 1) = 1
      AND COALESCE(item_type, 'sellable') <> 'service'
    ORDER BY iname
    LIMIT 500
");
$inventoryTransferUnitFlagSelect = ItemUnitColumnSupport::hasDefFlags($conn)
    ? 'iu.def_sale, iu.def_buy, iu.def_stock'
    : '0 AS def_sale, 0 AS def_buy, 0 AS def_stock';
$inventoryTransferUnitSwapSelect = ItemUnitColumnSupport::hasConversionSwapped($conn)
    ? 'iu.conversion_swapped'
    : '0 AS conversion_swapped';
$inventoryTransferUnits = inventoryTransferRows($conn, "
    SELECT iu.item_id, iu.unit_id, iu.u_val, {$inventoryTransferUnitSwapSelect}, {$inventoryTransferUnitFlagSelect},
           COALESCE(u.uname, CONCAT('وحدة ', iu.unit_id)) AS uname
    FROM item_units iu
    LEFT JOIN myunits u ON u.id = iu.unit_id
    WHERE COALESCE(iu.isdeleted, 0) = 0
      AND COALESCE(u.isdeleted, 0) = 0
    ORDER BY iu.item_id, u.uname, iu.unit_id
    LIMIT 1000
");
$inventoryTransferUnits = ItemUnitCatalogLabel::decorateRows($inventoryTransferUnits);
$inventoryTransferPreferredUnits = inventoryTransferRows($conn, "
    SELECT item_id, store_id, COALESCE(preferred_count_unit_id, preferred_purchase_unit_id, 0) AS preferred_unit_id
    FROM inventory_item_stock_levels
    WHERE is_active = 1
      AND (preferred_count_unit_id IS NOT NULL OR preferred_purchase_unit_id IS NOT NULL)
    LIMIT 2000
");
$inventoryTransferHasDestinationBranchColumns = inventoryTransferColumnExists($conn, 'inventory_transfers', 'destination_pos_branch')
    && inventoryTransferColumnExists($conn, 'inventory_transfers', 'destination_branch_uuid');
$inventoryTransferDestinationBranchExpr = $inventoryTransferHasDestinationBranchColumns
    ? 'COALESCE(t.destination_pos_branch, t.pos_branch)'
    : 't.pos_branch';
$inventoryTransferBranchJoin = inventoryTransferTableExists($conn, 'cloud_branches')
    ? "
    LEFT JOIN cloud_branches cb
           ON (
                " . ($inventoryTransferHasDestinationBranchColumns ? "(t.destination_branch_uuid IS NOT NULL AND t.destination_branch_uuid <> '' AND cb.branch_uuid = t.destination_branch_uuid)
                OR (COALESCE(t.destination_branch_uuid, '') = '' AND cb.pos_tenant = t.pos_tenant AND cb.pos_branch = {$inventoryTransferDestinationBranchExpr})" : "cb.pos_tenant = t.pos_tenant AND cb.pos_branch = {$inventoryTransferDestinationBranchExpr}") . "
              )"
    : '';
$inventoryTransferBranchNameSelect = inventoryTransferTableExists($conn, 'cloud_branches')
    ? "COALESCE(cb.branch_name, '') AS destination_branch_name,"
    : "'' AS destination_branch_name,";
$inventoryTransfers = inventoryTransferRows($conn, "
    SELECT t.id, t.status, t.created_at, t.sent_at, t.received_at,
           {$inventoryTransferDestinationBranchExpr} AS destination_pos_branch,
           src.aname AS source_store_name,
           dst.aname AS destination_store_name,
           {$inventoryTransferBranchNameSelect}
           COUNT(l.id) AS line_count,
           COALESCE(SUM(l.sent_qty), 0) AS sent_qty,
           COALESCE(SUM(l.received_qty), 0) AS received_qty
    FROM inventory_transfers t
    LEFT JOIN inventory_transfer_lines l ON l.transfer_id = t.id
    LEFT JOIN acc_head src ON src.id = t.source_store_id
    LEFT JOIN acc_head dst ON dst.id = t.destination_store_id
    {$inventoryTransferBranchJoin}
    GROUP BY t.id
    ORDER BY t.created_at DESC
    LIMIT 120
");

$inventoryTransferCsrfMeta = csrf_meta_tag('inventory_transfer', 'inventory-transfer-csrf');
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= $inventoryTransferCsrfMeta ?>

<style>
    .inventory-transfer-page{direction:rtl;background:#f6f8fb;min-height:calc(100vh - 57px)}
    .inventory-transfer-wrap{max-width:1440px;margin:0 auto;padding:18px}
    .inventory-transfer-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;background:#122033;color:#fff;border-radius:8px;box-shadow:0 12px 28px rgba(16,32,51,.16)}
    .inventory-transfer-title{margin:0;font-size:24px;font-weight:700;letter-spacing:0}
    .inventory-transfer-subtitle{margin:6px 0 0;color:#c8d4df;font-size:13px}
    .inventory-transfer-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:9px 12px;border-radius:8px;white-space:nowrap}
    .inventory-transfer-grid{display:grid;grid-template-columns:minmax(300px,420px) 1fr;gap:16px;margin-top:16px;min-width:0}
    .inventory-transfer-panel{background:#fff;border:1px solid #dde5ee;border-radius:8px;box-shadow:0 8px 20px rgba(21,35,50,.07);min-width:0;overflow:hidden}
    .inventory-transfer-panel-header{padding:14px 16px;border-bottom:1px solid #e7edf3;display:flex;justify-content:space-between;align-items:center;gap:10px}
    .inventory-transfer-panel-title{margin:0;font-size:16px;font-weight:700;color:#102033}
    .inventory-transfer-panel-body{padding:16px}
    .inventory-transfer-field{margin-bottom:13px;min-width:0}
    .inventory-transfer-field label{display:block;font-size:12px;color:#4f6175;margin-bottom:6px;font-weight:700}
    .inventory-transfer-field .form-control{border-radius:8px;border-color:#cfdae5;min-height:40px;max-width:100%;min-width:0}
    .inventory-transfer-search{margin-bottom:8px}
    .inventory-transfer-filter-note{margin-top:5px;color:#64748b;font-size:12px}
    .inventory-transfer-line-strip{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;color:#475569;font-size:12px;font-weight:800}
    .inventory-transfer-table th{background:#eef3f7;color:#334155;font-size:12px;border-color:#dde5ee;white-space:nowrap}
    .inventory-transfer-table td{border-color:#e5ebf1;vertical-align:middle}
    .inventory-transfer-table .form-control{border-radius:8px;min-height:36px}
    .inventory-transfer-btn{min-height:42px;border-radius:8px;border:0;background:#0f766e;color:#fff;font-weight:800;padding:0 18px}
    .inventory-transfer-icon-btn{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid #cfdae5;background:#fff;color:#102033}
    .inventory-transfer-toast{display:none;position:fixed;left:22px;bottom:22px;z-index:2000;min-width:280px;max-width:420px;border-radius:8px;padding:13px 16px;color:#fff;background:#102033;box-shadow:0 14px 34px rgba(15,23,42,.22)}
    .inventory-transfer-toast.error{background:#b91c1c}
    @media(max-width:992px){.inventory-transfer-grid{grid-template-columns:minmax(0,1fr)}.inventory-transfer-header{align-items:flex-start;flex-direction:column}}
</style>

<div class="content-wrapper inventory-transfer-page">
    <section class="content-header">
        <div class="inventory-transfer-wrap">
            <div class="inventory-transfer-header">
                <div>
                    <h1 class="inventory-transfer-title">تحويلات المخزون</h1>
                    <p class="inventory-transfer-subtitle">إرسال واستلام المخزون بين المخازن</p>
                </div>
                <div class="inventory-transfer-pill">
                    <i class="fas fa-exchange-alt"></i>
                    <span>وضع المخزون: <?= htmlspecialchars($inventoryTransferMode, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <?php if (!$inventoryTransfersAllowed): ?>
                <div class="alert alert-info mt-3 mb-0">وضع المخزن الواحد مفعّل: تحويلات المخزون بين مخازن متعددة غير متاحة.</div>
            <?php endif; ?>

            <?php if (!$inventoryTransferCanPost): ?>
                <div class="alert alert-warning mt-3 mb-0">يمكن إنشاء التحويل، لكن الإرسال والاستلام يحتاجان وضع bridge أو live للمخزون.</div>
            <?php endif; ?>

            <div class="inventory-transfer-grid">
                <div class="inventory-transfer-panel">
                    <div class="inventory-transfer-panel-header">
                        <h2 class="inventory-transfer-panel-title">تحويل جديد</h2>
                    </div>
                    <div class="inventory-transfer-panel-body"<?= !$inventoryTransfersAllowed ? ' style="opacity:0.55;pointer-events:none;"' : '' ?>>
                        <div class="inventory-transfer-field">
                            <label for="inventoryTransferSource">من مخزن</label>
                            <select id="inventoryTransferSource" class="form-control">
                                <?php foreach ($inventoryTransferStores as $store): ?>
                                    <option value="<?= (int) $store['id'] ?>"><?= htmlspecialchars($store['aname'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-transfer-field">
                            <label for="inventoryTransferDestination">إلى مخزن</label>
                            <select id="inventoryTransferDestination" class="form-control">
                                <?php foreach ($inventoryTransferStores as $store): ?>
                                    <option value="<?= (int) $store['id'] ?>"><?= htmlspecialchars($store['aname'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-transfer-field">
                            <label for="inventoryTransferDestinationBranch">فرع الوجهة</label>
                            <select id="inventoryTransferDestinationBranch" class="form-control">
                                <?php foreach ($inventoryTransferBranches as $branch): ?>
                                    <option value="<?= (int) $branch['pos_branch'] ?>" data-tenant="<?= (int) $branch['pos_tenant'] ?>" data-branch-uuid="<?= htmlspecialchars($branch['branch_uuid'] ?? '', ENT_QUOTES, 'UTF-8') ?>"<?= (int) $branch['pos_branch'] === (int) $inventoryTransferCurrentBranch['pos_branch'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($branch['branch_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-transfer-field">
                            <label for="inventoryTransferNotes">ملاحظات</label>
                            <textarea id="inventoryTransferNotes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="table-responsive">
                            <div class="inventory-transfer-line-strip">
                                <span>بنود التحويل</span>
                                <strong id="inventoryTransferLineTotal">0</strong>
                            </div>
                            <table class="table table-bordered inventory-transfer-table">
                                <thead>
                                    <tr>
                                        <th>الصنف</th>
                                        <th style="width:140px;">الوحدة</th>
                                        <th style="width:110px;">الكمية</th>
                                        <th style="width:52px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="inventoryTransferLinesBody"></tbody>
                            </table>
                        </div>
                        <div class="mt-3 d-flex justify-content-between">
                            <button type="button" class="inventory-transfer-icon-btn" id="addInventoryTransferLine" title="إضافة صنف"><i class="fas fa-plus"></i></button>
                            <button type="button" class="inventory-transfer-btn" id="saveInventoryTransfer"><i class="fas fa-check"></i> حفظ التحويل</button>
                        </div>
                    </div>
                </div>

                <div class="inventory-transfer-panel">
                    <div class="inventory-transfer-panel-header">
                        <h2 class="inventory-transfer-panel-title">التحويلات الحالية</h2>
                    </div>
                    <div class="inventory-transfer-panel-body">
                        <div class="table-responsive">
                            <table class="table table-bordered inventory-transfer-table">
                                <thead>
                                    <tr>
                                        <th>المستند</th>
                                        <th>المصدر</th>
                                        <th>الوجهة</th>
                                        <th>الحالة</th>
                                        <th>الأصناف</th>
                                        <th>مرسل / مستلم</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryTransfers as $transfer): ?>
                                        <tr>
                                            <td>TR-<?= (int) $transfer['id'] ?></td>
                                            <td><?= htmlspecialchars((($transfer['source_store_name'] ?? '') !== '') ? (string) $transfer['source_store_name'] : 'مخزن غير مسمى', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <div><?= htmlspecialchars((($transfer['destination_store_name'] ?? '') !== '') ? (string) $transfer['destination_store_name'] : 'مخزن غير مسمى', ENT_QUOTES, 'UTF-8') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars(($transfer['destination_branch_name'] ?? '') !== '' ? (string) $transfer['destination_branch_name'] : 'فرع غير مسمى', ENT_QUOTES, 'UTF-8') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars(inventoryTransferStatusLabel((string) ($transfer['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int) $transfer['line_count'] ?></td>
                                            <td><?= number_format((float) $transfer['sent_qty'], 3, '.', '') ?> / <?= number_format((float) $transfer['received_qty'], 3, '.', '') ?></td>
                                            <td><a class="btn btn-sm btn-outline-primary" href="inventory_transfer_detail.php?id=<?= (int) $transfer['id'] ?>"><i class="fas fa-eye"></i></a></td>
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

<div class="inventory-transfer-toast" id="inventoryTransferToast"></div>

<script>
const inventoryTransferItems = <?= json_encode($inventoryTransferItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryTransferUnits = <?= json_encode($inventoryTransferUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryTransferPreferredUnits = <?= json_encode($inventoryTransferPreferredUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryTransferSourceBranch = <?= json_encode($inventoryTransferCurrentBranch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function inventoryTransferEscapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
}

function inventoryTransferToast(message, isError = false) {
    const toast = document.getElementById('inventoryTransferToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

function inventoryTransferItemOptions() {
    return inventoryTransferItems.map(item => {
        const label = `${item.iname || item.id}${item.barcode ? ' - ' + item.barcode : ''}`;
        return `<option value="${Number(item.id || 0)}">${inventoryTransferEscapeHtml(label)}</option>`;
    }).join('');
}

function inventoryTransferUnitOptions(itemId, selectedUnitId = 0) {
    const units = inventoryTransferUnits.filter(unit => Number(unit.item_id || 0) === Number(itemId || 0));
    const options = ['<option value="">الوحدة الأساسية</option>'];
    units.forEach(unit => {
        const unitId = Number(unit.unit_id || 0);
        const selected = unitId === Number(selectedUnitId || 0) ? ' selected' : '';
        const label = unit.unit_label || unit.uname || unitId;
        options.push(`<option value="${unitId}"${selected}>${inventoryTransferEscapeHtml(label)}</option>`);
    });

    return options.join('');
}

function inventoryTransferPreferredUnit(itemId) {
    const sourceStoreId = Number(document.getElementById('inventoryTransferSource').value || 0);
    const preferred = inventoryTransferPreferredUnits.find(row =>
        Number(row.item_id || 0) === Number(itemId || 0)
        && Number(row.store_id || 0) === sourceStoreId
        && Number(row.preferred_unit_id || 0) > 0
    );

    return preferred ? Number(preferred.preferred_unit_id || 0) : 0;
}

function updateInventoryTransferLineTotal() {
    const count = Array.from(document.querySelectorAll('#inventoryTransferLinesBody tr')).filter(row =>
        Number(row.querySelector('.inventory-transfer-item').value || 0) > 0
    ).length;
    document.getElementById('inventoryTransferLineTotal').textContent = String(count);
}

function applyInventoryTransferItemSearch(row) {
    const input = row.querySelector('.inventory-transfer-item-search');
    const select = row.querySelector('.inventory-transfer-item');
    const term = String(input.value || '').trim().toLowerCase();
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
    const note = row.querySelector('.inventory-transfer-filter-note');
    note.textContent = term === '' ? '' : 'نتائج مطابقة: ' + visibleCount;
}

function refreshInventoryTransferRowUnit(row) {
    const itemId = Number(row.querySelector('.inventory-transfer-item').value || 0);
    const preferredUnitId = inventoryTransferPreferredUnit(itemId);
    row.querySelector('.inventory-transfer-unit').innerHTML = inventoryTransferUnitOptions(itemId, preferredUnitId);
}

function addInventoryTransferLine() {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <input class="form-control inventory-transfer-search inventory-transfer-item-search" type="search" autocomplete="off" placeholder="ابحث باسم الصنف أو الباركود">
            <select class="form-control inventory-transfer-item"><option value="">اختر الصنف</option>${inventoryTransferItemOptions()}</select>
            <div class="inventory-transfer-filter-note"></div>
        </td>
        <td><select class="form-control inventory-transfer-unit">${inventoryTransferUnitOptions(0)}</select></td>
        <td><input type="number" min="0" step="0.001" class="form-control inventory-transfer-qty"></td>
        <td><button type="button" class="inventory-transfer-icon-btn inventory-transfer-remove" title="حذف"><i class="fas fa-trash"></i></button></td>
    `;
    document.getElementById('inventoryTransferLinesBody').appendChild(row);
    row.querySelector('.inventory-transfer-item-search').addEventListener('input', () => applyInventoryTransferItemSearch(row));
    row.querySelector('.inventory-transfer-item').addEventListener('change', event => {
        refreshInventoryTransferRowUnit(row);
        updateInventoryTransferLineTotal();
    });
    row.querySelector('.inventory-transfer-remove').addEventListener('click', () => {
        row.remove();
        updateInventoryTransferLineTotal();
    });
    updateInventoryTransferLineTotal();
}

function collectInventoryTransferPayload() {
    const lines = [];
    const branchSelect = document.getElementById('inventoryTransferDestinationBranch');
    const selectedBranch = branchSelect ? branchSelect.options[branchSelect.selectedIndex] : null;
    document.querySelectorAll('#inventoryTransferLinesBody tr').forEach(row => {
        const itemId = Number(row.querySelector('.inventory-transfer-item').value || 0);
        const unitId = Number(row.querySelector('.inventory-transfer-unit').value || 0);
        const qty = Number(row.querySelector('.inventory-transfer-qty').value || 0);
        if (itemId > 0 && qty > 0) {
            const line = { item_id: itemId, requested_qty: qty.toFixed(6) };
            if (unitId > 0) {
                line.unit_id = unitId;
            }
            lines.push(line);
        }
    });
    return {
        source_store_id: Number(document.getElementById('inventoryTransferSource').value || 0),
        destination_store_id: Number(document.getElementById('inventoryTransferDestination').value || 0),
        destination_pos_branch: Number(branchSelect ? branchSelect.value : inventoryTransferSourceBranch.pos_branch || 0),
        destination_branch_uuid: selectedBranch ? String(selectedBranch.dataset.branchUuid || '') : String(inventoryTransferSourceBranch.branch_uuid || ''),
        notes: document.getElementById('inventoryTransferNotes').value.trim(),
        lines
    };
}

document.getElementById('addInventoryTransferLine').addEventListener('click', addInventoryTransferLine);
document.getElementById('inventoryTransferSource').addEventListener('change', () => {
    document.querySelectorAll('#inventoryTransferLinesBody tr').forEach(row => refreshInventoryTransferRowUnit(row));
});
document.getElementById('saveInventoryTransfer').addEventListener('click', async () => {
    const payload = collectInventoryTransferPayload();
    if (!payload.source_store_id || !payload.destination_store_id || payload.lines.length === 0) {
        inventoryTransferToast('راجع المخازن والأصناف قبل الحفظ', true);
        return;
    }
    if (payload.source_store_id === payload.destination_store_id && Number(payload.destination_pos_branch || 0) === Number(inventoryTransferSourceBranch.pos_branch || 0)) {
        inventoryTransferToast('مخزن المصدر والوجهة يجب أن يكونا مختلفين', true);
        return;
    }
    try {
        const response = await fetch('ajax/inventory_transfer_save.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="inventory-transfer-csrf"]').content
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر حفظ التحويل');
        }
        window.location.href = `inventory_transfer_detail.php?id=${Number(result.transfer_id || 0)}`;
    } catch (error) {
        inventoryTransferToast(error.message || 'تعذر حفظ التحويل', true);
    }
});

addInventoryTransferLine();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function inventoryTransferRows(mysqli $conn, string $sql): array
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

function inventoryTransferBranchRows(mysqli $conn, array $currentBranch): array
{
    $branches = [];
    $addBranch = static function (array $branch) use (&$branches): void {
        $tenant = (int) ($branch['pos_tenant'] ?? 0);
        $branchId = (int) ($branch['pos_branch'] ?? 0);
        $key = $tenant . ':' . $branchId;
        if (isset($branches[$key])) {
            return;
        }

        $name = trim((string) ($branch['branch_name'] ?? ''));
        $branches[$key] = [
            'pos_tenant' => $tenant,
            'pos_branch' => $branchId,
            'branch_uuid' => (string) ($branch['branch_uuid'] ?? ''),
            'branch_name' => $name !== '' ? $name : 'فرع غير مسمى',
        ];
    };

    $addBranch($currentBranch);
    if (inventoryTransferTableExists($conn, 'cloud_branches')) {
        foreach (inventoryTransferRows($conn, "
            SELECT COALESCE(pos_tenant, 0) AS pos_tenant,
                   COALESCE(pos_branch, 0) AS pos_branch,
                   branch_uuid,
                   COALESCE(NULLIF(branch_name, ''), 'فرع غير مسمى') AS branch_name
            FROM cloud_branches
            WHERE status = 'active'
              AND pos_branch IS NOT NULL
            ORDER BY branch_name, pos_branch
            LIMIT 100
        ") as $branch) {
            $addBranch($branch);
        }
    }

    return array_values($branches);
}

function inventoryTransferStatusLabel(string $status): string
{
    $labels = [
        'draft' => 'مسودة',
        'submitted' => 'بانتظار الإرسال',
        'sent' => 'مرسل من المصدر',
        'partially_received' => 'استلام جزئي',
        'received' => 'تم الاستلام',
        'closed' => 'مغلق',
        'cancelled' => 'ملغي',
        'returned' => 'مرتجع',
        'variance_closed' => 'مغلق بفرق',
    ];

    return $labels[$status] ?? 'حالة غير معروفة';
}

function inventoryTransferTableExists(mysqli $conn, string $table): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['table_count'] > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function inventoryTransferColumnExists(mysqli $conn, string $table, string $column): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['column_count'] > 0;
    } catch (Throwable $exception) {
        return false;
    }
}
