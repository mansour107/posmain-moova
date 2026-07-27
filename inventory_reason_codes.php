<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/classes/Inventory/InventoryReasonCodeService.php';
require_once __DIR__ . '/classes/Inventory/InventoryScopeResolver.php';

require_permission('inventory.edit', $conn);

$inventoryReasonFlags = new InventoryFeatureFlags();
$inventoryReasonScope = (new InventoryScopeResolver($inventoryReasonFlags->appConfig()))->resolveForConn($conn, [
    'source' => 'inventory_reason_code_admin',
], 'read');
$inventoryReasonService = new InventoryReasonCodeService();
$inventoryReasonRows = $inventoryReasonService->listAll($conn, $inventoryReasonScope, true);
$inventoryReasonGroups = [
    'waste' => 'هالك',
    'adjustment' => 'تسوية',
    'transfer_variance' => 'فرق تحويل',
    'count' => 'جرد',
    'purchase_return' => 'مرتجع شراء',
    'production_variance' => 'فرق إنتاج',
    'manual' => 'عام',
];
$inventoryReasonDirections = [
    'out' => 'خارج',
    'in' => 'داخل',
    'both' => 'كلاهما',
    'none' => 'بدون كمية',
];

$inventoryReasonCodeCsrfMeta = csrf_meta_tag('inventory_reason_code', 'inventory-reason-code-csrf');
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?= $inventoryReasonCodeCsrfMeta ?>

