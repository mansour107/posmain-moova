<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_service.php');

$sname = $_POST['sname'];
$info = $_POST['info'];

$sql="INSERT INTO services( sname, info) VALUES ( '$sname','$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add service')");

header('location:../services.php');
?>
