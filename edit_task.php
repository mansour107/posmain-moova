<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>
<?php

$id = $_GET['id'];

$sqltsk = "SELECT * FROM `tasks` WHERE `id` = $id";
$restsk = $conn->query($sqltsk);
$rowtsk = $restsk->fetch_assoc();
?>



<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">


            <div class=" card card-warning col-md-6">
                <div class="card-header">
                    <h3 class="card-title">تعديل المهمة </h3>
                </div>
                <!-- /.card-header -->
                <!-- form start -->
                <form action="do/do_edittask.php?id=<?= $id ?>" method="POST" enctype='multipart/form-data'>
                    <div class="card-body">
                        <div class="form-group">
                            <input name="name" value="<?= $rowtsk['name'] ?>" type="text" class="form-control" placeholder="العميل ">
                        </div>
                        <div class="form-group">
                            <input name="phone" value="<?= $rowtsk['phone'] ?> " type="text" class="form-control" placeholder="التليفون ">
                        </div>
                        <div class="form-group">



                            <textarea placeholder=" اكتب المهمه هنا  " class=" form-control" name="info" id="" cols="20" rows="5"><?= $rowtsk['info'] ?></textarea>
                        </div>
                    </div>
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
                                        <option <?php if ($rowusr['id'] == $rowtsk['user']) {
                                                    echo " selected ";
                                                } ?> value='<?= $rowusr['id'] ?>'><?= $rowusr['uname'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>


                        <div class="col">
                            <div class="form-group">
                                <label> <?= $lang_type_task ?> </label>
                                <select name='tasktybe' class="custom-select form-control form-control-sm">
                                    <?php
                                    $sqltybe = "select * from tasktybes order by name";
                                    $restybe = $conn->query($sqltybe);
                                    while ($rowtybe = $restybe->fetch_assoc()) { ?>
                                        <option <?php if ($rowtybe['id'] == $rowtsk['tasktybe']) {
                                                    echo " selected ";
                                                } ?> value='<?= $rowtybe['id'] ?>'><?= $rowtybe['name'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                    </div>


                    
    <div class="row">
      <div class="col">
      <div class="form-group">
          <label> تعليق المندوب</label>
          <input value="<?= $rowtsk['emp_comment'] ?>" type="text" id="" class="form-control" name="emp_comment">
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col">
      <div class="form-group">
          <label> تعليق العميل</label>
          <input value="<?= $rowtsk['cl_comment'] ?>" type="text" id="" class="form-control" name="cl_comment">
        </div>
      </div>
    </div>




                    <div class="row">
                        <div class="col">
                            <div class="form-group">
                                <label> <?= $lang__task ?></label>
                                <select name='important' class="custom-select form-control form-control-sm">
                                    <option <?php if ($rowtsk['important'] == 0) {
                                                echo " selected ";
                                            } ?> value='0'> <?= $lang__untask ?></option>
                                    <option <?php if ($rowtsk['important'] == 1) {
                                                echo " selected ";
                                            } ?> value='1'><?= $lang__task ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="col">
                            <div class="form-group">
                                <label> <?= $lang_urgent ?></label>
                                <select name='urgent' class="custom-select form-control form-control-sm">
                                    <option <?php if ($rowtsk['urgent'] == 0) {
                                                echo " selected ";
                                            } ?> value='0'> <?= $lang_unurgent ?></option>
                                    <option <?php if ($rowtsk['urgent'] == 1) {
                                                echo " selected ";
                                            } ?> value='1'><?= $lang_urgent ?></option>
                                </select>
                            </div>
                        </div>

                    </div>
                    <button type="submit" class="form-control btn btn-warning"><?= $lang_publicconfirm ?></button>

                    <div class="card-footer">

                    </div>
                </form>
            </div>
        </div>
    </section>
</div>


<?php include('includes/footer.php') ?>