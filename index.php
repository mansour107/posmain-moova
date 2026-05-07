<?php
// login.php
// صفحة تسجيل الدخول (مصححة ومحسنة)
// ملاحظات: انقل إعدادات الداتابيس لملف منفصل في مرحلة الإنتاج.

session_start();

// -------------------- إعدادات الداتابيس --------------------
$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'kody2';

// Check connection
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($dbhost, $dbuser, $dbpass);

if ($conn->connect_error) {
    header("Location: pre_start.php?error=server_down");
    exit;
}

// Try to select database
if (!$conn->select_db($dbname)) {
    header("Location: pre_start.php?reason=db_missing");
    exit;
}

// Enable SQL error reporting for debugging subsequent queries
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// -------------------- دوال مساعدة --------------------
function e($str) {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

// لو المستخدم مسجل بالفعل => اذهب للداشبورد
if (isset($_SESSION['login']) && isset($_SESSION['userid'])) {
    header("Location: dashboard.php");
    exit();
}

$error_message = null;

// -------------------- معالجة POST (تسجيل الدخول) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // تحقق من CSRF token (لو نموذج مرسل)
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $posted_csrf)) {
        $error_message = "طلب غير صالح (CSRF). حاول مرة أخرى.";
    } else {
        $user = trim($_POST['uname'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($user === '' || $password === '') {
            $error_message = "يرجى إدخال اسم المستخدم وكلمة المرور";
        } else {
            // استعلام مستخدم بواسطة prepared statement
            $stmt = $conn->prepare("SELECT id, uname, password, userrole, usertype FROM users WHERE uname = ? AND isdeleted != 1 LIMIT 1");
            if ($stmt === false) {
                $error_message = "خطأ في إعداد الاستعلام";
            } else {
                $stmt->bind_param("s", $user);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    $storedHash = $row['password'];
                    $userId = (int)$row['id'];

                    $password_ok = false;

                    // حالة 1: كلمة المرور مخزنة باستخدام password_hash (bcrypt/argon2...)
                    if (strlen($storedHash) >= 60 || str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2a$') || str_starts_with($storedHash, '$argon')) {
                        if (password_verify($password, $storedHash)) {
                            $password_ok = true;
                            // اذا كانت تحتاج إعادة هاش (خافت/تحسين الخوارزمية) -> اعيد هاش
                            if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                                $newHash = password_hash($password, PASSWORD_DEFAULT);
                                $u = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                                if ($u) {
                                    $u->bind_param("si", $newHash, $userId);
                                    $u->execute();
                                    $u->close();
                                }
                            }
                        }
                    }
                    // حالة 2: كلمة السر مخزنة بـ MD5 (طول 32) — دعم قديم، ننصح بالهجرة
                    elseif (strlen($storedHash) === 32) {
                        if (md5($password) === $storedHash) {
                            $password_ok = true;
                            // نعمل rehash إلى password_hash
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $u = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                            if ($u) {
                                $u->bind_param("si", $newHash, $userId);
                                $u->execute();
                                $u->close();
                            }
                        }
                    } else {
                        // محاولة password_verify كخيار عام
                        if (password_verify($password, $storedHash)) {
                            $password_ok = true;
                        }
                    }

                    if ($password_ok) {
                        // تسجيل جلسة آمن
                        session_regenerate_id(true);
                        $_SESSION['userid'] = $row['id'];
                        $_SESSION['usrole'] = $row['userrole'];
                        $_SESSION['usty'] = $row['usertype'];
                        $_SESSION['login'] = $row['uname'];

                        // تسجيل وقت الجلسة (prepared)
                        $session_stmt = $conn->prepare("INSERT INTO session_time(user) VALUES (?)");
                        if ($session_stmt) {
                            $session_stmt->bind_param("i", $userId);
                            $session_stmt->execute();
                            $session_stmt->close();
                        }

                        // هنا ممكن تستدعي logger لو معرف
                        // if (isset($logger)) { $logger->logLogin($row['uname'], true); }

                        header("Location: dashboard.php");
                        exit();
                    } else {
                        // رسالة عامة لا تكشف إن اليوزر غير موجود أو الباسورد خاطئ
                        $error_message = "اسم المستخدم أو كلمة المرور غير صحيحة";
                        // if (isset($logger)) { $logger->logLogin($user, false, "Invalid credentials"); }
                    }
                } else {
                    // مستخدم غير موجود
                    $error_message = "اسم المستخدم أو كلمة المرور غير صحيحة";
                    // if (isset($logger)) { $logger->logLogin($user, false, "User not found"); }
                }

                $stmt->close();
            }
        }
    }
}

// -------------------- جلب قائمة المستخدمين للـ <select> --------------------
$users = [];
$resuser = $conn->query("SELECT id, uname FROM users WHERE isdeleted != '1' ORDER BY id ASC");
if ($resuser) {
    while ($r = $resuser->fetch_assoc()) {
        $users[] = $r;
    }
    $resuser->close();
}

