<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/classes/Inventory/InventoryReportsService.php';

require_login();
if (!posmain_inventory_dashboard_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$inventoryDashboardCanViewCost = posmain_inventory_dashboard_can_view_cost($conn);
$inventoryDashboardFilters = posmain_inventory_dashboard_filters($_GET);
$inventoryDashboardService = new InventoryReportsService();
$inventoryDashboardSummary = $inventoryDashboardService->dashboard($conn, $inventoryDashboardFilters);
$inventoryDashboardDetails = $inventoryDashboardService->dashboardDetails($conn, array_merge($inventoryDashboardFilters, ['limit' => 8]));
$inventoryDashboardStores = posmain_inventory_dashboard_stores($conn);
$inventoryDashboardBranches = posmain_inventory_dashboard_branches($conn);
$inventoryDashboardItems = posmain_inventory_dashboard_items($conn);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
    .inventory-dashboard-page{direction:rtl;background:#f5f7fb;min-height:calc(100vh - 57px);color:#102033}
    .inventory-dashboard-wrap{max-width:1480px;margin:0 auto;padding:18px}
    .inventory-dashboard-hero{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center;padding:20px;background:#102033;color:#fff;border-radius:8px;box-shadow:0 14px 32px rgba(16,32,51,.16)}
    .inventory-dashboard-title{margin:0;font-size:24px;font-weight:800;letter-spacing:0}
    .inventory-dashboard-subtitle{margin:7px 0 0;color:#c8d4df;font-size:13px;max-width:760px}
    .inventory-dashboard-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .inventory-dashboard-btn{min-height:40px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#102033;font-weight:800;padding:0 13px;display:inline-flex;align-items:center;gap:8px;text-decoration:none}
    .inventory-dashboard-btn:hover{color:#0f766e;text-decoration:none}
    .inventory-dashboard-btn.primary{background:#0f766e;border-color:#0f766e;color:#fff}
    .inventory-dashboard-filters{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin-top:14px;padding:14px;background:#fff;border:1px solid #dbe4ee;border-radius:8px;box-shadow:0 8px 18px rgba(15,23,42,.06)}
    .inventory-dashboard-field label{display:block;font-size:12px;color:#526477;font-weight:800;margin-bottom:6px}
    .inventory-dashboard-field .form-control{border-radius:8px;border-color:#ccd7e3;min-height:38px}
    .inventory-dashboard-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:14px}
    .inventory-dashboard-kpi{background:#fff;border:1px solid #dbe4ee;border-radius:8px;padding:14px;min-height:86px;box-shadow:0 8px 18px rgba(15,23,42,.06)}
    .inventory-dashboard-kpi span{display:block;color:#64748b;font-size:12px;font-weight:800}
    .inventory-dashboard-kpi strong{display:block;margin-top:8px;font-size:22px;line-height:1.15;color:#102033}
    .inventory-dashboard-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
    .inventory-dashboard-panel{background:#fff;border:1px solid #dbe4ee;border-radius:8px;box-shadow:0 8px 18px rgba(15,23,42,.06);min-width:0}
    .inventory-dashboard-panel.wide{grid-column:1 / -1}
    .inventory-dashboard-panel-header{padding:14px 16px;border-bottom:1px solid #e5edf5;display:flex;align-items:center;justify-content:space-between;gap:10px}
    .inventory-dashboard-panel-title{margin:0;font-size:16px;font-weight:800;color:#102033}
    .inventory-dashboard-chip{display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:6px 9px;background:#ecfdf5;color:#047857;font-weight:800;font-size:12px}
    .inventory-dashboard-table{margin:0;min-width:760px}
    .inventory-dashboard-table th{background:#edf3f8;color:#334155;font-size:12px;border-color:#dbe4ee;white-space:nowrap}
    .inventory-dashboard-table td{border-color:#e7edf3;vertical-align:middle;font-size:13px}
    .inventory-dashboard-empty{padding:26px;text-align:center;color:#64748b}
    .inventory-dashboard-note{margin-top:12px;color:#64748b;font-size:12px}
    @media(max-width:1200px){.inventory-dashboard-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.inventory-dashboard-grid{grid-template-columns:1fr}.inventory-dashboard-filters{grid-template-columns:repeat(3,minmax(130px,1fr))}}
    @media(max-width:768px){.inventory-dashboard-hero{grid-template-columns:1fr}.inventory-dashboard-actions{justify-content:flex-start}.inventory-dashboard-kpis{grid-template-columns:1fr 1fr}.inventory-dashboard-filters{grid-template-columns:1fr 1fr}}
</style>

<div class="content-wrapper inventory-dashboard-page">
    <section class="content-header">
        <div class="inventory-dashboard-wrap">
            <div class="inventory-dashboard-hero">
                <div>
                    <h1 class="inventory-dashboard-title">لوحة المخزون</h1>
                    <p class="inventory-dashboard-subtitle">ملخص يومي هادئ لمدير الفرع: قيمة المخزون، النقص، السالب، العمليات المفتوحة، آخر الحركات، وتأثير المخزون على توفر قائمة البيع.</p>
                </div>
                <div class="inventory-dashboard-actions">
                    <a class="inventory-dashboard-btn primary" href="inventory_reports.php"><i class="fas fa-chart-bar"></i> التقارير</a>
                    <a class="inventory-dashboard-btn" href="inventory_purchasing.php"><i class="fas fa-dolly-flatbed"></i> الاستلام</a>
                    <a class="inventory-dashboard-btn" href="inventory_counts.php"><i class="fas fa-clipboard-check"></i> الجرد</a>
                    <a class="inventory-dashboard-btn" href="inventory_adjustments.php"><i class="fas fa-sliders-h"></i> التسويات</a>
                </div>
            </div>

            <form method="GET" class="inventory-dashboard-filters">
                <div class="inventory-dashboard-field">
                    <label>المخزن</label>
                    <select name="store_id" class="form-control">
                        <option value="">كل المخازن</option>
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
                <div class="inventory-dashboard-field">
                    <label>عدد الصفوف</label>
                    <input type="number" name="limit" class="form-control" min="1" max="12" value="<?= (int) $inventoryDashboardFilters['limit'] ?>">
                </div>
                <div class="inventory-dashboard-field">
                    <label>&nbsp;</label>
                    <button type="submit" class="inventory-dashboard-btn primary"><i class="fas fa-filter"></i> تحديث اللوحة</button>
                </div>
            </form>

            <div class="inventory-dashboard-kpis">
                <?php foreach (posmain_inventory_dashboard_cards($inventoryDashboardSummary, $inventoryDashboardCanViewCost) as $card): ?>
                    <div class="inventory-dashboard-kpi">
                        <span><?= posmain_inventory_dashboard_h($card['label']) ?></span>
                        <strong><?= posmain_inventory_dashboard_h($card['value']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="inventory-dashboard-grid">
                <div class="inventory-dashboard-panel">
                    <div class="inventory-dashboard-panel-header">
                        <h2 class="inventory-dashboard-panel-title">يحتاج انتباه</h2>
                        <span class="inventory-dashboard-chip"><?= count($inventoryDashboardDetails['needs_attention'] ?? []) ?> صنف</span>
                    </div>
                    <?= posmain_inventory_dashboard_low_stock_table($inventoryDashboardDetails['needs_attention'] ?? []) ?>
                </div>

                <div class="inventory-dashboard-panel">
                    <div class="inventory-dashboard-panel-header">
                        <h2 class="inventory-dashboard-panel-title">اقتراحات الشراء</h2>
                        <a class="inventory-dashboard-chip" href="inventory_reports.php?report=replenishment_suggestions">فتح التقرير</a>
                    </div>
                    <?= posmain_inventory_dashboard_replenishment_table($inventoryDashboardDetails['replenishment_suggestions'] ?? []) ?>
                </div>

                <div class="inventory-dashboard-panel">
                    <div class="inventory-dashboard-panel-header">
                        <h2 class="inventory-dashboard-panel-title">آخر حركات المخزون</h2>
                        <a class="inventory-dashboard-chip" href="inventory_reports.php?report=movement_history">الحركات</a>
                    </div>
                    <?= posmain_inventory_dashboard_movements_table($inventoryDashboardDetails['recent_movements'] ?? []) ?>
                </div>

                <div class="inventory-dashboard-panel">
                    <div class="inventory-dashboard-panel-header">
                        <h2 class="inventory-dashboard-panel-title">تأثير توفر القائمة</h2>
                        <span class="inventory-dashboard-chip"><?= count($inventoryDashboardDetails['menu_availability_impact'] ?? []) ?> عنصر</span>
                    </div>
                    <?= posmain_inventory_dashboard_availability_table($inventoryDashboardDetails['menu_availability_impact'] ?? []) ?>
                </div>
            </div>
            <p class="inventory-dashboard-note">لوحة المخزون للقراءة فقط. إجراءات الاستلام والجرد والتحويل والهالك تتم من صفحاتها المخصصة وتظل محكومة بخدمات المخزون.</p>
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

function posmain_inventory_dashboard_filters(array $input): array
{
    return [
        'pos_tenant' => isset($input['pos_tenant']) && (int) $input['pos_tenant'] >= 0 ? (int) $input['pos_tenant'] : -1,
        'pos_branch' => isset($input['pos_branch']) && (int) $input['pos_branch'] >= 0 ? (int) $input['pos_branch'] : -1,
        'store_id' => isset($input['store_id']) && (int) $input['store_id'] > 0 ? (int) $input['store_id'] : 0,
        'item_id' => isset($input['item_id']) && (int) $input['item_id'] > 0 ? (int) $input['item_id'] : 0,
        'limit' => isset($input['limit']) ? max(1, min(12, (int) $input['limit'])) : 8,
    ];
}

function posmain_inventory_dashboard_stores(mysqli $conn): array
{
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

function posmain_inventory_dashboard_cards(array $dashboard, bool $canViewCost): array
{
    $cards = [
        ['label' => 'أصناف لها رصيد', 'value' => number_format((float) ($dashboard['item_count'] ?? 0), 0)],
        ['label' => 'أصناف منخفضة', 'value' => number_format((float) ($dashboard['low_stock_count'] ?? 0), 0)],
        ['label' => 'أصناف سالبة', 'value' => number_format((float) ($dashboard['negative_count'] ?? 0), 0)],
        ['label' => 'حركات اليوم', 'value' => number_format((float) ($dashboard['movements_today'] ?? 0), 0)],
        ['label' => 'جرد مفتوح', 'value' => number_format((float) ($dashboard['open_counts'] ?? 0), 0)],
        ['label' => 'تحويلات مفتوحة', 'value' => number_format((float) ($dashboard['open_transfers'] ?? 0), 0)],
        ['label' => 'أوامر شراء مفتوحة', 'value' => number_format((float) ($dashboard['open_purchase_orders'] ?? 0), 0)],
    ];
    if ($canViewCost) {
        array_unshift($cards, ['label' => 'قيمة المخزون', 'value' => posmain_inventory_dashboard_money($dashboard['stock_value'] ?? 0)]);
    }

    return array_slice($cards, 0, 8);
}

function posmain_inventory_dashboard_low_stock_table(array $rows): string
{
    if (!$rows) {
        return '<div class="inventory-dashboard-empty">لا توجد أصناف منخفضة حسب الفلاتر الحالية.</div>';
    }

    $html = '<div class="table-responsive"><table class="table table-bordered inventory-dashboard-table"><thead><tr><th>الصنف</th><th>المخزن</th><th>المتاح</th><th>نقطة الطلب</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . posmain_inventory_dashboard_h(($row['item_name'] ?? '') !== '' ? $row['item_name'] : 'صنف غير مسمى') . '</td><td>' . posmain_inventory_dashboard_h(($row['store_name'] ?? '') !== '' ? $row['store_name'] : 'مخزن غير مسمى') . '</td><td>' . posmain_inventory_dashboard_decimal($row['qty_available'] ?? 0) . '</td><td>' . posmain_inventory_dashboard_decimal($row['reorder_level'] ?? $row['minimum_level'] ?? 0) . '</td><td>' . posmain_inventory_dashboard_link($row['drilldown_url'] ?? '') . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function posmain_inventory_dashboard_replenishment_table(array $rows): string
{
    if (!$rows) {
        return '<div class="inventory-dashboard-empty">لا توجد اقتراحات شراء الآن.</div>';
    }

    $html = '<div class="table-responsive"><table class="table table-bordered inventory-dashboard-table"><thead><tr><th>الصنف</th><th>المورد</th><th>المقترح</th><th>وحدة الشراء</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $supplier = trim((string) ($row['default_supplier_name'] ?? '')) !== '' ? $row['default_supplier_name'] : 'بدون مورد افتراضي';
        $html .= '<tr><td>' . posmain_inventory_dashboard_h(($row['item_name'] ?? '') !== '' ? $row['item_name'] : 'صنف غير مسمى') . '</td><td>' . posmain_inventory_dashboard_h($supplier) . '</td><td>' . posmain_inventory_dashboard_decimal($row['suggested_purchase_qty'] ?? $row['suggested_qty'] ?? 0) . '</td><td>' . posmain_inventory_dashboard_h(($row['preferred_purchase_unit_name'] ?? '') !== '' ? $row['preferred_purchase_unit_name'] : 'الوحدة الأساسية') . '</td><td>' . posmain_inventory_dashboard_link($row['drilldown_url'] ?? '') . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function posmain_inventory_dashboard_movements_table(array $rows): string
{
    if (!$rows) {
        return '<div class="inventory-dashboard-empty">لا توجد حركات حديثة.</div>';
    }

    $html = '<div class="table-responsive"><table class="table table-bordered inventory-dashboard-table"><thead><tr><th>الوقت</th><th>الصنف</th><th>النوع</th><th>داخل</th><th>خارج</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . posmain_inventory_dashboard_h($row['created_at'] ?? '') . '</td><td>' . posmain_inventory_dashboard_h(($row['item_name'] ?? '') !== '' ? $row['item_name'] : 'صنف غير مسمى') . '</td><td>' . posmain_inventory_dashboard_h(posmain_inventory_dashboard_movement_label((string) ($row['movement_type'] ?? ''))) . '</td><td>' . posmain_inventory_dashboard_decimal($row['qty_in'] ?? 0) . '</td><td>' . posmain_inventory_dashboard_decimal($row['qty_out'] ?? 0) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function posmain_inventory_dashboard_availability_table(array $rows): string
{
    if (!$rows) {
        return '<div class="inventory-dashboard-empty">لا توجد عناصر قائمة متأثرة بالمخزون الآن.</div>';
    }

    $html = '<div class="table-responsive"><table class="table table-bordered inventory-dashboard-table"><thead><tr><th>عنصر القائمة</th><th>المكوّن المحدد</th><th>المتاح</th><th>القناة</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $orderChannel = posmain_inventory_dashboard_order_type_label((string) ($row['order_type'] ?? '')) . ' / ' . posmain_inventory_dashboard_channel_label((string) ($row['channel'] ?? ''));
        $html .= '<tr><td>' . posmain_inventory_dashboard_h(($row['sellable_item_name'] ?? '') !== '' ? $row['sellable_item_name'] : 'عنصر غير مسمى') . '</td><td>' . posmain_inventory_dashboard_h(($row['blocking_item_name'] ?? '') !== '' ? $row['blocking_item_name'] : ($row['unavailable_reason'] ?? '')) . '</td><td>' . posmain_inventory_dashboard_decimal($row['effective_available_qty'] ?? 0) . '</td><td>' . posmain_inventory_dashboard_h($orderChannel) . '</td><td>' . posmain_inventory_dashboard_link($row['drilldown_url'] ?? '') . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function posmain_inventory_dashboard_link(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    return '<a class="btn btn-sm btn-outline-primary" href="' . posmain_inventory_dashboard_h($url) . '"><i class="fas fa-arrow-left"></i></a>';
}

function posmain_inventory_dashboard_movement_label(string $movementType): string
{
    $labels = [
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

    return $labels[$movementType] ?? $movementType;
}

function posmain_inventory_dashboard_order_type_label(string $orderType): string
{
    $labels = [
        'table_order' => 'طاولة',
        'dine_in' => 'محلي',
        'takeaway' => 'سفري',
        'delivery' => 'توصيل',
    ];

    return $labels[$orderType] ?? $orderType;
}

function posmain_inventory_dashboard_channel_label(string $channel): string
{
    $labels = [
        'pos' => 'نقطة البيع',
        'pos_counter' => 'كاشير',
        'moova' => 'موفا',
        'online' => 'أونلاين',
    ];

    return $labels[$channel] ?? $channel;
}

function posmain_inventory_dashboard_decimal($value): string
{
    return posmain_inventory_dashboard_h(number_format((float) $value, 3, '.', ''));
}

function posmain_inventory_dashboard_money($value): string
{
    return posmain_inventory_dashboard_h(number_format((float) $value, 2, '.', ''));
}

function posmain_inventory_dashboard_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
