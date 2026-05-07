<?php 
include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
?>
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <div class="row">
                    <div class="col"><h4 class="hazaz">المدد الايجارية</h4></div>
                    <div class="col"></div>
                    <div class="col"><button onclick="printTable()" class="btn btn-info btn-lg hadi-white-flash">طباعة</button></div>
                </div>
                <div class="row"></div>
            </div>
            <div class="card-body">

            <div class="table table-responsive" id="myTable_wrapper">
                <table class="table table-bordered table-hover"  id="myTable" data-page-length='50'>
                <thead>
                    <tr>
                    
                        <th class="">م</th>
                        <th class="">اسم الوحده</th>
                        <th class="">اسم العميل</th>
                        <th class="">تاريخ الاستحقاق</th>
                        <th class="">المستحق</th>
                        <th class="">المدفوع</th>
                        
                    </tr>
                </thead>
                <tbody>
                    <?php 
                
                    $x=0;
                    $start = $conn->query("SELECT MIN(start_date) AS start_date1 FROM myrents")->fetch_assoc();
                    $end = $conn->query("SELECT MAX(end_date) AS end_date FROM myrents")->fetch_assoc();
                    $startStr = strtotime($start['start_date1']);
                    $endStr = strtotime($end['end_date']);
                    $period = ($endStr -  $startStr)/ (60*60*24 * 30); 
                    echo "اجمالي المدد الايجارية من ".$start['start_date1'] ."   الي  ". $end['end_date']; 
                    $resins = $conn->query("select * from myinstallments order by ins_date");
                    while ($rowins = $resins->fetch_assoc()) {
                    $x++;
                    ?>


                    <tr class=" <?php if (($rowins['ins_value']) === ($rowins['ins_paid'] )) {
                       echo "bg-secondary";
                    }else{
                        $sql = "SELECT *, (ins_date < NOW()) AS expired FROM myinstallments WHERE id = {$rowins['id']}";
                        $result = $conn->query($sql);
                        $rowexp = $result->fetch_assoc();
                        $class = $rowexp['expired'] ? 'bg-yellow' : '';
                        echo $class;}
                        ?>
                        ">
                        <td><?= $x ?></td>
                        <td>
                        <?php echo $conn->query("SELECT * FROM acc_head where id = {$rowins['rent_id']}")->fetch_assoc()['aname']; ?>
                        </td>
                        <td>
                        <a  style="color:black;" href="add_voucher.php?t=recive&acc=<?= $rowins['cl_id'] ?>&v=<?= $rowins['ins_value'] ?>&ins=<?=$rowins['id']?>">
                        <?php echo $conn->query("SELECT * FROM acc_head where id = {$rowins['cl_id']}")->fetch_assoc()['aname']; ?>
                        </a>
                        </td>
                        <td><?= $rowins['ins_date'] ?></td>
                        <td><?= $rowins['ins_value'] ?></td>
                        <td><?= $rowins['ins_paid'] ?></td>
                        
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




<?php include('includes/footer.php')?>