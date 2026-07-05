<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_group2.php');

$id = $_GET['id'];
$gname = trim($_POST['gname']);
$returnTo = 'mygroups.php?tab=subgroups';

// التحقق من عدم وجود مجموعة فرعية بنفس الاسم (باستثناء المجموعة الفرعية الحالية)
$check = $conn->query("SELECT id FROM item_group2 WHERE gname = '$gname' AND isdeleted = 0 AND id != $id");

if ($check->num_rows > 0) {
    header('location:../' . $returnTo . '&error=duplicate');
    exit();
}

$conn->query("UPDATE item_group2 SET gname = '$gname' WHERE id = $id");
header('location:../' . $returnTo);
