<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">

      <div class="card card-primary col-md-4">
        <div class="card-header">
            <h3>اضافة معدل للآداء</h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form role="form" action="do/doadd_kbi.php" method="post">
          <div class="card-body">
            <div class="form-group">
              <label for="">الاسم</label>
              <input name="kname" type="text" autofocus class="form-control " placeholder="اكتب معدل الآداء">
            </div>
            <div class="form-group">
            <div class="form-group">
              <label for="">النوع</label>
              <input name="ktybe" type="text" autofocus class="form-control " placeholder="اكتب معدل النوع">
            </div>
            <div class="form-group">
              <label for="">الوصف</label>

              <textarea class="form-control " name="info" id="" cols="20" rows="5"></textarea>
            </div>

          </div>
          <!-- /.card-body -->

          <div class="card-footer">
            <button type="submit" class="btn btn-primary btn-block"><?= $lang_publicconfirm ?></button>
          </div>
        </form>
      </div>
    </div>
    <!-- Content Header (Page header) -->
  </section>
</div>



<?php include('includes/footer.php') ?>