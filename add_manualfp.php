<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>




<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">

<div class=" card card-primary">
              <div class="card-header"> 
                <h3 class="card-title"><?=$lang_adfp_add?> </h3>
              </div>
              <!-- /.card-header -->
              <!-- form start -->
              <form role="form" action="do/doadd_manualfp.php" method="post">
                <div class="card-body">



                <div class="form-group">
                    <label for="exampleInputEmail1"><?=$lang_adfp_fptype?></label>
                    
                  </div>


                  <div class="form-group col-md-3">
                    <label for="exampleInputEmail1"><?=$lang_adfp_employee?></label>
                    <select class="form-control col-md-4 "  name="employee" id="emp"> 
                      <?php
                      $resemp =$conn->query("SELECT * FROM `employees` where isdeleted != 1 order by name ");
                      while ($rowemp = $resemp->fetch_assoc()) { ?>
                        <option value="<?= $rowemp['id']?>"><?= $rowemp['name']?></option>
                    <?php } ?>
                      </select>
                  </div>

                  <div class="form-group">
                    <label for="exampleInputEmail1"><?=$lang_adfp_fptype?></label>
                    <select class="form-control col-md-1" name="fptybe" id=""> 
                      <?php
                      $restyb =$conn->query("SELECT * FROM `fptybes`  order by id ");
                      while ($rowtyb = $restyb->fetch_assoc()) { ?>
                        <option value="<?= $rowtyb['id']?>"><?= $rowtyb['name']?></option>
                    <?php } ?>
                      </select>
                  </div>
                  
                  <div class="form-group">
                    <label for="exampleInputEmail1"><?=$lang_adfp_day?></label>
                   <input class="form-control col-lg-2" type="date" name="fpdate" id=""> 
                  </div>



                  <div class="form-group">
                    <label for="exampleInputEmail1"> <?=$lang_adfp_time?> </label>
                    <input class="form-control col-lg-2" type="time"    name="fptime" id="">
                  </div>


                </div>
                <!-- /.card-body -->
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary btn-block"><?=$lang_publicconfirm?></button>
                </div>
              </form>
            </div>

</div>
</section>
</div>
<script>
  $(document).ready(function() {
    $('#emp').select2()
    $('#emp').select2('open');
  })
</script>

<?php include('includes/footer.php') ?>


