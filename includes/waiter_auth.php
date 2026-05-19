<?php
require_once __DIR__ . '/session_bootstrap.php';

/**
 * Waiter Authentication Check
 * يجب تضمين هذا الملف في أي صفحة تحتاج حماية للويترز فقط
 * تم التحديث: استخدام جدول users بدلاً من waiters
 */

// التحقق من تسجيل دخول الويتر
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    // إعادة التوجيه لصفحة تسجيل الدخول
    header('Location: waiter_login.php');
    exit;
}

// التحقق من أن المستخدم هو ويتر
if (!isset($_SESSION['is_waiter']) || $_SESSION['is_waiter'] != 1) {
    // المستخدم ليس ويتر
    header('Location: waiter_login.php?error=not_waiter');
    exit;
}

// التحقق من صلاحية الجلسة (اختياري - انتهاء الجلسة بعد فترة معينة)
$session_timeout = posmain_session_lifetime_seconds();
if (isset($_SESSION['waiter_login_time'])) {
    $elapsed_time = time() - $_SESSION['waiter_login_time'];
    if ($elapsed_time > $session_timeout) {
        // انتهت صلاحية الجلسة
        session_unset();
        session_destroy();
        header('Location: waiter_login.php?timeout=1');
        exit;
    }
}

// تحديث وقت آخر نشاط
$_SESSION['waiter_last_activity'] = time();
?>
