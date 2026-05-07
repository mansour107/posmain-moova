<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
    <?php if ($role['show_attandance'] == 1) { ?>

        <div class="card">
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">

          <div class="card-header">
         
          <div class="row">
              <div class="col-md-6"> 
                <h1 class="card-title"> سجل الحضور و الانصراف </h1>
              </div>
              </div>
         
              <div class="row">
                <div class="col-md-1">
                <a href="add_manualfp.php" class="btn btn-block btn-large bg-lime-300 h-16">+</a>
                </div>

                <div class="col-md-2">
                <div class="form-group">  
                <label for="">الاسم :</label>
                  <select required class="form-control" name="employee" id="">
                    <option value="0">كل الموظفين</option>
                <?php
                $sqlemp = "SELECT * FROM employees WHERE isdeleted != 1";
                $resemp = $conn->query($sqlemp);
                
                while ($rowemp = $resemp->fetch_assoc()) {
                ?>
                <option <?php
              if (isset($_POST['employee'])){
                if ($_POST['employee'] == $rowemp['id']) {
                  echo "selected";
                }
              }?> value="<?= $rowemp['id']?>"><?= $rowemp['name'] ?></option>

                <?php } ?>
              </select>
            
            </div>
            </div>


              <div class="col-md-3">
                <div class="form-group">
                  <label for="">من :</label>
                  <input required name="fromdate" class="form-control" placeholder="ابحث في الاسم" type="date"  <?php
                    if (isset($_POST['fromdate']))
                    {echo "value=".$_POST['fromdate'];}?>  >
                </div>
              </div>


              <div class="col-md-3">
                <div class="form-group">
                  <label for="">الي :</label>
                  <input required name="todate" class="form-control" placeholder="ابحث في الاسم" type="date"
                    <?php
                    if (isset($_POST['todate']))
                    {echo "value=".$_POST['todate'];}?>>

                </div>
              </div>
              <div class="col-md-2">
                <button class="btn btn-block btn-large bg-sky-500 text-slate-50 h-16"  type="submit"><i class="nav-icon fa-light fas fa-search"></i></button>
            </div>
                </div>
          </div>
          </form>

          <div class="card-body">

          
            <table id="" class="table-bordered table-hover">
              <thead>
                <tr>
                  <th>م</th>
                  <th>الاسم</th>
                  <th>نوع البصمه</th>
                  <th>التاريخ</th>
                  <th>الوقت</th>
                  <th>عمليات</th>
                </tr>
              </thead>
              
                <tbody>
                <?php
         if (isset($_POST['fromdate'])) {
          $t1  = $_POST['employee'];
          $t2  = $_POST['fromdate'];
          $t3  = $_POST['todate'];
            if ($t1 == 0) {
              $sql = "SELECT * FROM `attandance` WHERE fpdate BETWEEN '$t2' and '$t3' AND isdeleted != 1 ORDER BY fpdate ASC";
          } else {
              $sql = "SELECT * FROM `attandance` WHERE employee = '$t1' AND fpdate BETWEEN '$t2' and '$t3' AND isdeleted != 1 ORDER BY fpdate ASC";
          }
      } else {
          $today = date("Y-m-d");
          $sql = "SELECT * FROM `attandance` WHERE fpdate BETWEEN '$today' and '$today' AND isdeleted != 1 ORDER BY id DESC LIMIT 60";
      }
      
              $res = $conn->query($sql);


              $x = 0;

              while ($row = $res->fetch_assoc()) {
                $x++;
              ?>
                  <tr>
                    <td><?php echo $x ?></td>
                    <td>

                      <?php $empid = $row['employee'];
                      $rowemp = $conn->query("select * from employees where id = $empid")->fetch_assoc();
                      if ($rowemp['id'] > 1) {
                        echo $rowemp['name'];
                      } ?></td>
                    <td><?php $tybeid = $row['fptybe'];
                        $rowtyb = $conn->query("select * from fptybes where id = $tybeid")->fetch_assoc();

                        echo $rowtyb['name'];

                        ?></td>
                    <td><?= $row['fpdate'] ?></td>
                    <td><?= $row['time'] ?></td>
                    <td><a class="btn btn-warning" href="edit_manualfp.php?id=<?= $row['id'] ?>"><?= $lang_edit ?></a><a href="#" class="btn btn-danger " data-toggle="modal" data-target="#modal-danger">
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
                                     <a href="DO/dodel_fp.php?id=<?= $row['id'] ?>"class="btn btn-outline-light">حذف</a>
                               </div>

                          </div>
                          <!-- /.modal-content -->
                        </div>
                        <!-- /.modal-dialog -->
                      </div>
                    </td>

                  </tr>
                <?php } ?>
                </tbody>
                 
            </table>
          </div>
          </div>
        
          
<?php }else{echo $userErrorMassage;}?>

</div>
</section>
</div>
<?php include('includes/footer.php') ?>