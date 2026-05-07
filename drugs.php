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
                    <h1><?= $lang_drugindex ?></h1>
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
                        <h3 class="card-title"><a href="add_drugs.php" class="btn btn-large btn-primary">    اضافه دواء جديد</a></h3>
                    </div>
                    <!-- /.card-header -->
                    <div class="card-body">
                        <div class="table-responsive">
                        <table id="example2" class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>اسم الدواء</th>
                                    <th>  الماده الفعاله</th>
                                    <th>  الغرض</th>
                                    <th>   الاعراض الجانبيه</th>
                                    <th> عمليات</th>
                                   
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sqldrg= "SELECT * FROM DRUGS order by id desc" ;
                                $resdrg = $conn->query($sqldrg);
                                $x=0;
                                while ($rowdrg = $resdrg->fetch_assoc()) {
                                    $x++

                                ?>
                                <tr>
                                <th>#</th>
                                    <th><?= $rowdrg['name']; ?></th>
                                    <th><?= $rowdrg['effectivematerial']; ?></th>
                                    <th><?= $rowdrg['purpose']; ?></th>                                    
                                    <th><?= $rowdrg['sideeffects']; ?></th>                                    
                                    <th> <a href="edit_drugs.php" class="btn btn-primary">Edit</a></th>
                                                                   </tr>

                                <?php } ?>


                            </tbody>
                       
                                </tbody>
                                <tfoot>
                                    <tr>
                                    <th>م</th>
                                    <th>اسم الدواء</th>
                                    <th>  الماده الفعاله</th>
                                    <th>  الغرض</th>
                                    <th>الاعراض الجانبيه</th>
                                    <th> عمليات</th>
                                       
                                    </tr>
                                </tfoot>
                        </table>
                        </div>
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