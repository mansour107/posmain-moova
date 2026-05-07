<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>عقود خارجية </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                    </ol>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><a href="add_externalcontract.php" class="btn btn-large btn-primary"> <?= $lang_add_new ?></a></h3>
                    </div>
                    <!-- /.card-header -->
                    <div class="card-body">
                        <table id="example2" class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>اسم الموظف</th>
                                    <th>الوظيفة</th>
                                    <th>المرتب</th>
                                    <th>تفاصيل</th>
                                    <th colspan="2">عمليات</th>
                                </tr>
                            </thead>
                            <?php
                            $sql = "SELECT `id` , `employee` , `jop` , `salary` FROM  `hiringcontracts` WHERE `type` = 2 AND isdeleted is NULL";
                            $sqlemp = "SELECT `name` FROM `employees` WHERE `id` = `employee`.`id`";

                            $res = $conn->query($sql);

                            $x = 0;

                            while ($row = $res->fetch_assoc()) {
                                $x++;
                            ?>
                                <tbody>
                                    <tr>
                                        <th><?php echo $x ?></th>
                                        <th><a href='#'><?php
                                                        $empid = $row['employee'];
                                                        $rowemp = $conn->query("select * from employees where id = $empid")->fetch_assoc();
                                                        echo $rowemp['name'] ?><a></th>
                                        <th><a href='#'><?php
                                                        $jopid = $row['jop'];
                                                        $rowjop = $conn->query("select * from jops where id = $jopid")->fetch_assoc();
                                                        echo $rowjop['name'] ?><a></th>
                                        <th><?= $row['salary'] ?></th>
                                        <th><a href="print/contracta4.php?id=<?= $row['id'] ?>" class="btn btn-secondary text-center">تفاصيل</a></th>
                                        <th><a class="btn btn-warning" href="edit_employee.php?id=<?= $row['id'] ?>"><?= $lang_edit ?></a>
                                            <a href="#" class="btn btn-danger" data-toggle="modal" data-target="#modal-danger">
                                                حذف
                                            </a>

                                            <div class="modal fade" id="modal-danger">
                                                <div class="modal-dialog">
                                                    <div class="modal-content bg-danger">
                                                        <div class="modal-header">
                                                            <h4 class="modal-title">تحذير</h4>
                                                            <a href="#">
                                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                    <span aria-hidden="true">&times;</span>
                                                                </button>
                                                            </a>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>هل تريد بالتأكيد حذف هذه المهمة</p>
                                                        </div>
                                                        <div class="modal-footer justify-content-between">
                                                            <button type="button" class="btn btn-outline-light" data-dismiss="modal">الغاء</button>
                                                            <a href="DO/dodel_contract.php?id=<?= $row['id'] ?>"class="btn btn-outline-light">حذف</a>
                                                           
                                                        </div>
                                                    </div>
                                                    <!-- /.modal-content -->
                                                </div>
                                                <!-- /.modal-dialog -->
                                            </div>

                                        </th>
                                    </tr>
                                <?php } ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th>م</th>
                                        <th>اسم الموظف</th>
                                        <th>الوظيفة</th>
                                        <th>المرتب</th>
                                        <th>تفاصيل</th>
                                        <th>عمليات</th>
                                    </tr>
                                </tfoot>
                        </table>
                    </div>
                    <!-- /.card-body -->
                </div>
                <!-- /.card -->
            </div>
            <!-- /.card-body -->
        </div>
        <!-- /.card -->
</div>
<!-- /.col -->
</div>
<!-- /.row -->
</section>
<!-- /.content -->
</div>


<?php include('includes/footer.php') ?>