// إغلاق الاتصال بعد العرض (اتركه مفتوحًا للعمليات إذا لزم)
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Kody POS | تسجيل الدخول</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/favicon/favicon.png" type="image/png">

  <!-- Fonts -->
  <link rel="stylesheet" href="assets/fonts/fonts.css">
  
  <!-- Icons -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  
  <!-- Bootstrap 5 (Local) -->
  <link href="assets/libs/bootstrap5/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root {
        --primary-gradient: linear-gradient(135deg, #942C21 0%, #be3e31 100%);
        --glass-bg: rgba(255, 255, 255, 0.9);
        --glass-border: rgba(255, 255, 255, 0.6);
        --input-bg: #fff5f5;
        --theme-color: #942C21;
    }

    body {
        font-family: 'Cairo', sans-serif;
        background-image: url('assets/wallpaper/background.jpg');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        height: 100vh;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    /* Dark Overlay */
    body::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: -1;
        backdrop-filter: blur(5px);
    }

    .login-container {
        width: 100%;
        max-width: 400px;
        padding: 20px;
        z-index: 10;
        animation: fadeInUp 0.8s ease-out;
    }

    .login-card {
        background: var(--glass-bg);
        border-radius: 20px;
        padding: 40px 30px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .login-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 6px;
        background: var(--primary-gradient);
    }

    .brand-logo {
        width: 120px;
        height: auto;
        margin-bottom: 20px;
        filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
        transition: transform 0.3s ease;
    }
    
    .brand-logo:hover {
        transform: scale(1.05) rotate(-5deg);
    }

    .login-title {
        color: #333;
        font-weight: 700;
        margin-bottom: 5px;
        font-size: 1.8rem;
    }

    .login-subtitle {
        color: #666;
        font-size: 0.95rem;
        margin-bottom: 30px;
    }

    .form-control, .form-select {
        background-color: var(--input-bg);
        border: 2px solid transparent;
        border-radius: 12px;
        padding: 12px 15px;
        font-size: 1rem;
        transition: all 0.3s ease;
    }

    .form-control:focus, .form-select:focus {
        background-color: #fff;
        border-color: #942C21;
        box-shadow: 0 0 0 4px rgba(148, 44, 33, 0.15);
    }

    .input-group-text {
        background-color: var(--input-bg);
        border: none;
        border-radius: 12px;
        color: #942C21;
    }
    
    .form-label {
        font-weight: 600;
        color: #444;
        margin-bottom: 8px;
        display: block;
        text-align: right;
    }

    .btn-login {
        background: var(--primary-gradient);
        border: none;
        border-radius: 12px;
        padding: 14px;
        font-weight: 700;
        font-size: 1.1rem;
        color: white;
        width: 100%;
        transition: all 0.3s ease;
        margin-top: 20px;
        box-shadow: 0 4px 15px rgba(148, 44, 33, 0.3);
    }

    .btn-login:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(148, 44, 33, 0.4);
    }
    
    .btn-login:active {
        transform: translateY(0);
    }

    .form-check-input:checked {
        background-color: #942C21;
        border-color: #942C21;
    }

    .alert {
        border-radius: 12px;
        font-size: 0.9rem;
        padding: 10px 15px;
        border: none;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }
    
    .alert-danger {
        background-color: #ffe5e5;
        color: #d63031;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Decoration Circles */
    .circle {
        position: absolute;
        border-radius: 50%;
        z-index: -1;
        opacity: 0.6;
    }
    
    .circle-1 {
        top: 10%;
        left: 20%;
        width: 150px;
        height: 150px;
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        filter: blur(40px);
        animation: float 6s ease-in-out infinite;
    }
    
    .circle-2 {
        bottom: 10%;
        right: 20%;
        width: 200px;
        height: 200px;
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        filter: blur(50px);
        animation: float 8s ease-in-out infinite reverse;
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-20px); }
    }
  </style>
</head>
<body>

<!-- Animated Background Elements -->
<div class="circle circle-1"></div>
<div class="circle circle-2"></div>

<div class="login-container">
    <div class="login-card">
        <div class="text-center">
            <img src="assets/favicon/favicon.png" alt="Logo" class="brand-logo">
            <h2 class="login-title">مرحباً بك مجدداً</h2>
            <p class="login-subtitle">سجل الدخول للمتابعة إلى النظام</p>
        </div>

        <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger mb-4 fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= e($error_message) ?>
        </div>
        <?php endif; ?>

        <form action="<?= e($_SERVER['PHP_SELF']) ?>" method="post" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="mb-4 text-end">
                <label for="uname" class="form-label">اسم المستخدم</label>
                <div class="input-group">
                    <!-- <span class="input-group-text bg-white border-0 ps-3"><i class="fas fa-user text-muted"></i></span> -->
                    <select name="uname" id="uname" class="form-select border-start-0 ps-0" required style="border-radius: 0 12px 12px 0;">
                        <option value="" selected disabled>اختر المستخدم...</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= e($u['uname']) ?>"
                                <?= (isset($_POST['uname']) && $_POST['uname'] === $u['uname']) ? 'selected' : '' ?>>
                                <?= e($u['uname']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-4 text-end">
                <label for="password" class="form-label">كلمة المرور</label>
                <div class="input-group">
                    <!-- <span class="input-group-text bg-white border-0 ps-3"><i class="fas fa-lock text-muted"></i></span> -->
                    <input type="password" name="password" id="password" class="form-control border-start-0 ps-0" placeholder="أدخل كلمة المرور" required style="border-radius: 0 12px 12px 0;">
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label text-muted small" for="remember">
                        تذكرني
                    </label>
                </div>
                <!-- <a href="#" class="text-decoration-none small text-primary fw-bold">نسيت كلمة المرور؟</a> -->
            </div>

            <button type="submit" class="btn btn-login">
                تسجيل الدخول <i class="fas fa-arrow-left ms-2"></i>
            </button>
        </form>
    </div>
    
    <div class="text-center mt-4 text-white-50 small">
        &copy; <?= date('Y') ?> جميع الحقوق محفوظة
    </div>
</div>

<!-- Scripts -->
<script src="assets/libs/bootstrap5/js/bootstrap.bundle.min.js"></script>
<script>
    // Simple script to auto-focus password if user logic is dynamic (optional)
    document.getElementById('uname').addEventListener('change', function() {
        if(this.value) {
            document.getElementById('password').focus();
        }
    });
</script>

</body>
</html>
