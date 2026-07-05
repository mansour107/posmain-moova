<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_shift.php');

$password = $_POST['password'];
$syspass = $rowstg['edit_pass'];
if ($password == $syspass) {
    $id = $_GET['id'];
    $conn->query("UPDATE shifts SET isdeleted = 1 where id = $id");
    header('location:../shifts.php');
} else {
    echo "password not correct";
}
?>
