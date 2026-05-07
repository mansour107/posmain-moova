<?php include 'includes/header.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">

            <div class="card">
                <form action="" method="post">
                    <div class="card-header">
                        <h1>المبيعات بالساعات</h1>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="">من:</label>
                                    <input type="date" name="from" 
                                           value="<?= $_POST['from'] ?? '' ?>">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="">إلى:</label>
                                    <input type="date" name="to" 
                                           value="<?= $_POST['to'] ?? '' ?>">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">بحث</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="card-body">
                    <?php
                    // الافتراضي: اليوم الحالي
                    $from = date('Y-m-d');
                    $to   = date('Y-m-d');

                    // لو المستخدم عمل فلترة
                    if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['from']) && !empty($_POST['to'])) {
                        $from = $_POST['from'];
                        $to   = $_POST['to'];
                    }

                    // استعلام: تجميع المبيعات حسب الساعة من crtime
                    $sql = "SELECT HOUR(crtime) as sales_hour,
                                   SUM(pro_value) as total_sales
                            FROM ot_head
                            WHERE (pro_tybe = 9 OR pro_tybe = 3)
                            AND DATE(crtime) BETWEEN '$from' AND '$to'
                            GROUP BY sales_hour
                            ORDER BY sales_hour ASC";

                    $res = $conn->query($sql);

                    // مصفوفة للساعات (0 → 23)
                    $hours = array_fill(0, 24, 0);

                    while ($row = $res->fetch_assoc()) {
                        $hours[(int)$row['sales_hour']] = $row['total_sales'];
                    }

                    $grand_total = array_sum($hours);
                    $avg = $grand_total / 24;

                    // استبعاد الساعات الصفرية عشان نحسب الأعلى والأقل
                    $filtered = array_filter($hours, fn($v) => $v > 0);

                    $max_val = max($filtered);
                    $min_val = min($filtered);

                    $max_hour = array_search($max_val, $hours);
                    $min_hour = array_search($min_val, $hours);
                    ?>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="alert alert-info">
                                <b>متوسط الساعة:</b><br>
                                <?= number_format($avg, 2) ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-warning">
                                <b>الإجمالي الكلي:</b><br>
                                <?= number_format($grand_total, 2) ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-success">
                                <b>أعلى ساعة:</b><br>
                                <?= sprintf("%02d:00", $max_hour) ?> 
                                (<?= number_format($max_val, 2) ?>)
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-danger">
                                <b>أقل ساعة:</b><br>
                                <?= sprintf("%02d:00", $min_hour) ?> 
                                (<?= number_format($min_val, 2) ?>)
                            </div>
                        </div>
                    </div>

                    <div class="table">
                        <table class="table table-bordered text-center">
                            <thead>
                                <tr>
                                    <th>الساعة</th>
                                    <th>إجمالي المبيعات</th>
                                    <th>التصنيف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($h = 0; $h < 24; $h++): 
                                    $val = $hours[$h];
                                    if ($val > $avg) {
                                        $mark = "✅ فوق المتوسط";
                                        $style = "background: #d4edda;";
                                    } elseif ($val < $avg) {
                                        $mark = "❌ تحت المتوسط";
                                        $style = "background: #f8d7da;";
                                    } else {
                                        $mark = "⚖️ يساوي المتوسط";
                                        $style = "background: #fff3cd;";
                                    }
                                ?>
                                    <tr style="<?= $style ?>">
                                        <td><?= sprintf("%02d:00", $h) ?></td>
                                        <td><?= number_format($val, 2) ?></td>
                                        <td><?= $mark ?></td>
                                    </tr>
                                <?php endfor; ?>

                                <tr style="font-weight: bold; background: #f0f0f0;">
                                    <td class="text-center">الإجمالي الكلي</td>
                                    <td colspan="2"><?= number_format($grand_total, 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
