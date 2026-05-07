<?php
/**
 * إصلاح سريع للمشاكل
 */

// إعدادات قاعدة البيانات
$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'focus';

$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>إصلاح سريع للمشاكل</h2>";

// 1. إنشاء جدول system_logs
$sql = "CREATE TABLE IF NOT EXISTS `system_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `timestamp` datetime NOT NULL,
    `level` varchar(20) NOT NULL,
    `message` text NOT NULL,
    `user_id` int(11) DEFAULT NULL,
    `username` varchar(100) DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    `request_uri` varchar(500) DEFAULT NULL,
    `context` text DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

if ($conn->query($sql) === TRUE) {
    echo "<p>✅ تم إنشاء جدول system_logs</p>";
} else {
    echo "<p>❌ خطأ في إنشاء الجدول: " . $conn->error . "</p>";
}

// 2. إنشاء مجلد logs
if (!file_exists('logs')) {
    if (mkdir('logs', 0755, true)) {
        echo "<p>✅ تم إنشاء مجلد logs</p>";
    } else {
        echo "<p>❌ خطأ في إنشاء مجلد logs</p>";
    }
} else {
    echo "<p>✅ مجلد logs موجود</p>";
}

// 3. اختبار النظام
echo "<h3>اختبار النظام:</h3>";

try {
    // تحميل النظام
    require_once 'includes/simple_logger.php';
    
    if (isset($logger)) {
        $logger->log('info', 'System test completed successfully');
        echo "<p>✅ تم تحميل نظام Logging بنجاح</p>";
        
        // اختبار الحصول على السجلات
        $logs = $logger->getLogs('info', 3, 0);
        echo "<p>✅ تم الحصول على " . count($logs) . " سجل</p>";
        
    } else {
        echo "<p>❌ فشل في تحميل نظام Logging</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ خطأ: " . $e->getMessage() . "</p>";
}

$conn->close();

echo "<h3>✅ تم الانتهاء!</h3>";
echo "<p><a href='test_logging.php'>اختبار النظام</a> | <a href='index.php'>الصفحة الرئيسية</a></p>";
?>
