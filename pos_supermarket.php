<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/classes/PasswordService.php';

posmain_send_no_store_headers();

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:index.php');
    exit;
}

include(__DIR__ . '/includes/connect.php');

if (isset($_GET['logout'])) {
    require_once __DIR__ . '/includes/auth_guard.php';
    posmain_clear_pos_shift_session(false);
    header('location:pos_supermarket.php');
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
        require_once __DIR__ . '/includes/auth_guard.php';
        require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';
        (new ShiftSessionService())->openForCashier($conn, (int) $current_user['id'], [
            'opening_cash' => $_POST['opening_cash'] ?? '0',
        ]);
        $_SESSION['pos_user_name'] = $current_user['uname'];
        header('location:pos_supermarket.php');
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

require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';
try {
    (new ShiftSessionService())->openForCashier($conn, $current_user_id);
} catch (Throwable $drawerOpenException) {
    error_log('POS drawer session ensure failed: ' . $drawerOpenException->getMessage());
}

$id = 0;
$rowed = [];
$posdate = date('Y-m-d', strtotime('-4 hours'));
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM ot_head WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rowed = $result->fetch_assoc();
    $stmt->close();
}

$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$pos_body_class = 'bg-light supermarket-theme vh-100 overflow-hidden d-flex flex-column';
include('includes/pos_simple_header.php');
?>

<!-- Assets (CSS & JS) -->
<?php include('includes/pos_assets.php'); ?>
<link rel="stylesheet" href="css/pos_supermarket.css?v=<?= time() ?>">
<?= csrf_meta_tag('pos_browser', 'posmain-csrf-token') ?>
<script>
(function () {
    const tokenElement = document.querySelector('meta[name="posmain-csrf-token"]');
    const csrfToken = tokenElement ? tokenElement.getAttribute('content') : '';
    window.POSMAIN_CSRF_TOKEN = csrfToken;
    window.POSMAIN_CSRF_HEADER = 'X-CSRF-Token';
    window.POSMAIN_ATTACH_CSRF_HEADER = function (xhr, settings) {
        const method = ((settings && (settings.type || settings.method)) || 'GET').toUpperCase();
        if (!/^(POST|PUT|PATCH|DELETE)$/.test(method) || !window.POSMAIN_CSRF_TOKEN) {
            return;
        }

        xhr.setRequestHeader(window.POSMAIN_CSRF_HEADER, window.POSMAIN_CSRF_TOKEN);
        xhr.setRequestHeader('X-POSMAIN-CSRF-Token', window.POSMAIN_CSRF_TOKEN);
    };

    if (window.jQuery && typeof window.jQuery.ajaxSetup === 'function') {
        window.jQuery.ajaxSetup({ beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER });
    }
})();
</script>

<!-- نظام القفل -->
<?php include('includes/pos_lock_system.php'); ?>
<?php include('includes/pos_session_guard.php'); ?>

<!-- Hidden input for Edit Mode -->
<input type="hidden" id="edit_order_id" value="<?= isset($id) ? (int) $id : '' ?>">

<!-- Navbar (Supermarket Style) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand me-5 fw-bold" href="dashboard.php">
            <i class="fas fa-shopping-cart me-2 text-warning"></i>
            كاشير السوبر ماركت
        </a>

        <div class="collapse navbar-collapse d-flex justify-content-end" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item d-flex align-items-center">
                    <div class="text-white me-4" style="font-size: 0.9rem;">
                        <i class="fas fa-user-circle me-1"></i>
                        <?= htmlspecialchars($_SESSION['pos_user_name'] ?? $_SESSION['login'] ?? 'الكاشير', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <button class="btn btn-outline-light btn-sm me-2" id="fullscreenBtn" title="ملء الشاشة">
                        <i class="fas fa-expand-arrows-alt"></i>
                    </button>

                    <button type="button" class="btn btn-outline-info btn-sm me-2 position-relative" data-bs-toggle="modal"
                        data-bs-target="#shiftExpenseModal" title="تسجيل مصروف">
                        <i class="fas fa-wallet me-1"></i> مصروف
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle d-none js-shift-expense-badge"></span>
                    </button>

                    <button type="button" class="btn btn-outline-warning btn-sm me-2" data-bs-toggle="modal"
                        data-bs-target="#closeShiftModal" title="إغلاق الشيفت">
                        <i class="fas fa-power-off me-1"></i> إغلاق الشيفت
                    </button>
                    <a href="pos_supermarket.php?logout=1" class="btn btn-danger btn-sm">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Main Content -->
<div class="flex-grow-1 d-flex flex-column overflow-hidden">
<?php
$action_url = "do/doadd_invoice.php";
include('includes/pos_supermarket_content.php');
?>
</div>

<?php include('includes/pos_simple_footer.php'); ?>
