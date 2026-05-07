<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<?php
if (isset($_GET['id'])) {
  $id = $_GET['id'];
  $sqlcv = "SELECT * FROM `cvs` where id = $id";
  $rescv = $conn->query($sqlcv);
  $rowcv = $rescv->fetch_assoc();
}
?>


<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">



      <div class=" card card-warning">
        <div class="card-header">
          <h3 class="card-title">تعديل سيرة ذاتيه </h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form id="validate_form" role="form" action="do/doedit_cv.php?id=<?= $rowcv['id']?>" method="POST">


          <div class="card-body">


            <div class="row">


              <div class="col">

                <div class="form-group">
                  <label for="name"> <?= $lang_addhicont_name ?></label>
                  <input name="name" data-parsley-trigger="keyup" required id="name" type="text" class="form-control" value="<?= $rowcv['name'] ?>" placeholder="ادخل الاسم">
                </div>

                <div class="form-group">
                  <label for="jopdescription">الشهاده الجامعيه</label>
                  <input name="jopdescription" type="text" data-parsley-trigger="keyup" required id="jopdescription" class="form-control" rows="5" value="<?= $rowcv['degree'] ?>" placeholder="ادخل الشهاده الجامعيه">
                </div>


                <div class="form-group">
                  <label for="salary">العنوان</label>
                  <input name="salary" data-parsley-trigger="keyup" required type="text" id="salary" class="form-control" value="<?= $rowcv['address'] ?>">
                </div>


              </div>

            

              <div class="col">

                <div class="form-group">
                  <label for="workhours">تاريخ الميلاد </label>
                  <input name="workhours" data-parsley-trigger="keyup" required type="date" id="workhours" class="form-control" value="<?= $rowcv['birthdate'] ?>">
                </div>


                <div class="form-group">
                  <label for="inorderhours">رقم الهاتف</label>
                  <input name="inorderhours" data-parsley-trigger="keyup" required type="number" id="inorderhours" class="form-control" value="<?= $rowcv['phone'] ?>">
                </div>


                <div class="form-group">
                  <label for="workdaysoff">الايميل</label>
                  <input name="workdaysoff" data-parsley-trigger="keyup"  type="email" id="workdaysoff" class="form-control" value="<?= $rowcv['email'] ?>">
                </div>

              </div>

            </div>

            <div class="row">
                <div class="col">
                <div class="form-group">
                  <label for="endcontract">Skills</label>
                  <textarea class="form-control" data-parsley-trigger="keyup" required name="info" id="info" rows="5"><?= $rowcv['skills'] ?></textarea>
                </div>

                </div>
              </div>
            <div class="row">
              <div class="col">
                <div class="form-group">
                  <label for="joprule1">Experience 1</label>
                  <input name="joprule1" data-parsley-trigger="keyup" requiredid="joprule1" type="text" class="form-control" value="<?= $rowcv['exp1'] ?>">
                </div>
                <div class="form-group">
                  <label for="joprule2"> Experience  2</label>
                  <input name="joprule2" id="joprule2" type="text" class="form-control" value="<?= $rowcv['exp2'] ?>">
                </div>
                <div class="form-group">
                  <label for="joprule3"> Experience  3</label>
                  <input name="joprule3" id="joprule3" type="text" class="form-control" value="<?= $rowcv['exp3'] ?>">
                </div>
               
              </div>
            </div>

            <div class="row">

                <div class="col">
                <div class="form-group">
                  <label for="joprule4"> اخر راتب</label>
                  <input name="joprule4" id="joprule4" type="text" class="form-control" value="<?= $rowcv['lastsalary'] ?>">
                </div>
                </div>

                <div class="col">
                <div class="form-group">
                  <label for="joprule4"> File image</label>
                  <input name="joprule4" id="joprule4" type="file" class="form-control" >
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