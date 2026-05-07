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
                    <h1>  الروشته</h1>
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
                        <h3 class="card-title"><a href="add_rese.php" class="btn btn-large btn-primary">    اضافه روشته</a></h3>
                    </div>
                    <!-- /.card-header -->
                    <div class="card-body">
                        <table id="example2" class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>م</th>
                                    <th>اسم المريض</th>
                                    <th>   الدواء</th>
                                    <th>  الاشعه</th>
                                    <th>    التحاليل</th>
                                    
                                    <th> <a href="edit_rese.php" class="btn btn-primary">Edit</a></th>
                                   
                                </tr>
                            </thead>
                       
                                </tbody>
                                <tfoot>
                                    <tr>
                                    <th>م</th>
                                    <th>اسم المريض</th>
                                    <th>   الدواء</th>
                                    <th>  الاشعه</th>
                                    <th>    التحاليل</th>
                                    
                                    <th> <a href="edit_rese.php" class="btn btn-primary">Edit</a></th>
                                       
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