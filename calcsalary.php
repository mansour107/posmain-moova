<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
    <?php if ($role['show_attandance'] == 1) { ?>


    <div class="row mb-2">
        <div class="col-sm-6">
          <h1>قائمة دفاتر الحضور </h1>
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
        <div class="row">
            <div class="col"></div>
            <div class="col"></div>
        </div>
            <h3 class="card-title"><a href="add_calcsalary.php" class="btn btn-large btn-primary"> <?= $lang_add_new ?></a></h3>

          </div>
          <!-- /.card-header -->
          <div class="card-body table-responsive">
            
          <table id="example2" class="table table-bordered table-hover">
              <thead class="bg-light text-sm">
                <tr>
                  <th>م</th>
                  <th><?= $lang_publicname ?></th>
                  <th>المده</th>
                  <th> الراتب</th>
                  <th>ايام الحضور</th>
                  <th>أجر اليوم</th>
                  <th>أجر الساعة</th>
                  <th>س ع المستحقه</th> 
                  <th>س ع الفعليه</th>
                  <th>س الاضافي </th> 
                  <th>المستحق</th>
                  <th>الانتاجية</th>
                  
                  <th><?= $lang_publicoperations ?></th>
                </tr>
              </thead>
              <tbody>
              <?php
              $sqldoc = "SELECT * FROM `attdocs` WHERE isdeleted != 1 order by id desc";
              $resdoc = $conn->query($sqldoc);
              $x = 0;
              while ($rowdoc = $resdoc->fetch_assoc()) {
                $x++;
              ?>
                
                  <tr>
                  <td><?= $x ?></td>
                  <td><?php
                $empid =$rowdoc['empid']; 
                  $rowemp = $conn->query("SELECT * FROM employees where id =  '$empid'")->fetch_assoc();
                  $startdate = $rowdoc['fromdate'];
                  $enddate = $rowdoc['todate'];
                  $rowsh = $conn->query("SELECT sum(curhours - defhours) as diffrence FROM attlog where employee =  '$empid' AND curhours > defhours AND day >= '$startdate' AND day <= '$enddate' ")->fetch_assoc();
                  $rowsh1 = $conn->query("SELECT sum(curhours ) - sum( defhours) as diffrence FROM attlog where employee =  '$empid' AND  day >= '$startdate' AND day <= '$enddate' AND statue != 0 ")->fetch_assoc();
                  ?>
                  <a href="accattlogs.php?id=<?= $rowdoc['id']?>">
                  <?php echo  $rowdoc['id']."# " .$rowemp['name'] ?>
                  </a>
                </td>

                  <td class="w-28"><?= 'من ' .$rowdoc['fromdate'] ?><br>
                  <?= 'الي' . $rowdoc['todate'] ?></td>
                  <td><?= round($rowemp['salary'],2)?> </td>
                  <td><?= $rowdoc['workdays']. ' / '.$rowdoc['alldays']?> </td>
                  <td><?= round($rowemp['salary'] / $rowdoc['workdays'] ,2 )?></td>
                  <td><?= round($rowemp['salary'] / $rowdoc['exphours'] ,2 )?> </td>
                  <td><?= $rowdoc['exphours']  ?> </td>
                  <td><?= $rowdoc['accualhours'] . 'h'?> </td>
                  <td><?= round($rowsh['diffrence'] ,2) .' / '. round($rowsh1['diffrence'],2) ?> </td>
                  <td class="bg-sky-100"><?= round($rowdoc['entitle'] ,2) ?></td>
                  <td class="bg-sky-100"><?php
                  $empname = $rowemp['name']; 
                  $rowprod = $conn->query("SELECT sum(value) as prod_val FROM productions where emp_name =  '$empname' AND date >= '$startdate' AND date <= '$enddate' ")->fetch_assoc(); echo $rowprod['prod_val']?></td>
                  <td><a href="do/dodel_attdoc.php?doc=<?= $rowdoc['id']?>"><div class="btn btn-danger">X</div> </a><span title="<?= $rowdoc['info']?>" class="btn text-red-500 bg-slate-200 ">?</span></td>
                  </tr>
                  <?php } ?>

                </tbody>
                
            </table>
          </div>
          <!-- /.card-body -->
        </div>
        <!-- /.card -->
      </div>
    </div>
</div>
<?php }else{echo $userErrorMassage ;} ?>
</div>
</section>
</div>


<?php include('includes/footer.php') ?>