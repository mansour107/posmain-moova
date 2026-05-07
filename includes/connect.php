<?php
// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'kody2';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($dbhost, $dbuser, $dbpass);

if ($conn->connect_error) {
    if (basename($_SERVER['PHP_SELF']) !== 'pre_start.php' && strpos($_SERVER['PHP_SELF'], 'ajax/') === false) {
        header("Location: pre_start.php?error=server_down");
        exit;
    } else {
        die("Connection failed: " . $conn->connect_error);
    }
}

if (!$conn->select_db($dbname)) {
    if (basename($_SERVER['PHP_SELF']) !== 'pre_start.php' && strpos($_SERVER['PHP_SELF'], 'ajax/') === false) {
        header("Location: pre_start.php?reason=db_missing");
        exit;
    } else if (strpos($_SERVER['PHP_SELF'], 'ajax/') !== false) {

        die("Database '$dbname' not found. Please run pre_start.php");
    }
}

// Enable SQL error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


set_exception_handler(function ($e) {
    // تسجيل الخطأ الحقيقي في ملف log (للمطور فقط)
    $logFile = __DIR__ . '/../logs/sql_errors.log';
    $logDir  = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logMsg = '[' . date('Y-m-d H:i:s') . '] '
            . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine()
            . PHP_EOL;
    @file_put_contents($logFile, $logMsg, FILE_APPEND);

    $errorCode = strtoupper(substr(md5($e->getMessage() . time()), 0, 8));

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
           || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
           || (strpos($_SERVER['PHP_SELF'], 'ajax/') !== false);

    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'database_error',
            'message' => 'حدث خطأ في قاعدة البيانات، يرجى التواصل مع الفيندور',
            'code'    => $errorCode,
        ]);
        exit;
    }

    $scriptPath = $_SERVER['PHP_SELF'];
    $depth = substr_count($scriptPath, '/') - 1; 
    
    if (strpos($scriptPath, '/do/') !== false || strpos($scriptPath, '/ajax/') !== false) {
        $basePath = '../';
    } else {
        $basePath = '';
    }
    
    header('Location: ' . $basePath . 'sql_error.php?code=' . $errorCode);
    exit;
});
if (file_exists('simple_logger.php')) {
    require_once 'simple_logger.php';
}

// settings

$sqlstg = "SELECT * FROM `settings` WHERE 1";
$resstg = $conn->query($sqlstg);
$rowstg = $resstg->fetch_assoc();


$restwn = $conn->query("SELECT * from towns ");


// user powers
$role = []; 
if (isset($_SESSION['usrole'])) {
$user_role_id = $_SESSION['usrole'];
$sqlrole = "SELECT * FROM `usr_pwrs` WHERE id = $user_role_id ";
$resrole = $conn->query($sqlrole);
$role = $resrole->fetch_assoc();
}

$edit_pass = $rowstg['edit_pass'];
date_default_timezone_set('Africa/Cairo'); 
$now = new DateTime();

if ((int)$now->format('H') < 4) {
    $now->modify('-1 day');
}

$today = $now->format('Y-m-d');

$user = "";
if (isset($_COOKIE['login'])) {
  $user = $_COOKIE['login'];
}else {
  $user = '';
}

$userErrorMassage = '<div class="alert alert-danger text-center">
    <i class="fas fa-exclamation-triangle"></i> 
    ليس لديك صلاحية للوصول إلى هذه الصفحة
</div>';