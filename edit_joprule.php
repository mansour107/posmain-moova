<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php');

$id = $_GET['id'];
$sqlrule = "SELECT * FROM `joprules` WHERE `id` = '$id'";
$rowjoprule = $conn->query($sqlrule)->fetch_assoc();


?>
<style>
    .ltr {
        direction: ltr;
        width: 80%;

    }
</style>
<div class="ltr card card-warning">
    <div class="card-header">
        <h3 class="card-title">تعديل الدور الوظيفي</h3>
    </div>
    <!-- /.card-header -->
    <!-- form start -->
    <form action="do/doedit_joprule.php?id=<?= $id ?>" method="post">
        <div class="card-body">
            <div class="form-group">
                <label for="exampleInputEmail1"> <?= $lang_name_rule ?></label>
                <input value="<?= $rowjoprule['name'] ?>" name="name" type="text" class="form-control" placeholder="اكتب اسم الوظيفه">
            </div>
            <div class="form-group">
                <label for="exampleInputPassword1"><?= $lang_publicinfo ?></label>

                <textarea class="form-control" name="info" id="" cols="20" rows="5">
                    <?= $rowjoprule['info'] ?>
                </textarea>
            </div>

        </div>
        <!-- /.card-body -->

        <div class="card-footer">
            <button type="submit" class="btn btn-warning">Submit</button>
        </div>
    </form>
</div>


<?php include('includes/footer.php') ?>