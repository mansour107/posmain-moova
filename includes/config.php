<?php
/**
 * ملف الإعدادات الآمن
 * يحتوي على إعدادات قاعدة البيانات والإعدادات العامة
 */

// إعدادات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'focus');

// إعدادات الأمان
define('SITE_URL', 'http://localhost/horstec');
define('ADMIN_EMAIL', 'admin@horstec.com');

// إعدادات الجلسة
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // ضع 1 في HTTPS
ini_set('session.cookie_samesite', 'Strict');

// إعدادات أخرى
date_default_timezone_set('Africa/Cairo');

// دالة الاتصال بقاعدة البيانات
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            die("Database connection failed");
        }
        
        // تعيين charset
        $conn->set_charset("utf8");
    }
    
    return $conn;
}

// دالة تنظيف البيانات المدخلة
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// دالة التحقق من صحة البريد الإلكتروني
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// دالة التحقق من صحة الرقم
function validateNumber($number) {
    return is_numeric($number) && $number > 0;
}

// دالة تسجيل الأخطاء
function logError($message, $file = '', $line = 0) {
    $log_message = date('Y-m-d H:i:s') . " - Error: $message";
    if ($file) $log_message .= " in $file";
    if ($line) $log_message .= " on line $line";
    $log_message .= "\n";
    
    error_log($log_message, 3, 'logs/error.log');
}

// دالة التحقق من تسجيل الدخول
function checkLogin() {
    if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
        header('location:../index.php');
        exit();
    }
}

// دالة تشفير كلمة المرور
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// دالة التحقق من كلمة المرور
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
?>
