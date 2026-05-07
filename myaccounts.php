<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<?php if (isset($_GET['acc_parent'])) {
    $parent = $_GET['acc_parent'];
}else {
    $parent= 0;
} ?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
        <div class="card card-default">
            <div class="card-header">
                <h3>قائمة <?php 
                if (!isset($_GET['acc_parent'])) {
                    $sqlacc = "SELECT * FROM acc_head ";
                    echo "الحسابات";
                }elseif ($_GET['acc_parent'] == 18) {
                    $sqlacc = "SELECT * FROM acc_head where parent_id = 18 ";
                    echo "الصناديق";
                }else {
                    $sqlacc = "SELECT * FROM acc_head";
                    echo "الحسابات";
                }
                ?>
            <a class="btn btn-success btn-sm float-right" href="add_account.php?parent_id=<?= $parent ?>">اضف جديد</a>    
            </h3>
                
            </div>
            <div class="card-body">
                <div class="table table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>م</th>
                                <th>اسم الحساب</th>
                                <th>رصيد الحساب</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $resacc = $conn->query($sqlacc);
                            $x = 0;
                            while ($rowacc = $resacc->fetch_assoc()) {
                             $x++
                             ?>
                            <tr>
                                <td><?= $x ?></td>
                                <td><?= $rowacc['aname'] ?></td>
                                <td><?= $rowacc['balance']  ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>


    </div>
  </section>
</div>
<?php include('includes/footer.php') ?>

