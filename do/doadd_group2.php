<?php 
include('../includes/connect.php');

$gname = trim($_POST['gname']);

// التحقق من عدم وجود تصنيف بنفس الاسم
$check = $conn->query("SELECT id FROM item_group2 WHERE gname = '$gname' AND isdeleted = 0");

if ($check->num_rows > 0) {
    // التصنيف موجود بالفعل
    header('location:../item_categories.php?error=duplicate');
    exit();
}

// إضافة التصنيف الجديد
$conn->query("INSERT INTO item_group2 (gname) VALUES ('$gname')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add group2')");

header('location:../item_categories.php');
?>