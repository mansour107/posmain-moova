<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>


<?php $id = $_GET['id'];
$sqlfp = "SELECT * FROM `attandance` WHERE `id` = '$id'";
$rowfp = $conn->query($sqlfp)->fetch_assoc();
?>
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class=" card card-warning">
                <div class="card-header">
                    <h3 class="card-title"><?= $lang_adfp_add ?> </h3>
                </div>
                <!-- /.card-header -->
                <!-- form start -->
                <form role="form" action="do/doedit_fp.php?id=<?= $id ?>" method="post">
                    <div class="card-body">



                        <div class="form-group">
                            <label for="exampleInputEmail1"><?= $lang_adfp_fptype ?></label>

                        </div>


                        <div class="form-group">
                            <label for="exampleInputEmail1"><?= $lang_adfp_employee ?></label>
                            <select class="form-control" name="employee" id="">
                                <?php
                                $resemp = $conn->query("SELECT * FROM `employees` order by name ");

                                while ($rowemp = $resemp->fetch_assoc()) { ?>
                                    <option <?php if ($rowemp['id'] == $rowfp['employee'])  echo "selected"; ?> value="<?= $rowemp['id'] ?>"><?= $rowemp['name'] ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="exampleInputEmail1"><?= $lang_adfp_fptype ?></label>
                            <select class="form-control" name="fptybe" id="">
                                <?php
                                $restyb = $conn->query("SELECT * FROM `fptybes` order by id ");
                                while ($rowtyb = $restyb->fetch_assoc()) { ?>
                                    <option <?php if ($rowtyb['id'] == $rowfp['fptybe']) echo "selected"; ?> value="<?= $rowtyb['id'] ?>"><?= $rowtyb['name'] ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="exampleInputEmail1"><?= $lang_adfp_day ?></label>
                            <input class="form-control" value="<?= $rowfp['fpdate'] ?>" type="date" name="fpdate" id="">
                        </div>



                        <div class="form-group">
                            <label for="exampleInputEmail1"> <?= $lang_adfp_time ?> </label>
                            <input class="form-control" value="<?= $rowfp['time'] ?>" type="time" name="fptime" id="">
                        </div>


                    </div>
                    <!-- /.card-body -->
                    <div class="card-footer">
                        <button type="submit" class="btn btn-warning btn-block"><?= $lang_publicconfirm ?></button>
                    </div>
                </form>
            </div>

        </div>
    </section>
</div>

<?php include('includes/footer.php') ?>