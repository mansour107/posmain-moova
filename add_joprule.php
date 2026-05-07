<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>
<style>
  .ltr {
    direction: ltr;
    width: 80%;

  }
</style>
<div class="ltr card card-primary">
  <div class="card-header">
    <h3 class="card-title"> <?= $lang_add_joprule ?></h3>
  </div>
  <!-- /.card-header -->
  <!-- form start -->
  <form action="do/doadd_joprule.php" method="post">
    <div class="card-body">
      <div class="form-group">
        <label for="exampleInputEmail1"> <?= $lang_name_rule ?></label>
        <input name="name" type="text" class="form-control" placeholder="<?= $lang_plholder_joprule ?>">
      </div>
      <div class="form-group">
        <label for="exampleInputPassword1"><?= $lang_publicinfo ?></label>

        <textarea class="form-control" name="info" id="" cols="20" rows="5"></textarea>
      </div>

    </div>
    <!-- /.card-body -->

    <div class="card-footer">
      <button type="submit" class="btn btn-primary"><?= $lang_publicconfirm ?></button>
    </div>
  </form>
</div>


<?php include('includes/footer.php') ?>