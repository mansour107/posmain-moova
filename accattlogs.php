<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <?php
            $logid = $_GET['id'];
            $sqllog = "SELECT * FROM `attlog` WHERE attdoc = '$logid'";
            $reslog = $conn->query($sqllog);
            $rowlog = $reslog->fetch_assoc();
            if (empty($rowlog)) {
                echo "يبدو انك دخلت هذه الصفحه من المكان الخطأ .. برجاء عدم التلاعب في اللينك";
            } else {
                $empid = $rowlog['employee'];
                $sqlemp = "SELECT * FROM `employees` WHERE id = $empid";
                $resemp = $conn->query($sqlemp);
                $rowemp = $resemp->fetch_assoc();
            ?>
                <div class="card">
                    <div class="card-header">
                        <h1>المعالجه رقم <a href=""><?= $rowlog['attdoc'] ?></a> للموظف : <?= $rowemp['name'] ?></h1>
                        <div class="row">
                            <div class="col">
                                من يوم <?= $rowlog['day']; ?> الي يوم <?php $rowlast = $conn->query("SELECT * FROM `attlog` WHERE attdoc = '$logid' order by id desc limit 1")->fetch_assoc();
                                                                        echo $rowlast['day'] ?>
                            </div>
                   
                    
                            
                        </div>
                    </div>
                
            <?php } ?>
            <div class="card-body">
            <div class="row">
                <div class="col-md-3 bg-slate-900 text-slate-50 text-center">
                    <h2 class="">الانتاجية</h2>
                </div>
            </div>
            <div class="table">
            <table id="" class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th class="w-2">م</th>
                                <th class="w-4">التاريخ</th>
                                <th class="w-2">ع الوحدات</th>
                                <th class="w-2">س الوحدة</th>
                                <th class="w-2">القيمة</th>
                                <th class="w-20">بيان</th>
                                <th class="">ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $empname = $rowemp['name'];
                            $startdate = $rowlog['day'];
                            $enddate = $rowlast['day'];
                            $sql = "SELECT * FROM `productions` where emp_name =  '$empname' AND date >= '$startdate' AND date <= '$enddate' order by date asc";
                            $result = $conn->query($sql);
                            $i = 0;
                            while($row = $result->fetch_assoc()){
                                   $i ++; 
                                   ?>
                            <tr>
                                <td class="p-1 "><?= $i ?></td>
                                <td class="p-1 "><?= $row['date'] ?></td>
                                <td class="p-1 "><?= $row['qty'] ?></td>
                                <td class="p-1 "><?= $row['price'] ?></td>
                                <td class="p-1 "><?= $row['value'] ?></td>
                                <td class="p-1 "><?= $row['info'] ?></td>
                                <td class="p-1 "><?= $row['info2'] ?></td>
                             
                            </tr>
                            <?php }?>
                        </tbody>
                    </table>
            </div>
            </div>



            <div class="card-body">
                <div class="table-responsive">
                    <div class="row">
                        <div class="col-md-3 bg-slate-900 text-slate-50 text-center " >
                            <h2 >الحضور والانصراف</h2>
                        </div>
                    </div>
                    <table class="table table-bordered table-responsive" data-page-length='50' id="" >
                        <thead>
                            <tr>
                                <th>م</th>
                                <th>تاريخ</th>
                                <th>الحاله</th>
                                <th>الشيفت</th>
                                <th>دخول</th>
                                <th>خروج</th>
                                <th>ساعات العمل</th>
                                <th>المستحق</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $x = 1;
                            $sqllog = "SELECT * FROM `attlog` WHERE attdoc = '$logid'";
                            $reslog = $conn->query($sqllog);
                            while ($rowlog = $reslog->fetch_assoc()) { ?>
                                <tr>
                                    <td><?= $x++ ?> -- <?= $rowlog['id'] ?></td>
                                    <td><?= $rowlog['day'] ?></td>
                                    <td><?php
                                        if ($rowlog['statue'] == 0) {
                                            echo "<p class='bg-success'> اجازة </p>";
                                        } elseif ($rowlog['statue'] == 1) {
                                            echo "<p class='bg-danger'> غائب </p>";
                                        } elseif ($rowlog['statue'] == 2) {
                                            echo "<p class=''> حضور </p>";
                                        } ?>
                                    </td>
                                    <td>من : <?= $rowlog['starttime'] ?>الي : <?= $rowlog['endtime'] ?></td>
                                    <td><?= $rowlog['fpin'] ?></td>
                                    <td><?= $rowlog['fpout'] ?></td>
                                    <td class="td7"><?= $rowlog['curhours'] ?></td>
                                    <td class="td8"><?= $rowlog['realdue'] ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th class="sumth7"></th>
                                <th class="sumth8"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            </div>
        </div>
    </section>
</div>

<script>
    $(document).ready(function() {
        var cumulativeSum = 0;
        $('#horsTable tr').each(function() {
            var td7Value = parseFloat($(this).find('.td7').text());
            var td8Value = parseFloat($(this).find('.td8').text());
            if (!isNaN(td7Value) && !isNaN(td8Value)) {
                cumulativeSum += td7Value - td8Value;
                $(this).find('.td6').text(cumulativeSum);
            }
        });
    });
    var sum7 = 0;
    $(".td7").each(function() {
        sum7 += parseFloat($(this).text()) || 0;
    });
    $(".sumth7").text(sum7.toFixed(2));
    var sum8 = 0;
    $(".td8").each(function() {
        sum8 += parseFloat($(this).text()) || 0;
    });
    $(".sumth8").text(sum8);
</script>
<?php include('includes/footer.php') ?>
