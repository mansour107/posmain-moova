<?php
/**
 * نظام التنبيهات للأحداث المهمة
 */

class AlertSystem {
    private $conn;
    private $logger;
    
    public function __construct($database_connection, $logger_instance) {
        $this->conn = $database_connection;
        $this->logger = $logger_instance;
    }
    
    /**
     * إرسال تنبيه فوري
     */
    public function sendAlert($type, $title, $message, $severity = 'medium', $recipients = []) {
        $alert_data = [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'recipients' => $recipients,
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $_SESSION['userid'] ?? null
        ];
        
        // تسجيل التنبيه
        $this->logger->log('info', "Alert sent: $title", $alert_data);
        
        // حفظ التنبيه في قاعدة البيانات
        $this->saveAlert($alert_data);
        
        // إرسال التنبيهات حسب النوع
        switch ($type) {
            case 'email':
                $this->sendEmailAlert($alert_data);
                break;
            case 'sms':
                $this->sendSMSAlert($alert_data);
                break;
            case 'push':
                $this->sendPushAlert($alert_data);
                break;
        }
    }
    
    /**
     * تنبيهات الأمان
     */
    public function securityAlert($event, $description, $severity = 'high') {
        $this->sendAlert('security', "تنبيه أمني: $event", $description, $severity);
    }
    
    /**
     * تنبيهات مالية
     */
    public function financialAlert($operation, $amount, $account, $severity = 'medium') {
        $title = "تنبيه مالي: $operation";
        $message = "تم تنفيذ عملية $operation بمبلغ $amount في الحساب $account";
        $this->sendAlert('financial', $title, $message, $severity);
    }
    
    /**
     * تنبيهات النظام
     */
    public function systemAlert($component, $issue, $severity = 'medium') {
        $title = "تنبيه نظام: $component";
        $message = "مشكلة في $component: $issue";
        $this->sendAlert('system', $title, $message, $severity);
    }
    
    /**
     * حفظ التنبيه في قاعدة البيانات
     */
    private function saveAlert($alert_data) {
        $stmt = $this->conn->prepare("
            INSERT INTO alerts 
            (type, title, message, severity, recipients, user_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $recipients_json = json_encode($alert_data['recipients']);
        $stmt->bind_param("sssssis", 
            $alert_data['type'],
            $alert_data['title'],
            $alert_data['message'],
            $alert_data['severity'],
            $recipients_json,
            $alert_data['user_id'],
            $alert_data['timestamp']
        );
        
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * إرسال تنبيه بالبريد الإلكتروني
     */
    private function sendEmailAlert($alert_data) {
        // هنا يمكن إضافة كود إرسال البريد الإلكتروني
        $this->logger->log('info', "Email alert sent: " . $alert_data['title']);
    }
    
    /**
     * إرسال تنبيه بالرسائل النصية
     */
    private function sendSMSAlert($alert_data) {
        // هنا يمكن إضافة كود إرسال الرسائل النصية
        $this->logger->log('info', "SMS alert sent: " . $alert_data['title']);
    }
    
    /**
     * إرسال تنبيه فوري
     */
    private function sendPushAlert($alert_data) {
        // هنا يمكن إضافة كود إرسال التنبيهات الفورية
        $this->logger->log('info', "Push alert sent: " . $alert_data['title']);
    }
    
    /**
     * الحصول على التنبيهات غير المقروءة
     */
    public function getUnreadAlerts($user_id = null) {
        $sql = "SELECT * FROM alerts WHERE read_status = 0";
        $params = [];
        $types = "";
        
        if ($user_id) {
            $sql .= " AND (user_id = ? OR user_id IS NULL)";
            $params[] = $user_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 10";
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $alerts = [];
        
        while ($row = $result->fetch_assoc()) {
            $alerts[] = $row;
        }
        
        $stmt->close();
        return $alerts;
    }
    
    /**
     * تحديد التنبيه كمقروء
     */
    public function markAsRead($alert_id) {
        $stmt = $this->conn->prepare("UPDATE alerts SET read_status = 1 WHERE id = ?");
        $stmt->bind_param("i", $alert_id);
        $stmt->execute();
        $stmt->close();
    }
}

// إنشاء instance عام للتنبيهات
if (isset($conn) && isset($logger)) {
    $alerts = new AlertSystem($conn, $logger);
} else {
    $alerts = null;
}
?>
