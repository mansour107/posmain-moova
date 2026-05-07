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
          <h1><?= $lang_employeeslist ?></h1>
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
            <h3 class="card-title"><a href="add_employee.php" class="btn btn-large btn-primary"> <?= $lang_add_new ?> </a></h3>
          </div>
          <!-- /.card-header -->
          <div class="card-body table-responsive">
            <table id="myTable" class="table table-bordered table-hover">
              <thead>
                <tr>
                  <th>م</th>
                  <th>id</th>
                  <th><?= $lang_publicname ?></th>
                  <th><?= $lang_publicjob ?></th>
                  <th><?= $lang_pbholder_phone ?></th>
                  <th>KBI</th>
                  <th><?= $lang_addemployee_jobdepart ?></th>
                  <th><?= $lang_addemployee_shift ?></th>
                  <th><?= $lang_addemployee_salary ?></th>
                  <th><?= $lang_publicinfo ?></th>
                  <th><?= $lang_publicoperations ?></th>
                </tr>
              </thead>
             
                <tbody >
                <?php
              $sql = "SELECT * FROM `employees`  WHERE `isdeleted` != 1 order by id desc";
              $res = $conn->query($sql);
              $x = 0;
              while ($row = $res->fetch_assoc()) {
                $x++;
              ?>
                  <tr>
                    <td><?php echo $x ?></td>
                    <td><?= $row['id']?></td>
                    <td class=""><a class='btn btn-outline-dark btn-edged font-thin' href="emprofile.php?id=<?= $row['id'] ?>"><?= $row['name'] ?></a></td>
                    <td><?php 
                    echo ($rowjop = $conn->query("SELECT name FROM jops WHERE id = '".$row['jop']."'")->fetch_assoc())
                     ? $rowjop['name'] 
                     : 'null';
                     ?></td>
                    <td><?= $row['number'] ?></td>
                        <td><?php
                          $emp_id = $row['id'];
                            $sumkbi = $conn->query("SELECT SUM(kbi_sum) AS total_kbi FROM emp_kbis WHERE emp_id = $emp_id")->fetch_assoc();
                            echo $sumkbi['total_kbi'];
                          ?></td>

                        <td>
                          <?php
                          $depid = $row['department'];
                          $rowdep = $conn->query("select * from departments where id = '$depid' ")->fetch_assoc();
                          if ($rowdep) {
                              echo $rowdep['name'];
                            }
                            ?></td>
                        <td><?php
                          $shiftid = $row['shift']; 
                          $rowshift = $conn->query("select * from shifts where id = '$shiftid' ")->fetch_assoc();
                          if ($rowshift['id'] > 0) {
                              echo $rowshift['name'];
                              ?></td>
                        <td><?= $row['salary'] ?></td>
                          <td><?= $row['info'] ?></td>
                    <td><a class="btn btn-warning" href="edit_employee.php?id=<?= $row['id'] ?>"><i class="fa fa-pen"></i></a>
                      <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#delemp<?= $row['id']?>"><i class="fa fa-trash"></i></button>

                      
            <div class="modal fade" id="delemp<?= $row['id']?>">
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
                                   <p> هل تريد بالتأكيد الحذف <?= $row['id']?> </p>
                                  </div>
                                  <div class="modal-footer justify-content-between">
                                   <button type="button" class="btn btn-outline-light" data-dismiss="modal">الغاء</button>
                                     <a href="do/dodel_employee.php?id=<?= $row['id'] ?>"class="btn btn-outline-light">حذف</a>
                               </div>

                          </div>
                          <!-- /.modal-content -->
                        </div>
                        <!-- /.modal-dialog -->
                      </div>

                    </td>
                  </tr>
                <?php }} ?>
                </tbody>
                <tfoot>
                <tr>
                  <th>م</th>
                  <th>id</th>
                  <th><?= $lang_publicname ?></th>
                  <th><?= $lang_publicjob ?></th>
                  <th><?= $lang_pbholder_phone ?></th>
                  <th>KBI</th>
                  <th><?= $lang_addemployee_jobdepart ?></th>
                  <th><?= $lang_addemployee_shift ?></th>
                  <th><?= $lang_addemployee_salary ?></th>
                  <th><?= $lang_publicinfo ?></th>
                  <th><?= $lang_publicoperations ?></th>
                </tr>
                </tfoot>
            </table>



<!-- /.col -->
</div>
</div>
</div>
</div>

</section>

</div>


<?php include('includes/footer.php') ?>