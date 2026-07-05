<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_allowances.php');

$id = $_GET['id'];
    $conn->query("UPDATE allowances SET isdeleted = 1 where id = $id");
    header('location:../allowences.php');


