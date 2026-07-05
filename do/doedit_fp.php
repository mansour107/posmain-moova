<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_fp.php');

$id = $_GET['id'];
    
    $employee = $_POST['employee'];
    $fptybe = $_POST['fptybe'];
    $fpdate = $_POST['fpdate'];
    $fptime = $_POST['fptime'];
    
    $conn->query("UPDATE attandance SET `employee` = '$employee', `fptybe` = '$fptybe', `fpdate` = '$fpdate', `time` = '$fptime' WHERE id = '$id'");
    header("location:../manualattandance.php");