<?php include('includes/header.php'); ?>
<?php include('includes/sidebar.php'); ?>
<?php include('includes/navbar.php'); ?>

<div class="content-wrapper">
<section class="content-header">
<div class="container-fluid">

<form action="do/doadd_role.php" method="post">
<div class="card shadow-sm bg-light">
    <div class="card-headerr p-5">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="fas fa-user-plus me-2"></i> إضافة دور جديد</h4>
            <button type="submit" class="btn btn-light btn-sm">
                <i class="fas fa-save me-1"></i> حفظ
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="form-group">
                    <label for="rollname" class="font-weight-bold">اسم الدور</label>
                    <input type="text" name="rollname" class="form-control" placeholder="أدخل اسم الدور" required>
                </div>
            </div>
            <div class="col-md-8">
                <div class="form-group">
                    <label for="info" class="font-weight-bold">وصف الدور</label>
                    <input type="text" name="info" class="form-control" placeholder="وصف مختصر للدور">
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-key me-2"></i> صلاحيات النظام</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="p-3">
                            <input type="text" id="itmsearch1" class="form-control mb-3" placeholder="بحث في الصلاحيات...">
                        </div>
                        <div class="table-responsive">
                            <table id="horsTable1" class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>اسم الصلاحية</th>
                                        <th class="text-center">عرض</th>
                                        <th class="text-center">جديد</th>
                                        <th class="text-center">تعديل</th>
                                        <th class="text-center">حذف</th>
                                        <th class="text-center">مفضلة</th>
                                    </tr>
                                    <tr class="bg-primary text-white">
                                        <th>اختيار الكل <input type="checkbox" id="checkall" class="ms-2"></th>
                                        <th colspan="5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="tr1">
                                        <td class="font-weight-bold">المستخدمين</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_users" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_users" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_users" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_users" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_users"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">العملاء</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_clients"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الموردين</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_suppliers" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_suppliers" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_suppliers" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_suppliers" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_suppliers"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الصناديق</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_funds" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_funds" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_funds" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_funds" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_funds"></td>
                                    </tr>
                                    
                                    <tr class="tr1">
                                        <td class="font-weight-bold">البنوك</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_banks" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_banks" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_banks" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_banks" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_banks"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">المخزون</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_stock" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_stock" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_stock" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_stock" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_stock"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">المصروفات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_expenses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_expenses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_expenses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_expenses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_expenses"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الايرادات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_revenuses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_revenuses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_revenuses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_revenuses" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_revenuses"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">دائنين آخرين</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_credits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_credits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_credits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_credits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_credits"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">مدينين آخرين</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_depits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_depits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_depits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_depits" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_depits"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الشركاء</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_partners" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_partners" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_partners" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_partners" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_partners"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الاصول</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_assets" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_assets" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_assets" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_assets" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_assets"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الاصول القابلة للتأجير</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_rentables" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_rentables" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_rentables" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_rentables" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_rentables"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الموظفين</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_employees" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_employees" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_employees" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_employees" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_employees"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الاصناف</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_items" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_items" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_items" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_items" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_items"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">مجموعات الاصناف</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_item_groups" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_item_groups" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_item_groups" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_item_groups" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_item_groups"></td>
                                    </tr>
                                    
                                    <tr class="tr1">
                                        <td class="font-weight-bold">المبيعات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_sales" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_sales" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_sales" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_sales" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_sales"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">مردود المبيعات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_resale" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_resale" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_resale" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_resale" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_resale"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">المشتريات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_purchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_purchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_purchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_purchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_purcases"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">مردود المشتريات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_repurchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_repurchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_repurchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_repurchases" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_repurchases"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">سندات القبض</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_recive" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_recive" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_recive" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_recive" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_recive"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">سندات الدفع</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_payment" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_payment" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_payment" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_payment" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_payment"></td>
                                    </tr>
                                    
                                    <tr class="tr1">
                                        <td class="font-weight-bold">العيادات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_clinics" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_clinics" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_clinics" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_clinics" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_clinics"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الحجوزات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_reservations" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_reservations" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_reservations" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_reservations" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_reservations"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">العملاء متقدم</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_advanced_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_advanced_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_advanced_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_advanced_clients" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_advanced_clients"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الادوية</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_drugs" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_drugs" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_drugs" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_drugs" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_drugs"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">بروفايل لعميل</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_client_profile" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_client_profile" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_client_profile" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_client_profile" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_client_profile"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">الفرص</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_attandance" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_attandance" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_attandance" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_attandance" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_attandance"></td>
                                    </tr>
                                    
                                    <tr class="tr1">
                                        <td class="font-weight-bold">موديول الحضور والانصراف</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_chances" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_chances" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_chances" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_chances" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_chances"></td>
                                    </tr>
                                    
                                    <tr class="tr1">
                                        <td class="font-weight-bold">المكالمات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_calls" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_calls" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_calls" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_calls" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_calls"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">قيود اليومية</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_journals" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_journals" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_journals" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_journals" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_journals"></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">حسابات الاستاذ</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_gl_reports" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_gl_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_gl_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_gl_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_gl_reports" disabled></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">تقارير العيادات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_clinic_reports" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_clinic_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_clinic_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_clinic_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_clinic_reports" disabled></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">تقارير التأجير</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_rent_reports" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_rent_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_rent_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_rent_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_rent_reports" disabled></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">تقارير المرتبات</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_payroll_reports" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_payroll_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_payroll_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_payroll_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_payroll_reports" disabled></td>
                                    </tr>

                                    <tr class="tr1">
                                        <td class="font-weight-bold">تقارير الحضور</td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="show_hr_reports" checked></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="add_hr_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="edit_hr_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="delete_hr_reports" disabled></td>
                                        <td class="text-center"><input type="checkbox" class="user-checkbox" name="is_fav_hr_reports" disabled></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-sidebar me-2"></i> خيارات القائمة الجانبية</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>الخيار</th>
                                        <th class="text-center">عرض</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="tr1">
                                        <td>اظهار قائمة البيانات الأساسية</td>
                                        <td class="text-center"><input type="checkbox" name="sid_entry" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قائمة المخزون</td>
                                        <td class="text-center"><input type="checkbox" name="sid_stock" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم المبيعات</td>
                                        <td class="text-center"><input type="checkbox" name="sid_sales" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم المشتريات</td>
                                        <td class="text-center"><input type="checkbox" name="sid_purchases" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار السندات</td>
                                        <td class="text-center"><input type="checkbox" name="sid_vouchers" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم العيادات</td>
                                        <td class="text-center"><input type="checkbox" name="sid_clinics" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم ادارة علاقات العملاء</td>
                                        <td class="text-center"><input type="checkbox" name="sid_crm" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم الحسابات</td>
                                        <td class="text-center"><input type="checkbox" name="sid_accounts" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم الاصول</td>
                                        <td class="text-center"><input type="checkbox" name="sid_assets" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار التقارير</td>
                                        <td class="text-center"><input type="checkbox" name="sid_reports" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم اداره الموارد البشرية</td>
                                        <td class="text-center"><input type="checkbox" name="sid_hr" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم المرتبات</td>
                                        <td class="text-center"><input type="checkbox" name="sid_payroll" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم التأجير</td>
                                        <td class="text-center"><input type="checkbox" name="sid_rents" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار قسم ادارة الكروت</td>
                                        <td class="text-center"><input type="checkbox" name="sid_cards" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>تعديل كلمات مرور المستخدمين</td>
                                        <td class="text-center"><input type="checkbox" name="edit_user_passwords" class="user-checkbox" checked></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i> الخيارات العامة</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>الخيار</th>
                                        <th class="text-center">عرض</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="tr1">
                                        <td>اظهار الحجوزات المنتهية</td>
                                        <td class="text-center"><input type="checkbox" name="show_ended_reservation" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار اجمالي الحجوزات</td>
                                        <td class="text-center"><input type="checkbox" name="show_total_reservation" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار بيانات المريض (مكرر)</td>
                                        <td class="text-center"><input type="checkbox" name="show_client_profile" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار مهمات كل الاشخاص</td>
                                        <td class="text-center"><input type="checkbox" name="show_all_tasks" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار الكروت في الشاشة الرئيسية</td>
                                        <td class="text-center"><input type="checkbox" name="show_main_cards" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار الاختصارات في الشاشة الرئيسية</td>
                                        <td class="text-center"><input type="checkbox" name="show_main_elements" class="user-checkbox" checked></td>
                                    </tr>
                                    <tr class="tr1">
                                        <td>اظهار الجداول في الشاشة الرئيسية</td>
                                        <td class="text-center"><input type="checkbox" name="show_main_tables" class="user-checkbox" checked></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

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