<?php include 'includes/header.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">

            <div class="card">
                <form action="" method="post">
                    <div class="card-header">
                        <h1>المبيعات مجموعات</h1>
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

                $sql = "SELECT 
                            COALESCE(g.id, 0) as group_id,
                            COALESCE(g.gname, 'بدون مجموعة') as group_name,
                            COALESCE(SUM(fd.qty_out), 0) as total_qty,
                            COALESCE(SUM(fd.det_value), 0) as total_sales
                        FROM myitems i
                        LEFT JOIN item_group g ON i.group1 = g.id
                        LEFT JOIN fat_details fd ON fd.item_id = i.id 
                            AND fd.isdeleted = 0 
                            AND (fd.fat_tybe = 9 OR fd.fat_tybe = 3)
                            AND fd.crtime BETWEEN '$from 00:00:00' AND '$to 23:59:59'
                        WHERE i.isdeleted = 0
                        GROUP BY COALESCE(g.id, 0), COALESCE(g.gname, 'بدون مجموعة')
                        HAVING total_qty > 0 OR total_sales > 0
                        ORDER BY total_sales DESC, total_qty DESC";

                $res = $conn->query($sql);

                $data = [];
                $grand_total = 0;
                $grand_qty = 0;

                while ($row = $res->fetch_assoc()) {
                    $data[] = $row;
                    $grand_total += $row['total_sales'];
                    $grand_qty += $row['total_qty'];
                }
                ?>

                <!-- جدول التفاصيل -->
                <div class="card-body">
                    <div class="table">
                        <table id="myTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>المجموعة</th>
                                    <th>الكمية المباعة</th>
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
                                        <td><?= htmlspecialchars($row['group_name']) ?></td>
                                        <td><?= $row['total_qty'] ?></td>
                                        <td><?= number_format($row['total_sales'], 2) ?></td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                            <?php
                            if ($x > 0) {
                                ?>
                                <tfoot>
                                    <tr style="font-weight: bold; background: #f0f0f0;">
                                        <td colspan="2" class="text-center">الإجمالي الكلي</td>
                                        <td><?= $grand_qty ?></td>
                                        <td><?= number_format($grand_total, 2) ?></td>
                                    </tr>
                                </tfoot>
                                <?php
                            }
                            ?>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
