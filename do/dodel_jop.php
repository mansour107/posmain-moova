<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_jop.php');

$password = $_POST['password'];
$syspass = $rowstg['edit_pass'];;
if ($password == $syspass) {
$id = $_POST['id'];
$conn->query("UPDATE jops SET isdeleted = 1 where id = $id");
header('location:../jops.php');}else{
    echo "password not correct";
}
