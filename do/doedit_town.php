<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_town.php');

$id = $_GET['id'];
$name = $_POST['name'];

$conn->query("UPDATE towns SET name = '$name' where id = $id");
header('location:../mytowns.php');
