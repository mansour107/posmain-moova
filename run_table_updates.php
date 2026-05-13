<?php
require_once __DIR__ . '/includes/production_guard.php';
production_guard_deny_route('run_table_updates.php');

// =====================================================
// تنفيذ تحديثات نظام الطاولات
// =====================================================

include('includes/connect.php');

echo "<h2>🚀 تنفيذ تحديثات نظام الطاولات...</h2><hr>";

// قراءة ملف SQL
$sql_file = 'update_tables_system.sql';
$sql_content = file_get_contents($sql_file);

// تقسيم الاستعلامات
$queries = array_filter(
    array_map('trim', explode(';', $sql_content)),
    function($query) {
        // إزالة التعليقات والأسطر الفارغة
        $query = preg_replace('/--.*$/m', '', $query);
        $query = trim($query);
        return !empty($query) && substr($query, 0, 2) !== '--';
    }
);

$success_count = 0;
$error_count = 0;
$skipped_count = 0;

foreach ($queries as $query) {
    if (empty($query)) continue;
    
    echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-right: 4px solid #0a7ea4;'>";
    echo "<strong>تنفيذ:</strong><br><code>" . htmlspecialchars(substr($query, 0, 100)) . "...</code><br>";
    
    try {
        if ($conn->query($query)) {
            echo "<span style='color: green;'>✅ نجح التنفيذ</span>";
            $success_count++;
        } else {
            $error_msg = $conn->error;
            
            // تجاهل أخطاء الأعمدة الموجودة بالفعل
            if (strpos($error_msg, 'Duplicate column name') !== false ||
                strpos($error_msg, 'Duplicate key name') !== false) {
                echo "<span style='color: orange;'>⚠️ موجود بالفعل (تم التجاهل)</span>";
                $skipped_count++;
            } else {
                echo "<span style='color: red;'>❌ خطأ: " . htmlspecialchars($error_msg) . "</span>";
                $error_count++;
            }
        }
    } catch (Exception $e) {
        echo "<span style='color: red;'>❌ استثناء: " . htmlspecialchars($e->getMessage()) . "</span>";
        $error_count++;
    }
    
    echo "</div>";
}

echo "<hr>";
echo "<h3>📊 النتائج النهائية:</h3>";
echo "<ul>";
echo "<li>✅ نجح: <strong>$success_count</strong></li>";
echo "<li>⚠️ تم تجاهله (موجود بالفعل): <strong>$skipped_count</strong></li>";
echo "<li>❌ فشل: <strong>$error_count</strong></li>";
echo "</ul>";

if ($error_count == 0) {
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px;'>";
    echo "<h4>🎉 تم تحديث قاعدة البيانات بنجاح!</h4>";
    echo "<p>يمكنك الآن استخدام نظام الطاولات الجديد.</p>";
    echo "<a href='pos_barcode.php' class='btn btn-primary'>اذهب إلى نظام POS</a>";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px;'>";
    echo "<h4>⚠️ حدثت بعض الأخطاء</h4>";
    echo "<p>راجع الأخطاء أعلاه وحاول تنفيذ الاستعلامات يدوياً من phpMyAdmin.</p>";
    echo "</div>";
}

// عرض هيكل الجدول المحدث
echo "<hr>";
echo "<h3>📋 هيكل جدول ot_head المحدث:</h3>";
$result = $conn->query("DESCRIBE ot_head");
if ($result) {
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #0a7ea4; color: white;'>";
    echo "<th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th>";
    echo "</tr>";
    while ($row = $result->fetch_assoc()) {
        $highlight = (in_array($row['Field'], ['table_id', 'order_status'])) ? "background: #d4edda;" : "";
        echo "<tr style='$highlight'>";
        echo "<td><strong>" . htmlspecialchars($row['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

$conn->close();
?>

<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
    .btn-primary { 
        background: #0a7ea4; 
        color: white; 
        padding: 10px 20px; 
        text-decoration: none; 
        border-radius: 5px; 
        display: inline-block;
        margin-top: 10px;
    }
    .btn-primary:hover { background: #086482; }
</style>
