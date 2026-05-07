<?php 
// Check if session is already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['login'])) {
    header('location:index.php');
    exit;
}

// Fix the include path - we're already in the includes directory
include(__DIR__ . '/connect.php');

$userid = $_SESSION['userid'];
$up = $conn->query("SELECT * FROM users where id = $userid ");

date_default_timezone_set('Africa/Cairo');

// Get language - use a more robust approach to find language files
$lang = isset($rowstg['lang']) ? $rowstg['lang'] : 'ar';

// Try multiple paths to find the language file
$language_paths = [
    '../language/' . $lang . '.php',  // From ajax directory
    '../../language/' . $lang . '.php', // From deeper ajax subdirectory
    'language/' . $lang . '.php',     // From root
    '../language/' . $lang . '.php',  // From includes directory
];

$language_file_found = false;
if ($lang != null && $lang != '') {
    foreach ($language_paths as $path) {
        if (file_exists($path)) {
            include($path);
            $language_file_found = true;
            break;
        }
    }
}

// If specific language file not found or lang is empty, try arabic as default
if (!$language_file_found || $lang == null || $lang == '') {
    $default_paths = [
        '../language/ar.php',
        '../../language/ar.php',
        'language/ar.php',
        '../language/ar.php'
    ];
    
    foreach ($default_paths as $path) {
        if (file_exists($path)) {
            include($path);
            $language_file_found = true;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= isset($lang_title) ? $lang_title : 'نظام نقاط البيع' ?></title>
    
    <!-- Favicon -->
    <link rel="icon" href="assets/favicon/favicon.png" type="image/png">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    
    <!-- NO Bootstrap here - will be loaded in page -->
    
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>