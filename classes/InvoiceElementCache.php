<?php
/**
 * Invoice Element Cache
 * نظام تخزين مؤقت لعناصر الفاتورة لتحسين الأداء
 */

class InvoiceElementCache {
    private static $cache = [];
    private static $enabled = true;
    
    /**
     * تفعيل/تعطيل الـ Cache
     */
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }
    
    /**
     * الحصول على عنصر من الـ Cache
     */
    public static function get($key) {
        if (!self::$enabled) {
            return null;
        }
        
        return self::$cache[$key] ?? null;
    }
    
    /**
     * حفظ عنصر في الـ Cache
     */
    public static function set($key, $value) {
        if (!self::$enabled) {
            return;
        }
        
        self::$cache[$key] = $value;
    }
    
    /**
     * التحقق من وجود عنصر في الـ Cache
     */
    public static function has($key) {
        if (!self::$enabled) {
            return false;
        }
        
        return isset(self::$cache[$key]);
    }
    
    /**
     * مسح الـ Cache
     */
    public static function clear() {
        self::$cache = [];
    }
    
    /**
     * مسح عنصر محدد من الـ Cache
     */
    public static function delete($key) {
        unset(self::$cache[$key]);
    }
    
    /**
     * الحصول على حجم الـ Cache
     */
    public static function size() {
        return count(self::$cache);
    }
    
    /**
     * إنشاء مفتاح Cache فريد
     */
    public static function generateKey($prefix, ...$params) {
        return $prefix . '_' . md5(serialize($params));
    }
}
