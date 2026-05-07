<?php 
include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');?>
<div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <div class="card-title float-left">
                    <h2>
                        الوحدات المنتجه
                    </h2>
                </div>
                <a href="add_production.php" class="btn float-right bg-green-600 text-slate-50">+</a>

            </div>
            <div class="card-body">
                <div class="table">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>م</th>
                                <th>id</th>
                                <th>التاريخ</th>
                                <th>اسم الموظف</th>
                                <th>ع الوحدات</th>
                                <th>س الوحدة</th>
                                <th>القيمة</th>
                                <th>بيان</th>
                                <th>ملاحظات</th>
                                <th><span class="fa fa-pen"></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT * FROM `productions`";
                            $result = $conn->query($sql);
                            $i = 0;
                            while($row = $result->fetch_assoc()){
                                   $i ++; 
                                   ?>
                            <tr>
                                <td class="p-1 "><?= $i ?></td>
                                <td class="p-1 "><?= $row['snd_id'] ?></td>
                                <td class="p-1 "><?= $row['date'] ?></td>
                                <td class="p-1 "><?= $row['emp_name'] ?></td>
                                <td class="p-1 "><?= $row['qty'] ?></td>
                                <td class="p-1 "><?= $row['price'] ?></td>
                                <td class="p-1 "><?= $row['value'] ?></td>
                                <td class="p-1 "><?= $row['info'] ?></td>
                                <td class="p-1 "><?= $row['info2'] ?></td>
                                <td class="p-1 ">
                                    <a href="edit_production.php?edit=<?= $row['snd_id'] ?>" class="btn btn-sm bg-yellow-300"><span class="fa fa-pen "></span></a>
                                </td>
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
<?php include('includes/footer.php');?>