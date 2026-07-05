<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_vtybe.php');

$id = $_GET['id'];
$name = $_POST['name'];
$value = $_POST['value'];
$sql = "UPDATE visittybes SET name='$name' , value='$value'  WHERE id = '$id'";
$conn->query($sql);
header('location:../vtybes.php');

