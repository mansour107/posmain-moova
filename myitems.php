<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/classes/Inventory/InventoryStockReadService.php';
require_once __DIR__ . '/classes/Items/ItemCatalogStatus.php';
require_once __DIR__ . '/classes/Items/ItemUnitCatalogLabel.php';

function item_catalog_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function item_catalog_number($value, int $scale = 3): string
{
    $number = is_numeric($value) ? (float) $value : 0.0;
    if (abs($number) < 0.0000005) {
        $number = 0.0;
    }

    $formatted = number_format($number, $scale, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
}

function item_catalog_type_label(string $type): string
{
    $labels = [
        'sellable' => 'يباع كما هو',
        'made' => 'يصنع من مكونات',
        'ingredient' => 'مادة خام',
        'packaging' => 'تغليف',
        'service' => 'خدمة',
    ];

    return $labels[$type] ?? 'يباع كما هو';
}
?>
<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/csrf.php';
include('includes/connect.php');
page_guard('menu.edit', $conn);
include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php
$hasActiveColumn = ItemCatalogStatus::hasActiveColumn($conn);
$itemTypeColumnResult = $conn->query("SHOW COLUMNS FROM myitems LIKE 'item_type'");
$hasItemTypeColumn = $itemTypeColumnResult && $itemTypeColumnResult->num_rows > 0;
$statusFilter = $hasActiveColumn ? (string) ($_GET['status'] ?? 'all') : 'all';
$typeFilter = $hasItemTypeColumn ? (string) ($_GET['type'] ?? 'all') : 'all';
$allowedStatusFilters = ['all', 'active', 'inactive'];
$allowedTypeFilters = ['all', 'sellable', 'made', 'ingredient', 'packaging', 'service'];

if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}
if (!in_array($typeFilter, $allowedTypeFilters, true)) {
    $typeFilter = 'all';
}

$where = ['COALESCE(isdeleted, 0) = 0'];
if ($hasActiveColumn && $statusFilter === 'active') {
    $where[] = 'COALESCE(is_active, 1) = 1';
} elseif ($hasActiveColumn && $statusFilter === 'inactive') {
    $where[] = 'COALESCE(is_active, 1) = 0';
}
if ($hasItemTypeColumn && $typeFilter !== 'all') {
    $where[] = "item_type = '" . $conn->real_escape_string($typeFilter) . "'";
}

$variantTableResult = $conn->query("SHOW TABLES LIKE 'item_variants'");
$hasVariantTable = $variantTableResult && $variantTableResult->num_rows > 0;
if ($hasVariantTable) {
    $where[] = 'NOT EXISTS (
        SELECT 1
        FROM item_variants ivc
        WHERE ivc.variant_item_id = myitems.id
          AND ivc.is_active = 1
    )';
}

