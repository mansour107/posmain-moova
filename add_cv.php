<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php');



?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">



      <div class=" card card-primary">
        <div class="card-header">
          <h3 class="card-title">اضافة سيرة ذاتيه </h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form id="validate_form" role="form" action="do/doadd_cv.php" method="POST">


          <div class="card-body">


            <div class="row">


              <div class="col">

                <div class="form-group">
                  <label for="name"> <?= $lang_addhicont_name ?></label>
                  <input name="name" data-parsley-trigger="keyup" required id="name" type="text" class="form-control" >
                </div>

                <div class="form-group">
                  <label for="degree">الشهاده الجامعيه</label>
                  <input name="degree" type="text" data-parsley-trigger="keyup" required id="degree" class="form-control" rows="5">
                </div>


                <div class="form-group">
                  <label for="address">العنوان</label>
                  <input name="address" data-parsley-trigger="keyup" required type="text" id="address" class="form-control" >
                </div>


              </div>

            

              <div class="col">

                <div class="form-group">
                  <label for="birthdate">تاريخ الميلاد </label>
                  <input name="birthdate" data-parsley-trigger="keyup" required type="date" id="birthdate" class="form-control" >
                </div>


                <div class="form-group">
                  <label for="phone">رقم الهاتف</label>
                  <input name="phone" data-parsley-trigger="keyup" required type="number" id="phone" class="form-control" >
                </div>


                <div class="form-group">
                  <label for="email">الايميل</label>
                  <input name="email" data-parsley-trigger="keyup"  type="email" id="email" class="form-control">
                </div>

              </div>

            </div>

            <div class="row">
                <div class="col">
                <div class="form-group">
                  <label for="skills">Skills</label>
                  <textarea class="form-control" data-parsley-trigger="keyup" required name="skills" id="info" rows="5"></textarea>
                </div>

                </div>
              </div>
            <div class="row">
              <div class="col">
                <div class="form-group">
                  <label for="exp1">Experience 1</label>
                  <input name="exp1" data-parsley-trigger="keyup" requiredid="exp1" type="text" class="form-control" >
                </div>
                <div class="form-group">
                  <label for="exp2"> Experience  2</label>
                  <input name="exp2" id="exp2" type="text" class="form-control" >
                </div>
                <div class="form-group">
                  <label for="exp3"> Experience  3</label>
                  <input name="exp3" id="exp3" type="text" class="form-control" >
                </div>
               
              </div>
            </div>

            <div class="row">

                <div class="col">
                <div class="form-group">
                  <label for="salary"> اخر راتب</label>
                  <input name="salary" id="salary" type="text" class="form-control" >
                </div>
                </div>

           </div>
          </div>
          <!-- /.card-body -->

          <div class=" card-footer">
            <button type="submit" class="btn btn-primary btn-block"><?= $lang_addhicont_confirm ?></button>
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