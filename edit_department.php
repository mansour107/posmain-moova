<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php');

$id = $_GET['id'];
$sqldprt = "SELECT * FROM `departments` WHERE `id` = '$id' ";

$rowdprt = $conn->query($sqldprt)->fetch_assoc();

?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">

            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title text-dark"><?= $lang_depart_edit ?></h3>
                </div>
                <!-- /.card-header -->
                <!-- form start -->
                <form role="form" action="do/doedit_department.php?id=<?= $id ?>" method="post">
                    <div class="card-body">
                        <div class="form-group">
                            <label for="exampleInputEmail1"><?= $lang_publicname ?></label>
                            <input value="<?= $rowdprt['name'] ?>" name="name" type="text" class="form-control" placeholder="<?= $lang_depart_depname ?>">
                        </div>
                        <div class="form-group">
                            <label for="exampleInputPassword1"> <?= $lang_publicinfo ?></label>

                            <textarea class="form-control" name="info" id="" cols="20" rows="5">

                                <?= $rowdprt['info'] ?>
                            </textarea>
                        </div>

                    </div>
                    <!-- /.card-body -->

                    <div class="card-footer">
                        <button type="submit" class="btn btn-warning btn-block"><?= $lang_publicconfirm ?></button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Content Header (Page header) -->
    </section>
</div>



<?php include('includes/footer.php') ?>