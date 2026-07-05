<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_service.php');

$id = $_GET['id'];
$sname = $_POST['sname'];
$info = $_POST['info'];

$sql="UPDATE services SET sname='$sname',info='$info' WHERE id = $id ";
$conn->query($sql);
header('location:../services.php');
?>
