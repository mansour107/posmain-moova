<?php session_start();
if (!isset($_COOKIE['login'])) {

  header('location:indexmop.php');
}
include('includes/connect.php');
$userid = $_COOKIE['login'];
$up = $conn->query("SELECT * FROM employees where id = $userid ")
?>
<?php include('language/ar.php') ?>
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
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Daterange picker -->
  <link rel="stylesheet" href="plugins/daterangepicker/daterangepicker.css">
  <!-- summernote -->
  <link rel="stylesheet" href="plugins/summernote/summernote-bs4.css">
  <!-- Google Font: Source Sans Pro -->
  <link href="assets/fonts/fonts.css" rel="stylesheet">
  <!-- Bootstrap 4 RTL -->
  <link rel="stylesheet" href="dist/css/bootstrap-rtl.min.css">
  <!-- Custom style for RTL -->
  <link rel="stylesheet" href="dist/css/custom.css">

  <style>
    .col-md-4 {
      float: left !important
    }

    .card-title {
      float: right
    }

    .main-footer {
      width: 80% !important;
      float: left
    }

    .cover {
      width: 100px;
      height: auto
    }

    .posframe {
      float: left;
      width: 80%;
      height: 1000px;
      margin-left: 0px;
    }

    .footer {
      position: fixed;
      bottom: 0%;
      left: 0%;

    }

    .row {
      padding: 5px
    }

    .ltr {
      float: left;
      width: 80%
    }
  </style>

  <script src="dist/js/js.js"></script>
</head>

<body class="hold-transition sidebar-mini layout-fixed" style="background-image: url('assets/wallpaper/background.jpg');" dir="rtl">
  <div class="wrapper">