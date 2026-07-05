<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_allowance.php');

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['name'])) {

    foreach ($_POST as $key => $value) {
        $$key = $value;
    }

    $sql = "INSERT INTO `allowances` ( `name`,`info` , `tybe` ) VALUES ('$name','$info','$tybe')";
    $conn->query($sql);

    $conn->query("INSERT INTO `process`(`type`) VALUES ('add allownces')");

    header("location:../allowences.php");
}
