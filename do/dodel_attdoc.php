<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_attdoc.php');

$doc = $_GET['doc'];
$conn->query("DELETE FROM `attlog` WHERE attdoc = $doc");
$conn->query("DELETE FROM `attdocs` WHERE id = $doc");
header('location:../calcsalary.php');
?>