$inventoryStockReadService = new InventoryStockReadService();
$sql = 'SELECT *, ' . ItemCatalogStatus::activeSelectSql($conn) . ' FROM myitems WHERE ' . implode(' AND ', $where) . ' ORDER BY iname ASC, id ASC';
$resitm = $conn->query($sql);
$itemRows = $resitm ? $resitm->fetch_all(MYSQLI_ASSOC) : [];
$itemRows = $inventoryStockReadService->decorateItems($conn, $itemRows);
?>
<div class="content-wrapper">
    <section class="content-header item-catalog-page">
        <div class="container-fluid">

            <?php if (isset($_GET['recost']) && $_GET['recost'] === 'ok'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="إغلاق">&times;</button>
                    <i class="fas fa-check-circle"></i>
                    تم إعادة حساب التكاليف بنجاح.
                </div>
            <?php elseif (isset($_GET['recost']) && $_GET['recost'] === 'fail'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="إغلاق">&times;</button>
                    <i class="fas fa-exclamation-triangle"></i>
                    تعذّر إكمال إعادة حساب التكاليف. تحقق من الاتصال بقاعدة البيانات أو البيانات ثم أعد المحاولة.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['active'])): ?>
                <?php
                $activeMessages = [
                    'enabled' => ['alert-success', 'تم تفعيل الصنف.'],
                    'disabled' => ['alert-warning', 'تم تعطيل الصنف.'],
                    'missing' => ['alert-danger', 'لا يمكن تغيير حالة الصنف قبل تشغيل تحديثات قاعدة البيانات.'],
                    'invalid' => ['alert-danger', 'تعذّر تحديد الصنف المطلوب.'],
                    'fail' => ['alert-danger', 'تعذّر تحديث حالة الصنف.'],
                ];
                $activeMessage = $activeMessages[(string) $_GET['active']] ?? null;
                ?>
                <?php if ($activeMessage): ?>
                    <div class="alert <?= item_catalog_h($activeMessage[0]) ?> alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="إغلاق">&times;</button>
                        <?= item_catalog_h($activeMessage[1]) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="item-catalog-shell">
                <div class="item-catalog-toolbar">
                    <div>
                        <h3>الأصناف</h3>
                        <p>اضغط على اسم الصنف أو الصف لفتح صفحة التفاصيل والتعديل.</p>
                    </div>

                    <div class="item-catalog-actions">
                        <div class="item-catalog-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="search" class="form-control" placeholder="بحث بالاسم أو الباركود أو الوحدة">
                        </div>
                        <a href="add_item.php" id="addNewElement" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            صنف جديد
                        </a>
                        <a href="do/recost.php" class="btn btn-outline-secondary">
                            <i class="fas fa-calculator"></i>
                            إعادة حساب
                        </a>
                        <a href="items_factory.php" class="btn btn-outline-info">
                            <i class="fas fa-magic"></i>
                            مصنع الأصناف
                        </a>
                        <button id="reset-manual-prices" class="btn btn-outline-warning" type="button">
                            <i class="fas fa-shield-alt"></i>
                            إعادة تعيين الحماية
                        </button>
                    </div>
                </div>

                <div class="item-catalog-filters">
                    <?php if ($hasActiveColumn): ?>
                        <a class="filter-chip <?= $statusFilter === 'all' ? 'active' : '' ?>" href="myitems.php?status=all&type=<?= item_catalog_h($typeFilter) ?>">الكل</a>
                        <a class="filter-chip <?= $statusFilter === 'active' ? 'active' : '' ?>" href="myitems.php?status=active&type=<?= item_catalog_h($typeFilter) ?>">نشط</a>
                        <a class="filter-chip <?= $statusFilter === 'inactive' ? 'active' : '' ?>" href="myitems.php?status=inactive&type=<?= item_catalog_h($typeFilter) ?>">معطّل</a>
                    <?php endif; ?>

                    <?php if ($hasItemTypeColumn): ?>
                        <?php foreach ($allowedTypeFilters as $filterType): ?>
                            <?php
                            if ($filterType === 'packaging' && $typeFilter !== 'packaging') {
                                continue;
                            }
                            $label = $filterType === 'all' ? 'كل الأنواع' : item_catalog_type_label($filterType);
                            $href = 'myitems.php?status=' . rawurlencode($statusFilter) . '&type=' . rawurlencode($filterType);
                            ?>
                            <a class="filter-chip <?= $typeFilter === $filterType ? 'active' : '' ?>" href="<?= item_catalog_h($href) ?>"><?= item_catalog_h($label) ?></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="item-catalog-table-wrap">
                    <table data-page-length="50" id="horsTable" class="table item-catalog-table">
                        <thead>
                            <tr>
                                <th>الباركود</th>
                                <th>الاسم</th>
                                <th>الكميه</th>
                                <th>الوحدة</th>
                                <th>سعر البيع</th>
                                <th>سعر الشراء</th>
                                <th>سعر التكلفة</th>
                                <th>عمليات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($itemRows as $rowitm): ?>
                            <?php
                            $itemid = (int) $rowitm['id'];
                            $editUrl = 'add_item.php?edit=' . $itemid;
                            $isActive = (int) ($rowitm['catalog_is_active'] ?? $rowitm['is_active'] ?? 1) === 1;
                            $resunt = $conn->query("SELECT iu.*, u.uname FROM item_units iu LEFT JOIN myunits u ON u.id = iu.unit_id WHERE iu.item_id = $itemid AND COALESCE(iu.isdeleted, 0) = 0 ORDER BY iu.id ASC");
                            $unitRows = [];
                            while ($r = $resunt->fetch_assoc()) {
                                $unitRows[] = $r;
                            }
                            $searchParts = [
                                (string) $rowitm['id'],
                                isset($rowitm['barcode']) ? (string) $rowitm['barcode'] : '',
                                (string) $rowitm['iname'],
                                isset($rowitm['name2']) ? (string) $rowitm['name2'] : '',
                                isset($rowitm['info']) ? (string) $rowitm['info'] : '',
                                isset($rowitm['item_type']) ? item_catalog_type_label((string) $rowitm['item_type']) : '',
                                $isActive ? 'نشط' : 'معطل',
                            ];
                            foreach ($unitRows as $ur) {
                                $searchParts[] = isset($ur['uname']) ? (string) $ur['uname'] : '';
                                $searchParts[] = isset($ur['unit_barcode']) ? (string) $ur['unit_barcode'] : '';
                                $searchParts[] = isset($ur['u_val']) ? (string) $ur['u_val'] : '';
                            }
                            $dataSearch = item_catalog_h(implode(' ', array_filter($searchParts)));
                            $qtyDisplay = item_catalog_number($rowitm['stock_qty_display'] ?? $rowitm['itmqty'] ?? 0);
                            $baseQty = item_catalog_number($rowitm['legacy_itmqty'] ?? $rowitm['itmqty'] ?? 0, 6);
                            $stockUnitRow = ItemUnitCatalogLabel::stockRow($unitRows);
                            $unitName = $stockUnitRow['uname'] ?? '';
                            $stockUnitValue = $stockUnitRow !== null
                                ? ItemUnitCatalogLabel::displayFactorValue($stockUnitRow)
                                : '1';
                            $unitSelectOptions = ItemUnitCatalogLabel::buildSelectOptions($unitRows);
                            ?>
                            <tr class="catalog-row <?= $isActive ? '' : 'catalog-row-inactive' ?>" data-search="<?= $dataSearch ?>" data-edit-url="<?= item_catalog_h($editUrl) ?>" tabindex="0">
                                <td class="barcode-cell"><?= item_catalog_h($rowitm['barcode'] ?? '') ?></td>
                                <td class="item-name-cell">
                                    <a class="item-open-link" href="<?= item_catalog_h($editUrl) ?>">
                                        <span class="item-open-main"><?= item_catalog_h($rowitm['iname'] ?? '') ?></span>
                                        <i class="fas fa-chevron-left" aria-hidden="true"></i>
                                    </a>
                                    <div class="item-row-meta">
                                        <?php if ($hasItemTypeColumn): ?>
                                            <span><?= item_catalog_h(item_catalog_type_label((string) ($rowitm['item_type'] ?? 'sellable'))) ?></span>
                                        <?php endif; ?>
                                        <span class="status-pill <?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'نشط' : 'معطّل' ?></span>
                                    </div>
                                </td>
                                <td class="qty" data-row-id="<?= $itemid ?>" data-original-qty="<?= item_catalog_h($baseQty) ?>">
                                    <span id="item_qty_<?= $itemid ?>"><?= item_catalog_h($qtyDisplay) ?></span>
                                    <?php if ($unitName !== ''): ?>
                                        <small><?= item_catalog_h($unitName) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="unit">
                                    <select id="item_unit_<?= $itemid ?>" class="form-control form-control-sm" data-row-id="<?= $itemid ?>">
                                        <?php if (!$unitRows): ?>
                                            <option value="1">الوحدة الأساسية</option>
                                        <?php endif; ?>
                                        <?php foreach ($unitSelectOptions as $unitOption): ?>
                                            <option value="<?= item_catalog_h($unitOption['value']) ?>"<?= $unitOption['value'] === $stockUnitValue ? ' selected' : '' ?>>
                                                <?= item_catalog_h($unitOption['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="price-cell"><?= item_catalog_h(item_catalog_number($rowitm['price1'] ?? 0)) ?></td>
                                <td class="price-cell"><?= item_catalog_h(item_catalog_number($rowitm['last_price'] ?? 0)) ?></td>
                                <td class="price-cell cost-cell">
                                    <i class="fas fa-lock" aria-hidden="true"></i>
                                    <?= item_catalog_h(item_catalog_number($rowitm['cost_price'] ?? 0)) ?>
                                </td>
                                <td class="ops-cell">
                                    <a class="catalog-op history-op" href="item_summery.php?id=<?= $itemid ?>" title="سجل الحركة">
                                        <i class="fas fa-history"></i>
                                        سجل الحركة
                                    </a>
                                    <?php if ($hasActiveColumn): ?>
                                        <form action="do/toggle_item_active.php" method="post" class="catalog-op-form">
                                            <?= csrf_input('menu_write') ?>
                                            <input type="hidden" name="id" value="<?= $itemid ?>">
                                            <input type="hidden" name="active" value="<?= $isActive ? 0 : 1 ?>">
                                            <button type="submit" class="catalog-op status-op <?= $isActive ? 'disable' : 'enable' ?>" title="<?= $isActive ? 'تعطيل' : 'تفعيل' ?>">
                                                <i class="fas <?= $isActive ? 'fa-pause-circle' : 'fa-check-circle' ?>"></i>
                                                <?= $isActive ? 'تعطيل' : 'تفعيل' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button type="button" class="catalog-op delete-op" data-toggle="modal" data-target="#deleteitm<?= $itemid ?>" title="حذف">
                                        <i class="fas fa-trash"></i>
                                        حذف
                                    </button>

                                    <div class="modal fade" id="deleteitm<?= $itemid ?>">
                                        <div class="modal-dialog">
                                            <div class="modal-content bg-danger">
                                                <div class="modal-header">
                                                    <h4 class="modal-title">تحذير</h4>
                                                    <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
                                                        <span aria-hidden="true">&times;</span>
                                                    </button>
                                                </div>
                                                <form action="do/dodel_item.php?id=<?= $itemid ?>" method="post">
                                                    <?= csrf_input('menu_write') ?>
                                                    <div class="modal-body">
                                                        <p>هل تريد بالتأكيد حذف <?= item_catalog_h($rowitm['iname'] ?? '') ?>؟</p>
                                                    </div>
                                                    <div class="modal-footer justify-content-between">
                                                        <button type="submit" class="btn btn-flat btn-sm btn-outline-light btn-block">حذف</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($itemRows) === 0): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    لا توجد أصناف بعد. أضف أول صنف من <a href="add_item.php">صفحة إضافة صنف</a>.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </section>
</div>

<style>
.item-catalog-page {
    direction: rtl;
}

.item-catalog-shell {
    background: #ffffff;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
    overflow: hidden;
}

.item-catalog-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 22px 24px 16px;
    background: #f8fafc;
    border-bottom: 1px solid #e6edf5;
}

