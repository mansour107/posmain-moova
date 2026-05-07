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
                    <h1>السيره الذاتيه</h1>
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
                        <h3 class="card-title"><a href="add_cv.php" class="btn btn-large btn-primary"> <?= $lang_add_new ?></a></h3>
                    </div>
                    <!-- /.card-header -->
                    <div class="card-body">
                        <table id="example2" class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>الاسم</th>
                                    <th>المهارات</th>
                                    <th>آخر راتب</th>
                                    <th>اخري</th>
                                    <th>عمليات</th>
                                   
                                </tr>
                            </thead>
                            <tbody>

                            <?php $rescv = $conn->query("SELECT * FROM `cvs` where isdeleted != 1 order by id desc");
                            $x=0;
                            while($rowcv = $rescv->fetch_assoc()){
                                $x++;
                                ?>
                                <tr>
                                    <th>م</th>
                                    <th><?= $rowcv['name'] ?></th>
                                    <th><?= $rowcv['skills'] ?></th>
                                    <th><?= $rowcv['expsalary'] ?></th>
                                    <th>
                                        <?= isset($rowcv['exp1']) ? $rowcv['exp1'] : '' ?> <?= !empty($rowcv['exp1']) ? ',' : '' ?>
                                        <?= isset($rowcv['exp2']) ? $rowcv['exp2'] : '' ?> <?= !empty($rowcv['exp2']) ? ',' : '' ?>
                                        <?= isset($rowcv['exp3']) ? $rowcv['exp3'] : '' ?> <?= !empty($rowcv['exp3']) ? ',' : '' ?>
                                        <?= isset($rowcv['exp4']) ? $rowcv['exp4'] : '' ?> <?= !empty($rowcv['exp4']) ? ',' : '' ?>
                                        <?= isset($rowcv['exp5']) ? $rowcv['exp5'] : '' ?>
                                    </th>
                                    <th>
                                        <a href="edit_cv.php?id=<?= $rowcv['id'] ?>" class="btn btn-warning"><i class="fas fa-pen"></i></a>
                                    </th>
                                    
                                </tr>
                                <?php } ?>
                            </tbody>
                       
                           
                           
                            <tfoot>
                                    <tr>
                                    <th>م</th>
                                    <th>الاسم</th>
                                    <th>المهارات</th>
                                    <th>آخر راتب</th>
                                    <th>اخري</th>
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