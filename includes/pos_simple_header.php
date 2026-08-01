<?php 
require_once __DIR__ . '/session_bootstrap.php';

if (!isset($_SESSION['login'])) {
    header('location:index.php');
    exit;
}

// Reuse an existing POS bootstrap connection when the page has already loaded it.
if (!isset($conn) || !($conn instanceof mysqli)) {
    include(__DIR__ . '/connect.php');
}

require_once __DIR__ . '/auth_guard.php';
$posmainHeaderPermission = trim((string) ($posmainHeaderPermission ?? 'pos.open'));
if ($posmainHeaderPermission === '') {
    $posmainHeaderPermission = 'pos.open';
}
require_permission($posmainHeaderPermission, $conn);
if ($posmainHeaderPermission === 'pos.open') {
    require_once __DIR__ . '/pos_main_pin_entry.php';
    posmain_apply_main_pin_pos_entry($conn, basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'pos.php')));
    if (!auth_guard_is_pos_barcode_unlocked()) {
        include __DIR__ . '/pos_login_screen.php';
        exit;
    }
}

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
    <link rel="icon" href="assets/logo/moova.png" type="image/png">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    
    <!-- NO Bootstrap here - will be loaded in page -->
    
    <style>
        body {
            font-family: 'Inter', 'IBM Plex Sans Arabic', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body<?= !empty($pos_body_class) ? ' class="' . htmlspecialchars($pos_body_class, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
<?php require __DIR__ . '/main_session_lock_client.php'; ?>
<?php if (!empty($success_message)) { include __DIR__ . '/pos_success_message.php'; } ?>
