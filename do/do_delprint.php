<?php include('../includes/connect.php') ;
$id = $_GET['id'];
$sqlchk = "select * from zankat where print = $id";
$rowchk = $conn->query($sqlchk);
$reschk = $rowchk->fetch_assoc();
if ($reschk < 1) {

$sqldel = "DELETE FROM `print` WHERE id = $id";
$conn->query($sqldel);

$conn->query("INSERT INTO `process`(`type`) VALUES ('delete print')");


header('location:../prints.php');
} else {
    header('location:../warning.php');
}

?>