<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
        <div class="card card">
            <div class="card-header">
                <div class="row">
                    <div class="col">
            <div class="h3">قائمه العملاء::            <a href="add_client.php"><div class="btn  btn-primary">جديد</div></a>
</div></div>
            <div class="col">
            </div>
                </div>
            </div>

            <div class="card-body">
            <div class="table-responsive">
            <table class="table table-hover table-stripped ">
                <thead>
                   <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الميلاد</th>
                    <th>الشكوي</th>
                    <th>عمليات</th>
                   </tr>
                </thead>
                <tbody>
                <?php  
                $sqlcl="SELECT * from clients order by name " ;
                $rescl = $conn->query($sqlcl);
                $x = 0;
                while ($rowcl = $rescl->fetch_assoc()) {
                  $x++
                ?>
               <tr>
                    <th><?= $x ?></th>
                    <th><a class="btn btn-dark" href="clprofile.php?id=<?= $rowcl['id'] ?>"><?= $rowcl['name'] ?></a></th>
                    <th><?= $rowcl['dateofbirth'] ?></th>
                    <th><?= $rowcl['diseses'] ?></th>
                    <th>
                        <a href="edit_client.php?id=<?= $rowcl['id'] ?>"><div class="btn btn-warning btn-sm">تعديل</div></a>
                        <a href="do/dodel_client.php?id=<?= $rowcl['id'] ?>"><div class="btn btn-danger btn-sm">حذف</div></a></th>
                   </tr>
                   <?php }?>

                </tbody>
                <tfoot>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الميلاد</th>
                    <th>الشكوي</th>
                    <th>
         
                    عمليات</th>
                   </tr>
                </tfoot>

            </table>
        </div>
            </div>
        </div>
        <?php if (isset($_GET['w'])) {
            if ($_GET['w'] == 'del') {
            
           ?>
        <script>
            alert("لا يمكن الحذف بسبب وجود يعض البيانات المرتبطة .. تأكد من مسح الييانات المرتبطة ثم حاول مرة اخري");
        </script>
        <?php } } ?>
    </section>
</div>

<?php include('includes/footer.php') ?>
