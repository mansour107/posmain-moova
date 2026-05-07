<?php
/**
 * Performance Configuration
 * إعدادات تحسين الأداء للنظام
 */

// تفعيل Output Buffering
if (!ob_get_level()) {
    ob_start();
}

// تفعيل Gzip Compression
if (!ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', 'On');
    ini_set('zlib.output_compression_level', '6');
}

// تحسين إعدادات PHP
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '60');
ini_set('max_input_time', '60');

// تفعيل OPcache (إذا كان متاحاً)
if (function_exists('opcache_get_status')) {
    $opcache_status = opcache_get_status();
    if (!$opcache_status['opcache_enabled']) {
        error_log('Warning: OPcache is not enabled. Enable it for better performance.');
    }
}

// إعدادات الـ Cache
define('CACHE_ENABLED', true);
define('CACHE_DURATION', 3600); // ساعة واحدة

// إعدادات قاعدة البيانات
define('DB_CACHE_ENABLED', true);
define('DB_PERSISTENT_CONNECTION', false); // استخدام اتصالات دائمة

// إعدادات الجلسات
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.cookie_lifetime', 3600);

// تحسين معالجة الأخطاء
if (defined('PRODUCTION') && PRODUCTION === true) {
    error_reporting(0);
    ini_set('display_errors', 'Off');
    ini_set('log_errors', 'On');
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
}

// دالة لقياس وقت التنفيذ
function startTimer() {
    return microtime(true);
}

function endTimer($start_time, $label = 'Execution') {
    $end_time = microtime(true);
    $execution_time = ($end_time - $start_time) * 1000; // بالميلي ثانية
    error_log("$label time: " . number_format($execution_time, 2) . " ms");
    return $execution_time;
}

// دالة لقياس استهلاك الذاكرة
function logMemoryUsage($label = 'Memory') {
    $memory = memory_get_usage(true) / 1024 / 1024; // بالميجابايت
    error_log("$label usage: " . number_format($memory, 2) . " MB");
    return $memory;
}

// تنظيف Output Buffer عند انتهاء السكريبت
register_shutdown_function(function() {
    if (ob_get_level()) {
        ob_end_flush();
    }
});
