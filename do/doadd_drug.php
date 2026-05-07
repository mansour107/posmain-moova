<?php include('../includes/connect.php');


$name = $_POST['name'];
$purpose = $_POST['purpose'];
$effectivematerial = $_POST['effectivematerial'];
$sideeffects = $_POST['sideeffects'];
$info = $_POST['info'];
$user = $_POST['user'];

$res1 = $conn->query("SELECT * FROM DRUGS WHERE NAME = '$name' ");
$numrows = mysqli_num_rows($res1);

if ($numrows > 0) {
    header('location:../warning.php?m=nameexists');
}else{
    $sql="INSERT INTO drugs(name, effectivematerial, purpose, sideeffects, info , user) VALUES ('$name','$effectivematerial','$purpose','$sideeffects','$info','$user')";
    $conn->query($sql);

    $conn->query("INSERT INTO `process`(`type`) VALUES ('add drug')");

header('location:../drugs.php');
}