.item-catalog-toolbar h3 {
    margin: 0;
    color: #0f172a;
    font-weight: 800;
}

.item-catalog-toolbar p {
    margin: 7px 0 0;
    color: #64748b;
    font-size: 14px;
}

.item-catalog-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.item-catalog-search {
    position: relative;
    min-width: min(420px, 48vw);
}

.item-catalog-search i {
    position: absolute;
    top: 50%;
    right: 14px;
    transform: translateY(-50%);
    color: #64748b;
}

.item-catalog-search .form-control {
    height: 42px;
    padding-right: 40px;
    border-color: #d7e0ea;
    border-radius: 8px;
    background: #ffffff;
}

.item-catalog-actions .btn {
    border-radius: 8px;
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-weight: 700;
}

.item-catalog-filters {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding: 14px 24px;
    border-bottom: 1px solid #e6edf5;
}

.filter-chip {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 6px 13px;
    border-radius: 8px;
    border: 1px solid #d7e0ea;
    color: #334155;
    background: #ffffff;
    font-weight: 700;
}

.filter-chip:hover,
.filter-chip.active {
    color: #0f766e;
    background: #ecfdf5;
    border-color: #99f6e4;
    text-decoration: none;
}

.item-catalog-table-wrap {
    overflow: auto;
}

