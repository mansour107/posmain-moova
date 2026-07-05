<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_logout.php');

// التحقق من وجود جلسة صالحة
if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:../index.php');
    exit();
}

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
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => (bool) ($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

header('location:../index.php');
exit();
?>
