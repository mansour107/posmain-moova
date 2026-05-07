<?php include('../includes/connect.php') ;
$id = $_GET['id'];
$sqlchk = "select * from zankat where prod = $id";
$rowchk = $conn->query($sqlchk);
$reschk = $rowchk->fetch_assoc();
if ($reschk < 1) {

$sqldel = "DELETE FROM `prods` WHERE id = $id";
$conn->query($sqldel);

$conn->query("INSERT INTO `process`(`type`) VALUES ('delete prod')");


header('location:../prods.php');
} else {
    header('location:../warning.php');
}

?>