<style>
    html,body{overflow-x:hidden}
    .inventory-reason-page{direction:rtl;background:#f5f7fb;min-height:calc(100vh - 57px)}
    .inventory-reason-wrap{max-width:1440px;width:100%;box-sizing:border-box;margin:0 auto;padding:18px;overflow-x:hidden}
    .inventory-reason-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;background:#172033;color:#fff;border-radius:8px;box-shadow:0 12px 30px rgba(15,23,42,.16)}
    .inventory-reason-title{margin:0;font-size:24px;font-weight:800;letter-spacing:0}
    .inventory-reason-subtitle{margin:6px 0 0;color:#cbd5e1;font-size:13px}
    .inventory-reason-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:9px 12px;border-radius:8px;white-space:nowrap}
    .inventory-reason-grid{display:grid;grid-template-columns:minmax(300px,390px) 1fr;gap:16px;margin-top:16px;min-width:0}
    .inventory-reason-panel{min-width:0;background:#fff;border:1px solid #dce4ef;border-radius:8px;box-shadow:0 8px 22px rgba(21,35,50,.07)}
    .inventory-reason-panel-header{padding:14px 16px;border-bottom:1px solid #e6edf5;display:flex;justify-content:space-between;align-items:center;gap:10px}
    .inventory-reason-panel-title{margin:0;font-size:16px;font-weight:800;color:#102033}
    .inventory-reason-panel-body{padding:16px;min-width:0}
    .inventory-reason-field{margin-bottom:13px}
    .inventory-reason-field label{display:block;font-size:12px;color:#4b5f74;margin-bottom:6px;font-weight:800}
    .inventory-reason-field .form-control{border-radius:8px;border-color:#cbd7e4;min-height:40px}
    .inventory-reason-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:14px}
    .inventory-reason-toggle{display:flex;align-items:center;gap:8px;border:1px solid #d8e2ec;background:#f8fafc;border-radius:8px;padding:10px 12px;color:#334155;font-weight:700}
    .inventory-reason-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
    .inventory-reason-btn{min-height:40px;border:0;border-radius:8px;padding:0 15px;font-weight:800}
    .inventory-reason-btn.primary{background:#0f766e;color:#fff}
    .inventory-reason-btn.secondary{background:#e2e8f0;color:#102033}
    .inventory-reason-table{min-width:920px}
    .inventory-reason-table th{background:#eef3f7;color:#334155;font-size:12px;border-color:#dce4ef;white-space:nowrap}
    .inventory-reason-table td{border-color:#e5ebf1;vertical-align:middle}
    .inventory-reason-badge{display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:5px 8px;font-size:12px;font-weight:800;background:#e0f2fe;color:#075985;white-space:nowrap}
    .inventory-reason-badge.off{background:#fee2e2;color:#991b1b}
    .inventory-reason-badge.system{background:#ede9fe;color:#5b21b6}
    .inventory-reason-row-actions{display:flex;gap:6px;flex-wrap:wrap}
    .inventory-reason-icon-btn{border:1px solid #cbd7e4;background:#fff;color:#102033;border-radius:8px;min-width:38px;min-height:34px}
    .inventory-reason-icon-btn.danger{color:#b91c1c;border-color:#fecaca;background:#fff5f5}
    .inventory-reason-icon-btn.success{color:#047857;border-color:#bbf7d0;background:#f0fdf4}
    .inventory-reason-icon-btn:disabled{opacity:.45;cursor:not-allowed}
    .inventory-reason-toast{display:none;position:fixed;left:22px;bottom:22px;z-index:2000;min-width:280px;max-width:420px;border-radius:8px;padding:13px 16px;color:#fff;background:#102033;box-shadow:0 14px 34px rgba(15,23,42,.22)}
    .inventory-reason-toast.error{background:#b91c1c}
    @media(max-width:992px){.inventory-reason-grid{grid-template-columns:1fr}.inventory-reason-header{align-items:flex-start;flex-direction:column}.inventory-reason-table{min-width:760px}}
</style>

<div class="content-wrapper inventory-reason-page">
    <section class="content-header">
        <div class="inventory-reason-wrap">
            <div class="inventory-reason-header">
                <div>
                    <h1 class="inventory-reason-title">أسباب عمليات المخزون</h1>
                    <p class="inventory-reason-subtitle">أكواد الهالك والتسويات وفروق التحويل</p>
                </div>
                <div class="inventory-reason-pill">
                    <i class="fas fa-tags"></i>
                    <span><?= count($inventoryReasonRows) ?> سبب</span>
                </div>
            </div>

            <div class="inventory-reason-grid">
                <div class="inventory-reason-panel">
                    <div class="inventory-reason-panel-header">
                        <h2 class="inventory-reason-panel-title">سبب جديد</h2>
                    </div>
                    <div class="inventory-reason-panel-body">
                        <input type="hidden" id="inventoryReasonCodeId" value="">
                        <div class="inventory-reason-field">
                            <label for="inventoryReasonCode">الرمز الداخلي</label>
                            <input id="inventoryReasonCode" class="form-control" maxlength="64" placeholder="اختياري عند إنشاء سبب جديد">
                            <small class="form-text text-muted">اتركه فارغا ليقترح النظام رمزا داخليا من المجموعة والاسم.</small>
                        </div>
                        <div class="inventory-reason-field">
                            <label for="inventoryReasonName">الاسم</label>
                            <input id="inventoryReasonName" class="form-control" maxlength="255" placeholder="تالف أو منتهي الصلاحية">
                        </div>
                        <div class="inventory-reason-field">
                            <label for="inventoryReasonGroup">المجموعة</label>
                            <select id="inventoryReasonGroup" class="form-control">
                                <?php foreach ($inventoryReasonGroups as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-reason-field">
                            <label for="inventoryReasonDirection">الاتجاه</label>
                            <select id="inventoryReasonDirection" class="form-control">
                                <?php foreach ($inventoryReasonDirections as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-reason-checks">
                            <label class="inventory-reason-toggle">
                                <input id="inventoryReasonRequiresApproval" type="checkbox">
                                <span>يحتاج اعتماد</span>
                            </label>
                            <label class="inventory-reason-toggle">
                                <input id="inventoryReasonIsActive" type="checkbox" checked>
                                <span>نشط</span>
                            </label>
                        </div>
                        <div class="inventory-reason-actions">
                            <button type="button" class="inventory-reason-btn secondary" id="resetInventoryReasonCode"><i class="fas fa-undo"></i> جديد</button>
                            <button type="button" class="inventory-reason-btn primary" id="saveInventoryReasonCode"><i class="fas fa-save"></i> حفظ السبب</button>
                        </div>
                    </div>
                </div>

                <div class="inventory-reason-panel">
                    <div class="inventory-reason-panel-header">
                        <h2 class="inventory-reason-panel-title">الأسباب الحالية</h2>
                    </div>
                    <div class="inventory-reason-panel-body">
                        <div class="table-responsive">
                            <table class="table table-bordered inventory-reason-table">
                                <thead>
                                    <tr>
                                        <th>الحالة</th>
                                        <th>الكود</th>
                                        <th>الاسم</th>
                                        <th>المجموعة</th>
                                        <th>الاتجاه</th>
                                        <th>الاعتماد</th>
                                        <th>النوع</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryReasonRows as $row): ?>
                                        <?php
                                            $group = (string) ($row['reason_group'] ?? 'manual');
                                            $direction = (string) ($row['direction'] ?? 'both');
                                            $editable = !empty($row['editable']);
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if ((int) ($row['is_active'] ?? 0) === 1): ?>
                                                    <span class="inventory-reason-badge"><i class="fas fa-check"></i> نشط</span>
                                                <?php else: ?>
                                                    <span class="inventory-reason-badge off"><i class="fas fa-ban"></i> متوقف</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($row['reason_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($row['reason_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($inventoryReasonGroups[$group] ?? $group, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($inventoryReasonDirections[$direction] ?? $direction, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= ((int) ($row['requires_approval'] ?? 0) === 1) ? 'نعم' : 'لا' ?></td>
                                            <td>
                                                <?php if ((int) ($row['is_system'] ?? 0) === 1): ?>
                                                    <span class="inventory-reason-badge system">نظامي</span>
                                                <?php else: ?>
                                                    <span class="inventory-reason-badge">مخصص</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="inventory-reason-row-actions">
                                                    <button type="button" class="inventory-reason-icon-btn" data-inventory-reason-edit="<?= (int) $row['id'] ?>" <?= $editable ? '' : 'disabled' ?> title="تعديل"><i class="fas fa-pen"></i></button>
                                                    <?php if ((int) ($row['is_active'] ?? 0) === 1): ?>
                                                        <button type="button" class="inventory-reason-icon-btn danger" data-inventory-reason-retire="<?= (int) $row['id'] ?>" <?= $editable ? '' : 'disabled' ?> title="إيقاف"><i class="fas fa-ban"></i></button>
                                                    <?php else: ?>
                                                        <button type="button" class="inventory-reason-icon-btn success" data-inventory-reason-reactivate="<?= (int) $row['id'] ?>" <?= $editable ? '' : 'disabled' ?> title="تفعيل"><i class="fas fa-check"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!$inventoryReasonRows): ?>
                            <div class="alert alert-secondary mb-0">لا توجد أسباب مخزون حتى الآن.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="inventory-reason-toast" id="inventoryReasonCodeToast"></div>

<script>
const inventoryReasonCodeRows = <?= json_encode($inventoryReasonRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inventoryReasonCodeCsrf = document.querySelector('meta[name="inventory-reason-code-csrf"]').getAttribute('content');

function inventoryReasonCodeToast(message, isError = false) {
    const toast = document.getElementById('inventoryReasonCodeToast');
    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 2600);
}

function inventoryReasonCodeReset() {
    document.getElementById('inventoryReasonCodeId').value = '';
    document.getElementById('inventoryReasonCode').value = '';
    document.getElementById('inventoryReasonName').value = '';
    document.getElementById('inventoryReasonGroup').value = 'waste';
    document.getElementById('inventoryReasonDirection').value = 'out';
    document.getElementById('inventoryReasonRequiresApproval').checked = false;
    document.getElementById('inventoryReasonIsActive').checked = true;
}

function inventoryReasonCodeEdit(id) {
    const row = inventoryReasonCodeRows.find(item => Number(item.id || 0) === Number(id || 0));
    if (!row || !row.editable) {
        return;
    }
    document.getElementById('inventoryReasonCodeId').value = row.id || '';
    document.getElementById('inventoryReasonCode').value = row.reason_code || '';
    document.getElementById('inventoryReasonName').value = row.reason_name || '';
    document.getElementById('inventoryReasonGroup').value = row.reason_group || 'manual';
    document.getElementById('inventoryReasonDirection').value = row.direction || 'both';
    document.getElementById('inventoryReasonRequiresApproval').checked = Number(row.requires_approval || 0) === 1;
    document.getElementById('inventoryReasonIsActive').checked = Number(row.is_active || 0) === 1;
    document.getElementById('inventoryReasonCode').focus();
}

function inventoryReasonCodeGenerateCode(name, group) {
    const source = String(name || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
    let slug = source.toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 36);
    const prefix = String(group || 'manual').toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 20) || 'MANUAL';
    if (!slug) {
        slug = Date.now().toString(36).toUpperCase();
    }
    return `${prefix}_${slug}`.replace(/_+/g, '_').slice(0, 64);
}

async function inventoryReasonCodePost(payload) {
    const response = await fetch('ajax/inventory_reason_code.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': inventoryReasonCodeCsrf,
        },
        body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'تعذر حفظ سبب العملية');
    }
    return data;
}

document.getElementById('resetInventoryReasonCode').addEventListener('click', inventoryReasonCodeReset);
document.getElementById('saveInventoryReasonCode').addEventListener('click', async () => {
    const payload = {
        action: 'save',
        id: Number(document.getElementById('inventoryReasonCodeId').value || 0),
        reason_code: document.getElementById('inventoryReasonCode').value.trim(),
        reason_name: document.getElementById('inventoryReasonName').value,
        reason_group: document.getElementById('inventoryReasonGroup').value,
        direction: document.getElementById('inventoryReasonDirection').value,
        requires_approval: document.getElementById('inventoryReasonRequiresApproval').checked ? 1 : 0,
        is_active: document.getElementById('inventoryReasonIsActive').checked ? 1 : 0,
    };
    if (!payload.id && !payload.reason_code) {
        payload.reason_code = inventoryReasonCodeGenerateCode(payload.reason_name, payload.reason_group);
        document.getElementById('inventoryReasonCode').value = payload.reason_code;
    }
    try {
        const result = await inventoryReasonCodePost(payload);
        inventoryReasonCodeToast(result.message || 'تم الحفظ');
        setTimeout(() => window.location.reload(), 600);
    } catch (error) {
        inventoryReasonCodeToast(error.message, true);
    }
});

document.querySelectorAll('[data-inventory-reason-edit]').forEach(button => {
    button.addEventListener('click', () => inventoryReasonCodeEdit(button.getAttribute('data-inventory-reason-edit')));
});
document.querySelectorAll('[data-inventory-reason-retire]').forEach(button => {
    button.addEventListener('click', async () => {
        try {
            const result = await inventoryReasonCodePost({ action: 'retire', id: Number(button.getAttribute('data-inventory-reason-retire') || 0) });
            inventoryReasonCodeToast(result.message || 'تم الإيقاف');
            setTimeout(() => window.location.reload(), 600);
        } catch (error) {
            inventoryReasonCodeToast(error.message, true);
        }
    });
});
document.querySelectorAll('[data-inventory-reason-reactivate]').forEach(button => {
    button.addEventListener('click', async () => {
        try {
            const result = await inventoryReasonCodePost({ action: 'reactivate', id: Number(button.getAttribute('data-inventory-reason-reactivate') || 0) });
            inventoryReasonCodeToast(result.message || 'تم التفعيل');
            setTimeout(() => window.location.reload(), 600);
        } catch (error) {
            inventoryReasonCodeToast(error.message, true);
        }
    });
});

inventoryReasonCodeReset();
</script>
