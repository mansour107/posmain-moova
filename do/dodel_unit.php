<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_unit.php');

$id = $_GET['id'];

// حذف الوحدة
$conn->query("DELETE FROM myunits WHERE id = $id");

header('location:../myunits.php');
?>
