<?php
// Prevent any output before we are ready
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0); // Keep 0 to prevent noise in JSON response

require_once __DIR__ . '/../includes/session_bootstrap.php';

// Valid shutdown handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        // Clear buffer
        while (ob_get_level()) ob_end_clean();
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Fatal Error: ' . $error['message'] . ' on line ' . $error['line']]);
        exit;
    }
});

try {
    // Include connection
    $connectPath = __DIR__ . '/../includes/connect.php';
    if (!file_exists($connectPath)) {
        throw new Exception("ملف الاتصال غير موجود");
    }

    require_once __DIR__ . '/../includes/auth_guard.php';
    
    // Include ShiftReport Class
    $classPath = __DIR__ . '/../classes/ShiftReport.php';
    if (!file_exists($classPath)) {
        throw new Exception("ملف كلاس التقرير غير موجود");
    }
    
    // Use output buffering to trap any output from includes
    ob_start();
    include($connectPath);
    include($classPath);
    ob_end_clean(); // Discard noise

    $user_id = auth_guard_user_id_from_session();
    if ($user_id < 1) {
        throw new Exception('الرجاء تسجيل الدخول أولاً');
    }

    if (!auth_guard_is_pos_barcode_unlocked()) {
        throw new Exception('مطلوب فتح نقطة البيع أولاً');
    }

    require_permission('pos.shift.close', $conn);

    if (!isset($conn) || $conn->connect_error) {
        throw new Exception('فشل الاتصال بقاعدة البيانات');
    }

    $conn->set_charset('utf8mb4');

    require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';
    $shiftSessions = new ShiftSessionService();
    $reportScope = [];
    $drawerSession = $shiftSessions->currentDrawerSession($conn, (int) $user_id, $reportScope);
    if (!$drawerSession && auth_guard_is_pos_barcode_unlocked()) {
        try {
            $shiftSessions->openForCashier($conn, (int) $user_id, $reportScope);
            $drawerSession = $shiftSessions->currentDrawerSession($conn, (int) $user_id, $reportScope);
        } catch (Throwable $drawerEnsureException) {
            error_log('Shift preview drawer ensure failed: ' . $drawerEnsureException->getMessage());
        }
    }
    if ($drawerSession) {
        $reportScope['shift_opened_at'] = $drawerSession['opened_at'];
        $reportScope['drawer_session_id'] = (int) $drawerSession['id'];
    }

    // Create ShiftReport instance scoped to the active drawer shift window.
    $report = new ShiftReport($conn, $user_id, null, $reportScope);
    
    // Get Totals using the unified logic (respects time boundaries)
    $totals = $report->getTotals();
    $expenseSummary = $drawerSession
        ? $shiftSessions->drawerExpenseSummary($conn, $drawerSession)
        : $shiftSessions->shiftExpenseSummary($conn, (int) $user_id, $reportScope);
    
    // Get cashier name
    $cashier_name = 'الكاشير';
    $user_stmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
    if ($user_stmt) {
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($row = $user_result->fetch_assoc()) {
            $cashier_name = $row['uname'];
        }
        $user_stmt->close();
    }
    
    $response_data = [
        'success' => true,
        'data' => [
            'total_orders' => intval($totals['total_orders'] ?? 0),
            'total_sales' => number_format($totals['total_net'] ?? 0, 2), // 使用 total_net (صافي) or total_gross (اجمالي) depending on preference. Using Net usually.
            'cashier_name' => $cashier_name,
            'shift_number' => date('Ymd') . '_' . $user_id,
            'expenses' => $expenseSummary,
            'expected_cash' => $expenseSummary['expected_cash'] ?? null,
        ]
    ];

    // Final buffer clean
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    
    $json = json_encode($response_data, JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        throw new Exception('JSON Encode Error: ' . json_last_error_msg());
    }
    
    echo $json;
    exit;

} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
