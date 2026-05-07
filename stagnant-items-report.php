<?php
include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');

// التحقق من الصلاحيات
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

// الحصول على الفلاتر من GET
$from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$days_period = isset($_GET['days']) ? intval($_GET['days']) : 30;
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$max_qty_filter = isset($_GET['max_qty']) ? intval($_GET['max_qty']) : null; // New filter

// حساب التاريخ بناءً على عدد الأيام إذا تم تحديده
if (isset($_GET['days']) && $_GET['days'] > 0) {
    $from_date = date('Y-m-d', strtotime("-{$days_period} days"));
    $to_date = date('Y-m-d');
}
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle text-warning"></i>
                        تقرير الأصناف الراكدة
                    </h3>
                </div>

                <div class="card-body" id="stagnantReport">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        هذا التقرير يعرض الأصناف التي لم يتم بيعها خلال الفترة المحددة
                    </div>

                    <!-- نموذج الفلترة -->
                    <form method="GET" class="row mb-4 bg-light p-3 rounded">
                        <div class="col-md-3">
                            <label for="filter_type"><strong>نوع الفلتر:</strong></label>
                            <select name="filter_type" id="filter_type" class="form-control" onchange="toggleFilterInputs()">
                                <option value="date_range" <?= (!isset($_GET['days']) || $_GET['days'] == 0) ? 'selected' : '' ?>>نطاق تاريخ</option>
                                <option value="days" <?= (isset($_GET['days']) && $_GET['days'] > 0) ? 'selected' : '' ?>>عدد أيام</option>
                            </select>
                        </div>

                        <div class="col-md-2" id="date_inputs" style="<?= (isset($_GET['days']) && $_GET['days'] > 0) ? 'display:none;' : '' ?>">
                            <label>من تاريخ:</label>
                            <input type="date" name="from" class="form-control" value="<?= $from_date ?>">
                        </div>
                        <div class="col-md-2" id="date_inputs2" style="<?= (isset($_GET['days']) && $_GET['days'] > 0) ? 'display:none;' : '' ?>">
                            <label>إلى تاريخ:</label>
                            <input type="date" name="to" class="form-control" value="<?= $to_date ?>">
                        </div>

                        <div class="col-md-2" id="days_input" style="<?= (!isset($_GET['days']) || $_GET['days'] == 0) ? 'display:none;' : '' ?>">
                            <label>عدد الأيام:</label>
                            <input type="number" name="days" class="form-control" value="<?= $days_period ?>" min="1" max="365">
                        </div>

                        <div class="col-md-2">
                            <label>أقل من عدد القطع:</label>  <!-- New label -->
                            <input type="number" name="max_qty" class="form-control" value="<?= $max_qty_filter ?? '' ?>" min="0">  <!-- New input -->
                        </div>

                        <div class="col-md-3">
                            <label>فئة الصنف:</label>
                            <select name="category" class="form-control">
                                <option value="0">جميع الفئات</option>
                                <?php
                                $cat_query = $conn->query("SELECT * FROM item_group WHERE isdeleted = 0 ORDER BY gname");
                                while ($cat_row = $cat_query->fetch_assoc()) {
                                    $selected = ($category_filter == $cat_row['id']) ? 'selected' : '';
                                    echo "<option value='{$cat_row['id']}' $selected>{$cat_row['gname']}</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label style="visibility: hidden;">عرض</label>
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-filter"></i> فلتر
                            </button>
                        </div>
                    </form>

                    <!-- إحصائيات سريعة -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <div class="alert alert-secondary">
                                <strong>فترة التقرير:</strong>
                                من <?= date('d/m/Y', strtotime($from_date)) ?>
                                إلى <?= date('d/m/Y', strtotime($to_date)) ?>
                                <span class="badge badge-info ml-2"><?= $days_period ?> يوم</span>
                            </div>
                        </div>
                    </div>

                    <!-- جدول البيانات -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="stagnantTable" data-page-length="50">
                            <thead class="thead-dark">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th class="text-center">كود الصنف</th>
                                    <th class="text-center">اسم الصنف</th>
                                    <th class="text-center">الفئة</th>
                                    <th class="text-center">الكمية المتاحة</th>
                                    <th class="text-center">سعر البيع</th>
                                    <th class="text-center">قيمة المخزون</th>
                                    <th class="text-center">آخر عملية بيع</th>
                                    <th class="text-center">أيام الركود</th>
                                    <th class="text-center">الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $x = 0;
                                $total_stagnant_value = 0;
                                $total_stagnant_qty = 0;

                                // بناء شرط الفئة
                                $category_condition = "";
                                if ($category_filter > 0) {
                                    $category_condition = "AND mi.group1 = $category_filter";
                                }

                                // بناء شرط الحد الأقصى للكمية
                                $max_qty_condition = "";
                                if ($max_qty_filter !== null && $max_qty_filter >= 0) {
                                    $max_qty_condition = "AND mi.itmqty <= $max_qty_filter";
                                }


                                // الاستعلام الرئيسي للأصناف الراكدة
                                $stagnant_query = "
                                    SELECT
                                        mi.id,
                                        mi.code,
                                        mi.iname,
                                        mi.itmqty,
                                        mi.price1,
                                        mi.cost_price,
                                        mi.group1,
                                        ig.gname as category_name,
                                        (
                                            SELECT MAX(fd.crtime)
                                            FROM fat_details fd
                                            WHERE fd.item_id = mi.id
                                            AND fd.isdeleted = 0
                                            AND (fd.fat_tybe = 3 OR fd.fat_tybe = 9)
                                        ) as last_sale_date,
                                        (
                                            SELECT COUNT(*)
                                            FROM fat_details fd
                                            WHERE fd.item_id = mi.id
                                            AND fd.isdeleted = 0
                                            AND (fd.fat_tybe = 3 OR fd.fat_tybe = 9)
                                            AND fd.crtime BETWEEN '$from_date 00:00:00' AND '$to_date 23:59:59'
                                        ) as sales_count
                                    FROM myitems mi
                                    LEFT JOIN item_group ig ON mi.group1 = ig.id
                                    WHERE mi.isdeleted = 0
                                    $category_condition
                                    $max_qty_condition  -- Added condition
                                    HAVING sales_count = 0
                                    ORDER BY mi.itmqty DESC, last_sale_date ASC
                                ";

                                $result = $conn->query($stagnant_query);
                                if ($result && $result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        $x++;

                                        // حساب قيمة المخزون
                                        $stock_value = $row['itmqty'] * $row['cost_price'];
                                        $total_stagnant_value += $stock_value;
                                        $total_stagnant_qty += $row['itmqty'];

                                        // حساب أيام الركود
                                        $days_stagnant = 0;
                                        $status_class = "badge-warning";
                                        $status_text = "راكد";

                                        if ($row['last_sale_date']) {
                                            $last_sale = new DateTime($row['last_sale_date']);
                                            $today = new DateTime();
                                            $days_stagnant = $today->diff($last_sale)->days;

                                            if ($days_stagnant > 90) {
                                                $status_class = "badge-danger";
                                                $status_text = "راكد جداً";
                                            } elseif ($days_stagnant > 60) {
                                                $status_class = "badge-warning";
                                                $status_text = "راكد";
                                            } else {
                                                $status_class = "badge-info";
                                                $status_text = "بطيء";
                                            }
                                        } else {
                                            $status_class = "badge-dark";
                                            $status_text = "لم يُباع مطلقاً";
                                            $days_stagnant = "∞";
                                        }
                                ?>
                                        <tr>
                                            <td class="text-center"><?= $x ?></td>
                                            <td class="text-center">
                                                <code><?= htmlspecialchars($row['code']) ?></code>
                                            </td>
                                            <td>
                                                <a href="item_summery.php?id=<?= $row['id'] ?>" class="btn btn-link btn-sm">
                                                    <?= htmlspecialchars($row['iname']) ?>
                                                </a>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-light">
                                                    <?= htmlspecialchars($row['category_name'] ?: 'غير محدد') ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $row['itmqty'] > 0 ? 'badge-success' : 'badge-secondary' ?>">
                                                    <?= number_format($row['itmqty'], 0) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?= number_format($row['price1'], 2) ?> ج
                                            </td>
                                            <td class="text-center">
                                                <strong><?= number_format($stock_value, 2) ?> ج</strong>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($row['last_sale_date']): ?>
                                                    <small><?= date('d/m/Y', strtotime($row['last_sale_date'])) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">لا يوجد</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-secondary">
                                                    <?= $days_stagnant === "∞" ? $days_stagnant : $days_stagnant . " يوم" ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $status_class ?>"><?= $status_text ?></span>
                                            </td>
                                        </tr>
                                <?php
                                    }
                                } else {
                                    echo "<tr><td colspan='10' class='text-center text-muted'>لا توجد أصناف راكدة في هذه الفترة</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- إحصائيات الملخص -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h5 class="card-title">ملخص الأصناف الراكدة</h5>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="info-box bg-warning">
                                                <span class="info-box-icon"><i class="fas fa-boxes"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">عدد الأصناف الراكدة</span>
                                                    <span class="info-box-number"><?= $x ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-box bg-info">
                                                <span class="info-box-icon"><i class="fas fa-cubes"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">إجمالي الكميات</span>
                                                    <span class="info-box-number"><?= number_format($total_stagnant_qty, 0) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-box bg-danger">
                                                <span class="info-box-icon"><i class="fas fa-money-bill"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">قيمة المخزون الراكد</span>
                                                    <span class="info-box-number"><?= number_format($total_stagnant_value, 2) ?> ج</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-box bg-success">
                                                <span class="info-box-icon"><i class="fas fa-percentage"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">فترة التقرير</span>
                                                    <span class="info-box-number"><?= $days_period ?> يوم</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer">
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i>
                        تم إنشاء هذا التقرير في <?= date('d/m/Y H:i:s') ?> بواسطة <?= $_SESSION['username'] ?? 'المستخدم' ?>
                    </small>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
function toggleFilterInputs() {
    const filterType = document.getElementById('filter_type').value;
    const dateInputs1 = document.getElementById('date_inputs');
    const dateInputs2 = document.getElementById('date_inputs2');
    const daysInput = document.getElementById('days_input');

    if (filterType === 'date_range') {
        dateInputs1.style.display = 'block';
        dateInputs2.style.display = 'block';
        daysInput.style.display = 'none';
    } else {
        dateInputs1.style.display = 'none';
        dateInputs2.style.display = 'none';
        daysInput.style.display = 'block';
    }
}

// DataTable initialization
$(document).ready(function() {
    $('#stagnantTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Arabic.json"
        },
        "pageLength": 50,
        "order": [[ 8, "desc" ]], // ترتيب حسب أيام الركود
        "columnDefs": [
            { "orderable": false, "targets": 0 }
        ]
    });
});
</script>

<?php include('includes/footer.php'); ?>