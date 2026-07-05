<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_manualfp.php');

$user = $_SESSION['userid'];
$employee = $_POST['employee'];
$fptybe = $_POST['fptybe'];
if ($_POST['fpdate'] == null) {$fpdate = date("Y-m-d");}else{$fpdate = $_POST['fpdate'];}
if ($_POST['fptime'] == null) {$fptime = date("H:i");}else{$fptime = $_POST['fptime'];}
$conn->query("INSERT INTO `attandance`(`employee`, `fptybe`, `fpdate`, `time`, `user`) VALUES ('$employee','$fptybe','$fpdate','$fptime','$user')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add manual fb')");

header('location:../manualattandance.php');
