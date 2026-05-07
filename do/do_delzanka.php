<?php include('../includes/connect.php') ;
$id = $_GET['id'];
$sqlchk = "select * from zankat where id = $id";
$rowchk = $conn->query($sqlchk);
$reschk = $rowchk->fetch_assoc();
if ($reschk < 1) {

    header('location:../warning.php');
} else {
    $sqldel = "DELETE FROM `zankat` WHERE id = $id";
    $conn->query($sqldel);
    $conn->query("INSERT INTO `process`(`type`) VALUES ('delete zanka')");
    
    header('location:../zankat.php');
    }

?>