<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_ptype.php');

$pname = $_POST['pname'];
$info = $_POST['info'];

$sql="INSERT INTO paper_types ( pname,  info) VALUES ( '$pname',  '$info') ";
$conn->query($sql);
header('location:../ptypes.php');
?>
