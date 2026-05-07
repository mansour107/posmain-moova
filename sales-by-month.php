<?php include 'includes/header.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">

            <div class="card">
                <form action="" method="post">
                    <div class="card-header">
                        <h1>المبيعات شهرياً</h1>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="">من:</label>
                                    <input type="date" name="from" value="<?= $_POST['from'] ?? '' ?>">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="">إلى:</label>
                                    <input type="date" name="to" value="<?= $_POST['to'] ?? '' ?>">
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
                    <div class="table">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>الشهر</th>
                                    <th>إجمالي المبيعات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $x = 0;

                                // الافتراضي: الشهر الحالي
                                $from = date('Y-m-01');
                                $to   = date('Y-m-t');

                                // لو المستخدم عمل فلترة
                                if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['from']) && !empty($_POST['to'])) {
                                    $from = $_POST['from'];
                                    $to   = $_POST['to'];
                                }

                                // استعلام: تجميع المبيعات حسب الشهر
                                $sql = "SELECT DATE_FORMAT(pro_date, '%Y-%m') as sales_month, 
                                               SUM(pro_value) as total_sales 
                                        FROM ot_head 
                                        WHERE (pro_tybe = 9 OR pro_tybe = 3)
                                        AND pro_date BETWEEN '$from' AND '$to'
                                        GROUP BY sales_month
                                        ORDER BY sales_month ASC";

                                $res = $conn->query($sql);
                                $grand_total = 0;

                                while ($row = $res->fetch_assoc()) {
                                    $x++;
                                    $grand_total += $row['total_sales'];
                                    ?>
                                <tr>
                                    <td><?= $x ?></td>
                                    <td><?= $row['sales_month'] ?></td>
                                    <td><?= number_format($row['total_sales'], 2) ?></td>
                                </tr>
                                <?php
                                }

                                // صف الإجمالي الكلي
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
