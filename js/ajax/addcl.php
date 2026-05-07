<?php
include '../../includes/connect.php';


    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $city = $_POST['city'];
    $address = $_POST['address'];
    $gender = $_POST['gender'];
    $height = $_POST['height'];
    $weight = $_POST['weight'];
    $dateofbirth = $_POST['dateofbirth'];
    $checkname = "SELECT name from clients where name = $name";
    if ($conn->query($conn->query($checkname)) > 0) { echo "هذا الاسم موجود مسبقا في ";}
    
    $checkphone = "SELECT phone from clients where phone = $phone";
    if ($conn->query($conn->query($checkphone)) > 0) { echo "هذا الرقم موجود مسبقا في ";}

    $sql = "INSERT INTO clients (name, phone, city, address, gender, height, weight, dateofbirth) 
            VALUES ('$name', '$phone', '$city', '$address', '$gender', '$height', '$weight', '$dateofbirth')";
      
        if ($conn->query($sql)) {
           echo "success";
        } else {echo "forbiden";}
echo json_encode($clientNames);
$conn->close();
?>