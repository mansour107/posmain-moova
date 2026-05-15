<?php
include('../includes/connect.php');
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

function password_change_audit($eventType, array $options = [])
{
    global $conn;

    try {
        (new SecurityAuditLogger())->record($conn, $eventType, $options);
    } catch (Throwable $ignored) {
        error_log('[password_change_audit] ' . $eventType . ' audit failed: ' . $ignored->getMessage());
    }
}

function password_change_fail($message, $auditReason = null)
{
    if ($auditReason !== null) {
        password_change_audit('password_change_failed', [
            'user_id' => current_user_id(),
            'target_type' => 'user',
            'target_id' => current_user_id(),
            'metadata' => ['reason' => $auditReason],
        ]);
    }

    echo "<script>alert('" . addslashes($message) . "'); history.back();</script>";
    exit();
}

// التحقق من تسجيل الدخول
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    password_change_fail('طلب غير صالح', 'invalid_method');
}

if (!verify_csrf_from_post_or_header('password_change')) {
    password_change_fail('طلب غير صالح، حاول مرة أخرى', 'csrf_invalid');
}

$user_id = current_user_id();
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_new_password = $_POST['confirm_new_password'] ?? '';

// التحقق من تطابق كلمات المرور الجديدة
if ($new_password !== $confirm_new_password) {
    password_change_fail('كلمات المرور الجديدة غير متطابقة', 'password_confirmation_mismatch');
}

// التحقق من طول كلمة المرور
if (strlen($new_password) < 6) {
    password_change_fail('كلمة المرور يجب أن تكون 6 أحرف على الأقل', 'password_too_short');
}

// الحصول على كلمة المرور الحالية من قاعدة البيانات
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    password_change_fail('المستخدم غير موجود', 'user_not_found');
}

$user = $result->fetch_assoc();
$stored_hash = $user['password'];

if (!PasswordService::verifyPassword($current_password, $stored_hash)) {
    password_change_fail('كلمة المرور الحالية غير صحيحة', 'current_password_invalid');
}

// تشفير كلمة المرور الجديدة
$new_password_hash = PasswordService::hashPassword($new_password);

// تحديث كلمة المرور في قاعدة البيانات
$update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$update_stmt->bind_param("si", $new_password_hash, $user_id);

if ($update_stmt->execute()) {
    // تسجيل العملية
    $conn->query("INSERT INTO `process`(`type`) VALUES ('change password by user')");
    password_change_audit('password_changed', [
        'user_id' => $user_id,
        'target_type' => 'user',
        'target_id' => $user_id,
    ]);
    
    echo "<script>alert('تم تغيير كلمة المرور بنجاح'); window.location.href='../dashboard.php';</script>";
} else {
    password_change_fail('حدث خطأ أثناء تغيير كلمة المرور', 'update_failed');
}

$update_stmt->close();
$stmt->close();
?>
