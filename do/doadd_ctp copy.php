<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_ctp copy.php');

$name = $_POST['name'];
$type = $_POST['type'];
$number = $_POST['number'];
$info = $_POST['info'];

$sql="INSERT INTO ctp( cname, type, number, info) VALUES ( '$cname', '$type', '$number', '$info') ";
$conn->query($sql);
header('location:../ctps.php');
?>
