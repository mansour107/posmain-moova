<?php if (!isset($_COOKIE['login'])) {
    header('location:../indexmop.php');
  } ?>

<?php include('includes/headermop.php') ?>
<?php include('includes/connect.php') ?>




<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
      <center>
<div class=" card card-danger">
              <div class="card-header">
                <h3 class="card-title">HRMS NAT  </h3>
              </div>

              <div class="form-group">
                  <?php
                  $empid = $_COOKIE['login'];
                      $resemp =$conn->query("SELECT * FROM `employees` where id = $empid ");
                      $rowemp = $resemp->fetch_assoc(); ?>
                    <label for="exampleInputEmail1">اهلا بك يا :  <?= $rowemp['name'] ?> </label>
                    <p>من فضلك ابصم بصمه واحده فقط</p>
                      <p> يتم الاعتبار بأول بصمه حضور  و آخر بصمه انصراف</p>
                      <p>الالتزام بمواعيد العمل يعتبر من المؤشرات المهمة لأصحاب القرار </p>
                  </div>


              <?php 
              $empid = $_COOKIE['login'];
              $fpdate = date("Y-m-d");
              $sqlfp = "SELECT * FROM attandance where employee = '$empid' AND fpdate =  '$fpdate' ";
              $resfp = $conn->query($sqlfp);
              while ($rowfp = $resfp->fetch_assoc()) {
              ?>
              <p>لقد بصمت اليوم في الساعه (<?= $rowfp['time'] ?>)  بصمه <?php 
              $fptybe = $rowfp['fptybe'];
              echo $fptybe;
              $rowtyb = $conn->query("SELECT * FROM fptybes where id = $fptybe ")->fetch_assoc();
               echo $rowtyb['name'];
               ?></p>
              <?php } ?>



              <form role="form" action="do/doadd_mopfp.php" method="post">
                <div class="card-body">

                  

                  <div class="form-group">
                    <label for="exampleInputEmail1">نوع البصمه</label>
                    <select class="form-control" name="fptybe" id=""> 
                      <?php
                      $restyb =$conn->query("SELECT * FROM `fptybes` order by id ");
                      while ($rowtyb = $restyb->fetch_assoc()) { ?>
                        <option value="<?= $rowtyb['id']?>"><?= $rowtyb['name']?></option>
                    <?php } ?>
                      </select>
                  </div>
                  

                </div>
                <!-- /.card-body -->
                <div class="card-footer">
                  
                  <button style="border-radius: 50%;height:150px;width:150px" type="submit" class="btn  btn-lg  btn-danger">بصمه</button>
                 
                </div>
              </form>
            </div>
            </center>
</div>
</section>
</div>

<?php include('includes/footer.php') ?>


