<?php 
session_start();

// التحقق من وجود جلسة صالحة
if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:../index.php');
    exit();
}

include('../includes/connect.php');

// تسجيل عملية تسجيل الخروج
$user = $_SESSION['login'];
$user_id = $_SESSION['userid'];

$stmt = $conn->prepare("INSERT INTO `process`(`type`) VALUES (?)");
$process_type = "logout >> " . $user;
$stmt->bind_param("s", $process_type);
$stmt->execute();

// تنظيف الجلسة بشكل آمن
$_SESSION = array();

// حذف cookie الجلسة
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header('location:../index.php');
exit();
?>