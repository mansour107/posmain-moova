<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_client.php');

$id= $_GET['id'];
$rowchkvst = $conn->query("SELECT * FROM reservations where client = '$id'") ;
if (empty($rowchkvst)) {
   $conn->query("DELETE FROM `clients` WHERE id = '$id'");
}else {
    header('location:../clients.php?w=del');
}