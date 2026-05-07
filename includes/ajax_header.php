<?php 
// AJAX Header - No HTML output, only authentication and database connection
// Use this for AJAX endpoints that return JSON

// Check if session is already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['login'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized - Please login'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Include database connection
include(__DIR__ . '/connect.php');

// Set user ID
$userid = $_SESSION['userid'];

// Set timezone
date_default_timezone_set('Africa/Cairo');

// Get user permissions if needed
$up = $conn->query("SELECT * FROM users WHERE id = $userid");

// Load language file if needed (without HTML output)
$lang = isset($rowstg['lang']) ? $rowstg['lang'] : 'ar';

$language_paths = [
    __DIR__ . '/../language/' . $lang . '.php',
    __DIR__ . '/language/' . $lang . '.php',
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

// If specific language file not found, try arabic as default
if (!$language_file_found || $lang == null || $lang == '') {
    $default_paths = [
        __DIR__ . '/../language/ar.php',
        __DIR__ . '/language/ar.php'
    ];
    
    foreach ($default_paths as $path) {
        if (file_exists($path)) {
            include($path);
            break;
        }
    }
}

// NO HTML OUTPUT - This is for AJAX only
?>
