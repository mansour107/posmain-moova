<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_production.php');

$id = $_GET['id'];
$sql = "DELETE FROM `productions` WHERE snd_id = '$id'";
$conn->query($sql); 
header('location:../production.php');
