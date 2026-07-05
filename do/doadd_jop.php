<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_jop.php');

$name = $_POST['name'];
$info = $_POST['info'];

$sql="INSERT INTO jops( name, info) VALUES ( '$name',  '$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add jop')");

header('location:../jops.php');
?>
