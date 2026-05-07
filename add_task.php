<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>
<?php
if (isset($_GET['id'])) {
$id = $_GET['id'];
$rowcha = $conn->query("SELECT * from tasks where id= '$id' ")->fetch_assoc();
}
?>
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">

<div class=" card card-primary <?php if(isset($_GET['id'])){echo "card-warning";}?>">
  <div class="card-header">
    <h3 class="card-title"><?= $lang_add_task ?> </h3>
  </div>
  <!-- /.card-header -->
  <!-- form start -->
  <form id="myForm" action="do/doadd_task.php" method="post" enctype='multipart/form-data'>
    <div class="card-body">
      <div class="form-group">
        <input list="cllist"  value="<?php if(isset($_GET['id'])){echo $rowcha['name'];}?>" name="name" type="text" class="form-control" placeholder="<?= $lang_pbholder_client ?> " >
        <input value="<?php if(isset($_GET['id'])){echo $rowcha['phone'];}?>"  name="phone" type="text" class="form-control" placeholder="<?= $lang_pbholder_phone ?> " >
      </div>
      <datalist id="cllist">
        <?php
        $resclients = $conn->query("SELECT * FROM clients order by name");
        while ($rowclients = $resclients->fetch_assoc()) {
        ?>
        <option value="<?= $rowclients['name']?>"></option>
        <?php } ?>
      </datalist>
    <!-- /.card-body -->
    <div class="row">
      <div class="col">
        <div class="form-group">
          <label><?= $lang_user ?></label>
          <select name='user' class="custom-select form-control form-control-sm">
            <?php
            $sqlusr = "select * from users ";
            $resusr = $conn->query($sqlusr);
            while ($rowusr = $resusr->fetch_assoc()) { ?>
              <option value='<?= $rowusr['id'] ?>'><?= $rowusr['uname'] ?></option>
            <?php } ?>
          </select>
        </div>
      </div>


      <div class="col">
        <div class="form-group">
          <label> <?= $lang_type_task ?> </label>
          <select name='tasktybe' class="custom-select form-control form-control-sm">
            <?php
            $sqltybe = "select * from tasktybes order by id";
            $restybe = $conn->query($sqltybe);
            while ($rowtybe = $restybe->fetch_assoc()) { ?>
              <option value='<?= $rowtybe['id'] ?>'><?= $rowtybe['name'] ?></option>
            <?php } ?>
          </select>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col">
      <div class="form-group">
          <label><?= $lang_delegate_comment ?></label>
          <input type="text" id="" class="form-control" name="emp_comment">
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col">
      <div class="form-group">
          <label><?= $lang_client_comment ?></label>
          <input type="text" id="" class="form-control" name="cl_comment">
        </div>
      </div>
    </div>




    <div class="row">
      <div class="col">
        <div class="form-group">
          <label> <?= $lang__task ?></label>
          <select name='important' class="custom-select form-control form-control-sm">
            <option value='0'> <?= $lang__untask ?></option>
            <option value='1'><?= $lang__task ?></option>
          </select>
        </div>
      </div>

      <div class="col">
        <div class="form-group">
          <label> <?= $lang_urgent ?></label>
          <select name='urgent' class="custom-select form-control form-control-sm">
            <option value='0'> <?= $lang_unurgent ?></option>
            <option value='1'><?= $lang_urgent ?></option>
          </select>
        </div>
      </div>

    </div>


    
    <div class="form-group">
        <textarea placeholder="<?= $lang_task_placeholder ?>" class="form-control" name="info" id="" cols="20" rows="5"></textarea>
      </div>
    <button type="submit" class="form-control btn btn-primary"><?= $lang_publicconfirm ?></button>

    <div class="card-footer">

    </div>
  </form>
</div>
    </div>
  </section>
</div>
<?php include('includes/footer.php') ?>