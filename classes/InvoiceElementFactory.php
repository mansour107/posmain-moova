<?php

require_once 'InvoiceElementBase.php';

/**
 * مصنع إنتاج عناصر الفاتورة - محسّن للأداء
 * Invoice Element Factory - Performance Optimized
 */
class InvoiceElementFactory
{
    // تخزين مؤقت للـ Classes المحملة
    private static $loadedClasses = [];
    
    // تخزين مؤقت للعناصر المنشأة
    private static $elementCache = [];
    
    // تفعيل/تعطيل الـ Cache
    private static $cacheEnabled = true;
    
    /**
     * تحميل جميع الـ Classes مرة واحدة
     */
    private static function loadAllClasses()
    {
        if (!empty(self::$loadedClasses)) {
            return; // محملة مسبقاً
        }
        
        $classes = [
            'InvoiceHeader' => __DIR__ . '/InvoiceHeader.php',
            'InvoiceDetails' => __DIR__ . '/InvoiceDetails.php',
            'InvoiceFooter' => __DIR__ . '/InvoiceFooter.php',
            'AddItemModal' => __DIR__ . '/AddItemModal.php'
        ];
        
        foreach ($classes as $className => $filePath) {
            if (!class_exists($className, false)) {
                require_once $filePath;
            }
            self::$loadedClasses[$className] = true;
        }
    }
    
    /**
     * إنشاء مفتاح Cache فريد
     */
    private static function getCacheKey($elementType, $invoiceType, $isEditMode, $data)
    {
        $dataHash = $data ? md5(serialize($data)) : 'null';
        return "{$elementType}_{$invoiceType}_" . ($isEditMode ? '1' : '0') . "_{$dataHash}";
    }
    
    /**
     * إنشاء عنصر فاتورة حسب النوع (محسّن)
     */
    public static function createElement($elementType, $invoiceType, $isEditMode = false, $data = null, $conn = null)
    {
        // التحقق من الـ Cache
        if (self::$cacheEnabled) {
            $cacheKey = self::getCacheKey($elementType, $invoiceType, $isEditMode, $data);
            if (isset(self::$elementCache[$cacheKey])) {
                return self::$elementCache[$cacheKey];
            }
        }
        
        // تحميل جميع الـ Classes مرة واحدة
        self::loadAllClasses();
        
        // إنشاء العنصر
        $element = null;
        
        switch (strtolower($elementType)) {
            case 'header':
                $element = new InvoiceHeader($invoiceType, $isEditMode, $data, $conn);
                break;
                
            case 'details':
                $element = new InvoiceDetails($invoiceType, $isEditMode, $data, $conn);
                break;
                
            case 'footer':
                $element = new InvoiceFooter($invoiceType, $isEditMode, $data, $conn);
                break;
                
            case 'add_item_modal':
                $element = new AddItemModal($invoiceType, $isEditMode, $data, $conn);
                break;
                
            default:
                throw new InvalidArgumentException("Unknown element type: $elementType");
        }
        
        // حفظ في الـ Cache
        if (self::$cacheEnabled && $element) {
            self::$elementCache[$cacheKey] = $element;
        }
        
        return $element;
    }

    /**
     * إنشاء جميع عناصر الفاتورة (محسّن)
     */
    public static function createAllElements($invoiceType, $isEditMode = false, $data = null, $conn = null)
    {
        // تحميل جميع الـ Classes مرة واحدة في البداية
        self::loadAllClasses();
        
        // إنشاء العناصر بشكل متوازي (بدون تكرار التحميل)
        return [
            'header' => new InvoiceHeader($invoiceType, $isEditMode, $data, $conn),
            'details' => new InvoiceDetails($invoiceType, $isEditMode, $data, $conn),
            'footer' => new InvoiceFooter($invoiceType, $isEditMode, $data, $conn),
            'add_item_modal' => new AddItemModal($invoiceType, $isEditMode, $data, $conn)
        ];
    }

    /**
     * التحقق من صحة جميع العناصر
     */
    public static function validateAllElements($elements)
    {
        $allErrors = [];
        
        foreach ($elements as $elementName => $element) {
            if ($element instanceof InvoiceElementBase) {
                $errors = $element->validate();
                if (!empty($errors)) {
                    $allErrors[$elementName] = $errors;
                }
            }
        }
        
        return $allErrors;
    }

    /**
     * عرض جميع العناصر
     */
    public static function renderAllElements($elements)
    {
        $output = [];
        
        foreach ($elements as $elementName => $element) {
            if ($element instanceof InvoiceElementBase) {
                $output[$elementName] = $element->render();
            }
        }
        
        return $output;
    }
    
    /**
     * تفعيل/تعطيل الـ Cache
     */
    public static function setCacheEnabled($enabled)
    {
        self::$cacheEnabled = $enabled;
    }
    
    /**
     * مسح الـ Cache
     */
    public static function clearCache()
    {
        self::$elementCache = [];
    }
    
    /**
     * الحصول على إحصائيات الـ Cache
     */
    public static function getCacheStats()
    {
        return [
            'enabled' => self::$cacheEnabled,
            'items' => count(self::$elementCache),
            'classes_loaded' => count(self::$loadedClasses)
        ];
    }
}