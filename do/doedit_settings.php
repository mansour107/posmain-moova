<?php 
error_log('[Settings] doedit_settings.php accessed - Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('[Settings] POST data: ' . print_r($_POST, true));

include('../includes/connect.php');

// التحقق من طريقة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}

// التحقق من تسجيل الدخول
if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:../index.php');
    exit();
}

// تنظيف وتأمين البيانات المدخلة
$companyname = trim($_POST['companyname'] ?? '');
$companyadd = trim($_POST['companyadd'] ?? '');
$companytel = trim($_POST['companytel'] ?? '');
$edit_pass = trim($_POST['edit_pass'] ?? '');
$lang = trim($_POST['lang'] ?? 'ar');
$showhr = (int)($_POST['showhr'] ?? 0);
$showatt = (int)($_POST['showatt'] ?? 0);
$showclinc = (int)($_POST['showclinc'] ?? 0);
$showrent = (int)($_POST['showrent'] ?? 0);
$bodycolor = trim($_POST['bodycolor'] ?? '#ffffff');
$showpayroll = (int)($_POST['showpayroll'] ?? 0);
$acc_rent = (int)($_POST['acc_rent'] ?? 0);
$def_pos_client = (int)($_POST['def_pos_client'] ?? 0);
$def_pos_store = (int)($_POST['def_pos_store'] ?? 0);
$def_pos_employee = (int)($_POST['def_pos_employee'] ?? 0);
$def_pos_fund = (int)($_POST['def_pos_fund'] ?? 0);
$pos_type = trim($_POST['pos_type'] ?? 'barcode');
$pos_has_password = isset($_POST['pos_has_password']) ? 1 : 0;

// التحقق من صحة البيانات المطلوبة
if (empty($companyname)) {
    die("Error: Company name is required");
}

// استخدام prepared statement لتحديث الإعدادات
$sql = "UPDATE settings 
SET company_name = ?, 
    company_add = ?, 
    company_tel = ?, 
    edit_pass = ?, 
    lang = ?, 
    acc_rent = ?, 
    showhr = ?, 
    showatt = ?, 
    showpayroll = ?, 
    bodycolor = ?, 
    showrent = ?, 
    showclinc = ?, 
    def_pos_client = ?, 
    def_pos_store = ?, 
    def_pos_employee = ?, 
    def_pos_fund = ?,
    pos_type = ?,
    pos_has_password = ? 
WHERE 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssiiiisissiiisi", 
    $companyname, $companyadd, $companytel, $edit_pass, $lang,
    $acc_rent, $showhr, $showatt, $showpayroll, $bodycolor,
    $showrent, $showclinc, $def_pos_client, $def_pos_store, 
    $def_pos_employee, $def_pos_fund, $pos_type, $pos_has_password
);

if ($stmt->execute()) {
    header('location:../dashboard.php');
} else {
    echo "Error updating settings: " . $conn->error;
}

$stmt->close();
?>
