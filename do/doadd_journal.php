<?php
session_start();
$user = $_SESSION['userid'];
include '../includes/connect.php';
// echo "<pre>";
// print_r($_POST);die;
// echo "</pre>";

    $journal_id = $_POST["journal_id"];
    $jdate = $_POST["jdate"];
    $details = $_POST["details"] ; 
    $rowdepit1 = $_POST["rowdepit"][0] ;
    $rowdepit2 = $_POST["rowdepit"][1] ;
    $rowdepit3 = $_POST["rowdepit"][2] ;
    $creditrow1 = $_POST["creditrow"][0] ;
    $creditrow2 = $_POST["creditrow"][1] ;
    $creditrow3 = $_POST["creditrow"][2] ;

    $sql1 = "INSERT INTO journal_heads (journal_id, total, details,user,jdate) VALUES ('$journal_id','$jdate','$details','$user','$jdate')";
    $conn->query($sql1);


    $journal_lastid =  $conn->insert_id;
    
    
    $sql2 = "INSERT INTO journal_entries ( journal_id, account_id, debit, credit,tybe,info) VALUES ('$journal_lastid',' $rowdepit2','$rowdepit1','0','0','$rowdepit3')";
    $conn->query($sql2);

    $sql3 = "INSERT INTO journal_entries ( journal_id, account_id, debit, credit,tybe,info) VALUES ('$journal_lastid','$creditrow2','0','$creditrow1','1','$creditrow3')";
    $conn->query($sql3);
    $conn->query("INSERT INTO `process`(`type`) VALUES ('add journal')");

    header("location:../daily_journal.php");


   



