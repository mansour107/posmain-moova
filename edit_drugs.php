<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php')?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">



      <div class=" card card-warning">
        <div class="card-header">
          <h3 class="card-title">  تعديل دواء   </h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form id="validate_form" role="form" action="" method="POST">


          <div class="card-body">


            <div class="row">


              <div class="col">

                <div class="form-group">
                  <label for="name"> اسم الدواء</label>
                  <input name="name" data-parsley-trigger="keyup" required id="name" type="text" class="form-control" >
                </div>

                <div class="form-group">
                  <label for="skills">الغرض </label>
                  <textarea class="form-control" data-parsley-trigger="keyup" required name="skills" id="info" rows="5"></textarea>
                </div>



              </div>

            

              <div class="col">

                <div class="form-group">
                  <label for="birthdate"> الماده الفعاله </label>
                  <input name="birthdate" data-parsley-trigger="keyup" required type="number" id="birthdate" class="form-control" >
                </div>


            
                <div class="form-group">
                  <label for="skills">الاعراض الجانبيه</label>
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