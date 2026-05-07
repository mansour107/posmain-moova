<?php 
include('includes/pos_simple_header.php');

// معالجة تسجيل الخروج
if (isset($_GET['logout'])) {
    unset($_SESSION['pos_authenticated']);
    unset($_SESSION['pos_user_id']);
    unset($_SESSION['pos_user_name']);
    header('Location: pos_barcode.php');
    exit();
}

// نظام الحماية البسيط
if (isset($rowstg['pos_has_password']) && $rowstg['pos_has_password'] == 1) {
    
    // معالجة تسجيل الدخول
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pos_barcode'])) {
        $barcode = trim($_POST['pos_barcode']);
        
        if (empty($barcode)) {
            $login_error = 'الرجاء إدخال الباركود';
        } else {
            // استخدام prepared statement للأمان
            $stmt = $conn->prepare("SELECT id, uname, password FROM users WHERE isdeleted = 0");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $user_found = false;
            
            if ($result && $result->num_rows > 0) {
                while ($user = $result->fetch_assoc()) {
                    $stored_password = $user['password'];
                    $is_valid = false;
                    if (strlen($stored_password) == 32) {
                        $is_valid = (md5($barcode) === $stored_password);
                    }
                    elseif (strpos($stored_password, '$2y$') === 0) {
                        $is_valid = password_verify($barcode, $stored_password);
                    }
                    
                    if ($is_valid) {
                        $_SESSION['pos_authenticated'] = true;
                        $_SESSION['pos_user_id'] = $user['id'];
                        $_SESSION['pos_user_name'] = $user['uname'];
                        $stmt->close();
                        header('Location: pos_barcode.php');
                        exit();
                    }
                }
                $login_error = 'باركود غير صحيح';
            } else {
                $login_error = 'خطأ في قاعدة البيانات';
            }
            $stmt->close();
        }
    }
    if (!isset($_SESSION['pos_authenticated']) || $_SESSION['pos_authenticated'] !== true) {
        // عرض شاشة تسجيل الدخول
        include('includes/pos_login_screen.php');
        exit();
    }
}
$check_tables = $conn->query("SELECT COUNT(*) as count FROM tables WHERE isdeleted = 0");
if ($check_tables) {
    $tables_count = $check_tables->fetch_assoc()['count'];
    if ($tables_count == 0) {
        // استخدام prepared statement للأمان
        $stmt = $conn->prepare("INSERT INTO tables (tname, table_case) VALUES (?, 0)");
        for ($i = 1; $i <= 12; $i++) {
            $table_name = "طاولة " . $i;
            $stmt->bind_param("s", $table_name);
            $stmt->execute();
        }
        $stmt->close();
    }
}
$posdate = date('Y-m-d', strtotime('-4 hours'));
if(isset($_GET['edit'])){
    $id = intval($_GET['edit']); // تأمين المدخلات
    $stmt = $conn->prepare("SELECT * FROM ot_head WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rowed = $result->fetch_assoc();
    $stmt->close();
}
$success_message = '';
if(isset($_SESSION['success_message'])){
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>

<!-- Assets (CSS & JS) -->
<?php include('includes/pos_assets.php'); ?>

<!-- نظام القفل -->
<?php include('includes/pos_lock_system.php'); ?>

<body class="bg-light">

<!-- Hidden input for Edit Mode -->
<input type="hidden" id="edit_order_id" value="<?= isset($id) ? $id : '' ?>">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <div class="moova-navbar-widget" aria-label="Moova POS widget">
            <?php include('elements/pos/cofe_widget.php'); ?>
        </div>

        <a class="navbar-brand me-3 fw-bold" href="dashboard.php">
            <i class="fas fa-home me-2"></i>
            نظام نقاط البيع
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto"></ul>

            <ul class="navbar-nav">
                <li class="nav-item">
                    <button class="btn btn-outline-light btn-sm me-2" id="fullscreenBtn" title="ملء الشاشة">
                        <i class="fas fa-expand-arrows-alt"></i>
                    </button>

                    <button type="button" class="btn btn-outline-warning btn-sm me-2" data-bs-toggle="modal"
                        data-bs-target="#closeShiftModal" title="إغلاق الشيفت">
                        <i class="fas fa-power-off me-1"></i> إغلاق الشيفت
                    </button>
                </li>
                <li class="nav-item">
                    <a href="do/do_logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt me-1"></i> 
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- رسالة النجاح -->
<?php include('includes/pos_success_message.php'); ?>

<!-- Main Content -->
<?php 
$action_url = "do/doadd_invoice.php";
include('includes/pos_content.php');
?>

<?php include('includes/pos_simple_footer.php');?>
