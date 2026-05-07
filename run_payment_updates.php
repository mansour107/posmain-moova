<?php
include('includes/connect.php');

echo "<h2>تحديث قاعدة البيانات لنظام السداد المحسن</h2>";

try {
    // قراءة ملف SQL
    $sql_file = 'update_payment_columns.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("ملف SQL غير موجود: $sql_file");
    }
    
    $sql_content = file_get_contents($sql_file);
    $queries = explode(';', $sql_content);
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($queries as $query) {
        $query = trim($query);
        if (empty($query)) continue;
        
        try {
            if ($conn->query($query)) {
                echo "<p style='color: green;'>✓ تم تنفيذ: " . substr($query, 0, 50) . "...</p>";
                $success_count++;
            } else {
                echo "<p style='color: red;'>✗ خطأ في: " . substr($query, 0, 50) . "... - " . $conn->error . "</p>";
                $error_count++;
            }
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ تحذير: " . substr($query, 0, 50) . "... - " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<hr>";
    echo "<h3>ملخص التحديث:</h3>";
    echo "<p>العمليات الناجحة: <strong style='color: green;'>$success_count</strong></p>";
    echo "<p>العمليات الفاشلة: <strong style='color: red;'>$error_count</strong></p>";
    
    if ($error_count == 0) {
        echo "<p style='color: green; font-weight: bold;'>✓ تم تحديث قاعدة البيانات بنجاح!</p>";
        echo "<p><a href='pos_tables.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>الذهاب إلى نظام الطاولات</a></p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>⚠ هناك أخطاء في التحديث. يرجى مراجعة الأخطاء أعلاه.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>خطأ عام: " . $e->getMessage() . "</p>";
}
?>