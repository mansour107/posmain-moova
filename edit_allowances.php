<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php');

$id = $_GET['id'];
$allsql = "SELECT * FROM allowances WHERE id = '$id'";
$resall = $conn->query($allsql);
$rowall = $resall->fetch_assoc();



?>


<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="card card-warning">
                <div class="card-header">
                    <h5>تعديل بدل او استقطاع</h5>

                </div>
                <form action="do/doedit_allowances.php?id=<?= $id ?>" method="post">
                    <div class="card-body">


                        <div class="form-group">
                            <label for="">الاسم</label>
                            <input value="<?= $rowall['name'] ?>" class="form-control form-control .bg-gradient-dark" type="text" name="name" id="">
                        </div>

                        <div class="form-group">
                            <label for="">النوع</label>
                            <select class="form-control form-control .bg-gradient-dark" name="tybe" id="">
                                <option <?php if ($rowall['tybe'] == 0) echo "selected" ?> value="0">بدلات</option>
                                <option <?php if ($rowall['tybe'] == 1) echo "selected" ?> value="1">استقطاع</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="">ملاحظات</label>
                            <input value="<?= $rowall['info'] ?>" class="form-control form-control .bg-gradient-dark" name="info" type="text" name="info" id="">
                        </div>




                    </div>
                    <div class="card-footer">
                        <button class="btn btn-warning btn-block" type="submit">submit</button>

                    </div>
                </form>

            </div>


        </div>
    </section>
</div>




<?php include('includes/footer.php') ?>