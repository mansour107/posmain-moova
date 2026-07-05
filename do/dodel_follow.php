<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_follow.php');

$id = $_GET['id'];
$conn->query("DELETE FROM tasks where id = $id");
header('location:../followup.php');
