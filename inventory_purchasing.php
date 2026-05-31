<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/InventoryFeatureFlags.php';

require_permission('inventory.edit', $conn);

$inventoryPurchaseFlags = new InventoryFeatureFlags();
$inventoryPurchaseMode = $inventoryPurchaseFlags->mode();
$inventoryPurchaseCanReceive = $inventoryPurchaseFlags->canWriteLedger();
$inventoryPurchaseCanApproveOrders = auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn);
$inventoryPurchaseHasDefaultSupplierColumn = inventoryPurchasingColumnExists($conn, 'inventory_item_stock_levels', 'default_supplier_account_id');
$inventoryPurchaseSuppliers = inventoryPurchasingRows($conn, "
    SELECT id, aname, code
    FROM acc_head
    WHERE isdeleted = 0
      AND is_basic = 0
      AND code LIKE '211%'
    ORDER BY aname
    LIMIT 200
");
$inventoryPurchaseStores = inventoryPurchasingRows($conn, "
    SELECT id, aname
    FROM acc_head
    WHERE isdeleted = 0
      AND is_stock = 1
    ORDER BY aname
    LIMIT 100
");
$inventoryPurchaseItems = inventoryPurchasingRows($conn, "
    SELECT id, iname, barcode, cost_price
    FROM myitems
    WHERE COALESCE(isdeleted, 0) = 0
    ORDER BY iname
    LIMIT 300
");
$inventoryPurchaseUnits = inventoryPurchasingRows($conn, "
    SELECT iu.item_id, iu.unit_id, iu.u_val, iu.cost_price, COALESCE(u.uname, CONCAT('وحدة ', iu.unit_id)) AS uname
    FROM item_units iu
    LEFT JOIN myunits u ON u.id = iu.unit_id
    WHERE COALESCE(iu.isdeleted, 0) = 0
      AND COALESCE(u.isdeleted, 0) = 0
    ORDER BY iu.item_id, u.uname, iu.unit_id
    LIMIT 1000
");
$inventoryPurchasePreferredUnits = inventoryPurchasingRows($conn, "
    SELECT
        item_id,
        store_id,
        COALESCE(preferred_purchase_unit_id, preferred_count_unit_id, 0) AS preferred_unit_id,
        " . ($inventoryPurchaseHasDefaultSupplierColumn ? "COALESCE(default_supplier_account_id, 0)" : "0") . " AS default_supplier_account_id
    FROM inventory_item_stock_levels
    WHERE is_active = 1
      AND (
        preferred_purchase_unit_id IS NOT NULL
        OR preferred_count_unit_id IS NOT NULL
        " . ($inventoryPurchaseHasDefaultSupplierColumn ? "OR default_supplier_account_id IS NOT NULL" : "") . "
      )
    ORDER BY store_id, item_id
    LIMIT 3000
");
$inventoryPurchaseOrders = inventoryPurchasingRows($conn, "
    SELECT id, supplier_account_id, destination_store_id, status, expected_at
    FROM inventory_purchase_orders
    WHERE status IN ('approved', 'partially_received')
    ORDER BY created_at DESC
    LIMIT 80
");
$inventoryPurchaseOrderLines = inventoryPurchasingRows($conn, "
    SELECT l.id, l.purchase_order_id, l.item_id, l.unit_id, l.ordered_qty, l.received_qty, l.unit_cost
    FROM inventory_purchase_order_lines l
    INNER JOIN inventory_purchase_orders o ON o.id = l.purchase_order_id
    WHERE o.status IN ('approved', 'partially_received')
    ORDER BY l.purchase_order_id DESC, l.id ASC
    LIMIT 500
");
$inventoryPurchasePendingOrders = inventoryPurchasingRows($conn, "
    SELECT id, supplier_account_id, destination_store_id, status, expected_at
    FROM inventory_purchase_orders
    WHERE status = 'submitted'
    ORDER BY submitted_at DESC, created_at DESC
    LIMIT 80
");
$inventoryPurchaseSupplierHistoryRows = inventoryPurchasingRows($conn, "
    SELECT
        r.supplier_account_id,
        l.item_id,
        COALESCE(l.unit_id, 0) AS unit_id,
        l.unit_cost,
        COALESCE(r.posted_at, r.received_at, r.created_at) AS document_at
    FROM inventory_purchase_receipt_lines l
    INNER JOIN inventory_purchase_receipts r ON r.id = l.purchase_receipt_id
    WHERE COALESCE(r.supplier_account_id, 0) > 0
      AND COALESCE(l.item_id, 0) > 0
      AND COALESCE(l.received_qty, 0) > 0
    ORDER BY r.supplier_account_id, l.item_id, COALESCE(r.posted_at, r.received_at, r.created_at) DESC, r.id DESC, l.id DESC
    LIMIT 1000
");
$inventoryPurchaseSupplierDefaults = [];
$inventoryPurchaseSupplierSeen = [];
foreach ($inventoryPurchaseSupplierHistoryRows as $historyRow) {
    $supplierDefaultKey = ((int) ($historyRow['supplier_account_id'] ?? 0)) . ':' . ((int) ($historyRow['item_id'] ?? 0));
    if (isset($inventoryPurchaseSupplierSeen[$supplierDefaultKey])) {
        continue;
    }
    $inventoryPurchaseSupplierSeen[$supplierDefaultKey] = true;
    $inventoryPurchaseSupplierDefaults[] = $historyRow;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= csrf_meta_tag('inventory_receiving', 'inventory-receiving-csrf') ?>

<style>
    .inventory-receiving-page {
        direction: rtl;
        background: #f6f8fb;
        min-height: calc(100vh - 57px);
    }
    .inventory-receiving-wrap {
        max-width: 1440px;
        margin: 0 auto;
        padding: 18px;
    }
    .inventory-receiving-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        background: #102033;
        color: #fff;
        border-radius: 8px;
        box-shadow: 0 12px 28px rgba(16, 32, 51, 0.16);
    }
    .inventory-receiving-title {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 0;
    }
    .inventory-receiving-subtitle {
        margin: 6px 0 0;
        color: #c8d4df;
        font-size: 13px;
    }
    .inventory-status-pill {
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
    .inventory-receiving-grid {
        display: grid;
        grid-template-columns: minmax(280px, 380px) 1fr;
        gap: 16px;
        margin-top: 16px;
    }
    .inventory-panel {
        background: #fff;
        border: 1px solid #dde5ee;
        border-radius: 8px;
        box-shadow: 0 8px 20px rgba(21, 35, 50, 0.07);
    }
    .inventory-panel-header {
        padding: 14px 16px;
        border-bottom: 1px solid #e7edf3;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    .inventory-panel-title {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: #102033;
    }
    .inventory-panel-body {
        padding: 16px;
    }
    .inventory-field {
        margin-bottom: 13px;
    }
    .inventory-field label {
        display: block;
        font-size: 12px;
        color: #4f6175;
        margin-bottom: 6px;
        font-weight: 700;
    }
    .inventory-field .form-control {
        border-radius: 8px;
        border-color: #cfdae5;
        min-height: 40px;
    }
    .inventory-segment {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .inventory-segment button {
        border: 1px solid #cfdae5;
        background: #f8fafc;
        color: #102033;
        border-radius: 8px;
        min-height: 40px;
        font-weight: 700;
    }
    .inventory-segment button.active {
        background: #0f766e;
        border-color: #0f766e;
        color: #fff;
    }
    .inventory-lines-table {
        width: 100%;
        table-layout: fixed;
        margin: 0;
    }
    .inventory-lines-table th {
        background: #eef3f7;
        color: #334155;
        font-size: 12px;
        border-color: #dde5ee;
        white-space: nowrap;
    }
    .inventory-lines-table td {
        border-color: #e5ebf1;
        vertical-align: middle;
    }
    .inventory-lines-table .form-control {
        min-height: 36px;
        border-radius: 8px;
    }
    .inventory-purchase-search {
        margin-bottom: 8px;
    }
    .inventory-purchase-filter-note {
        margin-top: 5px;
        color: #64748b;
        font-size: 12px;
    }
    .inventory-icon-btn {
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
    .inventory-primary-btn {
        min-height: 42px;
        border-radius: 8px;
        border: 0;
        background: #0f766e;
        color: #fff;
        font-weight: 800;
        padding: 0 18px;
    }
    .inventory-primary-btn:disabled {
        background: #94a3b8;
        cursor: not-allowed;
    }
    .inventory-total-strip {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }
    .inventory-total-cell {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
    }
    .inventory-total-cell span {
        display: block;
        color: #64748b;
        font-size: 12px;
    }
    .inventory-total-cell strong {
        display: block;
        color: #102033;
        font-size: 18px;
        margin-top: 2px;
    }
    .inventory-scan-panel {
        display: grid;
        grid-template-columns: minmax(220px, 1fr) 140px auto;
        gap: 10px;
        align-items: end;
        margin-bottom: 14px;
        padding: 12px;
        background: #f8fafc;
        border: 1px solid #dbe5ef;
        border-radius: 8px;
    }
    .inventory-scan-field label {
        display: block;
        margin-bottom: 6px;
        color: #4b5f74;
        font-size: 12px;
        font-weight: 800;
    }
    .inventory-scan-field .form-control {
        min-height: 40px;
        border-radius: 8px;
    }
    .inventory-scan-result {
        display: flex;
        align-items: center;
        min-height: 40px;
        color: #475569;
        font-weight: 700;
    }
    .inventory-scan-result.success {
        color: #047857;
    }
    .inventory-scan-result.error {
        color: #b91c1c;
    }
    .inventory-line-hit {
        outline: 2px solid #0f766e;
        outline-offset: -2px;
        background: #ecfdf5;
    }
    .inventory-toast {
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
    .inventory-toast.error {
        background: #b91c1c;
    }
    @media (max-width: 992px) {
        .inventory-receiving-grid,
        .inventory-total-strip {
            grid-template-columns: 1fr;
        }
        .inventory-receiving-header {
            align-items: flex-start;
            flex-direction: column;
        }
        .inventory-scan-panel {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="content-wrapper inventory-receiving-page">
    <section class="content-header">
        <div class="inventory-receiving-wrap">
            <div class="inventory-receiving-header">
                <div>
                    <h1 class="inventory-receiving-title">استلام المشتريات</h1>
                    <p class="inventory-receiving-subtitle">مخزون وارد ومردود مشتريات على دفتر المخزون الجديد</p>
                </div>
                <div class="inventory-status-pill">
                    <i class="fas fa-database"></i>
                    <span>وضع المخزون: <?= htmlspecialchars($inventoryPurchaseMode, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <?php if (!$inventoryPurchaseCanReceive): ?>
                <div class="alert alert-warning mt-3 mb-0">هذه الشاشة جاهزة، لكن التسجيل يحتاج وضع bridge أو live للمخزون.</div>
            <?php endif; ?>

            <div class="inventory-receiving-grid">
                <div class="inventory-panel">
                    <div class="inventory-panel-header">
                        <h2 class="inventory-panel-title">بيانات المستند</h2>
                    </div>
                    <div class="inventory-panel-body">
                        <div class="inventory-field">
                            <label>نوع العملية</label>
                            <div class="inventory-segment" role="group">
                                <button type="button" class="active" data-receiving-action="receive"><i class="fas fa-arrow-down"></i> استلام</button>
                                <button type="button" data-receiving-action="return"><i class="fas fa-undo"></i> مردود</button>
                            </div>
                        </div>
                        <div class="inventory-field">
                            <label for="inventorySupplier">المورد</label>
                            <select id="inventorySupplier" class="form-control">
                                <option value="">بدون مورد</option>
                                <?php foreach ($inventoryPurchaseSuppliers as $supplier): ?>
                                    <option value="<?= (int) $supplier['id'] ?>"><?= htmlspecialchars(($supplier['aname'] ?? '') . ' - ' . ($supplier['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-field">
                            <label for="inventoryStore">المخزن</label>
                            <select id="inventoryStore" class="form-control">
                                <?php foreach ($inventoryPurchaseStores as $store): ?>
                                    <option value="<?= (int) $store['id'] ?>"><?= htmlspecialchars($store['aname'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-field">
                            <label for="inventoryPurchaseOrder">أمر الشراء</label>
                            <select id="inventoryPurchaseOrder" class="form-control">
                                <option value="">استلام مباشر</option>
                                <?php foreach ($inventoryPurchaseOrders as $order): ?>
                                    <option value="<?= (int) $order['id'] ?>">PO-<?= (int) $order['id'] ?> / <?= htmlspecialchars(inventoryPurchaseOrderStatusLabel((string) ($order['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-field">
                            <label for="inventoryPendingPurchaseOrder">أوامر بانتظار الموافقة</label>
                            <div class="d-flex" style="gap:8px;">
                                <select id="inventoryPendingPurchaseOrder" class="form-control">
                                    <option value="">اختر أمر</option>
                                    <?php foreach ($inventoryPurchasePendingOrders as $order): ?>
                                        <option value="<?= (int) $order['id'] ?>">PO-<?= (int) $order['id'] ?> / <?= htmlspecialchars(inventoryPurchaseOrderStatusLabel((string) ($order['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="inventory-icon-btn" id="approveInventoryPurchaseOrder" title="اعتماد" <?= $inventoryPurchaseCanApproveOrders ? '' : 'disabled' ?>><i class="fas fa-check-double"></i></button>
                            </div>
                        </div>
                        <div class="inventory-field">
                            <label for="inventorySupplierInvoice">رقم فاتورة المورد</label>
                            <input id="inventorySupplierInvoice" class="form-control" type="text" autocomplete="off">
                        </div>
                        <div class="inventory-field">
                            <label for="inventoryNotes">ملاحظات</label>
                            <textarea id="inventoryNotes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="inventory-panel">
                    <div class="inventory-panel-header">
                        <h2 class="inventory-panel-title">الأصناف</h2>
                        <button type="button" class="inventory-icon-btn" id="addInventoryLine" title="إضافة صنف"><i class="fas fa-plus"></i></button>
                    </div>
                    <div class="inventory-panel-body">
                        <div class="inventory-total-strip">
                            <div class="inventory-total-cell">
                                <span>عدد الأصناف</span>
                                <strong id="inventoryLineCount">0</strong>
                            </div>
                            <div class="inventory-total-cell">
                                <span>إجمالي الكمية</span>
                                <strong id="inventoryQtyTotal">0.000</strong>
                            </div>
                            <div class="inventory-total-cell">
                                <span>إجمالي التكلفة</span>
                                <strong id="inventoryCostTotal">0.00</strong>
                            </div>
                        </div>

                        <div class="inventory-scan-panel">
                            <div class="inventory-scan-field">
                                <label for="inventoryPurchaseBarcodeScan">مسح باركود الاستلام</label>
                                <input id="inventoryPurchaseBarcodeScan" class="form-control" inputmode="text" autocomplete="off" placeholder="امسح الباركود أو اكتب رقم الصنف">
                            </div>
                            <div class="inventory-scan-field">
                                <label for="inventoryPurchaseScanQty">كمية كل مسحة</label>
                                <input id="inventoryPurchaseScanQty" class="form-control" type="number" min="0.001" step="0.001" value="1.000">
                            </div>
                            <div class="inventory-scan-result" id="inventoryPurchaseScanResult">جاهز لإضافة الأصناف بالمسح</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered inventory-lines-table">
                                <thead>
                                    <tr>
                                        <th style="width: 34%">الصنف</th>
                                        <th style="width: 16%">الوحدة</th>
                                        <th style="width: 14%">الكمية</th>
                                        <th style="width: 16%">تكلفة الوحدة</th>
                                        <th style="width: 14%">الإجمالي</th>
                                        <th style="width: 6%"></th>
                                    </tr>
                                </thead>
                                <tbody id="inventoryLinesBody"></tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex justify-content-end">
                            <button type="button" class="inventory-icon-btn ml-2" id="saveInventoryPurchaseOrder" title="حفظ أمر شراء"><i class="fas fa-save"></i></button>
                            <button type="button" class="inventory-icon-btn ml-2" id="submitInventoryPurchaseOrder" title="إرسال للموافقة"><i class="fas fa-paper-plane"></i></button>
                            <button type="button" class="inventory-primary-btn" id="postInventoryReceipt" <?= $inventoryPurchaseCanReceive ? '' : 'disabled' ?>>
                                <i class="fas fa-check"></i> تسجيل العملية
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="inventory-toast" id="inventoryToast"></div>

<script>
const inventoryItems = <?= json_encode($inventoryPurchaseItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryUnits = <?= json_encode($inventoryPurchaseUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryPurchasePreferredUnits = <?= json_encode($inventoryPurchasePreferredUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryPurchaseSupplierDefaults = <?= json_encode($inventoryPurchaseSupplierDefaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryPurchaseOrders = <?= json_encode($inventoryPurchaseOrders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryPurchaseOrderLines = <?= json_encode($inventoryPurchaseOrderLines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryPurchaseHasDefaultSupplierColumn = <?= $inventoryPurchaseHasDefaultSupplierColumn ? 'true' : 'false' ?>;
const inventoryPurchaseCanApproveOrders = <?= $inventoryPurchaseCanApproveOrders ? 'true' : 'false' ?>;
let inventoryAction = 'receive';

function inventoryEscapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[character]));
}

function inventoryItemOptions() {
    return inventoryItems.map(item => {
        const label = `${item.iname || item.id}${item.barcode ? ' - ' + item.barcode : ''}`;
        const search = [item.iname || '', item.barcode || '', item.id || ''].join(' ');
        return `<option value="${Number(item.id || 0)}" data-cost="${Number(item.cost_price || 0)}" data-search="${inventoryEscapeHtml(search)}">${inventoryEscapeHtml(label)}</option>`;
    }).join('');
}

function inventoryUnitOptions(itemId, selectedUnitId = 0) {
    const units = inventoryUnits.filter(unit => Number(unit.item_id || 0) === Number(itemId || 0));
    const options = ['<option value="" data-conversion="1" data-cost="">الوحدة الأساسية</option>'];
    units.forEach(unit => {
        const unitId = Number(unit.unit_id || 0);
        const selected = unitId === Number(selectedUnitId || 0) ? ' selected' : '';
        const label = `${unit.uname || unitId} × ${Number(unit.u_val || 1).toFixed(3)}`;
        options.push(`<option value="${unitId}" data-conversion="${Number(unit.u_val || 1)}" data-cost="${Number(unit.cost_price || 0)}"${selected}>${inventoryEscapeHtml(label)}</option>`);
    });

    return options.join('');
}

function inventoryPreferredPurchaseUnit(itemId) {
    const storeId = Number(document.getElementById('inventoryStore').value || 0);
    const preferred = inventoryPurchasePreferredUnits.find(row =>
        Number(row.item_id || 0) === Number(itemId || 0)
        && Number(row.store_id || 0) === storeId
        && Number(row.preferred_unit_id || 0) > 0
    );
    return preferred ? Number(preferred.preferred_unit_id || 0) : 0;
}

function inventoryDefaultSupplierAccount(itemId) {
    if (!inventoryPurchaseHasDefaultSupplierColumn) {
        return 0;
    }
    const storeId = Number(document.getElementById('inventoryStore').value || 0);
    const preferred = inventoryPurchasePreferredUnits.find(row =>
        Number(row.item_id || 0) === Number(itemId || 0)
        && Number(row.store_id || 0) === storeId
        && Number(row.default_supplier_account_id || 0) > 0
    );
    return preferred ? Number(preferred.default_supplier_account_id || 0) : 0;
}

function applyInventoryDefaultSupplier(itemId) {
    const supplier = document.getElementById('inventorySupplier');
    const defaultSupplierId = inventoryDefaultSupplierAccount(itemId);
    if (!Number(supplier.value || 0) && defaultSupplierId > 0) {
        supplier.value = String(defaultSupplierId);
    }
}

function inventorySupplierPurchaseDefault(itemId, unitId = 0) {
    const supplierId = Number(document.getElementById('inventorySupplier').value || 0);
    if (!supplierId || !Number(itemId || 0)) {
        return null;
    }
    const matches = inventoryPurchaseSupplierDefaults.filter(row =>
        Number(row.supplier_account_id || 0) === supplierId
        && Number(row.item_id || 0) === Number(itemId || 0)
    );
    if (!matches.length) {
        return null;
    }
    if (Number(unitId || 0) > 0) {
        return matches.find(row => Number(row.unit_id || 0) === Number(unitId || 0)) || null;
    }
    return matches[0] || null;
}

function inventoryDefaultPurchaseUnit(itemId) {
    const supplierDefault = inventorySupplierPurchaseDefault(itemId);
    if (supplierDefault && Number(supplierDefault.unit_id || 0) > 0) {
        return Number(supplierDefault.unit_id || 0);
    }
    return inventoryPreferredPurchaseUnit(itemId);
}

function inventoryDefaultUnitCost(itemId, unitId) {
    const supplierDefault = inventorySupplierPurchaseDefault(itemId, unitId);
    if (supplierDefault && Number(supplierDefault.unit_cost || 0) > 0) {
        return Number(supplierDefault.unit_cost || 0);
    }
    const selectedUnit = inventoryUnits.find(unit =>
        Number(unit.item_id || 0) === Number(itemId || 0)
        && Number(unit.unit_id || 0) === Number(unitId || 0)
    );
    if (selectedUnit && Number(selectedUnit.cost_price || 0) > 0) {
        return Number(selectedUnit.cost_price || 0);
    }
    const selectedItem = inventoryItems.find(item => Number(item.id || 0) === Number(itemId || 0));
    return selectedItem ? Number(selectedItem.cost_price || 0) : 0;
}

function addInventoryLine(defaults = {}) {
    const row = document.createElement('tr');
    const itemId = Number(defaults.item_id || 0);
    const unitId = Number(defaults.unit_id || 0) || inventoryDefaultPurchaseUnit(itemId);
    const unitCost = defaults.unit_cost !== undefined && defaults.unit_cost !== ''
        ? defaults.unit_cost
        : (itemId > 0 ? inventoryDefaultUnitCost(itemId, unitId).toFixed(3) : '');
    row.dataset.purchaseOrderLineId = defaults.purchase_order_line_id || '';
    row.innerHTML = `
        <td>
            <input class="form-control inventory-purchase-search inventory-purchase-item-search" type="search" autocomplete="off" placeholder="ابحث باسم الصنف أو الباركود">
            <select class="form-control inventory-item-select"><option value="">اختر الصنف</option>${inventoryItemOptions()}</select>
            <div class="inventory-purchase-filter-note"></div>
        </td>
        <td><select class="form-control inventory-unit-select">${inventoryUnitOptions(itemId, unitId)}</select></td>
        <td><input type="number" min="0" step="0.001" class="form-control inventory-qty" value="${defaults.qty || ''}"></td>
        <td><input type="number" min="0" step="0.001" class="form-control inventory-cost" value="${unitCost}"></td>
        <td><input type="text" class="form-control inventory-line-total" value="0.00" readonly></td>
        <td><button type="button" class="inventory-icon-btn inventory-remove-line" title="حذف"><i class="fas fa-trash"></i></button></td>
    `;
    document.getElementById('inventoryLinesBody').appendChild(row);
    if (itemId) {
        row.querySelector('.inventory-item-select').value = String(itemId);
        applyInventoryDefaultSupplier(itemId);
    }
    bindInventoryLine(row);
    recalcInventoryTotals();
}

function applyInventoryPurchaseItemSearch(row) {
    const input = row.querySelector('.inventory-purchase-item-search');
    const select = row.querySelector('.inventory-item-select');
    const note = row.querySelector('.inventory-purchase-filter-note');
    const term = String(input.value || '').trim().toLowerCase();
    let visibleCount = 0;

    Array.from(select.options).forEach(option => {
        if (!option.value) {
            option.hidden = false;
            return;
        }
        const haystack = String(option.dataset.search || option.textContent || '').toLowerCase();
        const matches = term === '' || haystack.indexOf(term) !== -1;
        option.hidden = !matches;
        if (matches) {
            visibleCount += 1;
        }
    });

    note.textContent = term === '' ? '' : 'نتائج مطابقة: ' + visibleCount;
}

function bindInventoryLine(row) {
    const item = row.querySelector('.inventory-item-select');
    const unit = row.querySelector('.inventory-unit-select');
    const qty = row.querySelector('.inventory-qty');
    const cost = row.querySelector('.inventory-cost');
    const search = row.querySelector('.inventory-purchase-item-search');
    search.addEventListener('input', () => applyInventoryPurchaseItemSearch(row));
    item.addEventListener('change', () => {
        const selected = item.options[item.selectedIndex];
        const itemId = Number(item.value || 0);
        applyInventoryDefaultSupplier(itemId);
        const preferredUnitId = inventoryDefaultPurchaseUnit(itemId);
        unit.innerHTML = inventoryUnitOptions(itemId, preferredUnitId);
        if (selected && !cost.value) {
            cost.value = inventoryDefaultUnitCost(itemId, preferredUnitId).toFixed(3);
        }
        row.dataset.purchaseOrderLineId = '';
        recalcInventoryTotals();
    });
    unit.addEventListener('change', () => {
        const itemId = Number(item.value || 0);
        const unitId = Number(unit.value || 0);
        const unitCost = inventoryDefaultUnitCost(itemId, unitId);
        if (unitCost > 0) {
            cost.value = unitCost.toFixed(3);
        }
        row.dataset.purchaseOrderLineId = '';
        recalcInventoryTotals();
    });
    qty.addEventListener('input', recalcInventoryTotals);
    cost.addEventListener('input', recalcInventoryTotals);
    row.querySelector('.inventory-remove-line').addEventListener('click', () => {
        row.remove();
        recalcInventoryTotals();
    });
}

function normalizeInventoryPurchaseScan(value) {
    return String(value || '').trim();
}

function setInventoryPurchaseScanResult(message, status = '') {
    const result = document.getElementById('inventoryPurchaseScanResult');
    result.textContent = message;
    result.classList.toggle('success', status === 'success');
    result.classList.toggle('error', status === 'error');
}

function findInventoryPurchaseItem(code) {
    const normalized = normalizeInventoryPurchaseScan(code);
    if (!normalized) {
        return null;
    }
    return inventoryItems.find(item =>
        String(item.barcode || '').trim() === normalized
        || String(item.id || '').trim() === normalized
    ) || null;
}

function findInventoryExistingLine(itemId, unitId) {
    return Array.from(document.querySelectorAll('#inventoryLinesBody tr')).find(row =>
        Number(row.querySelector('.inventory-item-select').value || 0) === Number(itemId || 0)
        && Number(row.querySelector('.inventory-unit-select').value || 0) === Number(unitId || 0)
        && Number(row.dataset.purchaseOrderLineId || 0) === 0
    ) || null;
}

function findInventoryBlankLine() {
    return Array.from(document.querySelectorAll('#inventoryLinesBody tr')).find(row =>
        Number(row.querySelector('.inventory-item-select').value || 0) === 0
        && Number(row.querySelector('.inventory-qty').value || 0) === 0
        && Number(row.dataset.purchaseOrderLineId || 0) === 0
    ) || null;
}

function highlightInventoryPurchaseLine(row) {
    row.classList.add('inventory-line-hit');
    setTimeout(() => row.classList.remove('inventory-line-hit'), 900);
}

function applyInventoryPurchaseScan(code) {
    const item = findInventoryPurchaseItem(code);
    const scanInput = document.getElementById('inventoryPurchaseBarcodeScan');
    const scanQty = Number(document.getElementById('inventoryPurchaseScanQty').value || 0);
    if (!item) {
        setInventoryPurchaseScanResult('لم يتم العثور على الصنف', 'error');
        inventoryToast('لم يتم العثور على الصنف', true);
        return;
    }
    if (!(scanQty > 0)) {
        setInventoryPurchaseScanResult('أدخل كمية صحيحة لكل مسحة', 'error');
        inventoryToast('أدخل كمية صحيحة لكل مسحة', true);
        return;
    }

    const itemId = Number(item.id || 0);
    applyInventoryDefaultSupplier(itemId);
    const unitId = inventoryDefaultPurchaseUnit(itemId);
    let row = findInventoryExistingLine(itemId, unitId);
    if (row) {
        const qty = row.querySelector('.inventory-qty');
        qty.value = (Number(qty.value || 0) + scanQty).toFixed(3);
    } else if ((row = findInventoryBlankLine())) {
        row.querySelector('.inventory-purchase-item-search').value = '';
        applyInventoryPurchaseItemSearch(row);
        const itemSelect = row.querySelector('.inventory-item-select');
        const unitSelect = row.querySelector('.inventory-unit-select');
        itemSelect.value = String(itemId);
        unitSelect.innerHTML = inventoryUnitOptions(itemId, unitId);
        unitSelect.value = unitId > 0 ? String(unitId) : '';
        row.querySelector('.inventory-qty').value = scanQty.toFixed(3);
        row.querySelector('.inventory-cost').value = inventoryDefaultUnitCost(itemId, unitId).toFixed(3);
    } else {
        addInventoryLine({
            item_id: itemId,
            unit_id: unitId,
            qty: scanQty.toFixed(3),
            unit_cost: inventoryDefaultUnitCost(itemId, unitId).toFixed(3)
        });
        row = Array.from(document.querySelectorAll('#inventoryLinesBody tr')).pop();
    }

    recalcInventoryTotals();
    if (row) {
        highlightInventoryPurchaseLine(row);
    }
    scanInput.value = '';
    scanInput.focus();
    setInventoryPurchaseScanResult(`تمت إضافة ${item.iname || itemId}`, 'success');
}

function recalcInventoryTotals() {
    let count = 0;
    let qtyTotal = 0;
    let costTotal = 0;
    document.querySelectorAll('#inventoryLinesBody tr').forEach(row => {
        const itemId = Number(row.querySelector('.inventory-item-select').value || 0);
        const qty = Number(row.querySelector('.inventory-qty').value || 0);
        const cost = Number(row.querySelector('.inventory-cost').value || 0);
        const total = qty * cost;
        row.querySelector('.inventory-line-total').value = total.toFixed(2);
        if (itemId > 0 && qty > 0) {
            count += 1;
            qtyTotal += qty;
            costTotal += total;
        }
    });
    document.getElementById('inventoryLineCount').textContent = String(count);
    document.getElementById('inventoryQtyTotal').textContent = qtyTotal.toFixed(3);
    document.getElementById('inventoryCostTotal').textContent = costTotal.toFixed(2);
}

function collectInventoryPayload() {
    const lines = [];
    document.querySelectorAll('#inventoryLinesBody tr').forEach(row => {
        const itemId = Number(row.querySelector('.inventory-item-select').value || 0);
        const unitId = Number(row.querySelector('.inventory-unit-select').value || 0);
        const qty = Number(row.querySelector('.inventory-qty').value || 0);
        const unitCost = Number(row.querySelector('.inventory-cost').value || 0);
        if (itemId > 0 && qty > 0) {
            const line = {
                item_id: itemId,
                qty: qty.toFixed(6),
                unit_cost: unitCost.toFixed(6)
            };
            if (unitId > 0) {
                line.unit_id = unitId;
            }
            const purchaseOrderLineId = Number(row.dataset.purchaseOrderLineId || 0);
            if (purchaseOrderLineId > 0) {
                line.purchase_order_line_id = purchaseOrderLineId;
            }
            lines.push(line);
        }
    });

    return {
        action: inventoryAction,
        supplier_account_id: Number(document.getElementById('inventorySupplier').value || 0),
        destination_store_id: Number(document.getElementById('inventoryStore').value || 0),
        purchase_order_id: Number(document.getElementById('inventoryPurchaseOrder').value || 0),
        supplier_invoice_no: document.getElementById('inventorySupplierInvoice').value.trim(),
        notes: document.getElementById('inventoryNotes').value.trim(),
        lines
    };
}

function loadPurchaseOrderLines(purchaseOrderId) {
    const id = Number(purchaseOrderId || 0);
    if (!id) {
        return;
    }
    const order = inventoryPurchaseOrders.find(row => Number(row.id) === id);
    if (order) {
        document.getElementById('inventorySupplier').value = String(order.supplier_account_id || '');
        document.getElementById('inventoryStore').value = String(order.destination_store_id || '');
    }
    const lines = inventoryPurchaseOrderLines.filter(line => Number(line.purchase_order_id) === id);
    document.getElementById('inventoryLinesBody').innerHTML = '';
    lines.forEach(line => {
        const remaining = Math.max(0, Number(line.ordered_qty || 0) - Number(line.received_qty || 0));
        if (remaining > 0) {
            addInventoryLine({
                purchase_order_line_id: Number(line.id || 0),
                item_id: Number(line.item_id || 0),
                unit_id: Number(line.unit_id || 0),
                qty: remaining.toFixed(3),
                unit_cost: Number(line.unit_cost || 0).toFixed(3)
            });
        }
    });
    if (!lines.length) {
        addInventoryLine();
    }
}

async function postPurchaseOrder(action) {
    const payload = collectInventoryPayload();
    payload.action = action;
    if (!payload.destination_store_id || payload.lines.length === 0) {
        inventoryToast('راجع المخزن والأصناف قبل حفظ أمر الشراء', true);
        return;
    }

    try {
        const response = await fetch('ajax/inventory_purchase_order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="inventory-receiving-csrf"]').content
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر حفظ أمر الشراء');
        }
        inventoryToast(result.message || 'تم حفظ أمر الشراء');
    } catch (error) {
        inventoryToast(error.message || 'تعذر حفظ أمر الشراء', true);
    }
}

async function approveSelectedPurchaseOrder() {
    if (!inventoryPurchaseCanApproveOrders) {
        inventoryToast('اعتماد أمر الشراء يحتاج صلاحية اعتماد المخزون', true);
        return;
    }
    const purchaseOrderId = Number(document.getElementById('inventoryPendingPurchaseOrder').value || 0);
    if (!purchaseOrderId) {
        inventoryToast('اختر أمر شراء للموافقة', true);
        return;
    }
    try {
        const response = await fetch('ajax/inventory_purchase_order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="inventory-receiving-csrf"]').content
            },
            body: JSON.stringify({ action: 'approve', purchase_order_id: purchaseOrderId })
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر اعتماد أمر الشراء');
        }
        inventoryToast(result.message || 'تم اعتماد أمر الشراء');
    } catch (error) {
        inventoryToast(error.message || 'تعذر اعتماد أمر الشراء', true);
    }
}

function inventoryToast(message, isError = false) {
    const toast = document.getElementById('inventoryToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

document.querySelectorAll('[data-receiving-action]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-receiving-action]').forEach(btn => btn.classList.remove('active'));
        button.classList.add('active');
        inventoryAction = button.dataset.receivingAction;
    });
});

document.getElementById('addInventoryLine').addEventListener('click', () => addInventoryLine());
document.getElementById('inventoryPurchaseOrder').addEventListener('change', event => loadPurchaseOrderLines(event.target.value));
document.getElementById('saveInventoryPurchaseOrder').addEventListener('click', () => postPurchaseOrder('create_draft'));
document.getElementById('submitInventoryPurchaseOrder').addEventListener('click', () => postPurchaseOrder('create_submit'));
document.getElementById('approveInventoryPurchaseOrder').addEventListener('click', approveSelectedPurchaseOrder);
document.getElementById('inventoryPurchaseBarcodeScan').addEventListener('keydown', event => {
    if (event.key === 'Enter') {
        event.preventDefault();
        applyInventoryPurchaseScan(event.target.value);
    }
});
document.getElementById('postInventoryReceipt').addEventListener('click', async () => {
    const payload = collectInventoryPayload();
    if (!payload.destination_store_id || payload.lines.length === 0) {
        inventoryToast('راجع المخزن والأصناف قبل التسجيل', true);
        return;
    }

    const button = document.getElementById('postInventoryReceipt');
    button.disabled = true;
    try {
        const response = await fetch('ajax/inventory_purchase_receive.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="inventory-receiving-csrf"]').content
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'تعذر تسجيل العملية');
        }
        inventoryToast(result.message || 'تم التسجيل');
        document.getElementById('inventoryLinesBody').innerHTML = '';
        addInventoryLine();
    } catch (error) {
        inventoryToast(error.message || 'تعذر تسجيل العملية', true);
    } finally {
        button.disabled = <?= $inventoryPurchaseCanReceive ? 'false' : 'true' ?>;
    }
});

addInventoryLine();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function inventoryPurchasingRows(mysqli $conn, string $sql): array
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

function inventoryPurchasingColumnExists(mysqli $conn, string $table, string $column): bool
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

function inventoryPurchaseOrderStatusLabel(string $status): string
{
    $labels = [
        'draft' => 'مسودة',
        'submitted' => 'بانتظار الاعتماد',
        'approved' => 'معتمد للشراء',
        'rejected' => 'مرفوض',
        'partially_received' => 'مستلم جزئيا',
        'received' => 'مستلم بالكامل',
        'closed' => 'مغلق',
        'cancelled' => 'ملغي',
    ];

    return $labels[$status] ?? $status;
}
