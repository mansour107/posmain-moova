<?php include('includes/header.php'); ?>
<?php require_once __DIR__ . '/classes/Financial/LegacySalesReportService.php'; ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3>تقرير الأصناف الأكثر مبيعًا وتصنيفها</h3>
                </div>
                <div class="card-body">
                    <!-- نموذج الفلترة -->
                    <form method="GET" class="row mb-4">
                        <div class="col-md-3">
                            <label>من تاريخ:</label>
                            <input type="date" name="from" class="form-control" value="<?= $_GET['from'] ?? '' ?>">
                        </div>
                        <div class="col-md-3">
                            <label>إلى تاريخ:</label>
                            <input type="date" name="to" class="form-control" value="<?= $_GET['to'] ?? '' ?>">
                        </div>
                        <div class="col-md-3">
                            <label>التصنيف:</label>
                            <select name="category" class="form-control">
                                <option value="">جميع التصنيفات</option>
                  
                                <?php
                                // جلب أنواع الأصناف من جدول item_group
                                $catResult = $conn->query("SELECT * FROM item_group WHERE isdeleted = 0 ORDER BY gname");
                                while ($cat = $catResult->fetch_assoc()) {
                                    $selected = (isset($_GET['category']) && $_GET['category'] == $cat['id']) ? 'selected' : '';
                                    echo "<option value='{$cat['id']}' $selected>{$cat['gname']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-success btn-block">فلتر</button>
                        </div>
                    </form>

                    <?php
                    // فلترة التاريخ
                    $from = $_GET['from'] ?? null;
                    $to = $_GET['to'] ?? null;
                    $category = $_GET['category'] ?? null;
                    
                    $products = [];
                    $itemTotals = (new LegacySalesReportService())->itemTotals($conn, $from, $to, [
                        'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
                        'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
                    ]);
                    foreach ($itemTotals as $row) {
                        // فلترة حسب نوع الصنف (JavaScript بدلاً من SQL)
                        if ((float) $row['total_qty'] > 0 && (!$category || $row['group1'] == $category)) {
                            $products[] = $row;
                        }
                    }
                    usort($products, static fn(array $a, array $b): int => (float) $b['total_qty'] <=> (float) $a['total_qty']);

                    // تصنيف الأصناف
                    $totalProducts = count($products);
                    $topSellersCount = max(1, ceil($totalProducts * 0.1)); // أعلى 10%
                    $cashCowsCount = max(1, ceil($totalProducts * 0.1)); // أعلى 10%
                    
                    $topSellers = array_slice($products, 0, $topSellersCount);
                    
                    // Cash Cows: أعلى الأصناف في الربح
                    usort($products, function($a, $b) {
                        return $b['total_profit'] <=> $a['total_profit'];
                    });
                    $cashCows = array_slice($products, 0, $cashCowsCount);
                    
                    // Stars: أعلى الأصناف في القيمة
                    usort($products, function($a, $b) {
                        return $b['total_value'] <=> $a['total_value'];
                    });
                    $stars = array_slice($products, 0, $cashCowsCount);
                    
                    // Loss Leaders: أصناف بربح سلبي أو ضعيف
                    $lossLeaders = array_filter($products, function($product) {
                        return $product['total_profit'] < 0 || 
                               ($product['total_profit'] > 0 && $product['total_profit'] < $product['total_value'] * 0.05);
                    });
                    
                    // إعادة ترتيب حسب الكمية
                    usort($products, function($a, $b) {
                        return $b['total_qty'] <=> $a['total_qty'];
                    });
                    ?>

                    <!-- فلتر JavaScript -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label>فلتر سريع حسب فئة الأداء:</label>
                            <select id="classificationFilter" class="form-control">
                                <option value="">جميع فئات الأداء</option>
                                <option value="الأكثر مبيعًا">الأكثر مبيعًا</option>
                                <option value="Cash Cows">Cash Cows</option>
                                <option value="Stars">Stars</option>
                                <option value="Loss Leaders">Loss Leaders</option>
                                <option value="عادي">عادي</option>
                            </select>
                        </div>
                    </div>

                    <!-- جدول الأصناف -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="productsTable" data-page-length="25">
                            <thead class="bg-dark text-white">
                                <tr>
                                    <th>#</th>
                                    <th>اسم الصنف</th>
                                    <th>الباركود</th>
                                    <th>التصنيف</th>
                                    <th>الكمية المباعة</th>
                                    <th>إجمالي القيمة</th>
                                    <th>إجمالي الربح</th>
                                    <th>فئة الأداء</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter = 1;
                                foreach ($products as $product) {
                                    $category = '';
                                    $badgeClass = '';
                                    
                                    // تحديد فئة الأداء
                                    if (in_array($product, $topSellers)) {
                                        $category = 'الأكثر مبيعًا';
                                        $badgeClass = 'badge-success';
                                    } elseif (in_array($product, $cashCows)) {
                                        $category = 'Cash Cows';
                                        $badgeClass = 'badge-primary';
                                    } elseif (in_array($product, $stars)) {
                                        $category = 'Stars';
                                        $badgeClass = 'badge-warning';
                                    } elseif (in_array($product, $lossLeaders)) {
                                        $category = 'Loss Leaders';
                                        $badgeClass = 'badge-danger';
                                    } else {
                                        $category = 'عادي';
                                        $badgeClass = 'badge-secondary';
                                    }
                                ?>
                                <tr data-category="<?= $product['group1'] ?>">
                                    <td><?= $counter++ ?></td>
                                    <td><?= $product['iname'] ?></td>
                                    <td><?= $product['barcode'] ?></td>
                                    <td class="text-center"><?= $product['group_name'] ?? 'غير محدد' ?></td>
                                    <td class="text-center"><?= number_format($product['total_qty'], 2) ?></td>
                                    <td class="text-center"><?= number_format($product['total_value'], 2) ?></td>
                                    <td class="text-center <?= $product['total_profit'] < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= number_format($product['total_profit'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $badgeClass ?>"><?= $category ?></span>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- إحصائيات سريعة -->
                    <div class="row mt-4">
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h5>الأكثر مبيعًا</h5>
                                    <h3><?= count($topSellers) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h5>Cash Cows</h5>
                                    <h3><?= count($cashCows) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h5>Stars</h5>
                                    <h3><?= count($stars) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h5>Loss Leaders</h5>
                                    <h3><?= count($lossLeaders) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- الرسم البياني -->
                    <div class="mt-5">
                        <h4>توزيع الأصناف حسب فئة الأداء</h4>
                        <div class="row">
                            <div class="col-md-6 mx-auto">
                                <div style="height: 200px;">
                                    <canvas id="productsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- JavaScript للفلترة والرسم البياني -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // تهيئة DataTable
    $('#productsTable').DataTable({
        "responsive": true,
        "lengthChange": true,
        "autoWidth": false,
        "pageLength": 25,
        "order": [[ 4, "desc" ]], // ترتيب حسب الكمية المباعة (العمود 4)
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Arabic.json"
        },
        "columnDefs": [
            { "orderable": false, "targets": 0 }, // تعطيل ترتيب عمود #
            { "orderable": false, "targets": 7 }  // تعطيل ترتيب عمود فئة الأداء
        ],
        "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        "initComplete": function() {
            // نقل أدوات التحكم إلى أسفل الجدول
            $('.dataTables_length').appendTo('#productsTable_wrapper .row:last .col-sm-12.col-md-5');
            $('.dataTables_filter').appendTo('#productsTable_wrapper .row:last .col-sm-12.col-md-7');
            $('.dataTables_info').appendTo('#productsTable_wrapper .row:last .col-sm-12.col-md-5');
            $('.dataTables_paginate').appendTo('#productsTable_wrapper .row:last .col-sm-12.col-md-7');
        }
    });

    // فلتر الجدول حسب فئة الأداء
    document.getElementById('classificationFilter').addEventListener('change', function() {
        const selectedClassification = this.value;
        const table = $('#productsTable').DataTable();
        
        if (selectedClassification === '') {
            table.search('').draw();
        } else {
            table.column(7).search(selectedClassification).draw(); // البحث في عمود فئة الأداء
        }
    });
    // بيانات فئات الأداء
    const categoryData = {
        labels: ['الأكثر مبيعًا', 'Cash Cows', 'Stars', 'Loss Leaders'],
        datasets: [{
            label: 'عدد الأصناف',
            data: [
                <?= count($topSellers) ?>,
                <?= count($cashCows) ?>,
                <?= count($stars) ?>,
                <?= count($lossLeaders) ?>
            ],
            backgroundColor: [
                'rgba(40, 167, 69, 0.8)',
                'rgba(0, 123, 255, 0.8)',
                'rgba(255, 193, 7, 0.8)',
                'rgba(220, 53, 69, 0.8)'
            ],
            borderColor: [
                'rgba(40, 167, 69, 1)',
                'rgba(0, 123, 255, 1)',
                'rgba(255, 193, 7, 1)',
                'rgba(220, 53, 69, 1)'
            ],
            borderWidth: 2
        }]
    };

    // إعدادات الرسم البياني
    const config = {
        type: 'doughnut',
        data: categoryData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'right',
                    labels: {
                        padding: 10,
                        font: {
                            size: 10
                        },
                        boxWidth: 12
                    }
                },
                title: {
                    display: true,
                    text: 'توزيع الأصناف حسب فئة الأداء',
                    font: {
                        size: 16
                    }
                }
            }
        }
    };

    // إنشاء الرسم البياني
    const ctx = document.getElementById('productsChart').getContext('2d');
    new Chart(ctx, config);
});
</script>

<?php include('includes/footer.php'); ?>
