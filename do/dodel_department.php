<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_department.php');

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_GET['id'])) {


    $id = $_GET['id'];
    $conn->query("UPDATE departments SET isdeleted = 1 where id = $id");
    header('location:../departments.php');
}
