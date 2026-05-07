<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">


        <div class="card">
            <div class="card-header">
                <h2>KBIs</h2>
                <div class="col-md-2 float-right">
                    <a href="add_kbi.php" class="btn btn-primary hadi-white-flash">اضافة kbi</a>
                </div>
            </div>
            <div class="card-body">
                

                <div class="table-responsive" >
                    <table id="myTable" class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم المعدل</th>
                                <th>الوصف</th>
                                <th>عمليات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $x=0;
                            $reskbi =$conn->query("SELECT * FROM kbis where isdeleted = 0");
                            while ($rowkbi = $reskbi->fetch_assoc()) {
                            $x++;
                            ?>
                            <form action="do/doedit_kbi.php?id=<?= $rowkbi['id']?>" method="post">
                            <tr>
                                <th><?= $x ?></th>
                                <th><input type="text" value="<?= $rowkbi['kname']?>" name="kname" class="form-control"></th>
                                <th><input type="text" value="<?= $rowkbi['info']?>" name="info" class="form-control"></th>
                                <th>
                                    <button type="submit" class="btn btn-sm btn-warning">
                                    <i class="fa fa-pen"></i></button>
                                <a hidden href="do/dodel_kbi.php?id=<?= $rowkbi['id'] ?>" class="btn bg-red-600 text-red-100"><i class="fa fa-trash"></i></a>
                                </th>
                            </tr>
                            </form>
                            <?php }?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>





        </div>
    </section>
</div>
<?php include('includes/footer.php') ?>
