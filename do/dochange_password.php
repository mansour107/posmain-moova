<?php
include('../includes/connect.php');

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

// التحقق من كلمة المرور الحالية
$current_password_valid = false;

// التحقق من كلمة المرور حسب نوع التشفير
if (strlen($stored_hash) >= 60 || str_starts_with($stored_hash, '$2y$') || str_starts_with($stored_hash, '$2a$') || str_starts_with($stored_hash, '$argon')) {
    // كلمة المرور مشفرة بـ password_hash
    if (password_verify($current_password, $stored_hash)) {
        $current_password_valid = true;
    }
} elseif (strlen($stored_hash) === 32) {
    // كلمة المرور مشفرة بـ MD5 (دعم قديم)
    if (md5($current_password) === $stored_hash) {
        $current_password_valid = true;
    }
} else {
    // محاولة التحقق العام
    if (password_verify($current_password, $stored_hash)) {
        $current_password_valid = true;
    }
}

if (!$current_password_valid) {
    echo "<script>alert('كلمة المرور الحالية غير صحيحة'); history.back();</script>";
    exit();
}

// تشفير كلمة المرور الجديدة
$new_password_hash = md5($new_password);

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
