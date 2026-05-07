<?php include('../includes/connect.php');
// echo "<pre>";
// print_r($_POST);
// echo "<pre>";
$rollname  = $_POST['rollname'];



if ($rollname === null) {
    echo "<h1>there is a big mistake __> go to your developer</h1>";
} else {
    $info = $_POST['info'];


    $show_users = isset($_POST['show_users']) ? 1 : 0;
    $add_users = isset($_POST['add_users']) ? 1: 0;
    $edit_users = isset($_POST['edit_users']) ? 1 : 0; 
    $delete_users = isset($_POST['delete_users']) ? 1 : 0; 
    $is_fav_users = isset($_POST['is_fav_users']) ? 1 : 0; 
    
    $show_general_entrys = isset($_POST['show_general_entrys']) ? 1 : 0;
    $add_general_entrys = isset($_POST['add_general_entrys']) ? 1 : 0;
    $edit_general_entrys = isset($_POST['edit_general_entrys']) ? 1 : 0; 
    $delete_general_entrys = isset($_POST['delete_general_entrys']) ? 1 : 0; 
    $is_fav_general_entrys = isset($_POST['is_fav_general_entrys']) ? 1 : 0; 
    
    $show_clients = isset($_POST['show_clients']) ? 1 : 0;
    $add_clients = isset($_POST['add_clients']) ? 1 : 0;
    $edit_clients = isset($_POST['edit_clients']) ? 1 : 0; 
    $delete_clients = isset($_POST['delete_clients']) ? 1 : 0; 
    $is_fav_clients = isset($_POST['is_fav_clients']) ? 1 : 0; 
    
    $show_suppliers = isset($_POST['show_suppliers']) ? 1 : 0;
    $add_suppliers = isset($_POST['add_suppliers']) ? 1 : 0;
    $edit_suppliers = isset($_POST['edit_suppliers']) ? 1 : 0; 
    $delete_suppliers = isset($_POST['delete_suppliers']) ? 1 : 0; 
    $is_fav_suppliers = isset($_POST['is_fav_suppliers']) ? 1 : 0; 
    
    $show_funds = isset($_POST['show_funds']) ? 1 : 0;
    $add_funds = isset($_POST['add_funds']) ?  : 0;
    $edit_funds = isset($_POST['edit_funds']) ? 1 : 0; 
    $delete_funds = isset($_POST['delete_funds']) ? 1 : 0; 
    $is_fav_funds = isset($_POST['is_fav_funds']) ? 1 : 0; 
    
    $show_banks = isset($_POST['show_banks']) ? 1 : 0;
    $add_banks = isset($_POST['add_banks']) ?  : 0;
    $edit_banks = isset($_POST['edit_banks']) ? 1 : 0; 
    $delete_banks = isset($_POST['delete_banks']) ? 1 : 0; 
    $is_fav_banks = isset($_POST['is_fav_banks']) ? 1 : 0; 
    
    $show_stock = isset($_POST['show_stock']) ? 1 : 0;
    $add_stock = isset($_POST['add_stock']) ?  : 0;
    $edit_stock = isset($_POST['edit_stock']) ? 1 : 0; 
    $delete_stock = isset($_POST['delete_stock']) ? 1 : 0; 
    $is_fav_stock = isset($_POST['is_fav_stock']) ? 1 : 0; 
    
    $show_expenses = isset($_POST['show_expenses']) ? 1 : 0;
    $add_expenses = isset($_POST['add_expenses']) ? 1 : 0;
    $edit_expenses = isset($_POST['edit_expenses']) ? 1 : 0; 
    $delete_expenses = isset($_POST['delete_expenses']) ? 1 : 0; 
    $is_fav_expenses = isset($_POST['is_fav_expenses']) ? 1 : 0; 
    
    $show_revenuses = isset($_POST['show_revenuses']) ? 1 : 0;
    $add_revenuses = isset($_POST['add_revenuses']) ? 1 : 0;
    $edit_revenuses = isset($_POST['edit_revenuses']) ? 1 : 0; 
    $delete_revenuses = isset($_POST['delete_revenuses']) ? 1 : 0; 
    $is_fav_revenuses = isset($_POST['is_fav_revenuses']) ? 1 : 0; 
    
    $show_credits = isset($_POST['show_credits']) ? 1 : 0;
    $add_credits = isset($_POST['add_credits']) ? 1 : 0;
    $edit_credits = isset($_POST['edit_credits']) ? 1 : 0; 
    $delete_credits = isset($_POST['delete_credits']) ? 1 : 0; 
    $is_fav_credits = isset($_POST['is_fav_credits']) ? 1 : 0; 

    $show_depits = isset($_POST['show_depits']) ? 1 : 0;
    $add_depits = isset($_POST['add_depits']) ? 1 : 0;
    $edit_depits = isset($_POST['edit_depits']) ? 1 : 0; 
    $delete_depits = isset($_POST['delete_depits']) ? 1 : 0; 
    $is_fav_depits = isset($_POST['is_fav_depits']) ? 1 : 0; 
    
    $show_partners = isset($_POST['show_partners']) ? 1 : 0;
    $add_partners = isset($_POST['add_partners']) ? 1 : 0;
    $edit_partners = isset($_POST['edit_partners']) ? 1 : 0; 
    $delete_partners = isset($_POST['delete_partners']) ? 1 : 0; 
    $is_fav_partners = isset($_POST['is_fav_partners']) ? 1 : 0; 
    
    $show_assets = isset($_POST['show_assets']) ? 1 : 0;
    $add_assets = isset($_POST['add_assets']) ? 1 : 0;
    $edit_assets = isset($_POST['edit_assets']) ? 1 : 0; 
    $delete_assets = isset($_POST['delete_assets']) ? 1 : 0; 
    $is_fav_assets = isset($_POST['is_fav_assets']) ? 1 : 0; 

    $show_rentables = isset($_POST['show_rentables']) ? 1 : 0;
    $add_rentables = isset($_POST['add_rentables']) ? 1 : 0;
    $edit_rentables = isset($_POST['edit_rentables']) ? 1 : 0; 
    $delete_rentables = isset($_POST['delete_rentables']) ? 1 : 0; 
    $is_fav_rentables = isset($_POST['is_fav_rentables']) ? 1 : 0; 
    
    $show_employees = isset($_POST['show_employees']) ? 1 : 0;
    $add_employees = isset($_POST['add_employees']) ? 1 : 0;
    $edit_employees = isset($_POST['edit_employees']) ? 1 : 0; 
    $delete_employees = isset($_POST['delete_employees']) ? 1 : 0; 
    $is_fav_employees = isset($_POST['is_fav_employees']) ? 1 : 0; 
    
    $show_items = isset($_POST['show_items']) ? 1 : 0;
    $add_items = isset($_POST['add_items']) ?  : 0;
    $edit_items = isset($_POST['edit_items']) ? 1 : 0; 
    $delete_items = isset($_POST['delete_items']) ? 1 : 0; 
    $is_fav_items = isset($_POST['is_fav_items']) ? 1 : 0; 

    $show_item_groups = isset($_POST['show_item_groups']) ? 1 : 0;
    $add_item_groups = isset($_POST['add_item_groups']) ? 1 : 0;
    $edit_item_groups = isset($_POST['edit_item_groups']) ? 1 : 0; 
    $delete_item_groups = isset($_POST['delete_item_groups']) ? 1 : 0; 
    $is_fav_item_groups = isset($_POST['is_fav_item_groups']) ? 1 : 0; 

    $show_sales = isset($_POST['show_sales']) ? 1 : 0;
    $add_sales = isset($_POST['add_sales']) ? 1 : 0;
    $edit_sales = isset($_POST['edit_sales']) ? 1 : 0; 
    $delete_sales = isset($_POST['delete_sales']) ? 1 : 0; 
    $is_fav_sales = isset($_POST['is_fav_sales']) ? 1 : 0; 

    $show_resale = isset($_POST['show_resale']) ? 1 : 0;
    $add_resale = isset($_POST['add_resale']) ? 1 : 0;
    $edit_resale = isset($_POST['edit_resale']) ? 1 : 0; 
    $delete_resale = isset($_POST['delete_resale']) ? 1 : 0; 
    $is_fav_resale = isset($_POST['is_fav_resale']) ? 1 : 0; 
    
    $show_purchases = isset($_POST['show_purchases']) ? 1 : 0;
    $add_purchases = isset($_POST['add_purchases']) ? 1 : 0;
    $edit_purchases = isset($_POST['edit_purchases']) ? 1 : 0; 
    $delete_purchases = isset($_POST['delete_purchases']) ? 1 : 0; 
    $is_fav_purcases = isset($_POST['is_fav_purcases']) ? 1 : 0; 

    
    $show_clinic_clients = isset($_POST['show_clinic_clients']) ? 1 : 0;
    $add_clinic_clients = isset($_POST['add_clinic_clients']) ? 1 : 0;
    $edit_clinic_clients = isset($_POST['edit_clinic_clients']) ? 1 : 0; 
    $delete_clinic_clients = isset($_POST['delete_clinic_clients']) ? 1 : 0; 
    $is_fav_clinic_clients = isset($_POST['is_fav_clinic_clients']) ? 1 : 0; 

    $show_repurchases = isset($_POST['show_repurchases']) ? 1 : 0;
    $add_repurchases = isset($_POST['add_repurchases']) ? 1 : 0;
    $edit_repurchases = isset($_POST['edit_repurchases']) ? 1 : 0; 
    $delete_repurchases = isset($_POST['delete_repurchases']) ? 1 : 0; 
    $is_fav_repurchases = isset($_POST['is_fav_repurchases']) ? 1 : 0; 
 
    $show_recive = isset($_POST['show_recive']) ? 1 : 0;
    $add_recive = isset($_POST['add_recive']) ? 1 : 0;
    $edit_recive = isset($_POST['edit_recive']) ? 1 : 0; 
    $delete_recive = isset($_POST['delete_recive']) ? 1 : 0; 
    $is_fav_recive = isset($_POST['is_fav_recive']) ? 1 : 0; 
    
    $show_payment = isset($_POST['show_payment']) ? 1 : 0;
    $add_payment = isset($_POST['add_payment']) ? 1 : 0;
    $edit_payment = isset($_POST['edit_payment']) ? 1 : 0; 
    $delete_payment = isset($_POST['delete_payment']) ? 1 : 0; 
    $is_fav_payment = isset($_POST['is_fav_payment']) ? 1 : 0; 

    $show_reservations = isset($_POST['show_reservations']) ? 1 : 0;
    $add_reservations = isset($_POST['add_reservations']) ? 1 : 0;
    $edit_reservations = isset($_POST['edit_reservations']) ? 1 : 0; 
    $delete_reservations = isset($_POST['delete_reservations']) ? 1 : 0; 
    $is_fav_reservations = isset($_POST['is_fav_reservations']) ? 1 : 0; 
    
    $show_advanced_clients = isset($_POST['show_advanced_clients']) ? 1 : 0;
    $add_advanced_clients = isset($_POST['add_advanced_clients']) ? 1 : 0;
    $edit_advanced_clients = isset($_POST['edit_advanced_clients']) ? 1 : 0; 
    $delete_advanced_clients = isset($_POST['delete_advanced_clients']) ? 1 : 0; 
    $is_fav_advanced_clients = isset($_POST['is_fav_advanced_clients']) ? 1 : 0; 
    
    $show_drugs = isset($_POST['show_drugs']) ? 1 : 0;
    $add_drugs = isset($_POST['add_drugs']) ? 1 : 0;
    $edit_drugs = isset($_POST['edit_drugs']) ? 1 : 0; 
    $delete_drugs = isset($_POST['delete_drugs']) ? 1 : 0; 
    $is_fav_drugs = isset($_POST['is_fav_drugs']) ? 1 : 0; 

    $show_client_profile = isset($_POST['show_client_profile']) ? 1 : 0;
    $add_client_profile = isset($_POST['add_client_profile']) ? 1 : 0;
    $edit_client_profile = isset($_POST['edit_client_profile']) ? 1 : 0; 
    $delete_client_profile = isset($_POST['delete_client_profile']) ? 1 : 0; 
    $is_fav_client_profile = isset($_POST['is_fav_client_profile']) ? 1 : 0; 

    $show_chances = isset($_POST['show_chances']) ? 1 : 0;
    $add_chances = isset($_POST['add_chances']) ? 1 : 0;
    $edit_chances = isset($_POST['edit_chances']) ? 1 : 0; 
    $delete_chances = isset($_POST['delete_chances']) ? 1 : 0; 
    $is_fav_chances = isset($_POST['is_fav_chances']) ? 1 : 0; 
   
    $show_attandance = isset($_POST['show_attandance']) ? 1 : 0;
    $add_attandance = isset($_POST['add_attandance']) ? 1 : 0;
    $edit_attandance = isset($_POST['edit_attandance']) ? 1 : 0; 
    $delete_attandance = isset($_POST['delete_attandance']) ? 1 : 0; 
    $is_fav_attandance = isset($_POST['is_fav_attandance']) ? 1 : 0; 
   
    $show_calls = isset($_POST['show_calls']) ? 1 : 0;
    $add_calls = isset($_POST['add_calls']) ?  : 0;
    $edit_calls = isset($_POST['edit_calls']) ? 1 : 0; 
    $delete_calls = isset($_POST['delete_calls']) ? 1 : 0; 
    $is_fav_calls = isset($_POST['is_fav_calls']) ? 1 : 0; 

    $show_journals = isset($_POST['show_journals']) ? 1 : 0;
    $add_journals = isset($_POST['add_journals']) ? 1 : 0;
    $edit_journals = isset($_POST['edit_journals']) ? 1 : 0; 
    $delete_journals = isset($_POST['delete_journals']) ? 1 : 0; 
    $is_fav_journals = isset($_POST['is_fav_journals']) ? 1 : 0; 


    $show_gl_reports = isset($_POST['show_gl_reports']) ? 1 : 0;

    $show_clinic_reports = isset($_POST['show_clinic_reports']) ? 1 : 0;

    $show_rent_reports = isset($_POST['show_rent_reports']) ? 1 : 0;

    $show_payroll_report = isset($_POST['show_payroll_reports']) ? 1 : 0; 

    $show_hr_report = isset($_POST['show_hr_reports']) ? 1 : 0;



    $sid_entry = isset($_POST['sid_entry']) ?  1: 0;
    $sid_stock = isset($_POST['sid_stock']) ?  1: 0;
    $sid_sales = isset($_POST['sid_sales']) ?  1: 0;
    $sid_purchases = isset($_POST['sid_purchases']) ? 1 : 0;
    $sid_vouchers = isset($_POST['sid_vouchers']) ? 1 : 0;
    $sid_clinics = isset($_POST['sid_clinics']) ? 1 : 0;
    $sid_crm = isset($_POST['sid_crm']) ? 1 : 0;
    $sid_accounts = isset($_POST['sid_accounts']) ? 1 : 0;
    $sid_assets = isset($_POST['sid_assets']) ? 1 : 0;
    $sid_reports = isset($_POST['sid_reports']) ? 1 : 0;
    $sid_hr = isset($_POST['sid_hr']) ? 1: 0;
    $sid_payroll = isset($_POST['sid_payroll']) ? 1 : 0;
    $sid_rents = isset($_POST['sid_rents']) ? 1 : 0;
    $sid_cards = isset($_POST['sid_cards']) ? 1 : 0;
    $edit_user_passwords = isset($_POST['edit_user_passwords']) ? 1 : 0;




    $show_ended_reservation = isset($_POST['show_ended_reservation']) ? 1 : 0;
    $show_total_reservation = isset($_POST['show_total_reservation']) ? 1 : 0;
    
    
    $show_all_tasks = isset($_POST['show_all_tasks']) ? 1 : 0;

    $show_main_cards = isset($_POST['show_main_cards']) ? 1 : 0;
    $show_main_elements = isset($_POST['show_main_elements']) ? 1 : 0;
    $show_main_tables = isset($_POST['show_main_tables']) ? 1 : 0;
 
   
    

    


$sql = "INSERT INTO usr_pwrs (
    rollname, is_fav_users, show_users, add_users, edit_users, delete_users, is_fav_general_entrys, show_general_entrys, add_general_entrys, edit_general_entrys, delete_general_entrys, is_fav_clients, show_clients, add_clients, edit_clients, is_fav_suppliers,delete_clients, show_suppliers, add_suppliers, edit_suppliers, delete_suppliers, is_fav_funds, show_funds, add_funds, edit_funds, delete_funds, is_fav_banks, show_banks, add_banks, edit_banks, delete_banks, is_fav_stock, show_stock, add_stock, edit_stock, delete_stock, is_fav_expenses, show_expenses, add_expenses, edit_expenses, delete_expenses, is_fav_revenuses, show_revenuses, add_revenuses, edit_revenuses, delete_revenuses, is_fav_credits, show_credits, add_credits, edit_credits, delete_credits, is_fav_depits, show_depits, add_depits, edit_depits, delete_depits, is_fav_partners, show_partners, add_partners, edit_partners, delete_partners, is_fav_assets, show_assets, add_assets, edit_assets, delete_assets, is_fav_rentables, show_rentables, add_rentables, edit_rentables, delete_rentables, is_fav_employees, show_employees, add_employees, edit_employees, delete_employees, is_fav_items, show_items, add_items, edit_items, delete_items, is_fav_item_groups, show_item_groups, add_item_groups, edit_item_groups, delete_item_groups, is_fav_sales, show_sales, add_sales, edit_sales, delete_sales, is_fav_resale, show_resale, add_resale, edit_resale, delete_resale, is_fav_purcases, show_purchases, add_purchases, edit_purchases, delete_purchases, is_fav_repurchases, show_repurchases, add_repurchases, edit_repurchases, delete_repurchases, is_fav_recive, show_recive, add_recive, edit_recive, delete_recive, is_fav_payment, show_payment, add_payment, edit_payment, delete_payment, is_fav_clinic_clients, show_clinic_clients, add_clinic_clients, edit_clinic_clients, delete_clinic_clients, is_fav_reservations, show_reservations, add_reservations, edit_reservations, delete_reservations, is_fav_drugs, show_drugs, add_drugs, edit_drugs, delete_drugs, is_fav_client_profile, show_client_profile, add_client_profile, edit_client_profile, delete_client_profile, is_fav_advanced_clients, show_advanced_clients, add_advanced_clients, edit_advanced_clients, delete_advanced_clients, is_fav_chances, show_chances, add_chances, edit_chances, delete_chances,is_fav_attandance, show_attandance, add_attandance, edit_attandance, delete_attandance, is_fav_calls, show_calls, add_calls, edit_calls, delete_calls, is_fav_journals, show_journals, add_journals, edit_journals, delete_journals, show_gl_reports, show_clinic_reports, show_rent_reports, show_payroll_report, show_hr_report, sid_entry, sid_stock, sid_sales, sid_purchases, sid_vouchers, sid_clinics, sid_crm, sid_accounts, sid_assets, sid_reports, sid_hr, sid_payroll, sid_rents, sid_cards, edit_user_passwords, info,show_ended_reservation,show_total_reservation,show_all_tasks,show_main_cards,show_main_elements,show_main_tables )
 VALUES ('$rollname', '$is_fav_users', '$show_users', '$add_users', '$edit_users', '$delete_users', '$is_fav_general_entrys', '$show_general_entrys', '$add_general_entrys', '$edit_general_entrys', '$delete_general_entrys', '$is_fav_clients', '$show_clients', '$add_clients', '$edit_clients', '$is_fav_suppliers', '$delete_clients', '$show_suppliers', '$add_suppliers', '$edit_suppliers', '$delete_suppliers', '$is_fav_funds', '$show_funds', '$add_funds', '$edit_funds', '$delete_funds', '$is_fav_banks', '$show_banks', '$add_banks', '$edit_banks', '$delete_banks', '$is_fav_stock', '$show_stock', '$add_stock', '$edit_stock', '$delete_stock', '$is_fav_expenses', '$show_expenses', '$add_expenses', '$edit_expenses', '$delete_expenses', '$is_fav_revenuses', '$show_revenuses', '$add_revenuses', '$edit_revenuses', '$delete_revenuses', '$is_fav_credits', '$show_credits', '$add_credits', '$edit_credits', '$delete_credits', '$is_fav_depits', '$show_depits', '$add_depits', '$edit_depits', '$delete_depits', '$is_fav_partners', '$show_partners', '$add_partners', '$edit_partners', '$delete_partners', '$is_fav_assets', '$show_assets', '$add_assets', '$edit_assets', '$delete_assets', '$is_fav_rentables', '$show_rentables', '$add_rentables', '$edit_rentables', '$delete_rentables', '$is_fav_employees', '$show_employees', '$add_employees', '$edit_employees', '$delete_employees', '$is_fav_items', '$show_items', '$add_items', '$edit_items', '$delete_items', '$is_fav_item_groups', '$show_item_groups', '$add_item_groups', '$edit_item_groups', '$delete_item_groups', '$is_fav_sales', '$show_sales', '$add_sales', '$edit_sales', '$delete_sales', '$is_fav_resale', '$show_resale', '$add_resale', '$edit_resale', '$delete_resale', '$is_fav_purcases', '$show_purchases', '$add_purchases', '$edit_purchases', '$delete_purchases', '$is_fav_repurchases', '$show_repurchases', '$add_repurchases', '$edit_repurchases', '$delete_repurchases', '$is_fav_recive', '$show_recive', '$add_recive', '$edit_recive', '$delete_recive', '$is_fav_payment', '$show_payment','$add_payment', '$edit_payment', '$delete_payment', '$is_fav_clinic_clients', '$show_clinic_clients', '$add_clinic_clients', '$edit_clinic_clients', '$delete_clinic_clients', '$is_fav_reservations', '$show_reservations', '$add_reservations', '$edit_reservations', '$delete_reservations', '$is_fav_drugs', '$show_drugs', '$add_drugs', '$edit_drugs', '$delete_drugs', '$is_fav_client_profile', '$show_client_profile', '$add_client_profile', '$edit_client_profile', '$delete_client_profile', '$is_fav_advanced_clients', '$show_advanced_clients', '$add_advanced_clients', '$edit_advanced_clients', '$delete_advanced_clients', '$is_fav_chances', '$show_chances', '$add_chances', '$edit_chances', '$delete_chances', '$is_fav_attandance', '$show_attandance', '$add_attandance', '$edit_attandance', '$delete_attandance', '$is_fav_calls', '$show_calls', '$add_calls', '$edit_calls', '$delete_calls', '$is_fav_journals', '$show_journals', '$add_journals', '$edit_journals', '$delete_journals', '$show_gl_reports', '$show_clinic_reports', '$show_rent_reports', '$show_payroll_report', '$show_hr_report', '$sid_entry', '$sid_stock', '$sid_sales', '$sid_purchases', '$sid_vouchers', '$sid_clinics', '$sid_crm', '$sid_accounts', '$sid_assets', '$sid_reports', '$sid_hr', '$sid_payroll', '$sid_rents', '$sid_cards', '$edit_user_passwords', '$info','$show_ended_reservation','$show_total_reservation','$show_all_tasks','show_main_cards','show_main_elements','show_main_tables')";
echo $sql;
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add role')");

}
header('location:../myroles.php');
?>