.item-catalog-table {
    margin: 0;
    min-width: 1120px;
}

.item-catalog-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #eef4f8;
    color: #334155;
    border-top: 0;
    border-bottom: 1px solid #dce6ef;
    font-size: 13px;
    white-space: nowrap;
}

.item-catalog-table td {
    vertical-align: middle;
    border-top: 1px solid #edf2f7;
}

.catalog-row {
    cursor: pointer;
    transition: background-color 0.16s ease, box-shadow 0.16s ease;
}

.catalog-row:hover,
.catalog-row:focus {
    background: #f5fbfa;
    box-shadow: inset 4px 0 0 #14b8a6;
    outline: none;
}

.catalog-row-inactive {
    color: #64748b;
    background: #fbfcfd;
}

.barcode-cell {
    color: #475569;
    font-weight: 700;
    direction: ltr;
    text-align: right;
}

.item-open-link {
    color: #0f172a;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 800;
    text-decoration: none;
}

.item-open-link:hover {
    color: #0f766e;
    text-decoration: none;
}

.item-open-main {
    max-width: 360px;
    overflow: visible;
    overflow-wrap: anywhere;
    text-overflow: clip;
    white-space: normal;
    line-height: 1.3;
}

.item-open-link i {
    color: #14b8a6;
    font-size: 12px;
}

