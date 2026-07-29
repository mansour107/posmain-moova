<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/InventoryFeatureFlags.php';

require_permission('inventory.edit', $conn);

$inventoryTransferId = (int) ($_GET['id'] ?? 0);
$inventoryTransferFlags = new InventoryFeatureFlags();
$inventoryTransferCanPost = $inventoryTransferFlags->canWriteQuantityLedger();
$inventoryTransferHasUnitTables = inventoryTransferDetailTableExists($conn, 'item_units') && inventoryTransferDetailTableExists($conn, 'myunits');
$inventoryTransferHasDestinationBranchColumns = inventoryTransferDetailColumnExists($conn, 'inventory_transfers', 'destination_pos_branch')
    && inventoryTransferDetailColumnExists($conn, 'inventory_transfers', 'destination_branch_uuid');
$inventoryTransferDestinationBranchExpr = $inventoryTransferHasDestinationBranchColumns
    ? 'COALESCE(t.destination_pos_branch, t.pos_branch)'
    : 't.pos_branch';
$inventoryTransferBranchJoin = inventoryTransferDetailTableExists($conn, 'cloud_branches')
    ? "
    LEFT JOIN cloud_branches cb
           ON (
                " . ($inventoryTransferHasDestinationBranchColumns ? "(t.destination_branch_uuid IS NOT NULL AND t.destination_branch_uuid <> '' AND cb.branch_uuid = t.destination_branch_uuid)
                OR (COALESCE(t.destination_branch_uuid, '') = '' AND cb.pos_tenant = t.pos_tenant AND cb.pos_branch = {$inventoryTransferDestinationBranchExpr})" : "cb.pos_tenant = t.pos_tenant AND cb.pos_branch = {$inventoryTransferDestinationBranchExpr}") . "
              )"
    : '';
$inventoryTransferBranchNameSelect = inventoryTransferDetailTableExists($conn, 'cloud_branches')
    ? "COALESCE(cb.branch_name, '') AS destination_branch_name,"
    : "'' AS destination_branch_name,";
