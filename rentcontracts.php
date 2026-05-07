<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php
 $t=""; 
if(isset($_GET['del'])){
   

    if ($_GET['del'] == '0') {
        $r= 1;
        $t = '';
        $sql = "SELECT * FROM myrents where isdeleted = 0";
    }
    if ($_GET['del'] == '1') {
        $r = 2;
        $t = 'المنتهية';
        $sql = "SELECT * FROM myrents where isdeleted = 1";

    }
}
?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">

        <div class="card">
            <div class="card-header">
                <h1>العقود <?= $t ?></h1>
            </div>
            <div class="card-body">
                <table id="example" class="table table-striped table-bordered text-center" style="width:100%" >
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم العميل</th>
                        <th>الوحدة الايجارية</th>
                        <th>تاريخ البداية</th>
                        <th>تاريخ النهاية</th>
                        <th>مبلغ العقد</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $x=0;
                    $resrent = $conn->query("$sql");
                    while($rowrent=$resrent->fetch_assoc()){
                        $x++;
                        
                        ?>
                        <tr>
                        <td><?= $x ?></td>
                        <td><?php if($resrent->num_rows>0){
                            $cl = $rowrent['cl_id']; 
                            echo $conn->query("SELECT aname FROM acc_head where id = $cl  ;")->fetch_assoc()['aname'];} ?>
                    </td>
                        <td> <?php if($resrent->num_rows>0){
                            $rent = $rowrent['rent_id']; 
                            echo $conn->query("SELECT aname FROM acc_head where id = $rent  ;")->fetch_assoc()['aname'];} ?></td>
                        <td><?= $rowrent['start_date'] ?></td>
                        <td><?= $rowrent['end_date'] ?></td>
                        <td><?= $rowrent['r_value'] ?></td>
                    </tr>
                    <?php }?>
                </tbody>
                
            
            
            
            </table>
            </div>
        </div>




        </div>
    </section>
</div>
<?php include('includes/footer.php') ?>
