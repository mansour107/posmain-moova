<?php
include('includes/connect.php');

echo "<h2>🔍 التحقق من هيكل قاعدة البيانات</h2>";
echo "<hr>";

// Check ot_head structure
echo "<h3>جدول ot_head:</h3>";
$result = $conn->query("DESCRIBE ot_head");

if ($result) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #0a7ea4; color: white;'>";
    echo "<th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th>";
    echo "</tr>";
    
    $has_table_id = false;
    $has_order_status = false;
    
    while ($row = $result->fetch_assoc()) {
        $highlight = '';
        if ($row['Field'] == 'table_id') {
            $has_table_id = true;
            $highlight = 'background: #d4edda;';
        }
        if ($row['Field'] == 'order_status') {
            $has_order_status = true;
            $highlight = 'background: #d4edda;';
        }
        
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
    
    echo "<hr>";
    echo "<h3>📊 نتيجة الفحص:</h3>";
    echo "<ul>";
    
    if ($has_table_id) {
        echo "<li>✅ حقل <strong>table_id</strong> موجود</li>";
    } else {
        echo "<li>❌ حقل <strong>table_id</strong> غير موجود - يجب تنفيذ التحديثات!</li>";
    }
    
    if ($has_order_status) {
        echo "<li>✅ حقل <strong>order_status</strong> موجود</li>";
    } else {
        echo "<li>❌ حقل <strong>order_status</strong> غير موجود - يجب تنفيذ التحديثات!</li>";
    }
    
    echo "</ul>";
    
    if (!$has_table_id || !$has_order_status) {
        echo "<div style='background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h4>⚠️ تحذير: قاعدة البيانات تحتاج تحديث!</h4>";
        echo "<p>افتح الرابط التالي لتنفيذ التحديثات:</p>";
        echo "<a href='run_table_updates.php' class='btn btn-warning' style='background: #ffc107; color: #000; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>";
        echo "🔄 تنفيذ تحديثات قاعدة البيانات";
        echo "</a>";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h4>✅ قاعدة البيانات جاهزة!</h4>";
        echo "<p>يمكنك الآن استخدام نظام الطاولات بشكل كامل.</p>";
        echo "<a href='pos_barcode.php' class='btn btn-success' style='background: #28a745; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>";
        echo "🚀 اذهب إلى نظام POS";
        echo "</a>";
        echo "</div>";
    }
    
} else {
    echo "<p style='color: red;'>❌ خطأ في الاستعلام: " . $conn->error . "</p>";
}

$conn->close();
?>

<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
    table { width: 100%; margin: 10px 0; }
    th { text-align: left; }
</style>

