<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_town.php');

$id =$_GET['id'];
$conn->query("UPDATE towns SET isdeleted  = 1 where id = $id");
header('location:../mytowns.php');