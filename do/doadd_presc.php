<?php
include('../includes/connect.php');
echo '<pre>';
print_r($_POST);print_r($_GET);
$analyses = $_POST['analyses'];
$client = $_GET['id'];
$sqlvst = "SELECT * FROM reservations  where client = $client order by id DESC limit 1";
$rowvst = $conn->query("$sqlvst")->fetch_assoc();
$visit = $rowvst['id'];
$sqlprsc = "INSERT INTO prescs( client, visit, analayses) VALUES ('$client','$visit','$analyses')";


$conn->query($sqlprsc);
$prescid = $conn->insert_id;

if (isset($_POST['drug'])) {
    $drug = $_POST['drug'];
    $dose = $_POST['dose'];
    $c = count($_POST['drug']);
    
    for ($i= 0; $i < $c ; $i++) { 
     $drugid = $_POST['drug'][$i];
     $dose = $_POST['dose'][$i];
   $sqldrug = "INSERT INTO prescdetails(drug, dose, prescid) VALUES ('$drugid','$dose','$prescid')";
   $conn->query($sqldrug);
   
    }}

    $conn->query("INSERT INTO `process`(`type`) VALUES ('add presc')");

    header('location:../presc.php?id='.$prescid) 
   
?>