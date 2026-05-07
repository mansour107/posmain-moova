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
          <h1><?= $lang_shifts ?></h1>
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
            <h3 class="card-title">
              <a href="add_shift.php" class="btn btn-primary">
                <i class="fas fa-plus mr-1"></i> <?= $lang_addshift ?>
              </a>
            </h3>
          </div>
          <!-- /.card-header -->
          <div class="card-body">
            <table id="example2" class="table table-bordered table-hover">
              <thead>
                <tr class="text-center">
                  <th style="width: 50px;">م</th>
                  <th><?= $lang_publicname ?></th>
                  <th>أيام العطلة</th>
                  <th style="width: 150px;">العمليات</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if ($conn) {
                  $sqlshft = "SELECT * from shifts order by id desc";
                  $resshft = $conn->query($sqlshft);
                  $x = 0;
                  while ($rowshft = $resshft->fetch_assoc()) {
                    $wd = $rowshft['workingdays'];
                    $days = explode(",", $wd);
                    $x++;
                ?>
                  <tr class="text-center">
                    <td><?= $x ?></td>
                    <td><?= $rowshft['name'] ?></td>
                    <td>
                      <?php
                      $off_days = [];
                      if (!in_array('6', $days)) $off_days[] = $lang_addsh_sat;
                      if (!in_array('7', $days)) $off_days[] = $lang_addsh_sun;
                      if (!in_array('1', $days)) $off_days[] = $lang_addsh_mon;
                      if (!in_array('2', $days)) $off_days[] = $lang_addsh_tue;
                      if (!in_array('3', $days)) $off_days[] = $lang_addsh_wed;
                      if (!in_array('4', $days)) $off_days[] = $lang_addsh_thu;
                      if (!in_array('5', $days)) $off_days[] = $lang_addsh_fri;
                      echo empty($off_days) ? "لا يوجد" : implode(" - ", $off_days);
                      ?>
                    </td>
                    <td>
                      <a href="edit_shift.php?id=<?= $rowshft['id'] ?>" class="btn btn-sm btn-warning">
                        <i class="fas fa-edit"></i>
                      </a>
                      <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#deleteModal<?= $rowshft['id'] ?>">
                        <i class="fas fa-trash"></i>
                      </button>

                      <!-- Delete Modal -->
                      <div class="modal fade" id="deleteModal<?= $rowshft['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                          <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                              <h5 class="modal-title">تأكيد الحذف</h5>
                              <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                              </button>
                            </div>
                            <form action="do/dodel_shift.php?id=<?= $rowshft['id'] ?>" method="POST">
                              <div class="modal-body text-right">
                                <p>هل أنت متأكد من حذف الوردية: <b><?= $rowshft['name'] ?></b>؟</p>
                                <div class="form-group">
                                  <label>كلمة المرور للتأكيد:</label>
                                  <input type="password" name="password" class="form-control" required>
                                </div>
                              </div>
                              <div class="modal-footer justify-content-between">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php 
                  }
                }
                ?>
              </tbody>
            </table>
          </div>
          <!-- /.card-body -->
        </div>
      </div>
    </div>
  </section>
</div>

<?php include('includes/footer.php') ?>