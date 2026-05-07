<?php

if ($_SERVER['REQUEST_METHOD'] == "POST") {

    $id = $_GET['id'];
    include('../includes/connect.php');
    foreach ($_POST as $key => $value) {
        $$key = $value;
    }

    $day = [];

    if (isset($_POST['sat'])) {
        array_push($day, '6');
    }
    if (isset($_POST['sun'])) {
        array_push($day, '7');
    }
    if (isset($_POST['mon'])) {
        array_push($day, '1');
    }
    if (isset($_POST['tus'])) {
        array_push($day, '2');
    }
    if (isset($_POST['wed'])) {
        array_push($day, '3');
    }
    if (isset($_POST['thur'])) {
        array_push($day, '4');
    }
    if (isset($_POST['fri'])) {
        array_push($day, '5');
    }
    $workingdays = implode(",", $day);

    
    
// Create DateTime objects for start and end times
$endTime = new DateTime($shiftend);
$startTime = new DateTime($shiftstart);

// Calculate the difference in seconds
$timeDiffInSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();

// Convert the difference to hours
$hours = $timeDiffInSeconds / 3600;



    $sqlshft ="UPDATE shifts
     SET 
     name = '$name' ,
    shiftstart = '$shiftstart',
    shiftend = '$shiftend' ,
    instart = '$instart',
    inend = '$inend' ,
    outstart = '$outstart' ,
    outend = '$outend',
    latelimit = '$latelimit',
    earlylimit = '$earlylimit',
    workingdays = '$workingdays',
    hours = '$hours' 
    WHERE id = '$id'";
    $conn->query($sqlshft);
    header('location:../shifts.php');
}
