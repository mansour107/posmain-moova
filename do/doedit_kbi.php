<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_kbi.php');

$id = $_GET['id'];
$kname = $_POST['kname'];
$info = $_POST['info'];

$conn->query("UPDATE kbis SET kname = '$kname',info = '$info' where id = $id");
header('location:../kbis.php');
