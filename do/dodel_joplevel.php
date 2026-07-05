<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_joplevel.php');

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_GET['id'])) {


    $id = $_GET['id'];
    $conn->query("UPDATE joplevels SET isdeleted = 1 where id = $id");
    header('location:../joplevels.php');
}
