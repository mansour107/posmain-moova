<?php
/**
 * نظام Logging شامل للنظام
 * يسجل جميع العمليات والأحداث المهمة
 */

class Logger {
    private $conn;
    private $logDir;
    private $maxLogSize = 10485760; // 10MB
    private $maxLogFiles = 5;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->logDir = 'logs/';
        
        // إنشاء مجلد السجلات إذا لم يكن موجوداً
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    /**
     * تسجيل حدث عام
     */
    public function log($level, $message, $context = []) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => strtoupper($level),
            'message' => $message,
            'user_id' => $_SESSION['userid'] ?? null,
            'username' => $_SESSION['login'] ?? 'Guest',
            'ip_address' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'context' => json_encode($context)
        ];
        
        // تسجيل في قاعدة البيانات
        $this->logToDatabase($logData);
        
        // تسجيل في ملف
        $this->logToFile($logData);
        
        // تسجيل في console للأخطاء الحرجة
        if ($level === 'error' || $level === 'critical') {
            error_log("[$level] $message - Context: " . json_encode($context));
        }
    }
    
    /**
     * تسجيل عملية مالية
     */
    public function logFinancial($operation, $amount, $account_from = null, $account_to = null, $details = []) {
        $context = [
            'operation_type' => 'financial',
            'operation' => $operation,
            'amount' => $amount,
            'account_from' => $account_from,
            'account_to' => $account_to,
            'details' => $details
        ];
        
        $this->log('info', "Financial Operation: $operation - Amount: $amount", $context);
    }
    
    /**
     * تسجيل عملية مستخدم
     */
    public function logUser($action, $target_user = null, $details = []) {
        $context = [
            'operation_type' => 'user_management',
            'action' => $action,
            'target_user' => $target_user,
            'details' => $details
        ];
        
        $this->log('info', "User Operation: $action", $context);
    }
    
    /**
     * تسجيل عملية نظام
     */
    public function logSystem($component, $action, $details = []) {
        $context = [
            'operation_type' => 'system',
            'component' => $component,
            'action' => $action,
            'details' => $details
        ];
        
        $this->log('info', "System Operation: $component - $action", $context);
    }
    
    /**
     * تسجيل خطأ
     */
    public function logError($error_message, $file = '', $line = 0, $context = []) {
        $context['file'] = $file;
        $context['line'] = $line;
        $context['error_type'] = 'php_error';
        
        $this->log('error', $error_message, $context);
    }
    
    /**
     * تسجيل محاولة تسجيل دخول
     */
    public function logLogin($username, $success, $reason = '') {
        $context = [
            'operation_type' => 'authentication',
            'username' => $username,
            'success' => $success,
            'reason' => $reason
        ];
        
        $level = $success ? 'info' : 'warning';
        $message = $success ? "Successful login: $username" : "Failed login attempt: $username - $reason";
        
        $this->log($level, $message, $context);
    }
    
    /**
     * تسجيل محاولة وصول غير مصرح
     */
    public function logUnauthorizedAccess($resource, $reason = '') {
        $context = [
            'operation_type' => 'security',
            'resource' => $resource,
            'reason' => $reason
        ];
        
        $this->log('warning', "Unauthorized access attempt to: $resource - $reason", $context);
    }
    
    /**
     * تسجيل في قاعدة البيانات
     */
    private function logToDatabase($logData) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO system_logs 
                (timestamp, level, message, user_id, username, ip_address, user_agent, request_uri, context) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param("sssisssss", 
                $logData['timestamp'],
                $logData['level'],
                $logData['message'],
                $logData['user_id'],
                $logData['username'],
                $logData['ip_address'],
                $logData['user_agent'],
                $logData['request_uri'],
                $logData['context']
            );
            
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            // في حالة فشل تسجيل قاعدة البيانات، سجل في ملف فقط
            error_log("Database logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * تسجيل في ملف
     */
    private function logToFile($logData) {
        $logFile = $this->logDir . 'system_' . date('Y-m-d') . '.log';
        
        $logEntry = sprintf(
            "[%s] %s: %s | User: %s (%s) | IP: %s | %s\n",
            $logData['timestamp'],
            $logData['level'],
            $logData['message'],
            $logData['username'],
            $logData['user_id'],
            $logData['ip_address'],
            $logData['context']
        );
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // تدوير الملفات إذا تجاوزت الحجم المحدد
        $this->rotateLogFiles($logFile);
    }
    
    /**
     * تدوير ملفات السجلات
     */
    private function rotateLogFiles($logFile) {
        if (file_exists($logFile) && filesize($logFile) > $this->maxLogSize) {
            $backupFile = $logFile . '.' . time();
            rename($logFile, $backupFile);
            
            // حذف الملفات القديمة
            $files = glob($this->logDir . 'system_*.log.*');
            if (count($files) > $this->maxLogFiles) {
                rsort($files);
                $filesToDelete = array_slice($files, $this->maxLogFiles);
                foreach ($filesToDelete as $file) {
                    unlink($file);
                }
            }
        }
    }
    
    /**
     * الحصول على IP العميل
     */
    private function getClientIP() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    /**
     * الحصول على السجلات
     */
    public function getLogs($level = null, $limit = 100, $offset = 0) {
        $sql = "SELECT * FROM system_logs WHERE 1=1";
        $params = [];
        $types = "";
        
        if ($level) {
            $sql .= " AND level = ?";
            $params[] = strtoupper($level);
            $types .= "s";
        }
        
        $sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        
        while ($row = $result->fetch_assoc()) {
            $row['context'] = json_decode($row['context'], true);
            $logs[] = $row;
        }
        
        $stmt->close();
        return $logs;
    }
    
    /**
     * إحصائيات السجلات
     */
    public function getLogStats() {
        $stats = [];
        
        // إجمالي السجلات
        $result = $this->conn->query("SELECT COUNT(*) as total FROM system_logs");
        $stats['total'] = $result->fetch_assoc()['total'];
        
        // السجلات حسب المستوى
        $result = $this->conn->query("
            SELECT level, COUNT(*) as count 
            FROM system_logs 
            GROUP BY level 
            ORDER BY count DESC
        ");
        $stats['by_level'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_level'][$row['level']] = $row['count'];
        }
        
        // السجلات اليوم
        $result = $this->conn->query("
            SELECT COUNT(*) as today 
            FROM system_logs 
            WHERE DATE(timestamp) = CURDATE()
        ");
        $stats['today'] = $result->fetch_assoc()['today'];
        
        return $stats;
    }
}

// إنشاء instance عام للـ Logger
if (isset($conn)) {
    $logger = new Logger($conn);
} else {
    $logger = null;
}
?>
