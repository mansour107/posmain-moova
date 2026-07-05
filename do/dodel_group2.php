<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_group2.php');

$id = $_GET['id'];
$conn->query("UPDATE item_group2 SET isdeleted  = 1 where id = $id");
header('location:../mygroups.php?tab=subgroups');
