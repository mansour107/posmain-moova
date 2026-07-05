<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_kbi.php');

$kname = $_POST['kname'];
$ktybe = $_POST['ktybe'];
$info = $_POST['info'];

$sql="INSERT INTO kbis( kname,ktybe, info) VALUES ( '$kname','$ktybe',  '$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add kbi')");

header('location:../kbis.php');
?>
