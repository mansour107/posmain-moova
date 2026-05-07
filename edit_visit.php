<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php');



?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">



      <div class=" card card-warning">
        <div class="card-header">
          <h3 class="card-title"> تعديل بيانات الزياره   </h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form id="validate_form" role="form" action="" method="POST">


          <div class="card-body">


            <div class="row">


              <div class="col">

                <div class="form-group">
                  <label for="name"> اسم المريض</label>
                  <input name="name" data-parsley-trigger="keyup" required id="name" type="text" class="form-control" >
                </div>
                </div>
                </div>


                <div class="row">
                 <div class="col">
                <div class="form-group">
                  <label for="degree">الوظيفه</label>
                  <input name="degree" type="text" data-parsley-trigger="keyup" required id="degree" class="form-control" rows="5">
                </div>

              </div>
              </div>

            
              <div class="row">
              <div class="col">

                <div class="form-group">
                  <label for="birthdate"> السن </label>
                  <input name="birthdate" data-parsley-trigger="keyup" required type="number" id="birthdate" class="form-control" >
                </div>
                </div>
                </div>

                <div class="row">
                <div class="col">
                <div class="form-group">
                  <label for="phone">تاريخ الزياره </label>
                  <input name="phone" data-parsley-trigger="keyup" required type="date" id="phone" class="form-control" >
                </div>
              
               

              </div>
              </div>

            </div>

            <div class="row">
                <div class="col">
                <div class="form-group">
                  <label for="skills">الشكوى</label>
                  <textarea class="form-control" data-parsley-trigger="keyup" required name="skills" id="info" rows="5"></textarea>
                </div>

                </div>
              </div>

          </div>
          <!-- /.card-body -->

          <div class=" card-footer">
            <button type="submit" class="btn btn-warning btn-block"><?= $lang_addhicont_confirm ?></button>
          </div>
        </form>
      </div>
    </div>
    <!-- Content Header (Page header) -->
  </section>
</div>

<script>
  $(document).ready(function() {
    $("#validate_form").parsley();
  })
</script>


<?php include('includes/footer.php') ?>