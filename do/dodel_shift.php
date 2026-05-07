<?php include('../includes/connect.php');
$password = $_POST['password'];
$syspass = $rowstg['edit_pass'];
if ($password == $syspass) {
    $id = $_GET['id'];
    $conn->query("UPDATE shifts SET isdeleted = 1 where id = $id");
    header('location:../shifts.php');
} else {
    echo "password not correct";
}
?>
