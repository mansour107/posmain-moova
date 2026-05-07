<?php
include('../includes/connect.php');


if ($_SERVER['REQUEST_METHOD'] == "POST") {
    foreach ($_POST as $key => $value) {
        $$key = $value;
    }
    print_r($_POST);
    $sql = "INSERT INTO `hiringcontracts`(`name` , `employee` , `jop` , `jopdescription` , `salary` , `salaryraise` , `endcontract` , `workhours` ,
    `inorderhours` , `workdaysoff` , `info` , `user` , `startcontract`,`joprule1` , `joprule2` , `joprule3` , `joprule4`)
    VALUES ('$name' , '$employee' , '$jop' , '$jopdescription' , '$salary' , '$salaryraise' , '$endcontract' , '$workhours' , '$inorderhours',
    '$workdaysoff' , '$info', '$user' , '$startcontract' , '$joprule1' , '$joprule2' , '$joprule3' , '$joprule4');";


    $conn->query($sql);
    if (isset($_POST['allow'])) {
        $allow = $_POST['allow'];
        $value = $_POST['value'];
        $c = count($_POST['allow']);
        
        for ($i= 0; $i < $c ; $i++) { 
         $allowid = $_POST['allow'][$i];
         $value = $_POST['value'][$i];
       $sqlallow = "INSERT INTO emp_allowences(empid, allowid, value) VALUES ('$employee','$allowid','$value')";
       $conn->query($sqlallow); 

        }
    }

    $conn->query("INSERT INTO `process`(`type`) VALUES ('add hiring contract')");

    header("location:../hiringcontracts.php");
    exit;
}
