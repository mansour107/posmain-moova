<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_account.php');

$id = $_GET['id'];
    $conn->query("UPDATE acc_head SET isdeleted = 1 where id = $id");
    header('location:../acc_report.php');


