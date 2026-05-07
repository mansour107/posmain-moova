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
          <h1>قائمة البدلات والإستقطاعات </h1>
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
            <h3 class="card-title"><a href="add_allowances.php" class="btn btn-large btn-primary"> <?= $lang_add_new ?></a></h3>
          </div>
          <!-- /.card-header -->
          <div class="card-body">
            <table id="example2" class="table table-bordered table-hover">
              <thead>
                <tr>
                  <th>م</th>
                  <th><?= $lang_publicname ?></th>
                  <th><?= $lang_publicinfo ?></th>
                  <th>النوع</th>
                  <th><?= $lang_publicoperations ?></th>
                </tr>
              </thead>
              <?php
              $sql = "SELECT * FROM `allowances` WHERE isdeleted is null ";
              $res = $conn->query($sql);

              $x = 0;

              while ($row = $res->fetch_assoc()) {
                $x++;
              ?>
                <tbody>
                  <tr>
                    <th><?php echo $x ?></th>
                    <th><a href='#'><?= $row['name'] ?></a></th>
                    <th><?= $row['info'] ?></th>
                    <th><?= ($row['tybe'] == 0) ? "استقطاع" : "بدل" ?></th>
                    <th><a class="btn btn-warning" href="edit_allowances.php?id=<?= $row['id'] ?>"><?= $lang_edit ?></a>
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
                           <a href="do/dodel_allowances.php?id=<?= $row['id']?>"class="btn btn-outline-light">حذف</a>
                           </div>

                        
                          </div>
                          <!-- /.modal-content -->
                        </div>
                        <!-- /.modal-dialog -->
                      </div>
                  </tr>
                <?php } ?>
                </tbody>
                <tfoot>
                  <tr>
                    <th>م</th>
                    <th><?= $lang_publicname ?></th>
                    <th><? $lang_publicinfo ?></th>
                    <th><?= $lang_publicoperations ?></th>
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