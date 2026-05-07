<?php 
session_start();
if (!isset($_SESSION['login'])) {

  header('location:index.php');
}
include('connect.php');
$userid = $_SESSION['userid'];
$up = $conn->query("SELECT * FROM users where id = $userid ")



?>
<!DOCTYPE html>
<html>

<head>
<meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="../dist/css/bootstrap4.2.min.css">
  <link rel="stylesheet" href="../dist/css/custom.css">
  <link href="../dist/css/horstec.css" rel="stylesheet">
 

  <style>
    .content-wrapper{
background-color:<?= $rowstg['bodycolor']?>;
}  
.nav-link{
  color:black !important;
  border:1;
}
.content-wrapper{
  background-color: <?= $rowstg['bodycolor'] ?> ;

}
@media print {
    .no-print {
        display: none !important;
    }
}    
  </style>

  <script src="../dist/js/js.js">

  </script>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
