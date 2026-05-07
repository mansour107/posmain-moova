<?php
/**
 * ملف إصلاح قاعدة البيانات
 * ينشئ الجداول المطلوبة لنظام Logging
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

echo "<h2>بدء إصلاح قاعدة البيانات...</h2>";

// إنشاء جدول system_logs
$sql1 = "CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` datetime NOT NULL,
  `level` enum('DEBUG','INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
  `message` text NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_uri` varchar(500) DEFAULT NULL,
  `context` json DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_level` (`level`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_username` (`username`),
  KEY `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql1) === TRUE) {
    echo "<p>✅ تم إنشاء جدول system_logs بنجاح</p>";
} else {
    echo "<p>❌ خطأ في إنشاء جدول system_logs: " . $conn->error . "</p>";
}

// إنشاء جدول alerts
$sql2 = "CREATE TABLE IF NOT EXISTS `alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('email','sms','push','security','financial','system') NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `recipients` json DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `read_status` tinyint(1) DEFAULT 0,
  `created_at` datetime NOT NULL,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_read_status` (`read_status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql2) === TRUE) {
    echo "<p>✅ تم إنشاء جدول alerts بنجاح</p>";
} else {
    echo "<p>❌ خطأ في إنشاء جدول alerts: " . $conn->error . "</p>";
}

// إنشاء مجلد السجلات
if (!file_exists('logs')) {
    if (mkdir('logs', 0755, true)) {
        echo "<p>✅ تم إنشاء مجلد logs بنجاح</p>";
    } else {
        echo "<p>❌ خطأ في إنشاء مجلد logs</p>";
    }
} else {
    echo "<p>✅ مجلد logs موجود بالفعل</p>";
}

// اختبار النظام
echo "<h3>اختبار النظام...</h3>";

try {
    // تحميل نظام Logging
    require_once 'includes/logger.php';
    
    if (isset($logger)) {
        $logger->log('info', 'Database fix completed successfully');
        echo "<p>✅ تم تحميل نظام Logging بنجاح</p>";
    } else {
        echo "<p>❌ فشل في تحميل نظام Logging</p>";
    }
    
    // تحميل نظام التنبيهات
    require_once 'includes/alerts.php';
    
    if (isset($alerts)) {
        $alerts->systemAlert('system', 'Database fix completed', 'low');
        echo "<p>✅ تم تحميل نظام التنبيهات بنجاح</p>";
    } else {
        echo "<p>❌ فشل في تحميل نظام التنبيهات</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ خطأ في اختبار النظام: " . $e->getMessage() . "</p>";
}

$conn->close();

echo "<h3>✅ تم الانتهاء من الإصلاح!</h3>";
echo "<p><a href='index.php'>العودة للصفحة الرئيسية</a></p>";
echo "<p><strong>تحذير:</strong> احذف هذا الملف بعد الانتهاء!</p>";
?>
