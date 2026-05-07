<?php include('../includes/connect.php') ;
$id = $_GET['id'];
$sqlchk = "select * from zankat where ptype = $id";
$rowchk = $conn->query($sqlchk);
$reschk = $rowchk->fetch_assoc();
if ($reschk < 1) {

$sqldel = "DELETE FROM `paper_types` WHERE id = $id";
$conn->query($sqldel);

$conn->query("INSERT INTO `process`(`type`) VALUES ('delete ptype')");


header('location:../ptypes.php');
} else {
    header('location:../warning.php');
}

?>