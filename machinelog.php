<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
    <?php if ($role['show_attandance'] == 1) { ?>

        <h2>الاعدادات العامة للنظام</h2>
   

    
    <div class="card card-primary">
        <div class="card-header">
اعدادات النظام

        </div>
        <div class="card-body">
            <a href="importfplog.php"><div class="btn btn-primary">استيراد الملفات</div></a>


        <h2>Employees Table</h2>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Salary</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>John Doe</td>
                    <td>HR</td>
                    <td>$5000</td>
                </tr>
                <tr>
                    <td>2</td>
                    <td>Jane Smith</td>
                    <td>IT</td>
                    <td>$6000</td>
                </tr>
                <!-- Add more rows here -->
            </tbody>
        </table>


        </div>
        <div class="card-footer">
            
        </div>
    </div>  


    
<?php }else{echo $userErrorMassage;} ?>
</div>
</section>
</div>


<?php include('includes/footer.php') ?>