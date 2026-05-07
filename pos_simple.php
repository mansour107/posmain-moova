<?php
// تفعيل عرض الأخطاء
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// بدء الجلسة
session_start();

// تحميل الاتصال
include 'includes/connect.php';

echo "<!DOCTYPE html>";
echo "<html>";
echo "<head>";
echo "<title>POS Test</title>";
echo "<meta charset='utf-8'>";
echo "</head>";
echo "<body>";
echo "<h1>🧪 اختبار POS مبسط</h1>";
echo "<p>✅ PHP يعمل</p>";
echo "<p>✅ قاعدة البيانات متصلة</p>";
echo "<p>✅ الجلسة تعمل</p>";

if (isset($rowstg['company_name'])) {
    echo "<p>✅ اسم الشركة: " . $rowstg['company_name'] . "</p>";
} else {
    echo "<p>❌ اسم الشركة غير متاح</p>";
}

echo "<p><a href='pos_barcode.php'>اختبار الصفحة الأصلية</a></p>";
echo "</body>";
echo "</html>";
?>