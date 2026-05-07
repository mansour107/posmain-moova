<?php 
include('../includes/connect.php');

$id = $_GET['id'];
$gname = trim($_POST['gname']);

// التحقق من عدم وجود تصنيف بنفس الاسم (باستثناء التصنيف الحالي)
$check = $conn->query("SELECT id FROM item_group2 WHERE gname = '$gname' AND isdeleted = 0 AND id != $id");

if ($check->num_rows > 0) {
    // التصنيف موجود بالفعل
    header('location:../item_categories.php?error=duplicate');
    exit();
}

$conn->query("UPDATE item_group2 SET gname = '$gname' WHERE id = $id");
header('location:../item_categories.php');
?>
