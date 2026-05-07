<?php include('includes/header.php'); ?>
<?php include('includes/sidebar.php'); ?>
<?php include('includes/navbar.php'); ?>

<div class="content-wrapper">
<section class="content-header">
<div class="container-fluid">
<?php 
$id = $_GET['no'];
$hash_id = md5($id);
$hash = $_GET['id'];
$role = $_GET['name'];
if ($hash_id !== $hash) {
    echo '<h1 class="bg-danger">غير مسموح بالتلاعب في اللينك اعلاه</h1>';
}else{
    $rowrol = $conn->query("SELECT * FROM usr_pwrs where id  = $id ")->fetch_assoc();
    if ($rowrol['rollname'] !== $role ) {
        echo '<h1 class="bg-danger">غير مسموح بالتلاعب في الاسم اعلاه</h1>';
    }else{
?>
<form action="do/doedit_role.php?id=<?= $rowrol['id'] ?>" method="post">
<div class="card card-warning">
    <div class="card-header ">
        <div class="row">
        <div class="col-md-10">
            <h3>تعديل دور <?= $rowrol['rollname']?></h3>
        </div>
        <div class="col-md-2">
        </div>
        <button type="submit" class="btn btn-light col-sm-2">حفظ</button>
    </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
        <div class="form-group border">
            <label for="rollname">اسم الدور</label>
            <input type="text" name="rollname" class="form-control form-control-sm" required value="<?= $rowrol['rollname']?>">
        </div></div>
            <div class="col-md-8">
        <div class="form-group border">
            <label for="info">وصف الدور</label>
            <input type="text" name="info" class="form-control form-control-sm" required value="<?= $rowrol['info']?>">
        </div></div>
        </div>
        <div class="row">
            <div class="col-md-6">

            
        <div class="table  table-responsive table-bordered table-hover" >
        <input type="text" id="itmsearch1" class="form-control form-control-sm" placeholder="search">
        <center>
            
            <table id="horsTable1" class="table  ">
                <thead>
                    <tr>
                        
                        <th class="">اسم الصلاحيه</th>
                        <th>عرض</th>
                        <th>جديد</th>
                        <th>تعديل</th>
                        <th>حذف</th>
                        <th>المفضلة</th>
                    </tr>
                    <tr>
                        <td>اختيار الكل <input type="checkbox" name="" id="checkall"></td>
                    </tr>
                </thead>
                <tbody>
                    <tr class="tr1">
                        <td>المستخدمين</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_users" id="" <?php if( $rowrol['show_users'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_users" id="" <?php if( $rowrol['add_users'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_users" id="" <?php if( $rowrol['edit_users'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_users" id="" <?php if( $rowrol['delete_users'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_users" id="" <?php if( $rowrol['is_fav_users'] == 1){echo "checked"; }?>></td>
                    </tr>


                    <tr class="tr1">
                        <td>العملاء</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_clients" id="" <?php if( $rowrol['show_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_clients" id="" <?php if( $rowrol['add_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_clients" id="" <?php if( $rowrol['edit_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_clients" id="" <?php if( $rowrol['delete_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_clients" id="" <?php if( $rowrol['is_fav_clients'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>الموردين</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_suppliers" id="" <?php if( $rowrol['show_suppliers'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_suppliers" id="" <?php if( $rowrol['add_suppliers'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_suppliers" id="" <?php if( $rowrol['edit_suppliers'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_suppliers" id="" <?php if( $rowrol['delete_suppliers'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_suppliers" id="" <?php if( $rowrol['is_fav_suppliers'] == 1){echo "checked"; }?>></td>
                    </tr>


                    <tr class="tr1">
                        <td>الصناديق</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_funds" id="" <?php if( $rowrol['show_funds'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_funds" id="" <?php if( $rowrol['add_funds'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_funds" id="" <?php if( $rowrol['edit_funds'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_funds" id="" <?php if( $rowrol['delete_funds'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_funds" id="" <?php if( $rowrol['is_fav_funds'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>البنوك</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_banks" id="" <?php if( $rowrol['show_banks'] == 1){echo " checked "; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_banks" id="" <?php if( $rowrol['add_banks'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_banks" id="" <?php if( $rowrol['edit_banks'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_banks" id="" <?php if( $rowrol['delete_banks'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_banks" id="" <?php if( $rowrol['is_fav_banks'] == 1){echo "checked"; }?>></td>
                    </tr>
                    
                    <tr class="tr1">
                        <td>المخزون</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_stock" id="" <?php if( $rowrol['show_stock'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_stock" id="" <?php if( $rowrol['add_stock'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_stock" id="" <?php if( $rowrol['edit_stock'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_stock" id="" <?php if( $rowrol['delete_stock'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_stock" id="" <?php if( $rowrol['is_fav_stock'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>المصروفات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_expenses" id="" <?php if( $rowrol['show_expenses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_expenses" id="" <?php if( $rowrol['add_expenses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_expenses" id="" <?php if( $rowrol['edit_expenses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_expenses" id="" <?php if( $rowrol['delete_expenses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_expenses" id="" <?php if( $rowrol['is_fav_expenses'] == 1){echo "checked"; }?>></td>
                    </tr>
                    
                    <tr class="tr1">
                        <td>الايرادات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_revenuses" id="" <?php if( $rowrol['show_revenuses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_revenuses" id="" <?php if( $rowrol['add_revenuses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_revenuses" id="" <?php if( $rowrol['edit_revenuses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_revenuses" id="" <?php if( $rowrol['delete_revenuses'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_revenuses" id="" <?php if( $rowrol['is_fav_revenuses'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>الدائنين</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_credits" id="" <?php if( $rowrol['show_credits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_credits" id="" <?php if( $rowrol['add_credits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_credits" id="" <?php if( $rowrol['edit_credits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_credits" id="" <?php if( $rowrol['delete_credits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_credits" id="" <?php if( $rowrol['is_fav_users'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>المدينين</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_depits" id="" <?php if( $rowrol['show_depits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_depits" id="" <?php if( $rowrol['add_depits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_depits" id="" <?php if( $rowrol['edit_depits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_depits" id="" <?php if( $rowrol['delete_depits'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_depits" id="" <?php if( $rowrol['is_fav_depits'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>الشركاء</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_partners" id="" <?php if( $rowrol['show_partners'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_partners" id="" <?php if( $rowrol['add_partners'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_partners" id="" <?php if( $rowrol['edit_partners'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_partners" id="" <?php if( $rowrol['delete_partners'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_partners" id="" <?php if( $rowrol['is_fav_partners'] == 1){echo "checked"; }?>></td>
                    </tr>


                    <tr class="tr1">
                        <td>الاصول</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_assets" id="" <?php if( $rowrol['show_assets'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_assets" id="" <?php if( $rowrol['add_assets'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_assets" id="" <?php if( $rowrol['edit_assets'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_assets" id="" <?php if( $rowrol['delete_assets'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_assets" id="" <?php if( $rowrol['is_fav_assets'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>الاصول القابلة للتأجير</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_rentables" id="" <?php if( $rowrol['show_rentables'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_rentables" id="" <?php if( $rowrol['add_rentables'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_rentables" id="" <?php if( $rowrol['edit_rentables'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_rentables" id="" <?php if( $rowrol['delete_rentables'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_rentables" id="" <?php if( $rowrol['is_fav_rentables'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>الموظفين</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_employees" id="" <?php if( $rowrol['show_employees'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_employees" id="" <?php if( $rowrol['add_employees'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_employees" id="" <?php if( $rowrol['edit_employees'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_employees" id="" <?php if( $rowrol['delete_employees'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_employees" id="" <?php if( $rowrol['is_fav_employees'] == 1){echo "checked"; }?>></td>
                    </tr>
                    
                    <tr class="tr1">
                        <td>الاصناف</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_items" id="" <?php if( $rowrol['show_items'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_items" id="" <?php if( $rowrol['add_items'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_items" id="" <?php if( $rowrol['edit_items'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_items" id="" <?php if( $rowrol['delete_items'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_items" id="" <?php if( $rowrol['is_fav_items'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>مجموعات الاصناف</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_item_groups" id="" <?php if( $rowrol['show_item_groups'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_item_groups" id="" <?php if( $rowrol['add_item_groups'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_item_groups" id="" <?php if( $rowrol['edit_item_groups'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_item_groups" id="" <?php if( $rowrol['delete_item_groups'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_item_groups" id="" <?php if( $rowrol['is_fav_item_groups'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>المبيعات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_sales" id="" <?php if( $rowrol['show_sales'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_sales" id="" <?php if( $rowrol['add_sales'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_sales" id="" <?php if( $rowrol['edit_sales'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_sales" id="" <?php if( $rowrol['delete_sales'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_sales" id="" <?php if( $rowrol['is_fav_sales'] == 1){echo "checked"; }?>></td>
                    </tr>


                    <tr class="tr1">
                        <td>مردود المبيعات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_resale" id="" <?php if( $rowrol['show_resale'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_resale" id="" <?php if( $rowrol['add_resale'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_resale" id="" <?php if( $rowrol['edit_resale'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_resale" id="" <?php if( $rowrol['delete_resale'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_resale" id="" <?php if( $rowrol['is_fav_resale'] == 1){echo "checked"; }?>></td>
                    </tr>
                    
                    <tr class="tr1">
                        <td>المشتريات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_purchases" id="" <?php if( $rowrol['show_purchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_purchases" id="" <?php if( $rowrol['add_purchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_purchases" id="" <?php if( $rowrol['edit_purchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_purchases" id="" <?php if( $rowrol['delete_purchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_purcases" id="" <?php if( $rowrol['is_fav_purcases'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>مردود المشتريات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_repurchases" id="" <?php if( $rowrol['show_repurchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_repurchases" id="" <?php if( $rowrol['add_repurchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_repurchases" id="" <?php if( $rowrol['edit_repurchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_repurchases" id="" <?php if( $rowrol['delete_repurchases'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_repurchases" id="" <?php if( $rowrol['is_fav_repurchases'] == 1){echo "checked"; }?>></td>
                    </tr>


                    <tr class="tr1">
                        <td>سندات القبض</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_recive" id="" <?php if( $rowrol['show_recive'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_recive" id="" <?php if( $rowrol['add_recive'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_recive" id="" <?php if( $rowrol['edit_recive'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_recive" id="" <?php if( $rowrol['delete_recive'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_recive" id="" <?php if( $rowrol['is_fav_recive'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>سندات الدفع</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_payment" id="" <?php if( $rowrol['show_payment'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_payment" id="" <?php if( $rowrol['add_payment'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_payment" id="" <?php if( $rowrol['edit_payment'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_payment" id="" <?php if( $rowrol['delete_payment'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_payment" id="" <?php if( $rowrol['is_fav_payment'] == 1){echo "checked"; }?>></td>
                    </tr>
                    
         

                    <tr class="tr1">
                        <td>الحجوزات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_reservations" id="" <?php if( $rowrol['show_reservations'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_reservations" id="" <?php if( $rowrol['add_reservations'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_reservations" id="" <?php if( $rowrol['edit_reservations'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_reservations" id="" <?php if( $rowrol['delete_reservations'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_reservations" id="" <?php if( $rowrol['is_fav_reservations'] == 1){echo "checked"; }?>></td>
                    </tr>

                    
                    <tr class="tr1">
                        <td>العملاء متقدم</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_advanced_clients" id="" <?php if( $rowrol['show_advanced_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_advanced_clients" id="" <?php if( $rowrol['add_advanced_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_advanced_clients" id="" <?php if( $rowrol['edit_advanced_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_advanced_clients" id="" <?php if( $rowrol['delete_advanced_clients'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_advanced_clients" id="" <?php if( $rowrol['is_fav_advanced_clients'] == 1){echo "checked"; }?>></td>
                    </tr>
                    
                    <tr class="tr1">
                        <td>الادوية</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_drugs" id="" <?php if( $rowrol['show_drugs'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_drugs" id="" <?php if( $rowrol['add_drugs'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_drugs" id="" <?php if( $rowrol['edit_drugs'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_drugs" id="" <?php if( $rowrol['delete_drugs'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_drugs" id="" <?php if( $rowrol['is_fav_drugs'] == 1){echo "checked"; }?>></td>
                    </tr>

                    

                    
                    <tr class="tr1">
                        <td>بروفايل العميل</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_client_profile" id="" <?php if( $rowrol['show_client_profile'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_client_profile" id="" <?php if( $rowrol['add_client_profile'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_client_profile" id="" <?php if( $rowrol['edit_client_profile'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_client_profile" id="" <?php if( $rowrol['delete_client_profile'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_client_profile" id="" <?php if( $rowrol['is_fav_client_profile'] == 1){echo "checked"; }?>></td>
                    </tr>

                    
                    <tr class="tr1">
                        <td>الفرص</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_chances" id="" <?php if( $rowrol['show_chances'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_chances" id="" <?php if( $rowrol['add_chances'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_chances" id="" <?php if( $rowrol['edit_chances'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_chances" id="" <?php if( $rowrol['delete_chances'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_chances" id="" <?php if( $rowrol['is_fav_chances'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>موديول الحضور و الانصراف</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_attandance" id="" <?php if( $rowrol['show_attandance'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_attandance" id="" <?php if( $rowrol['add_attandance'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_attandance" id="" <?php if( $rowrol['edit_attandance'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_attandance" id="" <?php if( $rowrol['delete_attandance'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_attandance" id="" <?php if( $rowrol['is_fav_attandance'] == 1){echo "checked"; }?>></td>
                    </tr>

                    
                    <tr class="tr1">
                        <td>ادارة المكالمات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_calls" id="" <?php if( $rowrol['show_calls'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_calls" id="" <?php if( $rowrol['add_calls'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_calls" id="" <?php if( $rowrol['edit_calls'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_calls" id="" <?php if( $rowrol['delete_calls'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_calls" id="" <?php if( $rowrol['is_fav_calls'] == 1){echo "checked"; }?>></td>
                    </tr>

                    <tr class="tr1">
                        <td>قيود اليومية</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_journals" id="" <?php if( $rowrol['show_journals'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_journals" id="" <?php if( $rowrol['add_journals'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_journals" id="" <?php if( $rowrol['edit_journals'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_journals" id="" <?php if( $rowrol['delete_journals'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_journals" id="" <?php if( $rowrol['is_fav_journals'] == 1){echo "checked"; }?>></td>
                    </tr>

                    
                    <tr class="tr1">
                        <td>تقارير حساب الاستاذ</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_gl_reports" id="" <?php if( $rowrol['show_gl_reports'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_gl_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_gl_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_gl_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_gl_reports" id="" disabled></td>
                    </tr>

                    
                    <tr class="tr1">
                        <td>تقارير العيادات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_clinic_reports" id="" <?php if( $rowrol['show_clinic_reports'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_clinic_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_clinic_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_clinic_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_clinic_reports" id="" disabled></td>
                    </tr>
                    
                    <tr class="tr1">
                        <td>تقارير التأجير</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_rent_reports" id="" <?php if( $rowrol['show_rent_reports'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_rent_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_rent_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_rent_reports" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_rent_reports" id="" disabled></td>
                    </tr>

                    
                    <tr class="tr1">
                        <td>تقاريرالمرتبات</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_payroll_report" id="" <?php if( $rowrol['show_payroll_report'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_payroll_report" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_payroll_report" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_payroll_report" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_payroll_report" id="" disabled></td>
                    </tr>

                    
                    <tr class="tr1">
                        <td>تقارير الموارد البشرية</td>
                        <td><input type="checkbox" class="user-checkbox" name="show_hr_report" id="" <?php if( $rowrol['show_hr_report'] == 1){echo "checked"; }?>></td>
                        <td><input type="checkbox" class="user-checkbox" name="add_hr_report" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="edit_hr_report" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="delete_hr_report" id="" disabled></td>
                        <td><input type="checkbox" class="user-checkbox" name="is_fav_hr_report" id="" disabled></td>
                    </tr>
                    </tbody>
            </table>
        </div>
        </center>


            </div>
            <div class="col-md-6">
                <div class="table table-responsive">
                    <table id="horsTable1" class="table table-bordered table-responsive table-hover">
                        <thead>
                            <tr>
                                <th>بيان</th>
                                <th>عرض</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="tr1">
                                <td>اظهار قائمة البيانات الاساسية من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_entry"  class="user-checkbox" <?php if( $rowrol['sid_entry'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قائمة المخزون من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_stock"  class="user-checkbox" <?php if( $rowrol['sid_stock'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم المبيعات من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_sales"  class="user-checkbox" <?php if( $rowrol['sid_sales'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم المشتريات من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_purchases"  class="user-checkbox" <?php if( $rowrol['sid_purchases'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار السندات من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_vouchers"  class="user-checkbox" <?php if( $rowrol['sid_vouchers'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم العيادات من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_clinics"  class="user-checkbox" <?php if( $rowrol['sid_clinics'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم ادارة علاقات العملاء من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_crm"  class="user-checkbox" <?php if( $rowrol['sid_crm'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم الحسابات من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_accounts"  class="user-checkbox" <?php if( $rowrol['sid_accounts'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم الاصول من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_assets"  class="user-checkbox" <?php if( $rowrol['sid_assets'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار التقارير من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_reports"  class="user-checkbox" <?php if( $rowrol['sid_reports'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم اداره الموارد البشرية من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_hr"  class="user-checkbox" <?php if( $rowrol['sid_hr'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم المرتبات من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_payroll"  class="user-checkbox" <?php if( $rowrol['sid_payroll'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار قسم التأجير من الجانب الايمن</td>
                                <td><input type="checkbox" name="sid_rents"  class="user-checkbox" <?php if( $rowrol['sid_rents'] == 1){echo "checked"; }?>></td>
                            </tr>

                        </tbody>
                    </table>
                </div>

                <div class="table table-responsive">
                    <table id="horsTable1" class="table table-bordered table-responsive table-hover">
                        <thead>
                            <tr>
                                <th>الخيارات العامة </th>
                                <th>عرض</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="tr1">
                                <td>اظهار الحجوزات المنتهية</td>
                                <td><input type="checkbox"  name="show_ended_reservation"  class="user-checkbox" <?php if( $rowrol['show_ended_reservation'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار اجمالي الزيارات</td>
                                <td><input type="checkbox"  name="show_total_reservation"  class="user-checkbox" <?php if( $rowrol['show_total_reservation'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td> (مكرر)اظهار بيانات المريض  <span title="قد يتم التعارض مع عرض لعملاء" class="text-slate-50  bg-red-500">?</span></td>
                                <td><input type="checkbox"  name="show_client_profile"  class="user-checkbox" <?php if( $rowrol['show_client_profile'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار مهمات كل الاشخاص</td>
                                <td><input type="checkbox"  name="show_all_tasks"  class="user-checkbox" <?php if( $rowrol['show_all_tasks'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار الكروت في الشاشة الرئيسية</td>
                                <td><input type="checkbox"  name="show_main_cards"  class="user-checkbox" <?php if( $rowrol['show_main_cards'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار الاختصارات في الشاشة الرئيسية</td>
                                <td><input type="checkbox"  name="show_main_elements"  class="user-checkbox" <?php if( $rowrol['show_main_elements'] == 1){echo "checked"; }?>></td>
                            </tr>
                            <tr class="tr1">
                                <td>اظهار الجداول في الشاشة الرئيسية</td>
                                <td><input type="checkbox"  name="show_main_tables"  class="user-checkbox" <?php if( $rowrol['show_main_tables'] == 1){echo "checked"; }?>></td>
                            </tr>
                        </tbody>
                        </table>
                        </div>




            </div>
        </div>
        
    </div>
</div>
</form>

<?php }}?>
<script>
    $(document).ready(function(){
      $("#itmsearch1").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#horsTable1 .tr1").filter(function() {
          $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
      });
    });   
</script>
<script>
    document.getElementById('checkall').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.tr1 .user-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
</script>

</div>
</section>
</div>

  <?php include('includes/footer.php'); ?>