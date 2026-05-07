<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">


 <?php
                if ($role['show_ended_reservation']  == 0 && isset($_GET['c'])){
                            echo  $userErrorMassage; 
                        }else{   
                            ?>

                        


        <div class="card <?php echo $_GET ? "card-warning" : "card-primary" ?>" >
            <div class="card-header">
            <h3 class="card-title">
                            <a href="add_reservation.php" class="btn btn-large bg-sky-300"> اضافة حجز جديد</a></h3>
                        <h1> الحجوزات<?php echo $_GET ? " المنتهية " : "" ?></h1>
            </div>
            <div class="card-body">
            <form action="<?= $_SERVER['PHP_SELF']; ?>" method="post" id='myForm'>
                        <div class="row yyyy">
                            <div class="col-lg-2">
                                <div class="form-group">
                                    <label for="">من</label>
                                    <input type="date" name="startdate" id="" class="form-control" value="<?php echo isset($_POST['startdate']) ? $_POST['startdate'] : date('Y-m-d'); ?>">
                                </div>
                            </div>
                        
                            <div class="col-lg-2">
                                <div class="form-group">
                                    <label for="">الي</label>
                                    <input type="date" name="enddate" id="" class="form-control" value="<?php echo isset($_POST['enddate']) ? $_POST['enddate'] : date('Y-m-d'); ?>">
                                </div>
                          </div>
                    
                          <div class="col-md-2"><button type="submit" class="btn btn-lg btn-secondary">بحث</button>
                                </div>
                                </div>
                        </form>

                        <table id="example2" class="table table-bordered table-hover table-responsive text-center">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>تاريخ</th>
                                    <th>الوقت</th>
                                    <th>اسم المريض</th>
                                    <th>نوع الحجز</th>
                                    <th>المدفوع</th>
                                    <th>المدة</th>
                                    <th> عمليات</th>
                                   
                                </tr>
                            </thead>
                       
                                <tbody>
                                    <?php
                                    $curdate = date("Y-m-d");
                                    if (isset($_POST['startdate'])) {
                                        $startdate = $_POST['startdate'];
                                        $enddate = $_POST['enddate'];
                                        
                                        if ($_GET) {
                                            if ($_GET['c']  = 'end' ){
                                                $sqlrsv = "SELECT * FROM reservations where date >= '$startdate' AND date <= '$enddate' AND duration IS NOT null  order by time ";
                                                }}else{$sqlrsv = "SELECT * FROM reservations where date >= '$startdate' AND date <= '$enddate' AND duration is null  order by time ";}
                                    }else{
                                        if ($_GET) {
                                            if ($_GET['c']  = 'end' ){
                                                
                                        $sqlrsv = "SELECT * FROM reservations where date = '$curdate' AND duration IS NOT null  order by time  ";
                                            }
                                        }else{
                                        $sqlrsv = "SELECT * FROM reservations where date = '$curdate' AND duration is null  order by time  ";    
                                        }
                                    }
                                    $resrsv = $conn->query($sqlrsv);
                                    $x=0;
                                    while ($rowrsv = $resrsv->fetch_assoc()) {
                                    $x++
                                    ?>
                                    <tr>
                                    <td><?= $x ?></td>
                                    <td><?= $rowrsv['date'] ?></td>
                                    <td><?= $rowrsv['time'] ?></td>
                                    <td ><a href="clprofile.php?id=<?= $rowrsv['client'] ?>" class="btn btn-outline-dark btn-block"><?php $clid = $rowrsv['client'];
                                    $rowcl = $conn->query("SELECT * FROM clients WHERE id = '$clid' ")->fetch_assoc();echo $rowcl['name'];  ?></a></td>
                                    <td><?php $vtybeid = $rowrsv['visittybe']; echo $conn->query("SELECT name FROM visittybes WHERE id = $vtybeid")->fetch_assoc()['name'] ?? '';?></td>
                                    <td class="paid"><?= $rowrsv['paid'] ?></td>
                                    <td><?= $rowrsv['duration'] ?> د </td>
                                    <td> <a href="edit_reservation.php?id=<?= $rowrsv['id'] ?>"><div class="btn btn-warning btn-sm">تعديل</div></a>
                                     <a href="do/dodel_reservation.php?id=<?= $rowrsv['id'] ?>"><div class="btn btn-danger btn-sm">حذف</div></a></td>
                                       
                                    </tr>
                                   <?php } ?>
                                </tbody>
                        </table>
     



            </div>

            <div class="card-footer">
                <?php if ($role['show_total_reservation']) {?>
                <div class="bg-slate-800 text-light col-md-1 text-md" id="total" >

                </div>
                <?php }?>



            </div>
        </div>
        <?php } ?>
           </div>
    </section>
</div>
<script>
$(document).ready(function() {
    let total = 0;
    $('#example2 .paid').each(function() {
        total += parseFloat($(this).text()) || 0;
    });
    $('#total').text('Total Paid: ' + total);
});
</script>

<?php include('includes/footer.php') ?>