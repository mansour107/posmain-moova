<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csv_export.php';
require_once __DIR__ . '/classes/Inventory/InventoryReportsService.php';

require_login();
if (!posmain_inventory_reports_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$inventoryReportsCanViewCost = posmain_inventory_reports_can_view_cost($conn);
$inventoryReportsTypes = posmain_inventory_report_types($inventoryReportsCanViewCost);
$inventoryReportsReport = posmain_inventory_report_key($_GET['report'] ?? '', $inventoryReportsTypes);
$inventoryReportsFilters = posmain_inventory_report_filters($_GET);
$inventoryReportsBranches = posmain_inventory_report_branches($conn);
$inventoryReportsStores = posmain_inventory_report_stores($conn);
$inventoryReportsSuppliers = posmain_inventory_report_suppliers($conn);
$inventoryReportsItems = posmain_inventory_report_items($conn);
$inventoryReportsCategories = posmain_inventory_report_categories($conn);
$inventoryReportsMovementTypes = posmain_inventory_report_movement_type_labels();
$inventoryReportsService = new InventoryReportsService();
$inventoryReportsDashboard = $inventoryReportsService->dashboard($conn, $inventoryReportsFilters);
$inventoryReportsRows = $inventoryReportsService->report($conn, $inventoryReportsReport, $inventoryReportsFilters);
$inventoryReportsColumns = posmain_inventory_report_columns($inventoryReportsReport, $inventoryReportsCanViewCost);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    posmain_inventory_reports_export_csv($inventoryReportsReport, $inventoryReportsColumns, $inventoryReportsRows);
    exit;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
    .inventory-report-page{direction:rtl;background:#f4f7fb;min-height:calc(100vh - 57px);color:#102033}
    .inventory-report-wrap{max-width:1480px;margin:0 auto;padding:18px}
    .inventory-report-hero{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 20px;background:#102033;color:#fff;border-radius:8px;box-shadow:0 14px 30px rgba(16,32,51,.16)}
    .inventory-report-title{margin:0;font-size:24px;font-weight:800;letter-spacing:0}
    .inventory-report-subtitle{margin:6px 0 0;color:#c9d7e4;font-size:13px}
    .inventory-report-actions{display:flex;gap:8px;flex-wrap:wrap}
    .inventory-report-btn{min-height:40px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#102033;font-weight:800;padding:0 14px;display:inline-flex;align-items:center;gap:8px}
    .inventory-report-btn.primary{background:#0f766e;border-color:#0f766e;color:#fff}
    .inventory-report-btn.dark{background:#15263a;border-color:#31445b;color:#fff}
    .inventory-report-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:14px}
    .inventory-report-kpi{background:#fff;border:1px solid #dbe4ee;border-radius:8px;padding:13px 14px;min-height:84px;box-shadow:0 8px 18px rgba(15,23,42,.06)}
    .inventory-report-kpi span{display:block;color:#64748b;font-size:12px;font-weight:700}
    .inventory-report-kpi strong{display:block;margin-top:8px;font-size:22px;color:#102033;line-height:1.1}
    .inventory-report-panel{background:#fff;border:1px solid #dbe4ee;border-radius:8px;box-shadow:0 8px 18px rgba(15,23,42,.06);margin-top:14px}
    .inventory-report-panel-header{padding:14px 16px;border-bottom:1px solid #e5edf5;display:flex;align-items:center;justify-content:space-between;gap:10px}
    .inventory-report-panel-title{margin:0;font-size:16px;font-weight:800}
    .inventory-report-filters{display:grid;grid-template-columns:repeat(8,minmax(120px,1fr));gap:10px;padding:14px 16px}
    .inventory-report-field label{display:block;font-size:12px;color:#526477;font-weight:800;margin-bottom:6px}
    .inventory-report-field .form-control{border-radius:8px;border-color:#ccd7e3;min-height:38px}
    .inventory-report-table{margin:0;table-layout:auto;min-width:1120px}
    .inventory-report-table th{background:#edf3f8;color:#334155;font-size:12px;border-color:#dbe4ee;white-space:nowrap}
    .inventory-report-table td{border-color:#e7edf3;vertical-align:middle;font-size:13px}
    .inventory-report-empty{padding:28px;text-align:center;color:#64748b}
    .inventory-report-note{margin-top:12px;color:#64748b;font-size:12px}
    .inventory-report-chip{display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:6px 9px;background:#ecfdf5;color:#047857;font-weight:800;font-size:12px}
    @media(max-width:1200px){.inventory-report-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.inventory-report-filters{grid-template-columns:repeat(4,minmax(120px,1fr))}}
    @media(max-width:768px){.inventory-report-hero{align-items:flex-start;flex-direction:column}.inventory-report-kpis{grid-template-columns:1fr 1fr}.inventory-report-filters{grid-template-columns:1fr 1fr}}
</style>

<div class="content-wrapper inventory-report-page">
    <section class="content-header">
        <div class="inventory-report-wrap">
            <div class="inventory-report-hero">
                <div>
                    <h1 class="inventory-report-title">لوحة وتقارير المخزون</h1>
                    <p class="inventory-report-subtitle">قراءة تشغيلية من دفتر المخزون الجديد: أرصدة، حركات، نقص، مشتريات، تحويلات، جرد، هالك، إنتاج، واستهلاك وصفات.</p>
                </div>
                <div class="inventory-report-actions">
                    <a class="inventory-report-btn dark" href="inventory_purchasing.php"><i class="fas fa-dolly-flatbed"></i> الاستلام</a>
                    <a class="inventory-report-btn dark" href="inventory_counts.php"><i class="fas fa-clipboard-check"></i> الجرد</a>
                    <a class="inventory-report-btn dark" href="inventory_transfers.php"><i class="fas fa-exchange-alt"></i> التحويلات</a>
                    <a class="inventory-report-btn dark" href="inventory_stock_levels.php"><i class="fas fa-layer-group"></i> مستويات المخزون</a>
                </div>
            </div>

            <div class="inventory-report-kpis">
                <?php foreach (posmain_inventory_dashboard_cards($inventoryReportsDashboard, $inventoryReportsCanViewCost) as $card): ?>
                    <div class="inventory-report-kpi">
                        <span><?= posmain_inventory_report_h($card['label']) ?></span>
                        <strong><?= posmain_inventory_report_h($card['value']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="inventory-report-panel">
                <div class="inventory-report-panel-header">
                    <h2 class="inventory-report-panel-title">فلترة التقرير</h2>
                    <?php if (!$inventoryReportsCanViewCost): ?>
                        <span class="inventory-report-chip"><i class="fas fa-eye-slash"></i> أعمدة التكلفة مخفية</span>
                    <?php endif; ?>
                </div>
                <form method="GET" class="inventory-report-filters">
                    <div class="inventory-report-field">
                        <label>التقرير</label>
                        <select name="report" class="form-control">
                            <?php foreach ($inventoryReportsTypes as $key => $label): ?>
                                <option value="<?= posmain_inventory_report_h($key) ?>" <?= $inventoryReportsReport === $key ? 'selected' : '' ?>>
                                    <?= posmain_inventory_report_h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-report-field">
                        <label>من تاريخ</label>
                        <input type="date" name="date_from" class="form-control" value="<?= posmain_inventory_report_h($inventoryReportsFilters['date_from']) ?>">
                    </div>
                    <div class="inventory-report-field">
                        <label>إلى تاريخ</label>
                        <input type="date" name="date_to" class="form-control" value="<?= posmain_inventory_report_h($inventoryReportsFilters['date_to']) ?>">
                    </div>
                    <div class="inventory-report-field">
                        <label>الفرع</label>
                        <select name="pos_branch" class="form-control">
                            <option value="">كل الفروع</option>
                            <?php if ($inventoryReportsFilters['pos_branch'] >= 0 && !posmain_inventory_report_branch_exists($inventoryReportsBranches, $inventoryReportsFilters['pos_branch'])): ?>
                                <option value="<?= (int) $inventoryReportsFilters['pos_branch'] ?>" selected>فرع محدد من الرابط</option>
                            <?php endif; ?>
                            <?php foreach ($inventoryReportsBranches as $branch): ?>
                                <option value="<?= (int) $branch['pos_branch'] ?>" <?= (int) $inventoryReportsFilters['pos_branch'] === (int) $branch['pos_branch'] ? 'selected' : '' ?>>
                                    <?= posmain_inventory_report_h(($branch['branch_name'] ?? '') !== '' ? (string) $branch['branch_name'] : 'فرع غير مسمى') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-report-field">
                        <label>المخزن</label>
                        <select name="store_id" class="form-control">
                            <option value="">كل المخازن</option>
                            <?php if ($inventoryReportsFilters['store_id'] > 0 && !posmain_inventory_report_option_exists($inventoryReportsStores, $inventoryReportsFilters['store_id'])): ?>
                                <option value="<?= (int) $inventoryReportsFilters['store_id'] ?>" selected>مخزن محدد من الرابط</option>
                            <?php endif; ?>
                            <?php foreach ($inventoryReportsStores as $store): ?>
                                <option value="<?= (int) $store['id'] ?>" <?= (int) $inventoryReportsFilters['store_id'] === (int) $store['id'] ? 'selected' : '' ?>>
                                    <?= posmain_inventory_report_h(($store['aname'] ?? '') !== '' ? $store['aname'] : 'مخزن غير مسمى') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-report-field">
                        <label>المورد</label>
                        <select name="supplier_account_id" class="form-control">
                            <option value="">كل الموردين</option>
                            <?php if ($inventoryReportsFilters['supplier_account_id'] > 0 && !posmain_inventory_report_option_exists($inventoryReportsSuppliers, $inventoryReportsFilters['supplier_account_id'])): ?>
                                <option value="<?= (int) $inventoryReportsFilters['supplier_account_id'] ?>" selected>مورد محدد من الرابط</option>
                            <?php endif; ?>
                            <?php foreach ($inventoryReportsSuppliers as $supplier): ?>
                                <option value="<?= (int) $supplier['id'] ?>" <?= (int) $inventoryReportsFilters['supplier_account_id'] === (int) $supplier['id'] ? 'selected' : '' ?>>
                                    <?= posmain_inventory_report_h(trim(($supplier['aname'] ?? '') . (($supplier['code'] ?? '') !== '' ? ' - ' . $supplier['code'] : ''))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-report-field">
                        <label>الصنف</label>
                        <select name="item_id" class="form-control">
                            <option value="">كل الأصناف</option>
                            <?php if ($inventoryReportsFilters['item_id'] > 0 && !posmain_inventory_report_option_exists($inventoryReportsItems, $inventoryReportsFilters['item_id'])): ?>
                                <option value="<?= (int) $inventoryReportsFilters['item_id'] ?>" selected>صنف محدد من الرابط</option>
                            <?php endif; ?>
                            <?php foreach ($inventoryReportsItems as $item): ?>
                                <option value="<?= (int) $item['id'] ?>" <?= (int) $inventoryReportsFilters['item_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                                    <?= posmain_inventory_report_h(trim(($item['iname'] ?? '') . (($item['barcode'] ?? '') !== '' ? ' - ' . $item['barcode'] : ''))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-report-field">
                        <label>الفئة</label>
                        <select name="category_id" class="form-control">
                            <option value="">كل الفئات</option>
                            <?php if ($inventoryReportsFilters['category_id'] > 0 && !posmain_inventory_report_option_exists($inventoryReportsCategories, $inventoryReportsFilters['category_id'])): ?>
                                <option value="<?= (int) $inventoryReportsFilters['category_id'] ?>" selected>فئة محددة من الرابط</option>
                            <?php endif; ?>
                            <?php foreach ($inventoryReportsCategories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= (int) $inventoryReportsFilters['category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                                    <?= posmain_inventory_report_h(($category['gname'] ?? '') !== '' ? $category['gname'] : 'فئة غير مسماة') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-report-field">
                        <label>نوع الحركة</label>
                        <select name="movement_type" class="form-control">
                            <option value="">كل الحركات</option>
                            <?php if ($inventoryReportsFilters['movement_type'] !== '' && !isset($inventoryReportsMovementTypes[$inventoryReportsFilters['movement_type']])): ?>
                                <option value="<?= posmain_inventory_report_h($inventoryReportsFilters['movement_type']) ?>" selected>نوع حركة محدد من الرابط</option>
                            <?php endif; ?>
                            <?php foreach ($inventoryReportsMovementTypes as $movementType => $movementLabel): ?>
                                <option value="<?= posmain_inventory_report_h($movementType) ?>" <?= $inventoryReportsFilters['movement_type'] === $movementType ? 'selected' : '' ?>>
                                    <?= posmain_inventory_report_h($movementLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-report-field">
                        <label>عدد الصفوف</label>
                        <input type="number" name="limit" class="form-control" min="1" max="5000" value="<?= (int) $inventoryReportsFilters['limit'] ?>">
                    </div>
                    <div class="inventory-report-field">
                        <label>&nbsp;</label>
                        <button type="submit" class="inventory-report-btn primary"><i class="fas fa-search"></i> عرض</button>
                    </div>
                    <div class="inventory-report-field">
                        <label>&nbsp;</label>
                        <button type="submit" name="export" value="csv" class="inventory-report-btn"><i class="fas fa-file-csv"></i> CSV</button>
                    </div>
                </form>
            </div>

            <div class="inventory-report-panel">
                <div class="inventory-report-panel-header">
                    <h2 class="inventory-report-panel-title"><?= posmain_inventory_report_h($inventoryReportsTypes[$inventoryReportsReport] ?? '') ?></h2>
                    <span class="inventory-report-chip"><?= count($inventoryReportsRows) ?> صف</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover inventory-report-table">
                        <thead>
                            <tr>
                                <?php foreach ($inventoryReportsColumns as $column => $label): ?>
                                    <th><?= posmain_inventory_report_h($label) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventoryReportsRows as $row): ?>
                                <tr>
                                    <?php foreach ($inventoryReportsColumns as $column => $label): ?>
                                        <td><?= posmain_inventory_report_cell($column, $row[$column] ?? '', $row) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$inventoryReportsRows): ?>
                    <div class="inventory-report-empty">لا توجد نتائج مطابقة للفلاتر الحالية.</div>
                <?php endif; ?>
            </div>
            <p class="inventory-report-note">هذه الصفحة للقراءة فقط ولا تعدل المخزون. كل الأرقام تأتي من دفتر المخزون والجداول التشغيلية الحالية.</p>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_inventory_reports_can_view(mysqli $conn): bool
{
    return auth_guard_has_permission('reports.view', $conn)
        || auth_guard_has_permission('inventory.edit', $conn)
        || auth_guard_has_permission('accounting.view', $conn);
}

function posmain_inventory_reports_can_view_cost(mysqli $conn): bool
{
    return auth_guard_is_admin_session($_SESSION, auth_guard_current_role_flags($conn))
        || auth_guard_has_permission('accounting.view', $conn);
}

function posmain_inventory_report_types(bool $canViewCost): array
{
    $types = [
        'inventory_levels' => 'أرصدة المخزون',
        'movement_history' => 'حركة المخزون',
        'low_stock' => 'الأصناف المنخفضة',
        'replenishment_suggestions' => 'اقتراحات إعادة الطلب',
        'purchase_history' => 'تاريخ المشتريات',
        'supplier_purchase_summary' => 'ملخص مشتريات الموردين',
        'transfer_history' => 'تاريخ التحويلات',
        'count_variance' => 'فروقات الجرد',
        'waste_adjustment' => 'الهالك والتسويات',
        'production_variance' => 'فروقات الإنتاج',
        'recipe_consumption' => 'استهلاك الوصفات',
        'menu_availability' => 'توفر القائمة / يمكن تحضير',
    ];
    if ($canViewCost) {
        $types['inventory_valuation'] = 'تقييم المخزون / تاريخ التكلفة';
        $types['cogs_reconciliation'] = 'مطابقة التكلفة مع القيود';
    }

    return $types;
}

function posmain_inventory_report_key($value, array $types): string
{
    $key = strtolower(trim((string) $value));

    return isset($types[$key]) ? $key : 'inventory_levels';
}

function posmain_inventory_report_filters(array $request): array
{
    return [
        'date_from' => posmain_inventory_report_date($request['date_from'] ?? ''),
        'date_to' => posmain_inventory_report_date($request['date_to'] ?? ''),
        'pos_tenant' => isset($request['pos_tenant']) && $request['pos_tenant'] !== '' ? max(0, (int) $request['pos_tenant']) : -1,
        'pos_branch' => isset($request['pos_branch']) && $request['pos_branch'] !== '' ? max(0, (int) $request['pos_branch']) : -1,
        'store_id' => isset($request['store_id']) && (int) $request['store_id'] > 0 ? (int) $request['store_id'] : 0,
        'supplier_account_id' => isset($request['supplier_account_id']) && (int) $request['supplier_account_id'] > 0 ? (int) $request['supplier_account_id'] : 0,
        'item_id' => isset($request['item_id']) && (int) $request['item_id'] > 0 ? (int) $request['item_id'] : 0,
        'category_id' => isset($request['category_id']) && (int) $request['category_id'] > 0 ? (int) $request['category_id'] : 0,
        'movement_type' => preg_replace('/[^a-zA-Z0-9_:-]/', '', strtolower(trim((string) ($request['movement_type'] ?? '')))),
        'limit' => max(1, min(5000, (int) ($request['limit'] ?? 500))),
    ];
}

function posmain_inventory_report_branches(mysqli $conn): array
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

    if (posmain_inventory_report_table_exists($conn, 'cloud_branches')) {
        foreach (posmain_inventory_report_rows($conn, "
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

    foreach ([
        'inventory_item_balances',
        'inventory_movements',
        'inventory_purchase_receipts',
        'inventory_transfers',
        'inventory_counts',
    ] as $table) {
        if (!posmain_inventory_report_table_exists($conn, $table)) {
            continue;
        }
        foreach (posmain_inventory_report_rows($conn, "
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

function posmain_inventory_report_stores(mysqli $conn): array
{
    return posmain_inventory_report_rows($conn, "
        SELECT id, aname
        FROM acc_head
        WHERE COALESCE(isdeleted, 0) = 0
          AND COALESCE(is_stock, 0) = 1
        ORDER BY aname
        LIMIT 150
    ");
}

function posmain_inventory_report_suppliers(mysqli $conn): array
{
    $rows = posmain_inventory_report_rows($conn, "
        SELECT DISTINCT supplier.id, supplier.aname, supplier.code
        FROM inventory_purchase_receipts receipt
        INNER JOIN acc_head supplier ON supplier.id = receipt.supplier_account_id
        WHERE COALESCE(supplier.isdeleted, 0) = 0
          AND COALESCE(receipt.supplier_account_id, 0) > 0
        ORDER BY supplier.aname
        LIMIT 200
    ");
    if ($rows) {
        return $rows;
    }

    return posmain_inventory_report_rows($conn, "
        SELECT id, aname, code
        FROM acc_head
        WHERE COALESCE(isdeleted, 0) = 0
          AND code LIKE '211%'
        ORDER BY aname
        LIMIT 200
    ");
}

function posmain_inventory_report_items(mysqli $conn): array
{
    return posmain_inventory_report_rows($conn, "
        SELECT id, iname, barcode
        FROM myitems
        WHERE COALESCE(isdeleted, 0) = 0
          AND COALESCE(track_stock, 1) = 1
          AND COALESCE(item_type, 'sellable') <> 'service'
        ORDER BY iname, id
        LIMIT 700
    ");
}

function posmain_inventory_report_categories(mysqli $conn): array
{
    return posmain_inventory_report_rows($conn, "
        SELECT id, gname
        FROM item_group
        WHERE COALESCE(isdeleted, 0) = 0
        ORDER BY gname, id
        LIMIT 300
    ");
}

function posmain_inventory_report_rows(mysqli $conn, string $sql): array
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

function posmain_inventory_report_table_exists(mysqli $conn, string $table): bool
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

function posmain_inventory_report_branch_exists(array $rows, int $posBranch): bool
{
    foreach ($rows as $row) {
        if ((int) ($row['pos_branch'] ?? -1) === $posBranch) {
            return true;
        }
    }

    return false;
}

function posmain_inventory_report_option_exists(array $rows, int $id): bool
{
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return true;
        }
    }

    return false;
}

function posmain_inventory_report_columns(string $report, bool $canViewCost): array
{
    $columns = [
        'inventory_levels' => [
            'store_name' => 'المخزن',
            'item_name' => 'الصنف',
            'barcode' => 'الباركود',
            'item_type' => 'النوع',
            'qty_on_hand' => 'المتوفر فعليا',
            'qty_reserved' => 'محجوز',
            'qty_available' => 'المتاح',
            'moving_average_cost' => 'متوسط التكلفة',
            'stock_value' => 'قيمة المخزون',
            'minimum_level' => 'الحد الأدنى',
            'reorder_level' => 'نقطة الطلب',
            'par_level' => 'المستهدف',
            'inventory_status' => 'الحالة',
            'last_movement_at' => 'آخر حركة',
            'drilldown_url' => 'تفاصيل',
        ],
        'movement_history' => [
            'created_at' => 'التاريخ',
            'store_name' => 'المخزن',
            'item_name' => 'الصنف',
            'movement_type' => 'نوع الحركة',
            'source_type' => 'المصدر',
            'qty_in' => 'داخل',
            'qty_out' => 'خارج',
            'unit_cost' => 'تكلفة الوحدة',
            'total_cost' => 'إجمالي التكلفة',
            'accounting_journal_id' => 'القيد',
            'drilldown_url' => 'تفاصيل',
        ],
        'low_stock' => [
            'store_name' => 'المخزن',
            'item_name' => 'الصنف',
            'qty_available' => 'المتاح',
            'minimum_level' => 'الحد الأدنى',
            'reorder_level' => 'نقطة الطلب',
            'par_level' => 'المستهدف',
            'suggested_qty' => 'الكمية المقترحة',
            'estimated_purchase_cost' => 'تكلفة تقديرية',
            'drilldown_url' => 'تفاصيل',
        ],
        'replenishment_suggestions' => [
            'store_name' => 'المخزن',
            'item_name' => 'الصنف',
            'qty_available' => 'المتاح',
            'reorder_level' => 'نقطة الطلب',
            'par_level' => 'المستهدف',
            'suggested_qty' => 'النقص بالوحدة الأساسية',
            'preferred_purchase_unit_name' => 'وحدة الشراء',
            'preferred_purchase_unit_conversion' => 'تحويل الوحدة',
            'suggested_purchase_qty' => 'كمية الشراء',
            'suggested_purchase_base_qty' => 'كمية الشراء الأساسية',
            'moving_average_cost' => 'متوسط التكلفة',
            'estimated_purchase_cost' => 'تكلفة تقديرية',
            'drilldown_url' => 'تفاصيل',
        ],
        'purchase_history' => [
            'document_at' => 'التاريخ',
            'receipt_id' => 'المستند',
            'supplier_name' => 'المورد',
            'store_name' => 'المخزن',
            'status' => 'الحالة',
            'item_names' => 'الأصناف',
            'received_qty' => 'مستلم',
            'returned_qty' => 'مرتجع',
            'total_cost' => 'إجمالي التكلفة',
            'drilldown_url' => 'تفاصيل',
        ],
        'supplier_purchase_summary' => [
            'supplier_name' => 'المورد',
            'receipt_count' => 'عدد المستندات',
            'line_count' => 'عدد السطور',
            'item_count' => 'عدد الأصناف',
            'store_names' => 'المخازن',
            'first_purchase_at' => 'أول شراء',
            'last_purchase_at' => 'آخر شراء',
            'received_qty' => 'مستلم',
            'returned_qty' => 'مرتجع',
            'net_received_qty' => 'صافي المستلم',
            'total_cost' => 'إجمالي التكلفة',
            'avg_unit_cost' => 'متوسط تكلفة الوحدة',
            'drilldown_url' => 'تفاصيل',
        ],
        'transfer_history' => [
            'created_at' => 'التاريخ',
            'transfer_id' => 'التحويل',
            'source_store_name' => 'من',
            'destination_store_name' => 'إلى',
            'status' => 'الحالة',
            'item_names' => 'الأصناف',
            'requested_qty' => 'مطلوب',
            'sent_qty' => 'مرسل',
            'received_qty' => 'مستلم',
            'variance_qty' => 'فرق',
            'total_cost' => 'قيمة مرسلة',
            'drilldown_url' => 'تفاصيل',
        ],
        'count_variance' => [
            'document_at' => 'التاريخ',
            'count_id' => 'الجرد',
            'store_name' => 'المخزن',
            'item_name' => 'الصنف',
            'snapshot_qty' => 'كمية النظام',
            'counted_qty' => 'الكمية المعدودة',
            'variance_qty' => 'فرق الكمية',
            'variance_percent' => 'نسبة الفرق',
            'variance_cost' => 'قيمة الفرق',
            'stale_count_conflict' => 'تغير أثناء الجرد',
            'drilldown_url' => 'تفاصيل',
        ],
        'waste_adjustment' => [
            'store_name' => 'المخزن',
            'item_name' => 'الصنف',
            'movement_type' => 'نوع الحركة',
            'movement_count' => 'عدد الحركات',
            'qty_in' => 'داخل',
            'qty_out' => 'خارج',
            'total_cost' => 'إجمالي التكلفة',
            'last_movement_at' => 'آخر حركة',
            'drilldown_url' => 'تفاصيل',
        ],
        'production_variance' => [
            'committed_at' => 'تاريخ الاعتماد',
            'batch_id' => 'تشغيلة',
            'store_name' => 'المخزن',
            'recipe_name' => 'الوصفة',
            'output_item_name' => 'الناتج',
            'planned_output_qty' => 'المخطط',
            'actual_output_qty' => 'الفعلي',
            'variance_qty' => 'فرق الكمية',
            'variance_percent' => 'نسبة الفرق',
            'input_cost' => 'تكلفة المدخلات',
            'output_cost' => 'تكلفة الناتج',
            'variance_reason' => 'السبب',
            'drilldown_url' => 'تفاصيل',
        ],
        'recipe_consumption' => [
            'store_name' => 'المخزن',
            'item_name' => 'المكون',
            'recipe_name' => 'الوصفة',
            'movement_count' => 'عدد الحركات',
            'qty_out' => 'كمية مستهلكة',
            'total_cost' => 'تكلفة الاستهلاك',
            'last_movement_at' => 'آخر استهلاك',
            'drilldown_url' => 'تفاصيل',
        ],
        'menu_availability' => [
            'store_name' => 'المخزن',
            'sellable_item_name' => 'عنصر القائمة',
            'effective_available_qty' => 'يمكن تحضير',
            'availability_status' => 'الحالة',
            'blocking_item_name' => 'المكوّن المحدد',
            'unavailable_reason' => 'سبب عدم التوفر',
            'order_type' => 'نوع الطلب',
            'channel' => 'القناة',
            'updated_at' => 'آخر تحديث',
            'drilldown_url' => 'تفاصيل',
        ],
        'inventory_valuation' => [
            'store_name' => 'المخزن',
            'item_name' => 'الصنف',
            'barcode' => 'الباركود',
            'qty_on_hand' => 'المتوفر فعليا',
            'qty_reserved' => 'محجوز',
            'qty_available' => 'المتاح',
            'moving_average_cost' => 'متوسط التكلفة',
            'current_stock_value' => 'قيمة المخزون',
            'last_movement_type' => 'آخر حركة',
            'last_unit_cost' => 'آخر تكلفة وحدة',
            'last_total_cost' => 'آخر قيمة حركة',
            'last_cost_movement_at' => 'وقت آخر حركة تكلفة',
            'drilldown_url' => 'تفاصيل',
        ],
        'cogs_reconciliation' => [
            'review_key' => 'المجموعة',
            'sample_movement_type' => 'نوع الحركة',
            'movement_count' => 'عدد الحركات',
            'movement_total' => 'قيمة الحركات',
            'journal_debit_total' => 'مدين القيود',
            'journal_credit_total' => 'دائن القيود',
            'reconciliation_status' => 'الحالة',
            'journal_details' => 'تفاصيل القيد',
        ],
    ];

    $selected = $columns[$report] ?? $columns['inventory_levels'];
    if (!$canViewCost) {
        foreach (['moving_average_cost', 'stock_value', 'unit_cost', 'total_cost', 'estimated_purchase_cost', 'variance_cost', 'input_cost', 'output_cost', 'movement_total', 'journal_debit_total', 'journal_credit_total', 'current_stock_value', 'last_unit_cost', 'last_total_cost'] as $costColumn) {
            unset($selected[$costColumn]);
        }
    }

    return $selected;
}

function posmain_inventory_dashboard_cards(array $dashboard, bool $canViewCost): array
{
    $cards = [
        ['label' => 'أصناف لها رصيد', 'value' => number_format((float) ($dashboard['item_count'] ?? 0), 0)],
        ['label' => 'أصناف منخفضة', 'value' => number_format((float) ($dashboard['low_stock_count'] ?? 0), 0)],
        ['label' => 'حركات اليوم', 'value' => number_format((float) ($dashboard['movements_today'] ?? 0), 0)],
        ['label' => 'محجوز حاليا', 'value' => posmain_inventory_report_decimal($dashboard['reserved_qty'] ?? 0)],
        ['label' => 'جرد مفتوح', 'value' => number_format((float) ($dashboard['open_counts'] ?? 0), 0)],
        ['label' => 'تحويلات مفتوحة', 'value' => number_format((float) ($dashboard['open_transfers'] ?? 0), 0)],
    ];
    if ($canViewCost) {
        array_unshift($cards, ['label' => 'قيمة المخزون', 'value' => posmain_inventory_report_money($dashboard['stock_value'] ?? 0)]);
        $cards[] = ['label' => 'هالك 7 أيام', 'value' => posmain_inventory_report_money($dashboard['waste_7d_cost'] ?? 0)];
    }

    return array_slice($cards, 0, 8);
}

function posmain_inventory_report_cell(string $column, $value, array $row): string
{
    if ($column === 'drilldown_url') {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        return '<a class="btn btn-sm btn-outline-primary" href="' . posmain_inventory_report_h($url) . '"><i class="fas fa-arrow-left"></i></a>';
    }
    if (in_array($column, ['movement_type', 'last_movement_type', 'sample_movement_type'], true)) {
        return posmain_inventory_report_h(posmain_inventory_report_movement_type_label((string) $value));
    }
    if ($column === 'item_type') {
        return posmain_inventory_report_h(posmain_inventory_report_item_type_label((string) $value));
    }
    if ($column === 'source_type') {
        return posmain_inventory_report_h(posmain_inventory_report_source_type_label((string) $value));
    }
    if ($column === 'order_type') {
        return posmain_inventory_report_h(posmain_inventory_report_order_type_label((string) $value));
    }
    if ($column === 'channel') {
        return posmain_inventory_report_h(posmain_inventory_report_channel_label((string) $value));
    }
    if ($column === 'inventory_status') {
        return posmain_inventory_report_h(posmain_inventory_report_inventory_status_label((string) $value));
    }
    if ($column === 'availability_status') {
        return posmain_inventory_report_h(posmain_inventory_report_availability_status_label((string) $value));
    }
    if ($column === 'reconciliation_status') {
        return posmain_inventory_report_h(posmain_inventory_report_reconciliation_status_label((string) $value));
    }
    if ($column === 'status') {
        return posmain_inventory_report_h(posmain_inventory_report_workflow_status_label((string) $value));
    }
    if (preg_match('/(qty|cost|value|total|percent|level)$/', $column)) {
        return posmain_inventory_report_h(posmain_inventory_report_decimal($value));
    }
    if ($column === 'stale_count_conflict') {
        return ((int) $value === 1) ? 'نعم' : 'لا';
    }

    return posmain_inventory_report_h($value);
}

function posmain_inventory_report_movement_type_labels(): array
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

function posmain_inventory_report_movement_type_label(string $movementType): string
{
    $labels = posmain_inventory_report_movement_type_labels();

    return $labels[$movementType] ?? $movementType;
}

function posmain_inventory_report_inventory_status_label(string $status): string
{
    $labels = [
        'negative' => 'رصيد سالب',
        'reorder' => 'عند نقطة الطلب',
        'low' => 'منخفض',
        'ok' => 'طبيعي',
    ];

    return $labels[$status] ?? $status;
}

function posmain_inventory_report_item_type_label(string $itemType): string
{
    $labels = [
        'sellable' => 'صنف بيع',
        'ingredient' => 'مكوّن',
        'packaging' => 'تغليف',
        'service' => 'خدمة',
    ];

    return $labels[$itemType] ?? $itemType;
}

function posmain_inventory_report_source_type_label(string $sourceType): string
{
    $labels = [
        'order' => 'طلب',
        'order_line' => 'سطر طلب',
        'invoice' => 'فاتورة',
        'fat_details' => 'فاتورة قديمة',
        'recipe' => 'وصفة',
        'recipe_order_line_usage' => 'استهلاك وصفة',
        'production_batch' => 'تشغيلة إنتاج',
        'purchase_invoice' => 'فاتورة شراء',
        'purchase_order' => 'أمر شراء',
        'purchase_receipt' => 'استلام شراء',
        'inventory_count' => 'جرد مخزون',
        'inventory_transfer' => 'تحويل مخزون',
        'adjustment' => 'تسوية مخزون',
        'reservation' => 'حجز مخزون',
        'sync_event' => 'مزامنة',
        'manual' => 'يدوي',
    ];

    return $labels[$sourceType] ?? $sourceType;
}

function posmain_inventory_report_order_type_label(string $orderType): string
{
    $labels = [
        'table_order' => 'طاولة',
        'dine_in' => 'محلي',
        'takeaway' => 'سفري',
        'delivery' => 'توصيل',
    ];

    return $labels[$orderType] ?? $orderType;
}

function posmain_inventory_report_channel_label(string $channel): string
{
    $labels = [
        'pos' => 'نقطة البيع',
        'pos_counter' => 'كاشير',
        'moova' => 'موفا',
        'online' => 'أونلاين',
    ];

    return $labels[$channel] ?? $channel;
}

function posmain_inventory_report_availability_status_label(string $status): string
{
    $labels = [
        'available' => 'متاح',
        'unavailable' => 'غير متاح',
    ];

    return $labels[$status] ?? $status;
}

function posmain_inventory_report_reconciliation_status_label(string $status): string
{
    $labels = [
        'balanced' => 'متوازن',
        'missing_journal' => 'قيد مفقود',
        'journal_mismatch' => 'فرق في القيد',
    ];

    return $labels[$status] ?? $status;
}

function posmain_inventory_report_workflow_status_label(string $status): string
{
    $labels = [
        'draft' => 'مسودة',
        'submitted' => 'بانتظار الاعتماد',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض',
        'closed' => 'مغلق',
        'cancelled' => 'ملغي',
        'sent' => 'مرسل',
        'partially_received' => 'استلام جزئي',
        'received' => 'مستلم',
        'posted' => 'مرحل',
        'returned' => 'مرتجع',
        'variance_closed' => 'مغلق بفرق',
    ];

    return $labels[$status] ?? $status;
}

function posmain_inventory_reports_export_csv(string $report, array $columns, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory-' . preg_replace('/[^a-z0-9_-]/', '-', $report) . '-' . date('Ymd-His') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    posmain_csv_write_row($output, posmain_csv_safe_row(array_values($columns)));
    foreach ($rows as $row) {
        $values = [];
        foreach (array_keys($columns) as $column) {
            $values[] = $row[$column] ?? '';
        }
        posmain_csv_write_row($output, posmain_csv_safe_row($values));
    }
    fclose($output);
}

function posmain_inventory_report_date($value): string
{
    $value = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function posmain_inventory_report_decimal($value): string
{
    return number_format((float) $value, 3, '.', '');
}

function posmain_inventory_report_money($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function posmain_inventory_report_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