.item-row-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 5px;
    color: #64748b;
    font-size: 12px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 2px 8px;
    border-radius: 8px;
    font-weight: 800;
}

.status-pill.active {
    color: #047857;
    background: #d1fae5;
}

.status-pill.inactive {
    color: #92400e;
    background: #fef3c7;
}

.qty span {
    display: inline-block;
    color: #0f172a;
    font-weight: 800;
    direction: ltr;
}

.qty small {
    display: block;
    color: #64748b;
    font-weight: 700;
}

.unit .form-control {
    min-width: 120px;
    border-radius: 8px;
    border-color: #d7e0ea;
}

.price-cell {
    color: #0f172a;
    font-weight: 800;
    direction: ltr;
    text-align: right;
}

.cost-cell i {
    color: #94a3b8;
    font-size: 11px;
    margin-left: 5px;
}

.ops-cell {
    min-width: 270px;
    white-space: nowrap;
}

.catalog-op,
.catalog-op-form {
    display: inline-flex;
    align-items: center;
    margin: 0 0 0 6px;
}

.catalog-op {
    justify-content: center;
    min-height: 34px;
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid #d7e0ea;
    background: #ffffff;
    color: #334155;
    font-weight: 800;
    gap: 6px;
}

.catalog-op:hover {
    text-decoration: none;
    background: #f8fafc;
    color: #0f172a;
}

.history-op {
    color: #0f766e;
    border-color: #99f6e4;
    background: #ecfdf5;
}

.status-op {
    color: #1d4ed8;
    border-color: #bfdbfe;
    background: #eff6ff;
}

.status-op.disable {
    color: #92400e;
    border-color: #fde68a;
    background: #fffbeb;
}

.delete-op {
    color: #b91c1c;
    border-color: #fecaca;
    background: #fff1f2;
}

@media (max-width: 991px) {
    .item-catalog-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .item-catalog-actions {
        justify-content: stretch;
    }

    .item-catalog-search {
        min-width: 100%;
    }
}
</style>

<script>
$(document).ready(function() {
    $('#reset-manual-prices').click(function() {
        if (confirm('هل أنت متأكد من إعادة تعيين حماية الأسعار؟ سيتم إعادة حساب جميع الأسعار عند الضغط على إعادة حساب')) {
            $.ajax({
                url: 'do/reset_manual_prices.php',
                method: 'POST',
                success: function() {
                    alert('تم إعادة التعيين بنجاح');
                    location.reload();
                },
                error: function() {
                    alert('حدث خطأ');
                }
            });
        }
    });

    $('.unit select').change(function() {
        var rowId = $(this).data('row-id');
        var selectedUnitValue = parseFloat($(this).val());
        var qtyElement = $('#item_qty_' + rowId);
        var originalQty = parseFloat($('.qty[data-row-id="' + rowId + '"]').data('original-qty'));

        if (selectedUnitValue && !isNaN(selectedUnitValue) && !isNaN(originalQty)) {
            var newQty = originalQty / selectedUnitValue;
            qtyElement.text(Number(newQty.toFixed(3)).toString());
        }
    });

    $('.catalog-row').on('click keydown', function(event) {
        var isKeyboardOpen = event.type === 'keydown' && (event.key === 'Enter' || event.key === ' ');
        if (event.type === 'keydown' && !isKeyboardOpen) {
            return;
        }

        if ($(event.target).closest('a, button, input, select, textarea, form, .modal').length) {
            return;
        }

        var editUrl = $(this).data('edit-url');
        if (editUrl) {
            window.location.href = editUrl;
        }
    });
});
</script>

<?php include('includes/footer.php') ?>
