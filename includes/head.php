<?php session_start();
if (!isset($_SESSION['login'])) {

  header('location:index.php');
}
include('includes/../connect.php');
$userid = $_SESSION['userid'];
$up = $conn->query("SELECT * FROM users where id = $userid ");


?>
<?php
$lang = $rowstg['lang'];
if ($lang == null) {
  include('../language/ar.php');
} else {
  include('../language/' . $lang . '.php');
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
  <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
  <link rel="icon" href="../uploads/22947314.png" type="image/ico">
  <!-- Ionicons -->
  <link rel="stylesheet" href="../dist/css/ionicons.min.css">
  <!-- Tempusdominus Bbootstrap 4 -->
  <link rel="stylesheet" href="../plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
  <!-- iCheck -->
  <link rel="stylesheet" href="../plugins/icheck-bootstrap/icheck-bootstrap.min.css">
  <!-- JQVMap -->
  <link rel="stylesheet" href="../plugins/jqvmap/jqvmap.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="../dist/css/adminlte.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="../plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Daterange picker -->
  <link rel="stylesheet" href="../plugins/daterangepicker/daterangepicker.css">
  <!-- summernote -->
  <link rel="stylesheet" href="../plugins/summernote/summernote-bs4.css">
  <!-- Google Font: Source Sans Pro -->
  <link href="../plugins/hadi/google.css" rel="stylesheet">
  <!-- Bootstrap 4 RTL -->
  <link rel="stylesheet" href="../dist/css/bootstrap4.2.min.css">
  <!-- Custom style for RTL -->
  <link rel="stylesheet" href="../dist/css/custom.css">
  <link rel="stylesheet" href="../plugins/select2/css/select2.min.css">
  <link rel="stylesheet" href="../plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">
  <!-- Bootstrap4 Duallistbox -->
  <link rel="stylesheet" href="../plugins/bootstrap4-duallistbox/bootstrap-duallistbox.min.css">


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
      padding: 5px;
    }

    .ltr {
      float: left;
      width: 80%
    }

    .error {
      color: red;
      font-size: 16px;
      margin-top: 5px;
    }

    input.parsley-success,
    select.parsley-success,
    textarea.parsley-success {
      color: #468847;
      background-color: #DFF0D8;
      border: 1px solid #D6E9C6;
    }

    input.parsley-error,
    select.parsley-error,
    textarea.parsley-error {
      color: #B94A48;
      background-color: #F2DEDE;
      border: 1px solid #EED3D7;
    }

    .parsley-errors-list {
      margin: 2px 0 3px;
      padding: 0;
      list-style-type: none;
      font-size: 0.9em;
      line-height: 0.9em;
      opacity: 0;

      transition: all .3s ease-in;
      -o-transition: all .3s ease-in;
      -moz-transition: all .3s ease-in;
      -webkit-transition: all .3s ease-in;
    }

    .parsley-errors-list.filled {
      opacity: 1;
    }

    .parsley-type,
    .parsley-required,
    .parsley-equalto {
      color: #ff0000;
    }
  
  </style>

  <script src="dist/js/js.js"></script>
</head>
<div class="wrapper">

<body class="hold-transition sidebar-mini layout-fixed">
