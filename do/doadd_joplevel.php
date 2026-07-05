<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_joplevel.php');

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    foreach ($_POST as $key => $value) {
        $$key = $value;
    }
    $sql = "INSERT INTO joplevels (`name`,`info`) VALUES ('$name','$info')";
    $res = $conn->query($sql);
    $conn->query("INSERT INTO `process`(`type`) VALUES ('add jop level')");

    header("location:../joplevels.php");
}
