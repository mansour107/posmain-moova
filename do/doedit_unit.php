<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_unit.php');

$id = $_GET['id'];
$uname = $_POST['uname'];

$sql = "UPDATE myunits SET uname='$uname'  WHERE id = '$id'";

$conn->query($sql);
header('location:../myunits.php');

