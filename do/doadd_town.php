<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_town.php');

$name = $_POST['name'];
$conn->query("INSERT INTO towns (name) values ('$name')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add town')");

header('location:../mytowns.php');