$inventoryTransfer = inventoryTransferDetailRow($conn, "
    SELECT t.*,
           {$inventoryTransferDestinationBranchExpr} AS resolved_destination_pos_branch,
           {$inventoryTransferBranchNameSelect}
           src.aname AS source_store_name,
           dst.aname AS destination_store_name
    FROM inventory_transfers t
    LEFT JOIN acc_head src ON src.id = t.source_store_id
    LEFT JOIN acc_head dst ON dst.id = t.destination_store_id
    {$inventoryTransferBranchJoin}
    WHERE t.id = " . $inventoryTransferId . "
    LIMIT 1
");
$inventoryTransferLines = $inventoryTransfer ? inventoryTransferDetailRows($conn, "
    SELECT l.*, i.iname, i.barcode" . ($inventoryTransferHasUnitTables ? ", COALESCE(u.uname, 'الوحدة الأساسية') AS unit_name, iu.u_val AS unit_conversion" : ", 'الوحدة الأساسية' AS unit_name, NULL AS unit_conversion") . "
    FROM inventory_transfer_lines l
    LEFT JOIN myitems i ON i.id = l.item_id
" . ($inventoryTransferHasUnitTables ? "    LEFT JOIN item_units iu ON iu.item_id = l.item_id AND iu.unit_id = l.unit_id
    LEFT JOIN myunits u ON u.id = l.unit_id
" : "") . "
    WHERE l.transfer_id = " . $inventoryTransferId . "
    ORDER BY i.iname, l.id
") : [];
$inventoryTransferVarianceReasons = $inventoryTransfer ? inventoryTransferDetailRows($conn, "
    SELECT id, reason_name
    FROM inventory_reason_codes
    WHERE is_active = 1
      AND reason_group IN ('transfer_variance', 'manual')
      AND direction IN ('out', 'both')
      AND ((pos_tenant = " . (int) ($inventoryTransfer['pos_tenant'] ?? 0) . " AND pos_branch = " . (int) ($inventoryTransfer['resolved_destination_pos_branch'] ?? $inventoryTransfer['pos_branch'] ?? 0) . ") OR (pos_tenant = 0 AND pos_branch = 0))
    ORDER BY is_system DESC, reason_name ASC, id ASC
    LIMIT 100
") : [];

if (!$inventoryTransfer) {
    http_response_code(404);
}

$inventoryTransferCsrfMeta = csrf_meta_tag('inventory_transfer', 'inventory-transfer-csrf');
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= $inventoryTransferCsrfMeta ?>

<style>
    .inventory-transfer-detail-page{direction:rtl;background:#f6f8fb;min-height:calc(100vh - 57px)}
    .inventory-transfer-detail-wrap{max-width:1440px;margin:0 auto;padding:18px}
    .inventory-transfer-detail-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;background:#122033;color:#fff;border-radius:8px;box-shadow:0 12px 28px rgba(16,32,51,.16)}
    .inventory-transfer-detail-title{margin:0;font-size:24px;font-weight:700;letter-spacing:0}
    .inventory-transfer-detail-subtitle{margin:6px 0 0;color:#c8d4df;font-size:13px}
    .inventory-transfer-status{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:9px 12px;border-radius:8px;white-space:nowrap}
    .inventory-transfer-panel{margin-top:16px;background:#fff;border:1px solid #dde5ee;border-radius:8px;box-shadow:0 8px 20px rgba(21,35,50,.07)}
    .inventory-transfer-panel-header{padding:14px 16px;border-bottom:1px solid #e7edf3;display:flex;justify-content:space-between;align-items:center;gap:10px}
    .inventory-transfer-panel-title{margin:0;font-size:16px;font-weight:700;color:#102033}
    .inventory-transfer-panel-body{padding:16px}
    .inventory-transfer-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}
    .inventory-transfer-summary-cell{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px}
    .inventory-transfer-summary-cell span{display:block;color:#64748b;font-size:12px}
    .inventory-transfer-summary-cell strong{display:block;color:#102033;font-size:18px;margin-top:2px}
    .inventory-transfer-scan-panel{display:grid;grid-template-columns:minmax(220px,1fr) 140px auto;gap:10px;align-items:end;margin-bottom:14px;padding:12px;background:#f8fafc;border:1px solid #dbe5ef;border-radius:8px}
    .inventory-transfer-scan-field label{display:block;margin-bottom:6px;color:#4b5f74;font-size:12px;font-weight:800}
    .inventory-transfer-scan-field .form-control{min-height:40px;border-radius:8px}
    .inventory-transfer-scan-result{display:flex;align-items:center;min-height:40px;color:#475569;font-weight:700}
    .inventory-transfer-scan-result.success{color:#047857}
    .inventory-transfer-scan-result.error{color:#b91c1c}
    .inventory-transfer-line-hit{outline:2px solid #0f766e;outline-offset:-2px;background:#ecfdf5}
    .inventory-transfer-lines{width:100%;table-layout:fixed;margin:0}
    .inventory-transfer-lines th{background:#eef3f7;color:#334155;font-size:12px;border-color:#dde5ee;white-space:nowrap}
    .inventory-transfer-lines td{border-color:#e5ebf1;vertical-align:middle}
    .inventory-transfer-lines .form-control{min-height:36px;border-radius:8px}
    .inventory-transfer-btn{min-height:42px;border-radius:8px;border:0;background:#0f766e;color:#fff;font-weight:800;padding:0 18px}
    .inventory-transfer-btn.secondary{background:#334155}
    .inventory-transfer-btn.warn{background:#b45309}
    .inventory-transfer-btn:disabled{background:#94a3b8;cursor:not-allowed}
    .inventory-transfer-toast{display:none;position:fixed;left:22px;bottom:22px;z-index:2000;min-width:280px;max-width:420px;border-radius:8px;padding:13px 16px;color:#fff;background:#102033;box-shadow:0 14px 34px rgba(15,23,42,.22)}
    .inventory-transfer-toast.error{background:#b91c1c}
    @media(max-width:992px){.inventory-transfer-summary{grid-template-columns:1fr 1fr}.inventory-transfer-detail-header{align-items:flex-start;flex-direction:column}.inventory-transfer-scan-panel{grid-template-columns:1fr}}
</style>

<div class="content-wrapper inventory-transfer-detail-page">
    <section class="content-header">
        <div class="inventory-transfer-detail-wrap">
            <?php if (!$inventoryTransfer): ?>
                <div class="alert alert-danger">مستند التحويل غير موجود</div>
            <?php else: ?>
                <div class="inventory-transfer-detail-header">
                    <div>
                        <h1 class="inventory-transfer-detail-title">TR-<?= (int) $inventoryTransfer['id'] ?></h1>
                        <p class="inventory-transfer-detail-subtitle"><?= htmlspecialchars((($inventoryTransfer['source_store_name'] ?? '') !== '') ? (string) $inventoryTransfer['source_store_name'] : 'مخزن غير مسمى', ENT_QUOTES, 'UTF-8') ?> ← <?= htmlspecialchars((($inventoryTransfer['destination_store_name'] ?? '') !== '') ? (string) $inventoryTransfer['destination_store_name'] : 'مخزن غير مسمى', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(($inventoryTransfer['destination_branch_name'] ?? '') !== '' ? (string) $inventoryTransfer['destination_branch_name'] : 'فرع غير مسمى', ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="inventory-transfer-status">
                        <i class="fas fa-exchange-alt"></i>
                        <span><?= htmlspecialchars(inventoryTransferDetailStatusLabel((string) ($inventoryTransfer['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <?php if (!$inventoryTransferCanPost): ?>
                    <div class="alert alert-warning mt-3 mb-0">الإرسال والاستلام يحتاجان وضع bridge أو live للمخزون.</div>
                <?php endif; ?>

                <div class="inventory-transfer-panel">
                    <div class="inventory-transfer-panel-header">
                        <h2 class="inventory-transfer-panel-title">تفاصيل التحويل</h2>
                        <a class="btn btn-sm btn-outline-secondary" href="inventory_transfers.php"><i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="inventory-transfer-panel-body">
                        <div class="inventory-transfer-summary">
                            <div class="inventory-transfer-summary-cell">
                                <span>عدد الأصناف</span>
                                <strong><?= count($inventoryTransferLines) ?></strong>
                            </div>
                            <div class="inventory-transfer-summary-cell">
                                <span>مطلوب</span>
                                <strong id="transferRequestedTotal">0.000</strong>
                            </div>
                            <div class="inventory-transfer-summary-cell">
                                <span>مرسل</span>
                                <strong id="transferSentTotal">0.000</strong>
                            </div>
                            <div class="inventory-transfer-summary-cell">
                                <span>مستلم</span>
                                <strong id="transferReceivedTotal">0.000</strong>
                            </div>
                        </div>

                        <div class="inventory-transfer-scan-panel">
                            <div class="inventory-transfer-scan-field">
                                <label for="inventoryTransferBarcodeScan">مسح باركود الاستلام</label>
                                <input id="inventoryTransferBarcodeScan" class="form-control" inputmode="text" autocomplete="off" placeholder="امسح الباركود أو اكتب رقم الصنف" <?= in_array($inventoryTransfer['status'], ['sent', 'partially_received'], true) ? '' : 'disabled' ?>>
                            </div>
                            <div class="inventory-transfer-scan-field">
                                <label for="inventoryTransferScanQty">كمية كل مسحة</label>
                                <input id="inventoryTransferScanQty" class="form-control" type="number" min="0.001" step="0.001" value="1.000" <?= in_array($inventoryTransfer['status'], ['sent', 'partially_received'], true) ? '' : 'disabled' ?>>
                            </div>
                            <div class="inventory-transfer-scan-result" id="inventoryTransferScanResult">جاهز للاستلام بالمسح</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered inventory-transfer-lines">
                                <thead>
                                    <tr>
                                        <th style="width:28%">الصنف</th>
                                        <th style="width:14%">الوحدة</th>
                                        <th style="width:12%">مطلوب</th>
                                        <th style="width:12%">مرسل</th>
                                        <th style="width:15%">استلام إجمالي</th>
                                        <th style="width:10%">فرق</th>
                                        <th style="width:9%">تكلفة</th>
                                    </tr>
                                </thead>
                                <tbody id="inventoryTransferLinesBody">
                                    <?php foreach ($inventoryTransferLines as $line): ?>
                                        <tr data-line-id="<?= (int) $line['id'] ?>" data-item-id="<?= (int) $line['item_id'] ?>" data-barcode="<?= htmlspecialchars((string) ($line['barcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-requested="<?= htmlspecialchars((string) $line['requested_qty'], ENT_QUOTES, 'UTF-8') ?>" data-sent="<?= htmlspecialchars((string) $line['sent_qty'], ENT_QUOTES, 'UTF-8') ?>" data-received="<?= htmlspecialchars((string) $line['received_qty'], ENT_QUOTES, 'UTF-8') ?>">
                                            <td><?= htmlspecialchars(((($line['iname'] ?? '') !== '') ? (string) $line['iname'] : 'صنف غير مسمى') . (($line['barcode'] ?? '') !== '' ? ' - ' . $line['barcode'] : ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(($line['unit_name'] ?? 'الوحدة الأساسية') . (!empty($line['unit_conversion']) ? ' × ' . number_format((float) $line['unit_conversion'], 3, '.', '') : ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= number_format((float) $line['requested_qty'], 3, '.', '') ?></td>
                                            <td><?= number_format((float) $line['sent_qty'], 3, '.', '') ?></td>
                                            <td><input type="number" min="0" step="0.001" class="form-control inventory-transfer-received" value="<?= htmlspecialchars((string) $line['received_qty'], ENT_QUOTES, 'UTF-8') ?>" <?= in_array($inventoryTransfer['status'], ['sent', 'partially_received'], true) ? '' : 'readonly' ?>></td>
                                            <td class="inventory-transfer-variance"><?= number_format((float) $line['variance_qty'], 3, '.', '') ?></td>
                                            <td><?= number_format((float) $line['unit_cost'], 3, '.', '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex justify-content-end flex-wrap" style="gap:8px;">
                            <select class="form-control" id="inventoryTransferVarianceReason" style="max-width:240px;" <?= in_array($inventoryTransfer['status'], ['sent', 'partially_received'], true) ? '' : 'disabled' ?>>
                                <option value="">سبب فرق التحويل</option>
                                <?php foreach ($inventoryTransferVarianceReasons as $reason): ?>
                                    <option value="<?= (int) $reason['id'] ?>"><?= htmlspecialchars($reason['reason_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="form-control" id="inventoryTransferVarianceNote" placeholder="ملاحظة الفرق" style="max-width:260px;" <?= in_array($inventoryTransfer['status'], ['sent', 'partially_received'], true) ? '' : 'disabled' ?>>
                            <button type="button" class="inventory-transfer-btn secondary" id="submitInventoryTransfer" <?= $inventoryTransfer['status'] === 'draft' ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> إرسال للمراجعة</button>
                            <button type="button" class="inventory-transfer-btn warn" id="sendInventoryTransfer" <?= (in_array($inventoryTransfer['status'], ['draft', 'submitted'], true) && $inventoryTransferCanPost) ? '' : 'disabled' ?>><i class="fas fa-truck"></i> إرسال من المصدر</button>
                            <button type="button" class="inventory-transfer-btn" id="receiveInventoryTransfer" <?= (in_array($inventoryTransfer['status'], ['sent', 'partially_received'], true) && $inventoryTransferCanPost) ? '' : 'disabled' ?>><i class="fas fa-dolly"></i> استلام في الوجهة</button>
                            <button type="button" class="inventory-transfer-btn warn" id="closeInventoryTransferVariance" <?= (in_array($inventoryTransfer['status'], ['sent', 'partially_received'], true) && $inventoryTransferCanPost) ? '' : 'disabled' ?>><i class="fas fa-balance-scale"></i> إغلاق الفرق</button>
                            <button type="button" class="inventory-transfer-btn secondary" id="cancelInventoryTransfer" <?= (in_array($inventoryTransfer['status'], ['draft', 'submitted'], true) || ($inventoryTransfer['status'] === 'sent' && $inventoryTransferCanPost)) ? '' : 'disabled' ?>><i class="fas fa-ban"></i> إلغاء التحويل</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="inventory-transfer-toast" id="inventoryTransferToast"></div>

<script>
const inventoryTransferId = <?= (int) $inventoryTransferId ?>;

function inventoryTransferToast(message, isError = false) {
    const toast = document.getElementById('inventoryTransferToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

function recalcInventoryTransfer() {
    let requested = 0;
    let sent = 0;
    let received = 0;
    document.querySelectorAll('#inventoryTransferLinesBody tr').forEach(row => {
        const req = Number(row.dataset.requested || 0);
        const sentQty = Number(row.dataset.sent || 0);
        const input = row.querySelector('.inventory-transfer-received');
        const rec = Number(input ? input.value || 0 : row.dataset.received || 0);
        requested += req;
        sent += sentQty;
        received += rec;
        row.querySelector('.inventory-transfer-variance').textContent = (sentQty - rec).toFixed(3);
    });
    document.getElementById('transferRequestedTotal').textContent = requested.toFixed(3);
    document.getElementById('transferSentTotal').textContent = sent.toFixed(3);
    document.getElementById('transferReceivedTotal').textContent = received.toFixed(3);
}

function collectReceiveLines() {
    const lines = [];
    document.querySelectorAll('#inventoryTransferLinesBody tr').forEach(row => {
        const qty = Number(row.querySelector('.inventory-transfer-received').value || 0);
        lines.push({
            transfer_line_id: Number(row.dataset.lineId || 0),
            received_qty: qty.toFixed(6)
        });
    });
    return lines;
}

function normalizeInventoryTransferScan(value) {
    return String(value || '').trim().toLowerCase();
}

function setInventoryTransferScanResult(message, type = '') {
    const node = document.getElementById('inventoryTransferScanResult');
    if (!node) {
        return;
    }
    node.textContent = message;
    node.classList.toggle('success', type === 'success');
    node.classList.toggle('error', type === 'error');
}

function findInventoryTransferScanRow(code) {
    const normalized = normalizeInventoryTransferScan(code);
    if (normalized === '') {
        return null;
    }
    const rows = Array.from(document.querySelectorAll('#inventoryTransferLinesBody tr'));
    return rows.find(row =>
        normalizeInventoryTransferScan(row.dataset.barcode) === normalized
        || normalizeInventoryTransferScan(row.dataset.itemId) === normalized
    ) || null;
}

function applyInventoryTransferScan(code) {
    const row = findInventoryTransferScanRow(code);
    const qtyNode = document.getElementById('inventoryTransferScanQty');
    const scanQty = Number(qtyNode ? qtyNode.value || 0 : 0);
    if (!row) {
        setInventoryTransferScanResult('الباركود غير موجود في هذا التحويل', 'error');
        inventoryTransferToast('الباركود غير موجود في هذا التحويل', true);
        return false;
    }
    if (!(scanQty > 0)) {
        setInventoryTransferScanResult('أدخل كمية صحيحة لكل مسحة', 'error');
        return false;
    }

    const input = row.querySelector('.inventory-transfer-received');
    if (!input || input.readOnly || input.disabled) {
        setInventoryTransferScanResult('لا يمكن تعديل الاستلام في حالة هذا التحويل', 'error');
        return false;
    }

    const current = Number(input.value || 0);
    const sentQty = Number(row.dataset.sent || 0);
    const next = current + scanQty;
    if (next > sentQty) {
        setInventoryTransferScanResult('الكمية الممسوحة أكبر من الكمية المرسلة', 'error');
        inventoryTransferToast('لا يمكن تجاوز الكمية المرسلة', true);
        return false;
    }

    input.value = next.toFixed(3);
    row.classList.add('inventory-transfer-line-hit');
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    window.setTimeout(() => row.classList.remove('inventory-transfer-line-hit'), 1200);
    recalcInventoryTransfer();
    setInventoryTransferScanResult('تم تحديث كمية الاستلام', 'success');
    return true;
}

async function postInventoryTransfer(url, payload, fallbackMessage) {
    const response = await fetch(url, {
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
        throw new Error(result.message || fallbackMessage);
    }
    inventoryTransferToast(result.message || fallbackMessage);
    window.setTimeout(() => window.location.reload(), 550);
}

document.querySelectorAll('.inventory-transfer-received').forEach(input => input.addEventListener('input', recalcInventoryTransfer));
const transferBarcodeInput = document.getElementById('inventoryTransferBarcodeScan');
if (transferBarcodeInput) {
    transferBarcodeInput.addEventListener('keydown', event => {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        const code = transferBarcodeInput.value;
        if (applyInventoryTransferScan(code)) {
            transferBarcodeInput.value = '';
            transferBarcodeInput.focus();
        }
    });
}
const submitTransferButton = document.getElementById('submitInventoryTransfer');
if (submitTransferButton) {
    submitTransferButton.addEventListener('click', async () => {
    try {
        await postInventoryTransfer('ajax/inventory_transfer_submit.php', { transfer_id: inventoryTransferId }, 'تم إرسال التحويل');
    } catch (error) {
        inventoryTransferToast(error.message || 'تعذر إرسال التحويل', true);
    }
    });
}
const sendTransferButton = document.getElementById('sendInventoryTransfer');
if (sendTransferButton) {
    sendTransferButton.addEventListener('click', async () => {
    try {
        await postInventoryTransfer('ajax/inventory_transfer_send.php', { transfer_id: inventoryTransferId }, 'تم إرسال المخزون');
    } catch (error) {
        inventoryTransferToast(error.message || 'تعذر إرسال المخزون', true);
    }
    });
}
const receiveTransferButton = document.getElementById('receiveInventoryTransfer');
if (receiveTransferButton) {
    receiveTransferButton.addEventListener('click', async () => {
    try {
        await postInventoryTransfer('ajax/inventory_transfer_receive.php', { transfer_id: inventoryTransferId, lines: collectReceiveLines() }, 'تم استلام المخزون');
    } catch (error) {
        inventoryTransferToast(error.message || 'تعذر استلام المخزون', true);
    }
    });
}

const closeVarianceButton = document.getElementById('closeInventoryTransferVariance');
if (closeVarianceButton) {
    closeVarianceButton.addEventListener('click', async () => {
    const reasonCodeId = Number(document.getElementById('inventoryTransferVarianceReason').value || 0);
    const reason = document.getElementById('inventoryTransferVarianceNote').value.trim();
    if (!reasonCodeId && reason === '') {
        inventoryTransferToast('أدخل سبب فرق التحويل قبل الإغلاق', true);
        return;
    }
    if (!window.confirm('سيتم إغلاق فرق التحويل بدون إضافة كمية أخرى للوجهة. هل تريد المتابعة؟')) {
        return;
    }
    try {
        await postInventoryTransfer('ajax/inventory_transfer_close_variance.php', {
            transfer_id: inventoryTransferId,
            reason_code_id: reasonCodeId,
            reason
        }, 'تم إغلاق فرق التحويل');
    } catch (error) {
        inventoryTransferToast(error.message || 'تعذر إغلاق فرق التحويل', true);
    }
    });
}

const cancelTransferButton = document.getElementById('cancelInventoryTransfer');
if (cancelTransferButton) {
    cancelTransferButton.addEventListener('click', async () => {
    const reason = window.prompt('سبب إلغاء التحويل', '');
    if (reason === null) {
        return;
    }
    if (!window.confirm('سيتم إلغاء التحويل. إذا كان مرسلاً ولم يستلم، سيعاد أثره للمصدر. هل تريد المتابعة؟')) {
        return;
    }
    try {
        await postInventoryTransfer('ajax/inventory_transfer_cancel.php', {
            transfer_id: inventoryTransferId,
            reason
        }, 'تم إلغاء التحويل');
    } catch (error) {
        inventoryTransferToast(error.message || 'تعذر إلغاء التحويل', true);
    }
    });
}

recalcInventoryTransfer();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function inventoryTransferDetailRow(mysqli $conn, string $sql): ?array
{
    try {
        $result = $conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return is_array($row) ? $row : null;
    } catch (Throwable $exception) {
        return null;
    }
}

function inventoryTransferDetailRows(mysqli $conn, string $sql): array
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

function inventoryTransferDetailStatusLabel(string $status): string
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

function inventoryTransferDetailTableExists(mysqli $conn, string $table): bool
{
    try {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function inventoryTransferDetailColumnExists(mysqli $conn, string $table, string $column): bool
{
    try {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    } catch (Throwable $exception) {
        return false;
    }
}
