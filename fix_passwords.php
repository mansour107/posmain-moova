<?php
/**
 * ملف إصلاح كلمات المرور
 * يحول كلمات المرور من MD5 إلى password_hash
 * يجب حذف هذا الملف بعد الاستخدام
 */

include('includes/connect.php');

// التحقق من وجود كلمة مرور خاصة للتشغيل
$admin_password = "HORSTEC_SECURE_2024";

if (!isset($_GET['key']) || $_GET['key'] !== $admin_password) {
    die("Access denied. This file should be deleted after use.");
}

echo "<h2>بدء عملية إصلاح كلمات المرور...</h2>";

// الحصول على جميع المستخدمين
$result = $conn->query("SELECT id, uname, password FROM users WHERE isdeleted != 1");

if ($result->num_rows > 0) {
    $updated_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $user_id = $row['id'];
        $username = $row['uname'];
        $old_password = $row['password'];
        
        // التحقق من أن كلمة المرور الحالية هي MD5 (32 حرف)
        if (strlen($old_password) === 32 && ctype_xdigit($old_password)) {
            // كلمة المرور الحالية هي MD5، نحتاج لتحديثها
            // نضع كلمة مرور افتراضية: username123
            $new_password = password_hash($username . "123", PASSWORD_DEFAULT);
            
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $new_password, $user_id);
            
            if ($update_stmt->execute()) {
                echo "<p>تم تحديث كلمة مرور المستخدم: $username (كلمة المرور الجديدة: {$username}123)</p>";
                $updated_count++;
            } else {
                echo "<p>خطأ في تحديث كلمة مرور المستخدم: $username</p>";
            }
            
            $update_stmt->close();
        } else {
            echo "<p>كلمة مرور المستخدم $username محدثة بالفعل</p>";
        }
    }
    
    echo "<h3>تم تحديث $updated_count مستخدم</h3>";
    echo "<p><strong>تحذير:</strong> يجب حذف هذا الملف فوراً بعد الانتهاء!</p>";
    echo "<p>كلمات المرور الجديدة: username123 (حيث username هو اسم المستخدم)</p>";
    
} else {
    echo "<p>لم يتم العثور على مستخدمين</p>";
}

$conn->close();
?>

<script>
// حذف الملف تلقائياً بعد 30 ثانية
setTimeout(function() {
    if (confirm('هل تريد حذف ملف الإصلاح الآن؟')) {
        fetch('delete_fix_file.php', {method: 'POST'})
        .then(() => {
            alert('تم حذف الملف بنجاح');
            window.close();
        });
    }
}, 30000);
</script>
