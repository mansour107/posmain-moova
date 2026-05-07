<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مسموح']);
    exit;
}

try {
    $user_id = $_SESSION['user_id'];
    $today = date('Y-m-d');
    
    // جلب بيانات المبيعات اليومية للمستخدم الحالي
    $query = "SELECT 
                COUNT(*) as total_invoices,
                COALESCE(SUM(total), 0) as total_sales,
                COALESCE(SUM(discount), 0) as total_discounts,
                COALESCE(SUM(net_val), 0) as net_sales
              FROM invoices 
              WHERE DATE(created_at) = ? 
              AND user_id = ?
              AND status != 'deleted'";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$today, $user_id]);
    $salesData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // جلب اسم المستخدم
    $userQuery = "SELECT name FROM users WHERE id = ?";
    $userStmt = $pdo->prepare($userQuery);
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    $salesData['cashier_name'] = $user['name'] ?? 'غير محدد';
    
    echo json_encode([
        'success' => true,
        'data' => $salesData
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
    ]);
}
?>