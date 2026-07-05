<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_ctp.php');

$id = $_GET['id'];
$cname = $_POST['cname'];
$type = $_POST['type'];
$number = $_POST['number'];
$info = $_POST['info'];

$sql="UPDATE ctp SET cname='$cname',type='$type',number='$number',info='$info' WHERE id= $id";
$conn->query($sql);
header('location:../ctps.php');