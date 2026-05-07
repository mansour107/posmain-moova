<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php')?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">



      <div class=" card card-primary">
        <div class="card-header">
          <h3 class="card-title">  اضافة دواء   </h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form  action="do/doadd_drug.php" method="POST">


          <div class="card-body">


          

                <div class="form-group">
                  <label for=""> اسم الدواء</label>
                  <input name="name" data-parsley-trigger="keyup" required id="name" type="text" class="form-control" >
                </div>

                <div class="form-group">
                  <label for=""> الماده الفعاله </label>
                  <input name="effectivematerial" data-parsley-trigger="keyup"  type="text" id="" class="form-control" >
                </div>

                <div class="form-group">
                  <label for="">الغرض </label>
                  <textarea class="form-control" data-parsley-trigger="keyup"  name="purpose" id="info" rows="3"></textarea>
                </div>
          
 
            
                <div class="form-group">
                  <label for="">الاعراض الجانبيه</label>
                  <textarea class="form-control" data-parsley-trigger="keyup"  name="sideeffects" id="" rows="3"></textarea>
                </div>

                     
                <div class="form-group">
                  <label for=""> ملاحظات </label>
                  <textarea class="form-control" data-parsley-trigger="keyup"  name="info" id="" rows="3"></textarea>
                </div>

                <input name="user" hidden value="<?= $_SESSION['userid']?>" data-parsley-trigger="keyup"  type="text" id="birthdate" class="form-control" >


              </div>

          
          <!-- /.card-body -->

          <div class=" card-footer">
            <button type="submit" class="btn btn-primary btn-block"><?= $lang_addhicont_confirm ?></button>
            </div>
            </div>
            </div>

        </form>
      
  </section>
</div>

<script>
  $(document).ready(function() {
    $("#validate_form").parsley();
  })
</script>


<?php include('includes/footer.php') ?>