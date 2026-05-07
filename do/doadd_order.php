<?php include('../includes/connect.php');

if ( $_SERVER['REQUEST_METHOD'] == "POST") {

    foreach ($_POST as $key => $value) {
        $$key = $value;
    }

$sql = "INSERT INTO `orders` (`employee` , `tybe` , `status` , `applyingdate` , `curdate`)
VALUES ('$employee' , '$tybe' , '$status' , '$applyingdate' , '$curdate');";

$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add order')");

header("location:../orders.php");
exit;

}