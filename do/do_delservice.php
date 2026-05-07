<?php include('../includes/connect.php') ;
$id = $_GET['id'];
$sqlchk = "select * from zankat where service = $id";
$rowchk = $conn->query($sqlchk);
$reschk = $rowchk->fetch_assoc();
if ($reschk < 1) {

$sqldel = "DELETE FROM `services` WHERE id = $id";
$conn->query($sqldel);

$conn->query("INSERT INTO `process`(`type`) VALUES ('delete service')");

header('location:../services.php');
} else {
    header('location:../warning.php');
}

?>