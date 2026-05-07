<?php 
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: index.php');
    exit;
}

// إعادة توجيه مباشرة لطباعة فاتورة الشيفت
header('Location: print/shift_sales_receipt.php');
exit;
?>