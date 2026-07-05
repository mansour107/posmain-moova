<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_kbi.php');

$id =$_GET['id'];
$conn->query("UPDATE kbis SET isdeleted  = 1 where id = $id");
header('location:../kbis.php');