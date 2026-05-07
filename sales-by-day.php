<?php include 'includes/header.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">

            <div class="card">
                <form action="" method="post">
                    <div class="card-header">
                        <h1>المبيعات أيام</h1>
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

                <?php
                // الافتراضي: اليوم الحالي
                $from = date('Y-m-d');
                $to   = date('Y-m-d');

                if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['from']) && !empty($_POST['to'])) {
                    $from = $_POST['from'];
                    $to   = $_POST['to'];
                }

                // استعلام التجميع اليومي
                $sql = "SELECT pro_date, SUM(pro_value) as total_sales 
                        FROM ot_head 
                        WHERE (pro_tybe = 9 OR pro_tybe = 3)
                        AND pro_date BETWEEN '$from' AND '$to'
                        GROUP BY pro_date
                        ORDER BY pro_date ASC";

                $res = $conn->query($sql);

                $data = [];
                $grand_total = 0;

                while ($row = $res->fetch_assoc()) {
                    $data[] = $row;
                    $grand_total += $row['total_sales'];
                }

                $count_days = count($data);

                $max_day = $min_day = $avg_day = null;

                if ($count_days > 0) {
                    // ترتيب حسب القيمة
                    usort($data, function($a, $b) {
                        return $b['total_sales'] <=> $a['total_sales'];
                    });

                    $max_day = $data[0];
                    $min_day = $data[$count_days - 1];
                    $avg_day = $grand_total / $count_days;
                }
                ?>

                <?php if ($count_days > 0): ?>
                <!-- كروت التجميع -->
                <div class="row p-3">
                    <div class="col-md-4">
                        <div class="alert alert-success text-center">
                            <h5>أعلى يوم مبيعات</h5>
                            <p><?= $max_day['pro_date'] ?> : <?= number_format($max_day['total_sales'], 2) ?></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-danger text-center">
                            <h5>أقل يوم مبيعات</h5>
                            <p><?= $min_day['pro_date'] ?> : <?= number_format($min_day['total_sales'], 2) ?></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-info text-center">
                            <h5>متوسط المبيعات اليومية</h5>
                            <p><?= number_format($avg_day, 2) ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- جدول التفاصيل -->
                <div class="card-body">
                    <div class="table">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>اليوم</th>
                                    <th>إجمالي المبيعات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $x = 0;
                                foreach ($data as $row) {
                                    $x++;
                                    ?>
                                    <tr>
                                        <td><?= $x ?></td>
                                        <td><?= $row['pro_date'] ?></td>
                                        <td><?= number_format($row['total_sales'], 2) ?></td>
                                    </tr>
                                    <?php
                                }

                                if ($x > 0) {
                                    ?>
                                    <tr style="font-weight: bold; background: #f0f0f0;">
                                        <td colspan="2" class="text-center">الإجمالي الكلي</td>
                                        <td><?= number_format($grand_total, 2) ?></td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
