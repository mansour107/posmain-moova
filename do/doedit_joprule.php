<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_joprule.php');

$id = $_GET['id'];
$name = $_POST['name'];
$info = $_POST['info'];

$sql = "UPDATE `joprules` SET `name` = '$name' , `info` = '$info' WHERE `id` = '$id'";
$conn->query($sql);
header('location:../joprules.php');
