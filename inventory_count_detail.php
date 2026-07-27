<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/InventoryFeatureFlags.php';

require_permission('inventory.edit', $conn);

$inventoryCountId = (int) ($_GET['id'] ?? 0);
$inventoryCountFlags = new InventoryFeatureFlags();
$inventoryCountCanClose = $inventoryCountFlags->canWriteLedger();
$inventoryCountCanViewCost = auth_guard_has_permission('accounting.view', $conn) || auth_guard_has_permission('reports.view', $conn);
$inventoryCountCanApprove = auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn);
$inventoryCountCanReverse = $inventoryCountCanClose && $inventoryCountCanApprove;
$inventoryCountHasUnitTables = inventoryCountDetailTableExists($conn, 'item_units') && inventoryCountDetailTableExists($conn, 'myunits');
$inventoryCount = inventoryCountDetailRow($conn, "
    SELECT c.*, s.aname AS store_name
    FROM inventory_counts c
    LEFT JOIN acc_head s ON s.id = c.store_id
    WHERE c.id = " . $inventoryCountId . "
    LIMIT 1
");
$inventoryCountLines = $inventoryCount ? inventoryCountDetailRows($conn, "
    SELECT l.*, i.iname, i.barcode" . ($inventoryCountHasUnitTables ? ", COALESCE(u.uname, 'الوحدة الأساسية') AS unit_name, l.unit_conversion_to_base AS unit_conversion" : ", 'الوحدة الأساسية' AS unit_name, l.unit_conversion_to_base AS unit_conversion") . "
    FROM inventory_count_lines l
    LEFT JOIN myitems i ON i.id = l.item_id
" . ($inventoryCountHasUnitTables ? "    LEFT JOIN myunits u ON u.id = l.unit_id
" : "") . "
    WHERE l.count_id = " . $inventoryCountId . "
    ORDER BY i.iname, l.id
") : [];

if (!$inventoryCount) {
    http_response_code(404);
}

$inventoryCountCsrfMeta = csrf_meta_tag('inventory_count', 'inventory-count-csrf');
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= $inventoryCountCsrfMeta ?>

<style>
    .inventory-count-detail-page {
        direction: rtl;
        background: #f6f8fb;
        min-height: calc(100vh - 57px);
    }
    .inventory-count-detail-wrap {
        max-width: 1440px;
        margin: 0 auto;
        padding: 18px;
    }
    .inventory-count-detail-header {
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
    .inventory-count-detail-title {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 0;
    }
    .inventory-count-detail-subtitle {
        margin: 6px 0 0;
        color: #c8d4df;
        font-size: 13px;
    }
    .inventory-count-status {
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
    .inventory-count-panel {
        margin-top: 16px;
        background: #fff;
        border: 1px solid #dde5ee;
        border-radius: 8px;
        box-shadow: 0 8px 20px rgba(21, 35, 50, 0.07);
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
    .inventory-count-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }
    .inventory-count-summary-cell {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
    }
    .inventory-count-summary-cell span {
        display: block;
        color: #64748b;
        font-size: 12px;
    }
    .inventory-count-summary-cell strong {
        display: block;
        color: #102033;
        font-size: 18px;
        margin-top: 2px;
    }
    .inventory-count-scan-panel {
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
    .inventory-count-scan-field label {
        display: block;
        margin-bottom: 6px;
        color: #4b5f74;
        font-size: 12px;
        font-weight: 800;
    }
    .inventory-count-scan-field .form-control {
        min-height: 40px;
        border-radius: 8px;
    }
    .inventory-count-scan-result {
        display: flex;
        align-items: center;
        min-height: 40px;
        color: #475569;
        font-weight: 700;
    }
    .inventory-count-scan-result.success {
        color: #047857;
    }
    .inventory-count-scan-result.error {
        color: #b91c1c;
    }
    .inventory-count-line-hit {
        outline: 2px solid #0f766e;
        outline-offset: -2px;
        background: #ecfdf5;
    }
    .inventory-count-lines {
        width: 100%;
        table-layout: fixed;
        margin: 0;
    }
    .inventory-count-lines th {
        background: #eef3f7;
        color: #334155;
        font-size: 12px;
        border-color: #dde5ee;
        white-space: nowrap;
    }
    .inventory-count-lines td {
        border-color: #e5ebf1;
        vertical-align: middle;
    }
    .inventory-count-lines .form-control {
        min-height: 36px;
        border-radius: 8px;
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
    .inventory-count-btn.secondary {
        background: #334155;
    }
    .inventory-count-btn.warn {
        background: #b45309;
    }
    .inventory-count-btn:disabled {
        background: #94a3b8;
        cursor: not-allowed;
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
        .inventory-count-summary {
            grid-template-columns: 1fr 1fr;
        }
        .inventory-count-detail-header {
            align-items: flex-start;
            flex-direction: column;
        }
        .inventory-count-scan-panel {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="content-wrapper inventory-count-detail-page">
    <section class="content-header">
        <div class="inventory-count-detail-wrap">
            <?php if (!$inventoryCount): ?>
                <div class="alert alert-danger">مستند الجرد غير موجود</div>
            <?php else: ?>
                <div class="inventory-count-detail-header">
                    <div>
                        <h1 class="inventory-count-detail-title">COUNT-<?= (int) $inventoryCount['id'] ?></h1>
                        <p class="inventory-count-detail-subtitle"><?= htmlspecialchars((($inventoryCount['store_name'] ?? '') !== '') ? (string) $inventoryCount['store_name'] : 'مخزن غير مسمى', ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars(inventoryCountDetailTypeLabel((string) ($inventoryCount['count_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="inventory-count-status">
                        <i class="fas fa-clipboard-check"></i>
                        <span><?= htmlspecialchars(inventoryCountDetailStatusLabel((string) ($inventoryCount['status'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <?php if (!$inventoryCountCanClose): ?>
                    <div class="alert alert-warning mt-3 mb-0">الإغلاق يحتاج وضع bridge أو live للمخزون.</div>
                <?php endif; ?>

                <div class="inventory-count-panel">
                    <div class="inventory-count-panel-header">
                        <h2 class="inventory-count-panel-title">تفاصيل الجرد</h2>
                        <a class="btn btn-sm btn-outline-secondary" href="inventory_counts.php"><i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="inventory-count-panel-body">
                        <div class="inventory-count-summary">
                            <div class="inventory-count-summary-cell">
                                <span>عدد الأصناف</span>
                                <strong id="countLineTotal"><?= count($inventoryCountLines) ?></strong>
                            </div>
                            <div class="inventory-count-summary-cell">
                                <span>تم عدها</span>
                                <strong id="countedLineTotal">0</strong>
                            </div>
                            <div class="inventory-count-summary-cell">
                                <span>إجمالي الفرق</span>
                                <strong id="varianceQtyTotal">0.000</strong>
                            </div>
                            <div class="inventory-count-summary-cell">
                                <span>تعارضات</span>
                                <strong id="staleLineTotal">0</strong>
                            </div>
                        </div>

                        <div class="inventory-count-scan-panel">
                            <div class="inventory-count-scan-field">
                                <label for="inventoryCountBarcodeScan">مسح الباركود</label>
                                <input id="inventoryCountBarcodeScan" class="form-control" inputmode="text" autocomplete="off" placeholder="امسح الباركود أو اكتب رقم الصنف" <?= $inventoryCount['status'] === 'draft' ? '' : 'disabled' ?>>
                            </div>
                            <div class="inventory-count-scan-field">
                                <label for="inventoryCountScanQty">كمية كل مسحة</label>
                                <input id="inventoryCountScanQty" class="form-control" type="number" min="0.001" step="0.001" value="1.000" <?= $inventoryCount['status'] === 'draft' ? '' : 'disabled' ?>>
                            </div>
                            <div class="inventory-count-scan-result" id="inventoryCountScanResult">جاهز للمسح</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered inventory-count-lines">
                                <thead>
                                    <tr>
                                        <th style="width: 28%">الصنف</th>
                                        <th style="width: 12%">الوحدة</th>
                                        <th style="width: 13%">دفتر المخزون</th>
                                        <th style="width: 13%">الكمية المعدودة</th>
                                        <th style="width: 13%">الفرق</th>
                                        <?php if ($inventoryCountCanViewCost): ?>
                                            <th style="width: 11%">قيمة الفرق</th>
                                        <?php endif; ?>
                                        <th style="width: 10%">الحالة</th>
                                    </tr>
                                </thead>
                                <tbody id="inventoryCountLinesBody">
                                    <?php foreach ($inventoryCountLines as $line): ?>
                                        <tr data-line-id="<?= (int) $line['id'] ?>" data-item-id="<?= (int) $line['item_id'] ?>" data-barcode="<?= htmlspecialchars((string) ($line['barcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-snapshot="<?= htmlspecialchars((string) $line['snapshot_qty'], ENT_QUOTES, 'UTF-8') ?>" data-stale="<?= (int) $line['stale_count_conflict'] ?>">
                                            <td><?= htmlspecialchars(((($line['iname'] ?? '') !== '') ? (string) $line['iname'] : 'صنف غير مسمى') . (($line['barcode'] ?? '') !== '' ? ' - ' . $line['barcode'] : ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(($line['unit_name'] ?? 'الوحدة الأساسية') . (!empty($line['unit_conversion']) ? ' × ' . number_format((float) $line['unit_conversion'], 3, '.', '') : ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="inventory-count-snapshot"><?= number_format((float) $line['snapshot_qty'], 3, '.', '') ?></td>
                                            <td><input type="number" min="0" step="0.001" class="form-control inventory-counted-qty" value="<?= $line['counted_qty'] !== null ? htmlspecialchars((string) $line['counted_qty'], ENT_QUOTES, 'UTF-8') : '' ?>" <?= $inventoryCount['status'] === 'draft' ? '' : 'readonly' ?>></td>
                                            <td class="inventory-count-variance"><?= number_format((float) $line['variance_qty'], 3, '.', '') ?></td>
                                            <?php if ($inventoryCountCanViewCost): ?>
                                                <td><?= number_format((float) $line['variance_cost'], 2, '.', '') ?></td>
                                            <?php endif; ?>
                                            <td class="inventory-count-line-status"><?= ((int) $line['stale_count_conflict'] === 1) ? 'تغير بعد الفتح' : htmlspecialchars(inventoryCountDetailStatusLabel((string) $inventoryCount['status']), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex justify-content-end flex-wrap" style="gap:8px;">
                            <button type="button" class="inventory-count-btn secondary" id="saveInventoryCount" <?= $inventoryCount['status'] === 'draft' ? '' : 'disabled' ?>><i class="fas fa-save"></i> حفظ</button>
                            <button type="button" class="inventory-count-btn" id="submitInventoryCount" <?= $inventoryCount['status'] === 'draft' ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> إرسال</button>
                            <button type="button" class="inventory-count-btn" id="approveInventoryCount" <?= ($inventoryCount['status'] === 'submitted' && $inventoryCountCanApprove) ? '' : 'disabled' ?>><i class="fas fa-check-double"></i> اعتماد</button>
                            <button type="button" class="inventory-count-btn warn" id="closeInventoryCount" <?= ($inventoryCount['status'] === 'approved' && $inventoryCountCanClose) ? '' : 'disabled' ?>><i class="fas fa-lock"></i> إغلاق</button>
                            <button type="button" class="inventory-count-btn warn" id="reverseInventoryCount" <?= ($inventoryCount['status'] === 'closed' && $inventoryCountCanReverse) ? '' : 'disabled' ?>><i class="fas fa-undo"></i> عكس الأثر</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="inventory-count-toast" id="inventoryCountToast"></div>

<script>
const inventoryCountId = <?= (int) $inventoryCountId ?>;
const inventoryCountCanApprove = <?= $inventoryCountCanApprove ? 'true' : 'false' ?>;

function inventoryCountToast(message, isError = false) {
    const toast = document.getElementById('inventoryCountToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

function recalcInventoryCount() {
    let counted = 0;
    let varianceTotal = 0;
    let stale = 0;
    document.querySelectorAll('#inventoryCountLinesBody tr').forEach(row => {
        const snapshot = Number(row.dataset.snapshot || 0);
        const input = row.querySelector('.inventory-counted-qty');
        const countedQty = input && input.value !== '' ? Number(input.value || 0) : null;
        if (countedQty !== null) {
            counted += 1;
            const variance = countedQty - snapshot;
            varianceTotal += variance;
            row.querySelector('.inventory-count-variance').textContent = variance.toFixed(3);
        }
        if (Number(row.dataset.stale || 0) === 1) {
            stale += 1;
        }
    });
    document.getElementById('countedLineTotal').textContent = String(counted);
    document.getElementById('varianceQtyTotal').textContent = varianceTotal.toFixed(3);
    document.getElementById('staleLineTotal').textContent = String(stale);
}

function collectInventoryCountLines() {
    const lines = [];
    document.querySelectorAll('#inventoryCountLinesBody tr').forEach(row => {
        const countedQty = row.querySelector('.inventory-counted-qty').value;
        if (countedQty !== '') {
            lines.push({
                item_id: Number(row.dataset.itemId || 0),
                counted_qty: Number(countedQty).toFixed(6)
            });
        }
    });
    return lines;
}

function normalizeInventoryCountScan(value) {
    return String(value || '').trim().toLowerCase();
}

function setInventoryCountScanResult(message, type = '') {
    const node = document.getElementById('inventoryCountScanResult');
    if (!node) {
        return;
    }
    node.textContent = message;
    node.classList.toggle('success', type === 'success');
    node.classList.toggle('error', type === 'error');
}

function findInventoryCountScanRow(code) {
    const normalized = normalizeInventoryCountScan(code);
    if (normalized === '') {
        return null;
    }
    const rows = Array.from(document.querySelectorAll('#inventoryCountLinesBody tr'));
    return rows.find(row =>
        normalizeInventoryCountScan(row.dataset.barcode) === normalized
        || normalizeInventoryCountScan(row.dataset.itemId) === normalized
    ) || null;
}

function applyInventoryCountScan(code) {
    const row = findInventoryCountScanRow(code);
    const qtyNode = document.getElementById('inventoryCountScanQty');
    const scanQty = Number(qtyNode ? qtyNode.value || 0 : 0);
    if (!row) {
        setInventoryCountScanResult('الباركود غير موجود في هذا الجرد', 'error');
        inventoryCountToast('الباركود غير موجود في هذا الجرد', true);
        return false;
    }
    if (!(scanQty > 0)) {
        setInventoryCountScanResult('أدخل كمية صحيحة لكل مسحة', 'error');
        return false;
    }

    const input = row.querySelector('.inventory-counted-qty');
    if (!input || input.readOnly || input.disabled) {
        setInventoryCountScanResult('لا يمكن تعديل هذا الجرد في حالته الحالية', 'error');
        return false;
    }

    const current = input.value === '' ? 0 : Number(input.value || 0);
    const next = current + scanQty;
    input.value = next.toFixed(3);
    row.classList.add('inventory-count-line-hit');
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    window.setTimeout(() => row.classList.remove('inventory-count-line-hit'), 1200);
    recalcInventoryCount();
    setInventoryCountScanResult('تم تحديث الكمية المعدودة', 'success');
    return true;
}

async function postInventoryCount(url, payload, fallbackMessage) {
    const response = await fetch(url, {
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
        const error = new Error(result.message || fallbackMessage);
        error.code = result.code || '';
        throw error;
    }
    inventoryCountToast(result.message || fallbackMessage);
    window.setTimeout(() => window.location.reload(), 550);
}

document.querySelectorAll('.inventory-counted-qty').forEach(input => input.addEventListener('input', recalcInventoryCount));
const barcodeInput = document.getElementById('inventoryCountBarcodeScan');
if (barcodeInput) {
    barcodeInput.addEventListener('keydown', event => {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        const code = barcodeInput.value;
        if (applyInventoryCountScan(code)) {
            barcodeInput.value = '';
            barcodeInput.focus();
        }
    });
}
const saveButton = document.getElementById('saveInventoryCount');
if (saveButton) {
    saveButton.addEventListener('click', async () => {
        try {
            await postInventoryCount('ajax/inventory_count_save.php', {
                count_id: inventoryCountId,
                lines: collectInventoryCountLines()
            }, 'تم حفظ كميات الجرد');
        } catch (error) {
            inventoryCountToast(error.message || 'تعذر حفظ الجرد', true);
        }
    });
}

const submitButton = document.getElementById('submitInventoryCount');
if (submitButton) {
    submitButton.addEventListener('click', async () => {
        try {
            await postInventoryCount('ajax/inventory_count_submit.php', { count_id: inventoryCountId }, 'تم إرسال الجرد');
        } catch (error) {
            inventoryCountToast(error.message || 'تعذر إرسال الجرد', true);
        }
    });
}

const approveButton = document.getElementById('approveInventoryCount');
if (approveButton) {
    approveButton.addEventListener('click', async () => {
        if (!inventoryCountCanApprove) {
            inventoryCountToast('اعتماد الجرد يحتاج صلاحية اعتماد المخزون', true);
            return;
        }
        try {
            await postInventoryCount('ajax/inventory_count_approve.php', { count_id: inventoryCountId }, 'تم اعتماد الجرد');
        } catch (error) {
            inventoryCountToast(error.message || 'تعذر اعتماد الجرد', true);
        }
    });
}

const closeButton = document.getElementById('closeInventoryCount');
if (closeButton) {
    closeButton.addEventListener('click', async () => {
        try {
            await postInventoryCount('ajax/inventory_count_close.php', { count_id: inventoryCountId }, 'تم إغلاق الجرد');
        } catch (error) {
            if (error.code === 'COUNT_STALE_SNAPSHOT' && window.confirm(error.message || 'تغير المخزون بعد فتح الجرد. هل تريد الإغلاق رغم ذلك؟')) {
                try {
                    await postInventoryCount('ajax/inventory_count_close.php', { count_id: inventoryCountId, allow_stale_close: true }, 'تم إغلاق الجرد');
                    return;
                } catch (retryError) {
                    inventoryCountToast(retryError.message || 'تعذر إغلاق الجرد', true);
                    return;
                }
            }
            inventoryCountToast(error.message || 'تعذر إغلاق الجرد', true);
        }
    });
}

const reverseButton = document.getElementById('reverseInventoryCount');
if (reverseButton) {
    reverseButton.addEventListener('click', async () => {
        const reason = window.prompt('اكتب سبب عكس أثر الجرد المغلق', '');
        if (reason === null) {
            return;
        }
        if (!window.confirm('سيتم إنشاء حركات عكسية وإلغاء مستند الجرد. هل تريد المتابعة؟')) {
            return;
        }
        try {
            await postInventoryCount('ajax/inventory_count_reverse.php', {
                count_id: inventoryCountId,
                reason
            }, 'تم عكس أثر الجرد');
        } catch (error) {
            inventoryCountToast(error.message || 'تعذر عكس أثر الجرد', true);
        }
    });
}

recalcInventoryCount();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function inventoryCountDetailRow(mysqli $conn, string $sql): ?array
{
    try {
        $result = $conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return is_array($row) ? $row : null;
    } catch (Throwable $exception) {
        return null;
    }
}

function inventoryCountDetailRows(mysqli $conn, string $sql): array
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

function inventoryCountDetailStatusLabel(string $status): string
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

function inventoryCountDetailTypeLabel(string $type): string
{
    $labels = [
        'selected' => 'أصناف محددة',
        'spot' => 'جرد سريع',
        'category' => 'تصنيف كامل',
        'full' => 'جرد كامل',
    ];

    return $labels[$type] ?? 'نوع جرد غير معروف';
}

function inventoryCountDetailTableExists(mysqli $conn, string $table): bool
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
