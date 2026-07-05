<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_unit.php');

$uname = $_POST['uname'];
$conn->query("INSERT INTO myunits (uname) values ('$uname')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add unit')");

header('location:../myunits.php');

