<?php
// بدء الجلسة
require_once __DIR__ . '/session_bootstrap.php';

require_once __DIR__ . '/db_bootstrap.php';
require_once __DIR__ . '/production_guard.php';
require_once __DIR__ . '/update_maintenance_guard.php';

if (!function_exists('posmain_is_json_request')) {
    function posmain_is_json_request(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            || (!empty($_SERVER['CONTENT_TYPE']) && strpos((string) $_SERVER['CONTENT_TYPE'], 'application/json') !== false)
            || (!empty($_SERVER['PHP_SELF']) && strpos((string) $_SERVER['PHP_SELF'], 'ajax/') !== false);
    }
}

if (!function_exists('posmain_error_reference')) {
    function posmain_error_reference(): string
    {
        try {
            return strtoupper(bin2hex(random_bytes(4)));
        } catch (Throwable $ignored) {
            return strtoupper(substr(hash('sha256', microtime(true) . getmypid()), 0, 8));
        }
    }
}

if (!function_exists('posmain_log_exception')) {
    function posmain_log_exception(Throwable $exception, string $reference, string $context = 'handled'): void
    {
        $logFile = __DIR__ . '/../logs/sql_errors.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $message = '[' . date('Y-m-d H:i:s') . '] '
            . 'ref=' . $reference . ' context=' . $context . ' '
            . get_class($exception) . ': ' . $exception->getMessage()
            . ' in ' . $exception->getFile() . ':' . $exception->getLine()
            . PHP_EOL . $exception->getTraceAsString() . PHP_EOL;
        error_log('[posmain_error] ref=' . $reference . ' context=' . $context . ' ' . $exception->getMessage());
        @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('posmain_log_warn_event')) {
    /**
     * Persist a non-exception warn-only event to a dedicated app log file so owners/ops
     * always have visibility regardless of PHP-FPM error_log routing (which can swallow
     * plain error_log() output on hosted pools). Mirrors the posmain_log_exception pattern.
     *
     * @param string $logName  Log file name within the app logs/ directory (e.g. 'recipe_negative_stock.log').
     * @param string $tag      Stable tag prefix for the line (e.g. 'recipe_negative_stock').
     * @param array  $fields   Key/value pairs rendered as k=v tokens on the log line.
     */
    function posmain_log_warn_event(string $logName, string $tag, array $fields = []): void
    {
        $logFile = __DIR__ . '/../logs/' . $logName;
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $tokens = '';
        foreach ($fields as $key => $value) {
            $tokens .= ' ' . $key . '=' . (string) $value;
        }
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $tag . ']' . $tokens . PHP_EOL;
        error_log('[' . $tag . ']' . $tokens);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('posmain_safe_exception_message')) {
    function posmain_safe_exception_message(Throwable $exception, string $fallbackMessage, bool $allowValidationMessage = true): string
    {
        if ($allowValidationMessage && $exception instanceof InvalidArgumentException) {
            return $exception->getMessage();
        }

        if (production_guard_is_production()) {
            return $fallbackMessage;
        }

        return $exception->getMessage();
    }
}

if (!function_exists('posmain_exception_payload')) {
    function posmain_exception_payload(
        Throwable $exception,
        string $fallbackMessage,
        string $code = 'ERROR',
        bool $allowValidationMessage = true,
        string $context = 'handled'
    ): array {
        $isValidation = $allowValidationMessage && $exception instanceof InvalidArgumentException;
        $payload = [
            'success' => false,
            'code' => $isValidation ? 'VALIDATION_FAILED' : $code,
            'message' => posmain_safe_exception_message($exception, $fallbackMessage, $allowValidationMessage),
        ];

        if (!$isValidation) {
            $reference = posmain_error_reference();
            posmain_log_exception($exception, $reference, $context);
            $payload['error_reference'] = $reference;
        }

        return $payload;
    }
}

if (!function_exists('posmain_json_exception_response')) {
    function posmain_json_exception_response(
        Throwable $exception,
        string $fallbackMessage,
        string $code = 'ERROR',
        int $statusCode = 500,
        bool $allowValidationMessage = true,
        string $context = 'json'
    ): void {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(
            posmain_exception_payload($exception, $fallbackMessage, $code, $allowValidationMessage, $context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

if (!function_exists('posmain_browser_exception_response')) {
    function posmain_browser_exception_response(
        Throwable $exception,
        string $fallbackMessage,
        int $statusCode = 500,
        bool $allowValidationMessage = true,
        string $context = 'browser'
    ): void {
        $payload = posmain_exception_payload($exception, $fallbackMessage, 'ERROR', $allowValidationMessage, $context);
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/html; charset=utf-8');
        }

        $message = htmlspecialchars((string) $payload['message'], ENT_QUOTES, 'UTF-8');
        $reference = isset($payload['error_reference'])
            ? '<br><small>مرجع الخطأ: ' . htmlspecialchars((string) $payload['error_reference'], ENT_QUOTES, 'UTF-8') . '</small>'
            : '';
        echo '<div dir="rtl" style="font-family: sans-serif; padding: 24px;">' . $message . $reference . '</div>';
        exit;
    }
}

if (!function_exists('posmain_handle_uncaught_exception')) {
    function posmain_handle_uncaught_exception(Throwable $exception, string $context = 'uncaught'): void
    {
        if (posmain_is_json_request()) {
            posmain_json_exception_response(
                $exception,
                'حدث خطأ في قاعدة البيانات، يرجى التواصل مع الدعم الفني',
                'DATABASE_ERROR',
                500,
                false,
                $context
            );
        }

        $reference = posmain_error_reference();
        posmain_log_exception($exception, $reference, $context);

        $scriptPath = (string) ($_SERVER['PHP_SELF'] ?? '');
        $basePath = (strpos($scriptPath, '/do/') !== false || strpos($scriptPath, '/ajax/') !== false) ? '../' : '';

        if (!headers_sent()) {
            header('Location: ' . $basePath . 'sql_error.php?code=' . rawurlencode($reference));
        } else {
            echo 'حدث خطأ في النظام. مرجع الخطأ: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
        }
        exit;
    }
}

posmain_update_maintenance_guard();

try {
    global $conn;
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    if (production_guard_is_production()) {
        posmain_handle_uncaught_exception($e, 'db_connect');
    }

    $reason = posmain_db_error_is_missing_database($e) ? 'reason=db_missing' : 'error=server_down';
    if (basename($_SERVER['PHP_SELF']) !== 'pre_start.php' && strpos($_SERVER['PHP_SELF'], 'ajax/') === false) {
        header("Location: pre_start.php?" . $reason);
        exit;
    }

    if (posmain_is_json_request()) {
        posmain_json_exception_response($e, 'Database connection failed', 'DATABASE_CONNECTION_FAILED', 500, false, 'db_connect');
    }

    posmain_browser_exception_response($e, 'Database connection failed', 500, false, 'db_connect');
}

// Enable SQL error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


set_exception_handler(function ($e) {
    posmain_handle_uncaught_exception($e);
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
    $user_role_id = (int) $_SESSION['usrole'];
    // Keep role loading compatible with pre-RBAC schemas while also retaining
    // every legacy permission flag used by auth_guard. Newer columns such as
    // role_key are optional until their migration has run.
    $stmt = $conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_role_id);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

$edit_pass = $rowstg['edit_pass'];
date_default_timezone_set('Africa/Cairo'); 
$appConfig = function_exists('posmain_app_config') ? posmain_app_config() : [];
if (!class_exists('BranchWorkerAutoDispatcher', false)) {
    require_once __DIR__ . '/../classes/Sync/BranchWorkerAutoDispatcher.php';
}
BranchWorkerAutoDispatcher::maybeDispatchFromWebRequest($appConfig);
$appTimezone = trim((string) ($appConfig['timezone'] ?? 'Africa/Cairo'));
if ($appTimezone !== '') {
    date_default_timezone_set($appTimezone);
}
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

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/auth_session_guards.php';
if (isset($conn) && $conn instanceof mysqli) {
    auth_guard_enforce_active_session_user($conn);
    posmain_enforce_auth_session_guards($conn);

    // Release the PHP session file lock before RBAC/business work for AJAX.
    // Dashboard/KDS/POS keep many concurrent polls; if any of them hold the
    // lock (including fast 403 denials), later same-session navigations hang.
    // Keep the session open for endpoints that must persist $_SESSION writes.
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $scriptBase = basename($scriptName);
    $isAjaxScript = strpos($scriptName, '/ajax/') !== false || strpos($scriptName, 'ajax/') === 0;
    $sessionMutatingAjax = [
        'main_session_lock.php' => true,
        'main_session_heartbeat.php' => true,
        'pos_lock.php' => true,
        'pos_pin_login.php' => true,
        'dismiss_pin_banner.php' => true,
    ];
    if (
        $isAjaxScript
        && session_status() === PHP_SESSION_ACTIVE
        && empty($sessionMutatingAjax[$scriptBase])
    ) {
        session_write_close();
    }

    require_once __DIR__ . '/entry_permission_guard.php';
    posmain_enforce_entry_permission($conn);
}
