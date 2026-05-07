<?php
// نظام Logging مبسط
class SimpleLogger {
    private static $logFile = 'logs/system.log';
    
    public static function log($message, $level = 'INFO') {
        // إنشاء مجلد logs إذا لم يكن موجود
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        
        // كتابة في ملف اللوج (بدون إظهار أخطاء إذا فشل)
        @file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    public static function error($message) {
        self::log($message, 'ERROR');
    }
    
    public static function info($message) {
        self::log($message, 'INFO');
    }
    
    public static function warning($message) {
        self::log($message, 'WARNING');
    }
}

// دوال مساعدة سريعة
function log_info($message) {
    SimpleLogger::info($message);
}

function log_error($message) {
    SimpleLogger::error($message);
}

function log_warning($message) {
    SimpleLogger::warning($message);
}
?>