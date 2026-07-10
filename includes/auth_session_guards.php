<?php

require_once __DIR__ . '/../classes/Security/MainAuthenticationService.php';
require_once __DIR__ . '/../classes/Security/LocalSecurityBootstrapService.php';

/**
 * Enforce bootstrap / must-change PIN restrictions and auth_version revocation.
 */
function posmain_enforce_auth_session_guards(mysqli $conn): void
{
    if (!auth_guard_is_logged_in()) {
        return;
    }

    $auth = new MainAuthenticationService();
    if (!$auth->sessionAuthVersionValid($conn)) {
        $auth->lockToLoginScreen();
        if (function_exists('auth_guard_is_json_request') && auth_guard_is_json_request()) {
            deny_json_or_redirect('AUTH_VERSION_STALE', 401);
        }
        header('Location: index.php?error=session_revoked');
        exit;
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    $allowedWhileRestricted = [
        'change_pin.php' => true,
        'do_change_pin.php' => true,
        'do_logout.php' => true,
        'main_session_lock.php' => true,
        'index.php' => true,
    ];

    if ($auth->isBootstrapRestrictedSession() && empty($allowedWhileRestricted[$script])) {
        if (function_exists('auth_guard_is_json_request') && auth_guard_is_json_request()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'code' => 'PIN_CHANGE_REQUIRED',
                'message' => 'PIN_CHANGE_REQUIRED',
                'redirect' => 'change_pin.php',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $bootstrap = !empty($_SESSION['posmain_bootstrap_pending']) ? '?bootstrap=1' : '';
        header('Location: change_pin.php' . $bootstrap);
        exit;
    }
}

function posmain_render_bootstrap_pin_banner(): void
{
    if (empty($_SESSION['posmain_bootstrap_pending']) && empty($_SESSION['posmain_pin_must_change'])) {
        return;
    }
    if (!empty($_SESSION['posmain_pin_banner_dismissed'])) {
        // Visual dismiss only; enforcement remains server-side.
    }
    $dismissed = !empty($_SESSION['posmain_pin_banner_dismissed']);
    if ($dismissed) {
        return;
    }
    $isBootstrap = !empty($_SESSION['posmain_bootstrap_pending']);
    $msg = $isBootstrap
        ? 'أنشئ رمز المالك الجديد. الرمز الابتدائي 0000 مؤقت ولن يعمل بعد التغيير.'
        : 'يرجى تغيير رمز الدخول المؤقت قبل المتابعة.';
    echo '<div class="ppm-banner" id="posmainPinBanner" role="status">'
        . '<div><strong>تنبيه أمان:</strong> ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
        . ' <a href="change_pin.php' . ($isBootstrap ? '?bootstrap=1' : '') . '">تغيير الرمز الآن</a></div>'
        . '<button type="button" id="posmainPinBannerDismiss" aria-label="إخفاء">إخفاء</button>'
        . '</div>';
    echo '<script>(function(){var b=document.getElementById("posmainPinBannerDismiss");'
        . 'if(b){b.addEventListener("click",function(){var el=document.getElementById("posmainPinBanner");'
        . 'if(el)el.remove();fetch("ajax/dismiss_pin_banner.php",{method:"POST",credentials:"same-origin",'
        . 'headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}});});}})();</script>';
}
