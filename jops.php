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
          <h1><?= $lang_jobslist ?> </h1>
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
            <h3 class="card-title"><a href="add_jop.php" class="btn btn-large btn-primary"> <?= $lang_add_new ?></a></h3>
          </div>
          <!-- /.card-header -->
          <div class="card-body">
            <table id="example2" class="table table-bordered table-hover">
              <thead>
                <tr class="text-center">
                  <th><?= $lang_seq ?></th>
                  <th><?= $lang_publicname ?></th>
                  <th><?= $lang_publicinfo ?></th>
                  <th><?= $lang_publicoperations ?></th>
                </tr>
              </thead>
              <tbody>
              <?php
              $sql = "SELECT * FROM `jops` WHERE isdeleted != 1 order by id desc";
              $res = $conn->query($sql);

              $x = 0;

              while ($row = $res->fetch_assoc()) {
                $x++;
              ?>
                  <tr class="text-center">
                    <td><?php echo $x ?></td>
                    <td><?= $row['name'] ?></td>
                    <td><?= $row['info'] ?></td>
                    <td>
                      <a class="btn btn-sm btn-warning" href="edit_jop.php?id=<?= $row['id'] ?>">
                        <i class="fas fa-edit"></i>
                      </a>
                      <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#modal-danger<?= $row['id'] ?>">
                        <i class="fas fa-trash"></i>
                      </button>
                      
                      <!-- Delete Modal -->
                      <form action="do/dodel_jop.php" method="POST">
                      <div class="modal fade" id="modal-danger<?= $row['id'] ?>">
                        <div class="modal-dialog">
                          <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                              <h4 class="modal-title"><?= $lang_warning ?></h4>
                              <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                              </button>
                            </div>
                            <div class="modal-body text-right">
                              <p><?= $lang_job_delete_confirm ?></p>
                              <input type="hidden" name="id" value="<?= $row['id'] ?>">
                              <div class="form-group">
                                <label><?= $lang_password ?>:</label>
                                <input type="password" name="password" class="form-control" required>
                              </div>
                            </div>
                            <div class="modal-footer justify-content-between">
                              <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= $lang_cancel ?></button>
                              <button type="submit" class="btn btn-danger"><?= $lang_delete ?></button>
                            </div>
                          </div>
                        </div>
                      </div>
                      </form>
                    </td>
                  </tr>
                <?php } ?>
                </tbody>

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