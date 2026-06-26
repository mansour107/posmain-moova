<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/pos_operational_store.php';
require_once __DIR__ . '/classes/Inventory/InventoryReportsService.php';

require_login();
if (!posmain_inventory_dashboard_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$inventoryDashboardCanViewCost = posmain_inventory_dashboard_can_view_cost($conn);
$inventoryDashboardFilters = posmain_inventory_dashboard_filters($_GET, $conn);
$inventoryDashboardItemFocus = (int) ($inventoryDashboardFilters['item_id'] ?? 0) > 0; // inventory_dashboard_item_focus
$inventoryDashboardMovementTypes = posmain_inventory_dashboard_movement_type_labels();
$inventoryDashboardService = new InventoryReportsService();
$inventoryDashboardMovements = $inventoryDashboardService->report($conn, 'movement_history', $inventoryDashboardFilters);
$inventoryDashboardSummary = $inventoryDashboardItemFocus
    ? []
    : $inventoryDashboardService->dashboard($conn, $inventoryDashboardFilters);
$inventoryDashboardStats = posmain_inventory_dashboard_movement_stats(
    $inventoryDashboardMovements,
    $inventoryDashboardSummary,
    $inventoryDashboardCanViewCost,
    $inventoryDashboardItemFocus
);
$inventoryDashboardStores = posmain_inventory_dashboard_stores($conn);
$inventoryDashboardSingleStore = posmain_single_store_mode_enabled();
$inventoryDashboardBranches = $inventoryDashboardItemFocus ? [] : posmain_inventory_dashboard_branches($conn);
$inventoryDashboardItems = $inventoryDashboardItemFocus ? [] : posmain_inventory_dashboard_items($conn);
$inventoryDashboardFocusedItem = $inventoryDashboardItemFocus
    ? posmain_inventory_dashboard_focused_item($conn, (int) $inventoryDashboardFilters['item_id'])
    : null;
$inventoryDashboardFocusedStoreName = posmain_inventory_dashboard_store_name(
    $inventoryDashboardStores,
    (int) ($inventoryDashboardFilters['store_id'] ?? 0)
);
$inventoryDashboardReportUrl = posmain_inventory_dashboard_reports_url($inventoryDashboardFilters);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
    .inventory-dashboard-page{direction:rtl;background:#f5f7fb;min-height:calc(100vh - 57px);color:#102033}
    .inventory-dashboard-wrap{max-width:1480px;margin:0 auto;padding:18px}
    .inventory-dashboard-hero{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center;padding:20px;background:#102033;color:#fff;border-radius:8px;box-shadow:0 14px 32px rgba(16,32,51,.16)}
    .inventory-dashboard-title{margin:0;font-size:24px;font-weight:800;letter-spacing:0}
    .inventory-dashboard-subtitle{margin:7px 0 0;color:#c8d4df;font-size:13px;max-width:760px;line-height:1.6}
    .inventory-dashboard-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .inventory-dashboard-btn{min-height:40px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#102033;font-weight:800;padding:0 13px;display:inline-flex;align-items:center;gap:8px;text-decoration:none}
    .inventory-dashboard-btn:hover{color:#0f766e;text-decoration:none}
    .inventory-dashboard-btn.primary{background:#0f766e;border-color:#0f766e;color:#fff}
    .inventory-dashboard-btn.ghost{background:transparent;border-color:rgba(255,255,255,.35);color:#fff}
    .inventory-dashboard-btn.ghost:hover{background:rgba(255,255,255,.08);color:#fff}
    .inventory-dashboard-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:14px}
    .inventory-dashboard-kpi{background:#fff;border:1px solid #dbe4ee;border-radius:8px;padding:14px;min-height:86px;box-shadow:0 8px 18px rgba(15,23,42,.06)}
    .inventory-dashboard-kpi span{display:block;color:#64748b;font-size:12px;font-weight:800}
    .inventory-dashboard-kpi strong{display:block;margin-top:8px;font-size:22px;line-height:1.15;color:#102033}
    .inventory-dashboard-kpi-note{display:block;margin-top:6px;color:#94a3b8;font-size:11px;font-weight:700}
    .inventory-dashboard-filters{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin-top:14px;padding:14px;background:#fff;border:1px solid #dbe4ee;border-radius:8px;box-shadow:0 8px 18px rgba(15,23,42,.06)}
    .inventory-dashboard-field label{display:block;font-size:12px;color:#526477;font-weight:800;margin-bottom:6px}
    .inventory-dashboard-field .form-control{border-radius:8px;border-color:#ccd7e3;min-height:38px}
    .inventory-dashboard-field.wide{grid-column:span 2}
    .inventory-dashboard-panel{background:#fff;border:1px solid #dbe4ee;border-radius:8px;box-shadow:0 8px 18px rgba(15,23,42,.06);margin-top:14px;min-width:0}
    .inventory-dashboard-panel-header{padding:14px 16px;border-bottom:1px solid #e5edf5;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .inventory-dashboard-panel-title{margin:0;font-size:16px;font-weight:800;color:#102033}
    .inventory-dashboard-chip{display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:6px 9px;background:#ecfdf5;color:#047857;font-weight:800;font-size:12px}
    .inventory-dashboard-table{margin:0;min-width:1120px}
    .inventory-dashboard-table th{background:#edf3f8;color:#334155;font-size:12px;border-color:#dbe4ee;white-space:nowrap;position:sticky;top:0;z-index:1}
    .inventory-dashboard-table td{border-color:#e7edf3;vertical-align:middle;font-size:13px}
    .inventory-dashboard-table .movement-in{color:#047857;font-weight:800}
    .inventory-dashboard-table .movement-out{color:#b45309;font-weight:800}
    .inventory-dashboard-item-link{color:#0f766e;font-weight:800;text-decoration:none}
    .inventory-dashboard-item-link:hover{color:#115e59;text-decoration:underline}
    .inventory-dashboard-empty{padding:26px;text-align:center;color:#64748b}
    .inventory-dashboard-note{margin-top:12px;color:#64748b;font-size:12px;line-height:1.6}
    .inventory-dashboard-filter-actions{display:flex;gap:8px;align-items:flex-end}
    @media(max-width:1200px){.inventory-dashboard-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.inventory-dashboard-filters{grid-template-columns:repeat(3,minmax(130px,1fr))}.inventory-dashboard-field.wide{grid-column:span 3}}
    @media(max-width:768px){.inventory-dashboard-hero{grid-template-columns:1fr}.inventory-dashboard-actions{justify-content:flex-start}.inventory-dashboard-kpis{grid-template-columns:1fr}.inventory-dashboard-filters{grid-template-columns:1fr 1fr}.inventory-dashboard-field.wide{grid-column:1 / -1}}
</style>

<div class="content-wrapper inventory-dashboard-page">
    <section class="content-header">
        <div class="inventory-dashboard-wrap">
            <div class="inventory-dashboard-hero">
                <div>
                    <?php if ($inventoryDashboardItemFocus): ?>
                        <h1 class="inventory-dashboard-title">حركات الصنف</h1>
                        <p class="inventory-dashboard-subtitle">
                            <strong><?= posmain_inventory_dashboard_h(($inventoryDashboardFocusedItem['iname'] ?? '') !== '' ? $inventoryDashboardFocusedItem['iname'] : 'صنف غير مسمى') ?></strong>
                            <?php if (($inventoryDashboardFocusedItem['barcode'] ?? '') !== ''): ?>
                                <span> — باركود <?= posmain_inventory_dashboard_h($inventoryDashboardFocusedItem['barcode']) ?></span>
                            <?php endif; ?>
                            <?php if ($inventoryDashboardFocusedStoreName !== ''): ?>
                                <span> — مخزن <?= posmain_inventory_dashboard_h($inventoryDashboardFocusedStoreName) ?></span>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <h1 class="inventory-dashboard-title">لوحة المخزون</h1>
                        <p class="inventory-dashboard-subtitle">دفتر حركات مباشر للقراءة فقط: تتبع الداخل والخارج حسب المخزن والفرع والصنف ونوع الحركة. ملخصات الأرصدة والتقارير التفصيلية تبقى في لوحة المخزون الرئيسية.</p>
                    <?php endif; ?>
                </div>
                <div class="inventory-dashboard-actions">
                    <a class="inventory-dashboard-btn ghost" href="inventory_reports.php"><i class="fas fa-chart-bar"></i> لوحة المخزون</a>
                    <?php if ($inventoryDashboardItemFocus): ?>
                        <a class="inventory-dashboard-btn ghost" href="add_item.php?edit=<?= (int) $inventoryDashboardFilters['item_id'] ?>"><i class="fas fa-box"></i> صفحة الصنف</a>
                        <a class="inventory-dashboard-btn primary" href="inventory_dashboard.php"><i class="fas fa-list"></i> كل الحركات</a>
                    <?php else: ?>
                        <a class="inventory-dashboard-btn primary" href="<?= posmain_inventory_dashboard_h($inventoryDashboardReportUrl) ?>"><i class="fas fa-table"></i> نفس الفلاتر في التقرير</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($inventoryDashboardStats): ?>
            <div class="inventory-dashboard-kpis">
                <?php foreach ($inventoryDashboardStats as $stat): ?>
                    <div class="inventory-dashboard-kpi">
                        <span><?= posmain_inventory_dashboard_h($stat['label']) ?></span>
                        <strong><?= posmain_inventory_dashboard_h($stat['value']) ?></strong>
                        <?php if (($stat['note'] ?? '') !== ''): ?>
                            <span class="inventory-dashboard-kpi-note"><?= posmain_inventory_dashboard_h($stat['note']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="GET" class="inventory-dashboard-filters">
                <?php if ($inventoryDashboardItemFocus): ?>
                    <input type="hidden" name="item_id" value="<?= (int) $inventoryDashboardFilters['item_id'] ?>">
                    <?php if ((int) $inventoryDashboardFilters['store_id'] > 0): ?>
                        <input type="hidden" name="store_id" value="<?= (int) $inventoryDashboardFilters['store_id'] ?>">
                    <?php endif; ?>
                <?php endif; ?>
                <div class="inventory-dashboard-field">
                    <label>من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="<?= posmain_inventory_dashboard_h($inventoryDashboardFilters['date_from']) ?>">
                </div>
                <div class="inventory-dashboard-field">
                    <label>إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="<?= posmain_inventory_dashboard_h($inventoryDashboardFilters['date_to']) ?>">
                </div>
                <?php if (!$inventoryDashboardItemFocus): ?>
                <div class="inventory-dashboard-field">
                    <label>المخزن</label>
                    <select name="store_id" class="form-control">
                        <?php if (!$inventoryDashboardSingleStore): ?>
                            <option value="">كل المخازن</option>
                        <?php endif; ?>
                        <?php foreach ($inventoryDashboardStores as $store): ?>
                            <option value="<?= (int) $store['id'] ?>" <?= (int) $inventoryDashboardFilters['store_id'] === (int) $store['id'] ? 'selected' : '' ?>>
                                <?= posmain_inventory_dashboard_h(($store['aname'] ?? '') !== '' ? $store['aname'] : 'مخزن غير مسمى') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-dashboard-field">
                    <label>الفرع</label>
                    <select name="pos_branch" class="form-control">
                        <option value="">كل الفروع</option>
                        <?php if ($inventoryDashboardFilters['pos_branch'] >= 0 && !posmain_inventory_dashboard_branch_exists($inventoryDashboardBranches, $inventoryDashboardFilters['pos_branch'])): ?>
                            <option value="<?= (int) $inventoryDashboardFilters['pos_branch'] ?>" selected>فرع محدد من الرابط</option>
                        <?php endif; ?>
                        <?php foreach ($inventoryDashboardBranches as $branch): ?>
                            <option value="<?= (int) $branch['pos_branch'] ?>" <?= (int) $inventoryDashboardFilters['pos_branch'] === (int) $branch['pos_branch'] ? 'selected' : '' ?>>
                                <?= posmain_inventory_dashboard_h(($branch['branch_name'] ?? '') !== '' ? (string) $branch['branch_name'] : 'فرع غير مسمى') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-dashboard-field">
                    <label>الصنف</label>
                    <select name="item_id" class="form-control">
                        <option value="">كل الأصناف</option>
                        <?php if ($inventoryDashboardFilters['item_id'] > 0 && !posmain_inventory_dashboard_option_exists($inventoryDashboardItems, $inventoryDashboardFilters['item_id'])): ?>
                            <option value="<?= (int) $inventoryDashboardFilters['item_id'] ?>" selected>صنف محدد من الرابط</option>
                        <?php endif; ?>
                        <?php foreach ($inventoryDashboardItems as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" <?= (int) $inventoryDashboardFilters['item_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                                <?= posmain_inventory_dashboard_h(trim(($item['iname'] ?? '') . (($item['barcode'] ?? '') !== '' ? ' - ' . $item['barcode'] : ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-dashboard-field wide">
                    <label>بحث</label>
                    <input type="search" name="q" class="form-control" placeholder="اسم الصنف أو الباركود أو رقم الصنف" value="<?= posmain_inventory_dashboard_h($inventoryDashboardFilters['q']) ?>">
                </div>
                <?php elseif ((int) $inventoryDashboardFilters['store_id'] <= 0): ?>
                <div class="inventory-dashboard-field">
                    <label>المخزن</label>
                    <select name="store_id" class="form-control">
                        <?php if (!$inventoryDashboardSingleStore): ?>
                            <option value="">كل المخازن</option>
                        <?php endif; ?>
                        <?php foreach ($inventoryDashboardStores as $store): ?>
                            <option value="<?= (int) $store['id'] ?>" <?= (int) $inventoryDashboardFilters['store_id'] === (int) $store['id'] ? 'selected' : '' ?>>
                                <?= posmain_inventory_dashboard_h(($store['aname'] ?? '') !== '' ? $store['aname'] : 'مخزن غير مسمى') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="inventory-dashboard-field">
                    <label>نوع الحركة</label>
                    <select name="movement_type" class="form-control">
                        <option value="">كل الحركات</option>
                        <?php if ($inventoryDashboardFilters['movement_type'] !== '' && !isset($inventoryDashboardMovementTypes[$inventoryDashboardFilters['movement_type']])): ?>
                            <option value="<?= posmain_inventory_dashboard_h($inventoryDashboardFilters['movement_type']) ?>" selected>نوع حركة محدد من الرابط</option>
                        <?php endif; ?>
                        <?php foreach ($inventoryDashboardMovementTypes as $movementType => $movementLabel): ?>
                            <option value="<?= posmain_inventory_dashboard_h($movementType) ?>" <?= $inventoryDashboardFilters['movement_type'] === $movementType ? 'selected' : '' ?>>
                                <?= posmain_inventory_dashboard_h($movementLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-dashboard-field">
                    <label>عدد الصفوف</label>
                    <input type="number" name="limit" class="form-control" min="1" max="500" value="<?= (int) $inventoryDashboardFilters['limit'] ?>">
                </div>
                <div class="inventory-dashboard-field">
                    <label>&nbsp;</label>
                    <div class="inventory-dashboard-filter-actions">
                        <button type="submit" class="inventory-dashboard-btn primary"><i class="fas fa-filter"></i> عرض الحركات</button>
                    </div>
                </div>
            </form>

            <div class="inventory-dashboard-panel">
                <div class="inventory-dashboard-panel-header">
                    <h2 class="inventory-dashboard-panel-title">سجل الحركات</h2>
                    <span class="inventory-dashboard-chip"><i class="fas fa-list"></i> <?= count($inventoryDashboardMovements) ?> حركة</span>
                    <?php if (!$inventoryDashboardCanViewCost): ?>
                        <span class="inventory-dashboard-chip" style="background:#fff7ed;color:#9a3412"><i class="fas fa-eye-slash"></i> أعمدة التكلفة مخفية</span>
                    <?php endif; ?>
                </div>
                <?= posmain_inventory_dashboard_movements_table($inventoryDashboardMovements, $inventoryDashboardCanViewCost, $inventoryDashboardItemFocus) ?>
            </div>
            <p class="inventory-dashboard-note">
                <?php if ($inventoryDashboardItemFocus): ?>
                    عرض حركات هذا الصنف فقط. الإجماليات محسوبة من الصفوف المعروضة حتى الحد المحدد.
                <?php else: ?>
                    هذه الصفحة للقراءة فقط ولا تعدل المخزون. الإجماليات أعلاه محسوبة من الصفوف المعروضة حتى الحد المحدد. للتصدير أو تقارير أخرى استخدم <a href="inventory_reports.php">لوحة المخزون</a>.
                <?php endif; ?>
            </p>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_inventory_dashboard_can_view(mysqli $conn): bool
{
    return auth_guard_has_permission('reports.view', $conn)
        || auth_guard_has_permission('inventory.edit', $conn)
        || auth_guard_has_permission('accounting.view', $conn);
}

function posmain_inventory_dashboard_can_view_cost(mysqli $conn): bool
{
    return auth_guard_has_permission('accounting.view', $conn)
        || auth_guard_has_permission('settings.manage', $conn);
}

function posmain_inventory_dashboard_filters(array $input, ?mysqli $conn = null): array
{
    $storeId = isset($input['store_id']) && (int) $input['store_id'] > 0 ? (int) $input['store_id'] : 0;
    if ($conn instanceof mysqli) {
        $storeId = posmain_apply_read_store_filter($conn, $storeId);
    }

    return [
        'date_from' => posmain_inventory_dashboard_date($input['date_from'] ?? ''),
        'date_to' => posmain_inventory_dashboard_date($input['date_to'] ?? ''),
        'pos_tenant' => isset($input['pos_tenant']) && (int) $input['pos_tenant'] >= 0 ? (int) $input['pos_tenant'] : -1,
        'pos_branch' => isset($input['pos_branch']) && $input['pos_branch'] !== '' ? max(0, (int) $input['pos_branch']) : -1,
        'store_id' => $storeId,
        'item_id' => isset($input['item_id']) && (int) $input['item_id'] > 0 ? (int) $input['item_id'] : 0,
        'q' => posmain_inventory_dashboard_search_text($input['q'] ?? ''),
        'movement_type' => preg_replace('/[^a-zA-Z0-9_:-]/', '', strtolower(trim((string) ($input['movement_type'] ?? '')))),
        'limit' => isset($input['limit']) ? max(1, min(500, (int) $input['limit'])) : 100,
    ];
}

function posmain_inventory_dashboard_date($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date instanceof DateTime ? $date->format('Y-m-d') : '';
}

function posmain_inventory_dashboard_search_text($value): string
{
    $value = trim((string) $value);

    return mb_substr($value, 0, 120);
}

function posmain_inventory_dashboard_reports_url(array $filters): string
{
    $params = ['report' => 'movement_history'];
    foreach (['date_from', 'date_to', 'pos_branch', 'store_id', 'item_id', 'q', 'movement_type', 'limit'] as $key) {
        $value = $filters[$key] ?? '';
        if ($key === 'pos_branch' && (int) $value < 0) {
            continue;
        }
        if (in_array($key, ['store_id', 'item_id'], true) && (int) $value <= 0) {
            continue;
        }
        if ($value === '' || $value === 0) {
            continue;
        }
        $params[$key] = $value;
    }

    return 'inventory_reports.php?' . http_build_query($params);
}

function posmain_inventory_dashboard_focused_item(mysqli $conn, int $itemId): ?array
{
    if ($itemId <= 0) {
        return null;
    }

    $rows = posmain_inventory_dashboard_rows($conn, '
        SELECT id, iname, barcode
        FROM myitems
        WHERE id = ' . $itemId . '
        LIMIT 1
    ');

    return $rows[0] ?? null;
}

function posmain_inventory_dashboard_store_name(array $stores, int $storeId): string
{
    if ($storeId <= 0) {
        return '';
    }

    foreach ($stores as $store) {
        if ((int) ($store['id'] ?? 0) === $storeId) {
            return trim((string) ($store['aname'] ?? '')) !== '' ? (string) $store['aname'] : 'مخزن غير مسمى';
        }
    }

    return '';
}

function posmain_inventory_dashboard_movement_stats(array $rows, array $dashboard, bool $canViewCost, bool $itemFocus = false): array
{
    $qtyIn = 0.0;
    $qtyOut = 0.0;
    $totalCost = 0.0;
    foreach ($rows as $row) {
        $qtyIn += (float) ($row['qty_in'] ?? 0);
        $qtyOut += (float) ($row['qty_out'] ?? 0);
        $totalCost += (float) ($row['total_cost'] ?? 0);
    }

    $stats = [];
    if (!$itemFocus) {
        $stats[] = ['label' => 'حركات اليوم', 'value' => number_format((float) ($dashboard['movements_today'] ?? 0), 0), 'note' => 'كل الفروع والمخازن'];
    }
    $stats[] = ['label' => 'حركات معروضة', 'value' => number_format(count($rows), 0), 'note' => $itemFocus ? 'لهذا الصنف' : 'حسب الفلاتر والحد'];
    $stats[] = ['label' => 'إجمالي الداخل', 'value' => posmain_inventory_dashboard_qty($qtyIn), 'note' => 'في الصفوف المعروضة'];
    $stats[] = ['label' => 'إجمالي الخارج', 'value' => posmain_inventory_dashboard_qty($qtyOut), 'note' => 'في الصفوف المعروضة'];
    if ($canViewCost) {
        $stats[] = ['label' => 'إجمالي التكلفة', 'value' => posmain_inventory_dashboard_money($totalCost), 'note' => 'في الصفوف المعروضة'];
    }

    return $stats;
}

function posmain_inventory_dashboard_stores(mysqli $conn): array
{
    if (function_exists('posmain_single_store_mode_enabled') && posmain_single_store_mode_enabled()) {
        return posmain_inventory_store_select_options($conn);
    }

    return posmain_inventory_dashboard_rows($conn, "
        SELECT id, aname
        FROM acc_head
        WHERE COALESCE(isdeleted, 0) = 0
          AND COALESCE(is_stock, 0) = 1
        ORDER BY aname
        LIMIT 150
    ");
}

function posmain_inventory_dashboard_branches(mysqli $conn): array
{
    $branches = [];
    $addBranch = static function ($posBranch, string $branchName = '') use (&$branches): void {
        $branchId = max(0, (int) $posBranch);
        $key = (string) $branchId;
        $name = trim($branchName);
        $name = $name !== '' ? $name : 'فرع غير مسمى';
        if (isset($branches[$key]) && trim((string) ($branches[$key]['branch_name'] ?? '')) !== '') {
            return;
        }

        $branches[$key] = [
            'pos_branch' => $branchId,
            'branch_name' => $name,
        ];
    };

    $branchConfig = function_exists('posmain_app_config') && is_array(posmain_app_config()['branch'] ?? null)
        ? posmain_app_config()['branch']
        : [];
    if (array_key_exists('pos_branch', $branchConfig) && $branchConfig['pos_branch'] !== null) {
        $addBranch(
            $branchConfig['pos_branch'],
            trim((string) ($branchConfig['name'] ?? '')) ?: 'الفرع الحالي'
        );
    }

    if (posmain_inventory_dashboard_table_exists($conn, 'cloud_branches')) {
        foreach (posmain_inventory_dashboard_rows($conn, "
            SELECT COALESCE(pos_branch, 0) AS pos_branch,
                   COALESCE(NULLIF(branch_name, ''), 'فرع غير مسمى') AS branch_name
            FROM cloud_branches
            WHERE status = 'active'
              AND pos_branch IS NOT NULL
            ORDER BY branch_name, pos_branch
            LIMIT 150
        ") as $branch) {
            $addBranch($branch['pos_branch'] ?? 0, (string) ($branch['branch_name'] ?? ''));
        }
    }

    foreach (['inventory_item_balances', 'inventory_movements'] as $table) {
        if (!posmain_inventory_dashboard_table_exists($conn, $table)) {
            continue;
        }
        foreach (posmain_inventory_dashboard_rows($conn, "
            SELECT DISTINCT COALESCE(pos_branch, 0) AS pos_branch
            FROM {$table}
            ORDER BY pos_branch
            LIMIT 150
        ") as $branch) {
            $addBranch($branch['pos_branch'] ?? 0);
        }
    }

    return array_values($branches);
}

function posmain_inventory_dashboard_items(mysqli $conn): array
{
    return posmain_inventory_dashboard_rows($conn, "
        SELECT id, iname, barcode
        FROM myitems
        WHERE COALESCE(isdeleted, 0) = 0
          AND COALESCE(track_stock, 1) = 1
          AND COALESCE(item_type, 'sellable') <> 'service'
        ORDER BY iname, id
        LIMIT 700
    ");
}

function posmain_inventory_dashboard_rows(mysqli $conn, string $sql): array
{
    $rows = [];
    try {
        $result = $conn->query($sql);
        if (!$result) {
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $rows;
}

function posmain_inventory_dashboard_table_exists(mysqli $conn, string $table): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) ($row['table_count'] ?? 0) > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function posmain_inventory_dashboard_branch_exists(array $rows, int $posBranch): bool
{
    foreach ($rows as $row) {
        if ((int) ($row['pos_branch'] ?? -1) === $posBranch) {
            return true;
        }
    }

    return false;
}

function posmain_inventory_dashboard_option_exists(array $rows, int $id): bool
{
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return true;
        }
    }

    return false;
}

function posmain_inventory_dashboard_movements_table(array $rows, bool $canViewCost, bool $hideItemColumns = false): string
{
    if (!$rows) {
        return '<div class="inventory-dashboard-empty">لا توجد حركات مطابقة للفلاتر الحالية.</div>';
    }

    $html = '<div class="table-responsive"><table class="table table-bordered table-hover inventory-dashboard-table"><thead><tr>';
    $html .= '<th>التاريخ</th><th>المخزن</th>';
    if (!$hideItemColumns) {
        $html .= '<th>الصنف</th><th>الباركود</th>';
    }
    $html .= '<th>نوع الحركة</th><th>المصدر</th><th>داخل</th><th>خارج</th>';
    if ($canViewCost) {
        $html .= '<th>تكلفة الوحدة</th><th>إجمالي التكلفة</th><th>القيد</th>';
    }
    $html .= '<th></th></tr></thead><tbody>';

    foreach ($rows as $row) {
        $qtyIn = (float) ($row['qty_in'] ?? 0);
        $qtyOut = (float) ($row['qty_out'] ?? 0);
        $html .= '<tr>';
        $html .= '<td>' . posmain_inventory_dashboard_h($row['created_at'] ?? '') . '</td>';
        $html .= '<td>' . posmain_inventory_dashboard_h(($row['store_name'] ?? '') !== '' ? $row['store_name'] : 'مخزن غير مسمى') . '</td>';
        if (!$hideItemColumns) {
            $html .= '<td>' . posmain_inventory_dashboard_item_link((int) ($row['item_id'] ?? 0), ($row['item_name'] ?? '') !== '' ? $row['item_name'] : 'صنف غير مسمى') . '</td>';
            $html .= '<td>' . posmain_inventory_dashboard_item_link((int) ($row['item_id'] ?? 0), (string) ($row['barcode'] ?? '')) . '</td>';
        }
        $html .= '<td>' . posmain_inventory_dashboard_h(posmain_inventory_dashboard_movement_label((string) ($row['movement_type'] ?? ''))) . '</td>';
        $html .= '<td>' . posmain_inventory_dashboard_h(posmain_inventory_dashboard_source_type_label((string) ($row['source_type'] ?? ''))) . '</td>';
        $html .= '<td class="movement-in">' . posmain_inventory_dashboard_decimal($qtyIn) . '</td>';
        $html .= '<td class="movement-out">' . posmain_inventory_dashboard_decimal($qtyOut) . '</td>';
        if ($canViewCost) {
            $html .= '<td>' . posmain_inventory_dashboard_money($row['unit_cost'] ?? 0) . '</td>';
            $html .= '<td>' . posmain_inventory_dashboard_money($row['total_cost'] ?? 0) . '</td>';
            $html .= '<td>' . posmain_inventory_dashboard_h($row['accounting_journal_id'] ?? '') . '</td>';
        }
        $html .= '<td>' . posmain_inventory_dashboard_link($row['drilldown_url'] ?? '') . '</td>';
        $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
}

function posmain_inventory_dashboard_item_link(int $itemId, string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    if ($itemId <= 0) {
        return posmain_inventory_dashboard_h($label);
    }

    return '<a class="inventory-dashboard-item-link" href="' . posmain_inventory_dashboard_h('add_item.php?edit=' . $itemId) . '">' . posmain_inventory_dashboard_h($label) . '</a>';
}

function posmain_inventory_dashboard_link(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    return '<a class="btn btn-sm btn-outline-primary" href="' . posmain_inventory_dashboard_h($url) . '"><i class="fas fa-arrow-left"></i></a>';
}

function posmain_inventory_dashboard_movement_type_labels(): array
{
    return [
        'purchase' => 'شراء',
        'purchase_return' => 'مرتجع شراء',
        'sale_direct' => 'بيع مباشر',
        'recipe_consumption' => 'استهلاك وصفة',
        'production_input' => 'إنتاج - مواد',
        'production_output' => 'إنتاج - ناتج',
        'waste' => 'هالك',
        'adjustment' => 'تسوية مخزون',
        'transfer_in' => 'تحويل وارد',
        'transfer_out' => 'تحويل صادر',
        'reservation' => 'حجز',
        'reservation_release' => 'إلغاء حجز',
        'refund_reversal' => 'مرتجع بيع',
        'sync_replay' => 'إعادة مزامنة',
        'opening_balance' => 'رصيد افتتاحي',
    ];
}

function posmain_inventory_dashboard_movement_label(string $movementType): string
{
    $labels = posmain_inventory_dashboard_movement_type_labels();

    return $labels[$movementType] ?? $movementType;
}

function posmain_inventory_dashboard_source_type_label(string $sourceType): string
{
    $labels = [
        'purchase_receipt' => 'استلام شراء',
        'sales_invoice' => 'فاتورة بيع',
        'inventory_count' => 'جرد مخزون',
        'inventory_transfer' => 'تحويل مخزون',
        'inventory_adjustment' => 'تسوية مخزون',
        'production_batch' => 'دفعة إنتاج',
        'recipe' => 'وصفة',
        'manual' => 'يدوي',
        'sync' => 'مزامنة',
    ];

    return $labels[$sourceType] ?? $sourceType;
}

function posmain_inventory_dashboard_decimal($value): string
{
    return posmain_inventory_dashboard_h(number_format((float) $value, 3, '.', ''));
}

function posmain_inventory_dashboard_qty($value): string
{
    return number_format((float) $value, 3, '.', '');
}

function posmain_inventory_dashboard_money($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function posmain_inventory_dashboard_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
