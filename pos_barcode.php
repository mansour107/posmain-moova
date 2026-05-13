<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/classes/PasswordService.php';

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:index.php');
    exit;
}

include(__DIR__ . '/includes/connect.php');

if (isset($_GET['logout'])) {
    unset($_SESSION['pos_authenticated'], $_SESSION['pos_user_id'], $_SESSION['pos_user_name']);
    header('location:pos_barcode.php');
    exit;
}

$current_user_id = (int) $_SESSION['userid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pos_barcode'])) {
    $entered_code = trim($_POST['pos_barcode']);

    $stmt = $conn->prepare("SELECT id, uname, password FROM users WHERE id = ? AND isdeleted = 0 LIMIT 1");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $current_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $is_valid_user_code = false;
    if ($current_user) {
        $stored_password = (string) $current_user['password'];
        $is_valid_user_code = PasswordService::verifyPassword($entered_code, $stored_password);
    }

    if ($is_valid_user_code) {
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = (int) $current_user['id'];
        $_SESSION['pos_user_name'] = $current_user['uname'];
        header('location:pos_barcode.php');
        exit;
    }

    $login_error = 'كود هذا المستخدم غير صحيح';
}

if (
    !isset($_SESSION['pos_authenticated']) ||
    $_SESSION['pos_authenticated'] !== true ||
    (int) ($_SESSION['pos_user_id'] ?? 0) !== $current_user_id
) {
    include('includes/pos_login_screen.php');
    exit;
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

$pos_body_class = 'bg-light';
include('includes/pos_simple_header.php');
?>

<!-- Assets (CSS & JS) -->
<?php include('includes/pos_assets.php'); ?>
<?php include('includes/pos_lock_system.php'); ?>

<!-- Hidden input for Edit Mode -->
<input type="hidden" id="edit_order_id" value="<?= isset($id) ? $id : '' ?>">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm pos-topbar">
    <div class="container-fluid">
        <div class="pos-brand-with-moova">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <i class="fas fa-home me-2"></i>
                <span>نظام نقاط البيع</span>
            </a>
            <div class="moova-navbar-widget" aria-label="Moova POS widget">
                <?php include('elements/pos/cofe_widget.php'); ?>
            </div>
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <div class="navbar-nav mx-auto pos-shift-status">
                <span class="pos-shift-pill">
                    <i class="fas fa-circle me-1"></i>الشيفت مفتوح
                </span>
                <span class="pos-cashier-pill">
                    <i class="fas fa-user me-1"></i>الكاشير: <?= htmlspecialchars($_SESSION['login'] ?? 'الموظف 1') ?>
                </span>
            </div>
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
