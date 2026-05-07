<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">


        <div class="card">
            <div class="card-header">
            <div class="row">    
            <div class="col-md-3 ">
                <h3>انواع الزيارات</h3>
                </div>
                <div class="col-md-3">


                <button id="addNewElement" type="button" class="btn  btn-success btn-sm hadi-white-flash" data-toggle="modal" data-target="#modal-xl">+</button>
                </div>
   
            </div>




            
<div class="modal fade" id="modal-xl" style="display: none;" aria-hidden="true">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<div class="modal-header">
<h4 class="modal-title">اضافة  نوع زيارة جديدة</h4>
<button type="button" class="close" data-dismiss="modal" aria-label="Close">
<span aria-hidden="true">×</span>
</button>
</div>




<form action="do/doadd_vtybe.php" method="post" id="addItemForm">
<div class="modal-body">

<input name="name" type="text" class="form-control" placeholder="ادخل الاسم">


<input name="value" type="number" class="form-control" placeholder="السعر">


<div class="modal-footer justify-content-between">
<button type="submit" class="btn btn-primary">Save changes</button>
</div>
</div>


</form>
<div>
<p id="msgitem"></p>
</div>
</div>
</div>
</div>





            </div>
            <div class="card-body">
                <div class="table table-responsive table-stribbed">
                    <table class="myTable" id="myTable">

                    <thead>
                        <tr>
                            <th>نوع الزيارة</th>
                            <th>السعر</th>
                            <th>عمليات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $restyb = $conn->query("SELECT * FROM visittybes where isdeleted != 1 order by id");
                        while($rowtyb = $restyb->fetch_assoc()){
                        ?>
                        
                        <tr>
                        <form action="do/doedit_vtybe.php?id=<?=$rowtyb['id'] ?>" method="post">
                            <td><input class="form-control" name="name" type="text" value="<?=$rowtyb['name'] ?>"></td>
                            <td><input class="form-control" name="value" type="text" value="<?=$rowtyb['value'] ?>"></td>
                            <td>
                                <button type="submit" class="btn btn-warning btn-sm">تعديل</button>
                                <a href="do/dodel_vtybe.php?id=<?= $rowtyb['id']?>" class="btn btn-danger btn-sm">حذف</a>

                               
                                
                        </td>
                        </form>
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
