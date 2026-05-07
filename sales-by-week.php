<?php include 'includes/header.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">

            <div class="card">
                <form action="" method="post">
                    <div class="card-header">
                        <h1>المبيعات أسبوعياً</h1>
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
                    // الافتراضي: الشهر الحالي
                    $from = date('Y-m-01');
                    $to   = date('Y-m-t');

                    // لو المستخدم عمل فلترة
                    if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['from']) && !empty($_POST['to'])) {
                        $from = $_POST['from'];
                        $to   = $_POST['to'];
                    }

                    // استعلام: تجميع المبيعات حسب الأسبوع
                    $sql = "SELECT YEARWEEK(pro_date, 1) as sales_week,
                                   MIN(pro_date) as week_start,
                                   MAX(pro_date) as week_end,
                                   SUM(pro_value) as total_sales
                            FROM ot_head 
                            WHERE (pro_tybe = 9 OR pro_tybe = 3)
                            AND pro_date BETWEEN '$from' AND '$to'
                            GROUP BY sales_week
                            ORDER BY sales_week ASC";

                    $res = $conn->query($sql);

                    $weeks = [];
                    $grand_total = 0;

                    while ($row = $res->fetch_assoc()) {
                        $weeks[] = $row;
                        $grand_total += $row['total_sales'];
                    }

                    $count_weeks = count($weeks);

                    $max_week = $min_week = $avg_week = null;

                    if ($count_weeks > 0) {
                        // نسخة منفصلة عشان نحدد الأكبر والأصغر
                        $sorted_weeks = $weeks;
                        usort($sorted_weeks, function($a, $b) {
                            return $b['total_sales'] <=> $a['total_sales'];
                        });

                        $max_week = $sorted_weeks[0];
                        $min_week = $sorted_weeks[$count_weeks - 1];
                        $avg_week = $grand_total / $count_weeks;
                    }
                    ?>

                    <!-- إحصائيات فوق -->
                    <?php if ($count_weeks > 0): ?>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="alert alert-success">
                                <b>أعلى أسبوع:</b> <?= $max_week['week_start'] ?> → <?= $max_week['week_end'] ?><br>
                                <?= number_format($max_week['total_sales'], 2) ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-danger">
                                <b>أقل أسبوع:</b> <?= $min_week['week_start'] ?> → <?= $min_week['week_end'] ?><br>
                                <?= number_format($min_week['total_sales'], 2) ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-info">
                                <b>متوسط الأسبوع:</b><br>
                                <?= number_format($avg_week, 2) ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-warning">
                                <b>الإجمالي الكلي:</b><br>
                                <?= number_format($grand_total, 2) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- جدول المبيعات -->
                    <div class="table">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>من</th>
                                    <th>إلى</th>
                                    <th>إجمالي المبيعات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $x = 0;
                                foreach ($weeks as $row) {
                                    $x++;
                                    ?>
                                    <tr>
                                        <td><?= $x ?></td>
                                        <td><?= $row['week_start'] ?></td>
                                        <td><?= $row['week_end'] ?></td>
                                        <td><?= number_format($row['total_sales'], 2) ?></td>
                                    </tr>
                                <?php } ?>

                                <?php if ($x > 0): ?>
                                <tr style="font-weight: bold; background: #f0f0f0;">
                                    <td colspan="3" class="text-center">الإجمالي الكلي</td>
                                    <td><?= number_format($grand_total, 2) ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
