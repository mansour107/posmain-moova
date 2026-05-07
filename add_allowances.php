<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>


<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">

         <?php if ($role['add_attandance'] == 1) { ?>

            <form action="do/doadd_allowance.php" method="post">
            <div class="card card-info">
                <div class="card-header">
                    <h5>اضافه بدل او استقطاع</h5>
                </div>
                <div class="card-body">
                        <div class="form-group col-lg-3">
                            <label for="">الاسم</label>
                            <input class="form-control form-control .bg-gradient-dark" type="text" name="name" id="">
                        </div>

                        <div class="form-group col-lg-3">
                            <label for="">النوع</label>
                            <select class="form-control form-control .bg-gradient-dark" name="tybe" id="">
                                <option value="1">بدلات</option>
                                <option value="0">استقطاع</option>
                            </select>
                        </div>

                        <div class="form-group col-lg-3">
                            <label for="">ملاحظات</label>
                            <input class="form-control form-control .bg-gradient-dark" name="info" type="text" name="info" id="">
                        </div>


                </div>
                <div class="card-footer">
                    <button class="btn btn-info btn-block" type="submit"><?= $lang_publicconfirm ?></button>

                </div>


            </div>

            </form>


            <?php }else{ echo $userErrorMassage;} ?>
        </div>
    </section>
</div>



<?php include('includes/footer.php') ?>