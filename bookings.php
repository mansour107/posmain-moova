<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
<section class="content-header">
<div class="container-fluid">
    
<div class="card">
    <div class="card-header">
        <h2 class="cake cake-zoomOut">ادارة الكروت الذكية</h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="myTable" class="table table-responsive table-hoverable ">
                <thead>
                    <tr>
                        <th>م</th>
                        <th>اسم العميل</th>
                        <th>رقم الكارت</th>
                        <th>المدفوع</th>
                        <th>نوع التعاقد</th>
                        <th>عدد الجلسات</th>
                        <th>المتبقي</th>
                        <th>حاله الكارت</th>
                        <th>من</th>
                        <th>الي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $x = 0;
                    $and = "";
                    if (isset($_GET['case'])) {
                        if($_GET['case'] == 0){$and = " AND bcase = '0'";}
                        if($_GET['case'] == 1){$and = " AND bcase = '1'";}
                        if($_GET['case'] == 2){$and = " AND bcase = '2'";}
                    }

                    $rescard = $conn->query("SELECT * FROM booking_cards where isdeleted = 0 $and");
                    while($rowcard = $rescard->fetch_assoc()){
                        $x++;
                    ?>
                    <tr>
                        <td><?= $x ?></td>
                        <td><?= $rowcard['client']?></td>
                        <td><button class="btn btn-light"><?= $rowcard['barcode']?></button></td>
                        <td><?= $rowcard['rcost']?></td>
                        <td><?= $rowcard['rtybe']?></td>
                        <td><?= $rowcard['qty']?></td>
                        <td><?= $rowcard['remain']?></td>
                        <td><?= $rowcard['bcase']?></td>
                        <td><?= $rowcard['fromdate']?></td>
                        <td><?= $rowcard['todate']?></td>
                    </tr>
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
