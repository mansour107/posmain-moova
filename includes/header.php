<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['login'])) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/connect.php';

$userid = isset($_SESSION['userid']) ? (int) $_SESSION['userid'] : 0;
if ($userid < 1) {
    header('Location: index.php');
    exit;
}

$res_up = $conn->query('SELECT * FROM users WHERE id = ' . $userid . ' LIMIT 1');
if (!$res_up || $res_up->num_rows === 0) {
    session_destroy();
    header('Location: index.php');
    exit;
}
$up = $res_up->fetch_assoc();

date_default_timezone_set('Africa/Cairo');

$langCode = isset($rowstg['lang']) && $rowstg['lang'] !== '' && $rowstg['lang'] !== null
    ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $rowstg['lang'])
    : '';
if ($langCode === '') {
    $langCode = 'ar';
}
$langFile = __DIR__ . '/../language/' . $langCode . '.php';
if (!is_file($langFile)) {
    $langFile = __DIR__ . '/../language/ar.php';
}
$lang = $langCode;
require $langFile;

$bodyColor = isset($rowstg['bodycolor']) ? trim((string) $rowstg['bodycolor']) : '';
if ($bodyColor === '' || !preg_match('/^#[0-9A-Fa-f]{3,8}$/', $bodyColor)) {
    $bodyColor = '#f0fdfa';
}

$assetVer = is_file(__DIR__ . '/../dist/css/custom.css')
    ? (string) filemtime(__DIR__ . '/../dist/css/custom.css')
    : '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title><?= htmlspecialchars($lang_title ?? 'نظام الإدارة', ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="icon" href="assets/favicon/favicon.png" type="image/png">

  <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" href="plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
  <link rel="stylesheet" href="dist/css/ionicons.min.css">
  <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
  <link rel="stylesheet" href="plugins/icheck-bootstrap/icheck-bootstrap.min.css">
  <link rel="stylesheet" href="plugins/jqvmap/jqvmap.min.css">
  <link rel="stylesheet" href="dist/css/adminlte.min.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="dist/css/animate.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="plugins/daterangepicker/daterangepicker.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="plugins/summernote/summernote-bs4.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="plugins/hadi/google.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/libs/playpen-sans-arabic-local.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="dist/css/bootstrap4.2.min.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="dist/css/custom.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="plugins/select2/css/select2.min.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="dist/css/hadianime.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="dist/css/horstec.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/styles/dashboard.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="assets/styles/sidebar-fixes.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="css/operations_responsive.css?v=<?= htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8') ?>">

  <style>
  .card-primary:not(.modern-card) > .card-header {
    background-color: #007bff !important;
    color: #fff !important;
  }
  .card-warning:not(.modern-card) > .card-header {
    background-color: #ffc107 !important;
    color: #1f2d3d !important;
  }
  .card-success:not(.modern-card) > .card-header {
    background-color: #28a745 !important;
    color: #fff !important;
  }
  .card-info:not(.modern-card) > .card-header {
    background-color: #17a2b8 !important;
    color: #fff !important;
  }
  .card-danger:not(.modern-card) > .card-header {
    background-color: #dc3545 !important;
    color: #fff !important;
  }
  .card-primary .card-title, .card-success .card-title, .card-info .card-title, .card-danger .card-title {
    color: #fff !important;
  }

  body .wrapper .main-sidebar .nav-sidebar .nav-link:hover {
    background: linear-gradient(135deg, #eff6ff, #dbeafe) !important;
    color: #1e40af !important;
    transform: translateX(3px) scale(1.02) !important;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25) !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
  }
  body .wrapper .main-sidebar .nav-sidebar .nav-link:hover .nav-icon {
    color: #2563eb !important;
    transform: scale(1.15) rotate(5deg) !important;
  }
  input:focus {
    background-color: greenyellow !important;
    border-color: #005b39 !important;
    box-shadow: 0 0 0 0.2rem rgba(168, 252, 171, 0.25) !important;
    outline: none !important;
    transition: all 0.3s ease !important;
  }

  .content-wrapper {
    background-color: <?= htmlspecialchars($bodyColor, ENT_QUOTES, 'UTF-8') ?>;
  }
  .nav-link {
    color: #000 !important;
  }

  @font-face {
    font-family: "Font Awesome 5 Free";
    font-style: normal;
    font-weight: 900;
    font-display: block;
    src: url("assets/libs/webfonts/fa-solid-900.woff2") format("woff2");
  }
  @font-face {
    font-family: "Font Awesome 5 Free";
    font-style: normal;
    font-weight: 400;
    font-display: block;
    src: url("assets/libs/webfonts/fa-regular-400.woff2") format("woff2");
  }
  @font-face {
    font-family: "Font Awesome 5 Brands";
    font-style: normal;
    font-weight: 400;
    font-display: block;
    src: url("assets/libs/webfonts/fa-brands-400.woff2") format("woff2");
  }

  i.fa, i.fas, i.far, i.fab, i.fal, i.fad,
  span.fa, span.fas, span.far, span.fab, span.fal, span.fad,
  .fa, .fas, .far, .fab, .fal, .fad {
    font-family: "Font Awesome 5 Free" !important;
    font-weight: 900 !important;
    font-style: normal !important;
    font-variant: normal !important;
    text-rendering: auto !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
    display: inline-block !important;
    direction: ltr !important;
  }
  .far {
    font-weight: 400 !important;
  }
  .fab {
    font-family: "Font Awesome 5 Brands" !important;
    font-weight: 400 !important;
  }
  </style>

  <script src="dist/modal/modal.js"></script>
  <script src="dist/css/tailwind.js"></script>
  <script src="dist/js/js.js"></script>
</head>

<body class="hold-transition sidebar-mini sidebar-collapse layout-fixed font-semibold" style="font-family: 'Playpen Sans Arabic', cursive;">
  <div class="wrapper">
