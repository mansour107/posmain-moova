<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_prod.php');

$id= $_GET['id'];
$pname = $_POST['pname'];
$info = $_POST['info'];

$sql="UPDATE prods SET pname='$oname',info='$info' WHERE id = $id";
$conn->query($sql);
header('location:../prods.php');
?>
