<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_reservation.php');

$id = $_GET['id'];
$conn->query("DELETE FROM `reservations` WHERE id= $id");
header('location:../reservations.php');
?>