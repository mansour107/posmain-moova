<?php include('includes/header.php'); ?>
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
                            <label>نوع الصنف:</label>
                            <select name="category" class="form-control">
                                <option value="">جميع الأصناف</option>
                  
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
                    
                    $dateFilter = "";
                    if ($from && $to) {
                        $dateFilter = "AND crtime BETWEEN '$from 00:00:00' AND '$to 23:59:59'";
                    } elseif ($from) {
                        $dateFilter = "AND crtime >= '$from 00:00:00'";
                    } elseif ($to) {
                        $dateFilter = "AND crtime <= '$to 23:59:59'";
                    }

                    // استعلام لجلب بيانات المبيعات لكل صنف
                    $sql = "SELECT 
                                i.id,
                                i.iname,
                                i.code,
                                i.price1,
                                i.cost_price,
                                i.group1,
                                g.gname as group_name,
                                COALESCE(SUM(fd.qty_out), 0) as total_qty,
                                COALESCE(SUM(fd.det_value), 0) as total_value,
                                COALESCE(SUM(fd.profit), 0) as total_profit
                            FROM myitems i
                            LEFT JOIN item_group g ON i.group1 = g.id
                            LEFT JOIN fat_details fd ON i.id = fd.item_id 
                                AND fd.isdeleted = 0 
                                AND (fd.fat_tybe = 9 OR fd.fat_tybe = 3) 
                                $dateFilter
                            WHERE i.isdeleted = 0
                            GROUP BY i.id, i.iname, i.code, i.price1, i.cost_price, i.group1, g.gname
                            HAVING total_qty > 0
                            ORDER BY total_qty DESC";

                    $result = $conn->query($sql);
                    $products = [];
                    while ($row = $result->fetch_assoc()) {
                        // فلترة حسب نوع الصنف (JavaScript بدلاً من SQL)
                        if (!$category || $row['group1'] == $category) {
                            $products[] = $row;
                        }
                    }

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
                            <label>فلتر سريع حسب التصنيف:</label>
                            <select id="classificationFilter" class="form-control">
                                <option value="">جميع التصنيفات</option>
                                <option value="الأكثر مبيعًا">الأكثر مبيعًا</option>
                                <option value="Cash Cows">Cash Cows</option>
                                <option value="Stars">Stars</option>
                                <option value="Loss Leaders">Loss Leaders</option>
                                <option value="عادي">عادي</option>
                            </select>
                        </div>
                    </div>

                    <!-- جدول التصنيف -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="productsTable" data-page-length="25">
                            <thead class="bg-dark text-white">
                                <tr>
                                    <th>#</th>
                                    <th>اسم الصنف</th>
                                    <th>الكود</th>
                                    <th>نوع الصنف</th>
                                    <th>الكمية المباعة</th>
                                    <th>إجمالي القيمة</th>
                                    <th>إجمالي الربح</th>
                                    <th>التصنيف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter = 1;
                                foreach ($products as $product) {
                                    $category = '';
                                    $badgeClass = '';
                                    
                                    // تحديد التصنيف
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
                                    <td><?= $product['code'] ?></td>
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
                        <h4>توزيع الأصناف حسب التصنيف</h4>
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
            { "orderable": false, "targets": 7 }  // تعطيل ترتيب عمود التصنيف
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

    // فلتر الجدول حسب التصنيف
    document.getElementById('classificationFilter').addEventListener('change', function() {
        const selectedClassification = this.value;
        const table = $('#productsTable').DataTable();
        
        if (selectedClassification === '') {
            table.search('').draw();
        } else {
            table.column(7).search(selectedClassification).draw(); // البحث في عمود التصنيف
        }
    });
    // بيانات التصنيفات
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
                    text: 'توزيع الأصناف حسب التصنيف',
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
