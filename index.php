<?php
// index.php — main login (PIN on local, username/password on hosted)

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/db_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/classes/PasswordService.php';
require_once __DIR__ . '/classes/Security/LoginThrottleService.php';
require_once __DIR__ . '/classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/classes/Security/PostLoginRouteService.php';
require_once __DIR__ . '/classes/Security/LocalSecurityBootstrapService.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    $reason = posmain_db_error_is_missing_database($e) ? 'reason=db_missing' : 'error=server_down';
    header('Location: pre_start.php?' . $reason);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mainAuthMode = 'password';
try {
    $mainAuthMode = posmain_main_auth_mode();
} catch (Throwable $e) {
    if ($e->getMessage() === 'MAIN_AUTH_MODE_UNSAFE') {
        http_response_code(503);
        echo 'Unsafe authentication configuration: PIN main login is not allowed on hosted/cloud/router deployments.';
        exit;
    }
    $mainAuthMode = 'password';
}

function e($str) {
    return htmlspecialchars((string) $str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function login_security_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $conn->prepare("
            SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        $cache[$table] = $exists;
        return $exists;
    } catch (Throwable $exception) {
        $cache[$table] = false;
        return false;
    }
}

function login_security_options(): array
{
    return [
        'max_attempts' => 5,
        'window_seconds' => 900,
        'lock_seconds' => 900,
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];
}

function login_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function login_throttle_blocked(mysqli $conn, LoginThrottleService $throttle, string $username, string $ip): bool
{
    if (!login_security_table_exists($conn, 'failed_login_attempts')) {
        return false;
    }
    try {
        return $throttle->isBlocked($conn, $username, $ip, login_security_options());
    } catch (Throwable $exception) {
        return false;
    }
}

function login_throttle_failure(mysqli $conn, LoginThrottleService $throttle, string $username, string $ip): void
{
    if (!login_security_table_exists($conn, 'failed_login_attempts')) {
        return;
    }
    try {
        $throttle->recordFailure($conn, $username, $ip, login_security_options());
    } catch (Throwable $exception) {
    }
}

function login_throttle_success(mysqli $conn, LoginThrottleService $throttle, string $username, string $ip): void
{
    if (!login_security_table_exists($conn, 'failed_login_attempts')) {
        return;
    }
    try {
        $throttle->recordSuccess($conn, $username, $ip);
    } catch (Throwable $exception) {
    }
}

function login_audit(mysqli $conn, SecurityAuditLogger $auditLogger, string $eventType, array $options = []): void
{
    if (!login_security_table_exists($conn, 'security_audit_log')) {
        return;
    }
    try {
        $auditLogger->record($conn, $eventType, $options);
    } catch (Throwable $exception) {
    }
}

function login_user_by_uname(mysqli $conn, string $user): ?array
{
    $stmt = $conn->prepare('SELECT id, uname, password, userrole, usertype FROM users WHERE uname = ? AND isdeleted != 1 LIMIT 1');
    $stmt->bind_param('s', $user);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function login_user_from_router_alias(mysqli $shopConn, array $route, string $identifier): ?array
{
    $targetUserId = isset($route['target_user_id']) && $route['target_user_id'] !== null
        ? (int) $route['target_user_id'] : 0;
    if ($targetUserId > 0) {
        $stmt = $shopConn->prepare('SELECT id, uname, password, userrole, usertype FROM users WHERE id = ? AND isdeleted != 1 LIMIT 1');
        $stmt->bind_param('i', $targetUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
    $targetUname = trim((string) ($route['target_uname'] ?? ''));
    return login_user_by_uname($shopConn, $targetUname !== '' ? $targetUname : $identifier);
}

function login_auth_version_for_user(mysqli $conn, int $userId): int
{
    try {
        $column = $conn->query("SHOW COLUMNS FROM users LIKE 'auth_version'");
        if (!$column || $column->num_rows < 1) {
            return 1;
        }
        $stmt = $conn->prepare(
            'SELECT COALESCE(auth_version, 1) AS auth_version FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return max(1, (int) ($row['auth_version'] ?? 1));
    } catch (Throwable $ignored) {
        return 1;
    }
}

function login_pin_must_change_for_user(mysqli $conn, int $userId): bool
{
    try {
        $column = $conn->query("SHOW COLUMNS FROM users LIKE 'pin_must_change'");
        if (!$column || $column->num_rows < 1) {
            return false;
        }
        $stmt = $conn->prepare(
            'SELECT COALESCE(pin_must_change, 0) AS pin_must_change FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return !empty($row['pin_must_change']);
    } catch (Throwable $ignored) {
        return false;
    }
}

function login_upgrade_password_if_needed(mysqli $conn, int $userId, string $password, string $storedHash): void
{
    if (!PasswordService::needsRehash($storedHash)) {
        return;
    }
    $newHash = PasswordService::hashPassword($password);
    $u = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    if ($u) {
        $u->bind_param('si', $newHash, $userId);
        $u->execute();
        $u->close();
    }
}

function login_insert_session_time(mysqli $conn, int $userId): void
{
    $session_stmt = $conn->prepare('INSERT INTO session_time(user) VALUES (?)');
    if ($session_stmt) {
        $session_stmt->bind_param('i', $userId);
        $session_stmt->execute();
        $session_stmt->close();
    }
}

function login_redirect_with_error(string $message, string $username = ''): void
{
    $_SESSION['login_flash'] = ['message' => $message, 'uname' => $username];
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? 'index.php'));
    exit();
}

function login_take_error_flash(): array
{
    $flash = $_SESSION['login_flash'] ?? null;
    unset($_SESSION['login_flash']);
    if (!is_array($flash)) {
        return ['message' => null, 'uname' => ''];
    }
    $message = trim((string) ($flash['message'] ?? ''));
    return [
        'message' => $message !== '' ? $message : null,
        'uname' => trim((string) ($flash['uname'] ?? '')),
    ];
}

function login_post_login_redirect(mysqli $conn, int $userId): string
{
    try {
        return (new PostLoginRouteService())->resolveRedirect($conn, $userId);
    } catch (Throwable $ignored) {
        return 'dashboard.php';
    }
}

csrf_token('default');
csrf_token('main_pin');

$routerEnabled = function_exists('posmain_router_enabled') && posmain_router_enabled();
if (
    isset($_SESSION['login'])
    && isset($_SESSION['userid'])
    && (!$routerEnabled || (class_exists('PosmainShopRouter') && PosmainShopRouter::activeSessionShopId() > 0))
) {
    if (!empty($_SESSION['posmain_bootstrap_pending']) || !empty($_SESSION['posmain_pin_must_change'])) {
        header('Location: change_pin.php' . (!empty($_SESSION['posmain_bootstrap_pending']) ? '?bootstrap=1' : ''));
        exit();
    }
    header('Location: ' . login_post_login_redirect($conn, (int) $_SESSION['userid']));
    exit();
}

// -------------------- PIN main login (local) --------------------
if ($mainAuthMode === 'pin') {
    try {
        $bootstrap = new LocalSecurityBootstrapService();
        if ($bootstrap->tableExists($conn) && !$bootstrap->isCompleted($conn)) {
            $bootstrap->ensureLocalBootstrap($conn);
        }
    } catch (Throwable $ignored) {
    }

    $pinPadId = 'mainPinPad';
    $pinPadCsrf = csrf_token('main_pin');
    $pinPadEndpoint = 'ajax/main_pin_login.php';
    $pinPadTitle = 'مرحباً بك';
    $pinPadSubtitle = 'أدخل رمز الدخول المكوّن من 4 أرقام';
    $pinPadError = null;
    $pinPadDigits = 4;
    $pinPadMode = 'login';
    $pinPadExtraFields = '';
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kody POS | تسجيل الدخول</title>
  <link rel="icon" href="assets/favicon/favicon.png" type="image/png">
  <link rel="stylesheet" href="assets/fonts/fonts.css">
  <?php include __DIR__ . '/includes/pin_pad_styles.php'; ?>
  <?= csrf_meta_tag('main_pin', 'main-pin-csrf-token') ?>
</head>
<body class="ppm-page">
  <div class="ppm-shell">
    <div style="text-align:center;margin-bottom:1rem;">
      <img src="assets/favicon/favicon.png" alt="Logo" class="ppm-brand">
    </div>
    <div class="ppm-offline-banner" id="mainPinOffline" role="status">لا يوجد اتصال بالشبكة</div>
    <?php include __DIR__ . '/includes/pin_pad_fragment.php'; ?>
    <div style="text-align:center;margin-top:1rem;color:rgba(255,255,255,.55);font-size:.8rem;">
      &copy; <?= date('Y') ?> جميع الحقوق محفوظة
    </div>
  </div>
  <script src="js/pin_pad.js"></script>
  <script>
  (function () {
    var offline = document.getElementById('mainPinOffline');
    function syncOffline() {
      if (!offline) return;
      if (navigator.onLine === false) offline.classList.add('is-visible');
      else offline.classList.remove('is-visible');
    }
    window.addEventListener('online', syncOffline);
    window.addEventListener('offline', syncOffline);
    syncOffline();
    if (window.PosmainPinPad) {
      window.PosmainPinPad.init('mainPinPad');
    }
  })();
  </script>
</body>
</html>
<?php
    exit;
}

// -------------------- Password main login (hosted) --------------------
$error_message = null;
$login_username = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $loginFlash = login_take_error_flash();
    $error_message = $loginFlash['message'];
    $login_username = $loginFlash['uname'];
    if ($error_message === null && isset($_GET['error']) && $_GET['error'] === 'user_deactivated') {
        $error_message = 'تم إيقاف حسابك. تواصل مع المدير للوصول مرة أخرى.';
    }
}
$loginThrottle = new LoginThrottleService();
$securityAuditLogger = new SecurityAuditLogger();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $posted_csrf)) {
        $error_message = 'طلب غير صالح (CSRF). حاول مرة أخرى.';
    } else {
        $user = trim($_POST['uname'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($user === '' || $password === '') {
            $error_message = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        } else {
            $clientIp = login_client_ip();
            if (login_throttle_blocked($conn, $loginThrottle, $user, $clientIp)) {
                $error_message = 'تم إيقاف محاولات الدخول مؤقتاً. حاول مرة أخرى بعد قليل.';
                login_audit($conn, $securityAuditLogger, 'login_throttled', [
                    'ip' => $clientIp,
                    'target_type' => 'user',
                    'metadata' => ['username' => $user],
                ]);
            } else {
                $shopConn = $conn;
                $route = null;
                try {
                    if ($routerEnabled) {
                        $router = new PosmainShopRouter();
                        $routerConn = posmain_router_db_connect();
                        try {
                            $route = $router->resolveLoginAlias($routerConn, $user);
                        } finally {
                            $routerConn->close();
                        }
                        if (!$route) {
                            $error_message = 'اسم المستخدم أو كلمة المرور غير صحيحة';
                            login_throttle_failure($conn, $loginThrottle, $user, $clientIp);
                            login_audit($conn, $securityAuditLogger, 'login_failure', [
                                'ip' => $clientIp,
                                'target_type' => 'user',
                                'metadata' => ['username' => $user, 'reason' => 'router_alias_not_found'],
                            ]);
                            throw new RuntimeException('LOGIN_HANDLED');
                        }
                        $shopConn = $router->connectShopFromRoute($route);
                    }

                    $row = $routerEnabled && $route
                        ? login_user_from_router_alias($shopConn, $route, $user)
                        : login_user_by_uname($shopConn, $user);

                    if ($row) {
                        $storedHash = $row['password'];
                        $userId = (int) $row['id'];
                        $password_ok = false;
                        if (PasswordService::verifyPassword($password, $storedHash)) {
                            $password_ok = true;
                            login_upgrade_password_if_needed($shopConn, $userId, $password, $storedHash);
                        }
                        if ($password_ok) {
                            login_throttle_success($conn, $loginThrottle, $user, $clientIp);
                            login_audit($conn, $securityAuditLogger, 'login_success', [
                                'user_id' => $userId,
                                'ip' => $clientIp,
                                'target_type' => 'user',
                                'target_id' => $userId,
                                'metadata' => [
                                    'username' => $row['uname'],
                                    'router_identifier' => $user,
                                    'shop_id' => $route ? (int) $route['id'] : null,
                                    'shop_slug' => $route ? (string) $route['slug'] : null,
                                ],
                            ]);
                            posmain_session_regenerate();
                            $_SESSION['userid'] = $row['id'];
                            $_SESSION['usrole'] = $row['userrole'];
                            $_SESSION['usty'] = $row['usertype'];
                            $_SESSION['login'] = $row['uname'];
                            $_SESSION['posmain_auth_method'] = 'password';
                            $_SESSION['posmain_auth_version'] = login_auth_version_for_user($shopConn, $userId);
                            $_SESSION['posmain_bootstrap_pending'] = false;
                            $_SESSION['posmain_pin_must_change'] = login_pin_must_change_for_user($shopConn, $userId);
                            if ($routerEnabled && $route) {
                                $_SESSION['posmain_shop_id'] = (int) $route['id'];
                                $_SESSION['posmain_shop_slug'] = (string) $route['slug'];
                                $_SESSION['posmain_shop_user_id'] = $userId;
                            }
                            login_insert_session_time($shopConn, $userId);
                            header(
                                'Location: ' . (
                                    !empty($_SESSION['posmain_pin_must_change'])
                                        ? 'change_pin.php'
                                        : login_post_login_redirect($shopConn, $userId)
                                )
                            );
                            exit();
                        }
                        $error_message = 'اسم المستخدم أو كلمة المرور غير صحيحة';
                        login_throttle_failure($conn, $loginThrottle, $user, $clientIp);
                        login_audit($conn, $securityAuditLogger, 'login_failure', [
                            'ip' => $clientIp,
                            'target_type' => 'user',
                            'metadata' => [
                                'username' => $user,
                                'reason' => 'invalid_credentials',
                                'shop_id' => $route ? (int) $route['id'] : null,
                            ],
                        ]);
                    } else {
                        $error_message = 'اسم المستخدم أو كلمة المرور غير صحيحة';
                        login_throttle_failure($conn, $loginThrottle, $user, $clientIp);
                        login_audit($conn, $securityAuditLogger, 'login_failure', [
                            'ip' => $clientIp,
                            'target_type' => 'user',
                            'metadata' => [
                                'username' => $user,
                                'reason' => 'user_not_found',
                                'shop_id' => $route ? (int) $route['id'] : null,
                            ],
                        ]);
                    }
                } catch (RuntimeException $e) {
                    if ($e->getMessage() !== 'LOGIN_HANDLED') {
                        throw $e;
                    }
                } catch (Throwable $e) {
                    error_log('Router login failed: ' . $e->getMessage());
                    $error_message = 'تعذر فتح بيانات المتجر. يرجى التواصل مع الدعم الفني.';
                    login_audit($conn, $securityAuditLogger, 'login_failure', [
                        'ip' => $clientIp,
                        'target_type' => 'user',
                        'metadata' => ['username' => $user, 'reason' => 'shop_route_error'],
                    ]);
                } finally {
                    if ($routerEnabled && $shopConn instanceof mysqli && $shopConn !== $conn) {
                        $shopConn->close();
                    }
                }
            }
        }
    }
    if ($error_message !== null) {
        login_redirect_with_error($error_message, trim($_POST['uname'] ?? ''));
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Kody POS | تسجيل الدخول</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/favicon/favicon.png" type="image/png">
  <link rel="stylesheet" href="assets/fonts/fonts.css">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link href="assets/libs/bootstrap5/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
        --primary-gradient: linear-gradient(135deg, #942C21 0%, #be3e31 100%);
        --glass-bg: rgba(255, 255, 255, 0.9);
        --glass-border: rgba(255, 255, 255, 0.6);
        --input-bg: #fff5f5;
    }
    body {
        font-family: 'Inter', 'IBM Plex Sans Arabic', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background-image: url('assets/wallpaper/background1.jpg');
        background-size: cover; background-position: center; background-repeat: no-repeat;
        height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; overflow: hidden;
    }
    body::before {
        content: ''; position: absolute; inset: 0; background: rgba(0,0,0,.4); z-index: -1; backdrop-filter: blur(5px);
    }
    .login-container { width: 100%; max-width: 400px; padding: 20px; z-index: 10; }
    .login-card {
        background: var(--glass-bg); border-radius: 20px; padding: 40px 30px;
        box-shadow: 0 15px 35px rgba(0,0,0,.2); backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border); text-align: center; position: relative; overflow: hidden;
    }
    .login-card::before { content:''; position:absolute; top:0; left:0; width:100%; height:6px; background: var(--primary-gradient); }
    .brand-logo { width: 120px; height: auto; margin-bottom: 20px; }
    .login-title { color:#333; font-weight:700; margin-bottom:5px; font-size:1.8rem; }
    .login-subtitle { color:#666; font-size:.95rem; margin-bottom:30px; }
    .form-control { background-color: var(--input-bg); border: 2px solid transparent; border-radius: 12px; padding: 12px 15px; }
    .form-control:focus { background:#fff; border-color:#942C21; box-shadow: 0 0 0 4px rgba(148,44,33,.15); }
    .form-label { font-weight:600; color:#444; margin-bottom:8px; display:block; text-align:right; }
    .btn-login {
        background: var(--primary-gradient); border:none; border-radius:12px; padding:14px; font-weight:700;
        font-size:1.1rem; color:white; width:100%; margin-top:20px; box-shadow: 0 4px 15px rgba(148,44,33,.3);
    }
    .alert-danger { background:#ffe5e5; color:#d63031; border:none; border-radius:12px; }
  </style>
</head>
<body>
<div class="login-container">
  <div class="login-card">
    <img src="assets/favicon/favicon.png" alt="Logo" class="brand-logo">
    <h2 class="login-title">مرحباً بك مجدداً</h2>
    <p class="login-subtitle">سجل الدخول للمتابعة إلى النظام</p>
    <?php if (!empty($error_message)): ?>
      <div class="alert alert-danger mb-4" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?= e($error_message) ?></div>
    <?php endif; ?>
    <form action="<?= e($_SERVER['PHP_SELF'] ?? 'index.php') ?>" method="post" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
      <div class="mb-4 text-end">
        <label for="uname" class="form-label">اسم المستخدم أو البريد أو الهاتف</label>
        <input type="text" name="uname" id="uname" class="form-control" value="<?= e($login_username) ?>" required autocomplete="username">
      </div>
      <div class="mb-4 text-end">
        <label for="password" class="form-label">كلمة المرور</label>
        <input type="password" name="password" id="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-login">تسجيل الدخول <i class="fas fa-arrow-left ms-2"></i></button>
    </form>
  </div>
</div>
<script src="assets/libs/bootstrap5/js/bootstrap.bundle.min.js"></script>
</body>
</html>
