<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodelete_chance.php');

$id = $_GET['id'];
$conn->query("DELETE FROM `chances` WHERE id = $id");
header('location:../chances.php');
