<?php include('../includes/connect.php');
$password = $_POST['password'];
$syspass = $rowstg['edit_pass'];;
if ($password == $syspass) {
$id = $_POST['id'];
$conn->query("UPDATE jops SET isdeleted = 1 where id = $id");
header('location:../jops.php');}else{
    echo "password not correct";
}
