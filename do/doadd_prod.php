<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_prod.php');

$pname = $_POST['pname'];
$info = $_POST['info'];

$sql="INSERT INTO prods( pname, info) VALUES ( '$pname', '$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add prod')");

header('location:../prods.php');
?>
