<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_contract.php');

$password = $_POST['password'];
$id = $_GET['id'];
$conn->query("UPDATE hiringcontracts SET isdeleted = 1 where id = $id");
header('location:../hiringcontracts.php');
