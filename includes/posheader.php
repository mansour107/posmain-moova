<?php 



session_start();
if (!isset($_SESSION['login'])) {

  header('location:index.php');
}
include('includes/connect.php');

$userid = $_SESSION['userid'];
$up = $conn->query("SELECT * FROM users where id = $userid ");

date_default_timezone_set('Africa/Cairo');


?>
<?php
$lang = $rowstg['lang'];
if ($lang == null) {
  include('language/ar.php');
} else {
  include('language/' . $lang . '.php');
}
?>
<!DOCTYPE html>
<html>

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title><?= $lang_title ?></title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="icon" href="assets/favicon/favicon.png" type="image/ico">

  <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" href="plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
  <!-- Theme style -->
  <!-- Ionicons -->
  <link rel="stylesheet" href="dist/css/ionicons.min.css">
  <!-- Tempusdominus Bbootstrap 4 -->
  <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
  <!-- iCheck -->
  <link rel="stylesheet" href="plugins/icheck-bootstrap/icheck-bootstrap.min.css">
  <!-- JQVMap -->
  <link rel="stylesheet" href="plugins/jqvmap/jqvmap.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <link rel="stylesheet" href="dist/css/animate.css">
  <link rel="stylesheet" href="dist/css/animate.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Daterange picker -->
  <link rel="stylesheet" href="plugins/daterangepicker/daterangepicker.css">
  <!-- summernote -->
  <link rel="stylesheet" href="plugins/summernote/summernote-bs4.css">
  <link href="plugins/hadi/google.css" rel="stylesheet">
  <link rel="stylesheet" href="dist/css/bootstrap4.2.min.css">

  <link rel="stylesheet" href="dist/css/custom.css">
  <link rel="stylesheet" href="plugins/select2/css/select2.min.css">
  <link rel="stylesheet" href="plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">
  <link href="dist/css/hadianime.css" rel="stylesheet">
  <link href="dist/css/horstec.css" rel="stylesheet">
 


<script src="dist/modal/modal.js"></script>

<script>console.log('0000000000000000000000000')</script>
  <script src="dist/css/tailwind.js"></script>
  <script>console.log('111111111111111111')</script>



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

</style>

  <script src="dist/js/js.js"></script>
</head>
<!-- 
<div class="loader">
<center>
<div class="hazaz">HORSTEC<div class="spinner-grow" role="status">
  <span class="sr-only">Loading...</span>
  </div>
  </div>

<p style="font-size:4vw !important" class="hadi-fade-in2">this may take few seconds</p>


</center>
</div> -->

<body class="" style="font-family: 'Roboto', tahoma;">
 
