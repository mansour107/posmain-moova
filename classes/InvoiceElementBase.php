<?php

/**
 * فئة أساسية لجميع عناصر الفاتورة - تطبيق مبادئ الـ polymorphism
 * Base class for all invoice elements - implementing polymorphism principles
 */
abstract class InvoiceElementBase 
{
    protected $invoiceType;  // نوع الفاتورة
    protected $isEditMode;   // وضع التعديل
    protected $data;         // البيانات المرتبطة بالعنصر
    protected $conn;         // اتصال قاعدة البيانات

    public function __construct($invoiceType, $isEditMode = false, $data = null, $conn = null)
    {
        $this->invoiceType = $invoiceType;
        $this->isEditMode = $isEditMode;
        $this->data = $data;
        $this->conn = $conn;
    }

    /**
     * دالة مجردة لعرض العنصر - يجب تنفيذها في كل فئة فرعية
     * Abstract method for rendering element - must be implemented in each subclass
     */
    abstract public function render();

    /**
     * دالة مجردة للتحقق من صحة البيانات
     * Abstract method for data validation
     */
    abstract public function validate();

    /**
     * الحصول على نوع الفاتورة
     */
    public function getInvoiceType()
    {
        return $this->invoiceType;
    }

    /**
     * تحديد ما إذا كان في وضع التعديل
     */
    public function isEditMode()
    {
        return $this->isEditMode;
    }

    /**
     * تعيين البيانات
     */
    public function setData($data)
    {
        $this->data = $data;
    }

    /**
     * الحصول على البيانات
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * تحديد نوع العميل/المورد بناءً على نوع الفاتورة
     */
    protected function getClientType()
    {
        // أنواع الفواتير التي تتطلب موردين
        $supplierTypes = [4, 11, 12]; // مشتريات، مردود مبيعات، أمر شراء
        
        // أنواع الفواتير التي تتطلب عملاء
        $clientTypes = [3, 10, 13, 14];   // مبيعات، مردود مشتريات، أمر بيع، عرض سعر
        
        if (in_array($this->invoiceType, $supplierTypes)) {
            return 'supplier';
        } elseif (in_array($this->invoiceType, $clientTypes)) {
            return 'client';
        }
        
        return 'unknown';
    }

    /**
     * الحصول على كود العميل/المورد من قاعدة البيانات
     */
    protected function getAccountCode()
    {
        $clientType = $this->getClientType();
        
        switch ($clientType) {
            case 'supplier':
                return '211'; // كود الموردين
            case 'client':
                return '122'; // كود العملاء
            default:
                return '122'; // افتراضي للعملاء
        }
    }

    /**
     * تنظيف وتأمين المدخلات
     */
    protected function sanitizeInput($input)
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * تنفيذ استعلام آمن باستخدام prepared statements
     */
    protected function executeSecureQuery($query, $params = [], $types = '')
    {
        if (!$this->conn) {
            throw new Exception('Database connection not available');
        }

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $this->conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        return $result;
    }

    /**
     * تحديد لون الخلفية بناءً على نوع الفاتورة
     */
    protected function getBackgroundClass()
    {
        if ($this->isEditMode) {
            return 'bg-red-500';
        }
        
        switch ($this->invoiceType) {
            case 3:
            case 4:
                return 'bg-teal-500';
            case 10:
            case 11:
            case 12:
            case 13:
                return 'bg-red-500';
            default:
                return 'bg-gray-500';
        }
    }
}