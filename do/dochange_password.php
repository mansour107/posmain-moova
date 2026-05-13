<?php
include('../includes/connect.php');
require_once __DIR__ . '/../classes/PasswordService.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('location:../index.php');
    exit();
}

$user_id = $_SESSION['userid'];
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_new_password = $_POST['confirm_new_password'] ?? '';

// التحقق من تطابق كلمات المرور الجديدة
if ($new_password !== $confirm_new_password) {
    echo "<script>alert('كلمات المرور الجديدة غير متطابقة'); history.back();</script>";
    exit();
}

// التحقق من طول كلمة المرور
if (strlen($new_password) < 6) {
    echo "<script>alert('كلمة المرور يجب أن تكون 6 أحرف على الأقل'); history.back();</script>";
    exit();
}

// الحصول على كلمة المرور الحالية من قاعدة البيانات
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<script>alert('المستخدم غير موجود'); history.back();</script>";
    exit();
}

$user = $result->fetch_assoc();
$stored_hash = $user['password'];

if (!PasswordService::verifyPassword($current_password, $stored_hash)) {
    echo "<script>alert('كلمة المرور الحالية غير صحيحة'); history.back();</script>";
    exit();
}

// تشفير كلمة المرور الجديدة
$new_password_hash = PasswordService::hashPassword($new_password);

// تحديث كلمة المرور في قاعدة البيانات
$update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$update_stmt->bind_param("si", $new_password_hash, $user_id);

if ($update_stmt->execute()) {
    // تسجيل العملية
    $conn->query("INSERT INTO `process`(`type`) VALUES ('change password by user')");
    
    echo "<script>alert('تم تغيير كلمة المرور بنجاح'); window.location.href='../dashboard.php';</script>";
} else {
    echo "<script>alert('حدث خطأ أثناء تغيير كلمة المرور'); history.back();</script>";
}

$update_stmt->close();
$stmt->close();
?>
