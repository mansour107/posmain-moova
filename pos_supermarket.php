<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/classes/PasswordService.php';
require_once __DIR__ . '/classes/Security/PinService.php';

posmain_send_no_store_headers();

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:index.php');
    exit;
}

include(__DIR__ . '/includes/connect.php');
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/pos_main_pin_entry.php';
require_once __DIR__ . '/config/app_config.php';
require_permission('pos.open', $conn);

$posmainCanCloseShift = auth_guard_has_permission('pos.shift.close', $conn);
$posmainCanRecordShiftExpense = auth_guard_has_permission('pos.cashdrawer.count', $conn);
$posmainCanRecordShiftPayIn = auth_guard_has_permission('pos.drawer.payin', $conn);
$posmainCanRecordShiftSafeDrop = auth_guard_has_permission('pos.drawer.safe_drop', $conn);
$posmainCanRecordDrawerCash = $posmainCanRecordShiftExpense || $posmainCanRecordShiftPayIn || $posmainCanRecordShiftSafeDrop;
$pos_main_pin_auth = function_exists('posmain_is_pin_main_auth') && posmain_is_pin_main_auth();
$pos_pin_mode = false;
$pos_legacy_fallback = true;
try {
    posmain_pin_secret();
    $pos_pin_mode = (new PinService())->anyActiveUserHasPin($conn);
    $pos_legacy_fallback = !$pos_pin_mode;
} catch (RuntimeException $pinSecretException) {
    if ($pinSecretException->getMessage() !== 'PIN_SECRET_MISSING') {
        throw $pinSecretException;
    }
}
posmain_apply_main_pin_pos_entry($conn, 'pos_supermarket.php');

if (isset($_GET['logout'])) {
    if ($pos_main_pin_auth) {
        require_once __DIR__ . '/classes/Security/MainAuthenticationService.php';
        (new MainAuthenticationService())->lockToLoginScreen();
        header('location:index.php');
        exit;
    }
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
        require_once __DIR__ . '/classes/Pos/Service/ShiftEntryService.php';
        $entry = (new ShiftEntryService())->resolveForUser($conn, (int) $current_user['id'], [
            'opening_cash' => $_POST['opening_cash'] ?? '0',
        ]);
        if (($entry['state'] ?? '') === ShiftEntryService::STATE_REGISTER_UNPAIRED) {
            header('location:register_pair.php');
            exit;
        }
        pos_set_acting_user((int) $current_user['id'], (string) $current_user['uname']);
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = (int) $current_user['id'];
        $_SESSION['posmain_shift_entry_state'] = (string) ($entry['state'] ?? '');
        $_SESSION['posmain_shift_entry_message'] = (string) ($entry['message'] ?? '');
        if (!empty($entry['drawer_session']['id'])) {
            $_SESSION['pos_drawer_session_id'] = (int) $entry['drawer_session']['id'];
        }
        $_SESSION['pos_user_name'] = $current_user['uname'];
        unset($_SESSION['pos_shift_closed_for_session']);
        header('location:' . (string) ($entry['redirect'] ?? 'pos_supermarket.php'));
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
require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php';
$posmainHandoverEnabled = false;
$posmainNeedsOpenCount = false;
$posmainOpenCountDenied = false;
$posmainShiftEntryState = (string) ($_SESSION['posmain_shift_entry_state'] ?? '');
$posmainShiftEntryMessage = (string) ($_SESSION['posmain_shift_entry_message'] ?? '');
$posmainBlockingSession = is_array($_SESSION['posmain_shift_blocking'] ?? null)
    ? $_SESSION['posmain_shift_blocking']
    : [];
if (!empty($_SESSION['pos_pending_takeover'])
    && in_array($posmainShiftEntryState, ['branch_blocked', ''], true)
) {
    $_SESSION['posmain_shift_entry_state'] = 'open_count_pending';
    $_SESSION['posmain_shift_entry_message'] = '';
    unset($_SESSION['posmain_shift_blocking']);
    $_SESSION['pos_unlocked_pending_open'] = true;
    $posmainShiftEntryState = 'open_count_pending';
    $posmainShiftEntryMessage = '';
    $posmainBlockingSession = [];
}
if ($posmainShiftEntryState === 'branch_blocked' && $current_user_id > 0) {
    $blockingId = (int) ($posmainBlockingSession['id'] ?? 0);
    $blockingStillOpen = false;
    if ($blockingId > 0) {
        $blockCheck = $conn->prepare('SELECT status FROM drawer_sessions WHERE id = ? LIMIT 1');
        if ($blockCheck) {
            $blockCheck->bind_param('i', $blockingId);
            $blockCheck->execute();
            $blockRow = $blockCheck->get_result()->fetch_assoc();
            $blockCheck->close();
            $blockingStillOpen = (($blockRow['status'] ?? '') === 'open');
        }
    }
    if (!$blockingStillOpen) {
        require_once __DIR__ . '/classes/Pos/Service/ShiftEntryService.php';
        try {
            $freshEntry = (new ShiftEntryService())->resolveForUser($conn, $current_user_id, [
                'opening_cash' => '0',
            ]);
            $posmainShiftEntryState = (string) ($freshEntry['state'] ?? '');
            $posmainShiftEntryMessage = (string) ($freshEntry['message'] ?? '');
            $_SESSION['posmain_shift_entry_state'] = $posmainShiftEntryState;
            $_SESSION['posmain_shift_entry_message'] = $posmainShiftEntryMessage;
            if (!empty($freshEntry['blocking_session']) && is_array($freshEntry['blocking_session'])) {
                $posmainBlockingSession = $freshEntry['blocking_session'];
                $_SESSION['posmain_shift_blocking'] = $posmainBlockingSession;
            } else {
                $posmainBlockingSession = [];
                unset($_SESSION['posmain_shift_blocking']);
            }
            if (!empty($freshEntry['drawer_session']['id'])) {
                $_SESSION['pos_drawer_session_id'] = (int) $freshEntry['drawer_session']['id'];
            }
        } catch (Throwable $healException) {
            error_log('POS supermarket branch_blocked heal failed: ' . $healException->getMessage());
        }
    }
}
$posmainShiftBlocked = in_array($posmainShiftEntryState, [
    'branch_blocked',
    'register_transfer_required',
    'stale_shift',
    'permission_denied',
    'entry_error',
], true);
$posmainOverrideActive = !empty($_SESSION['pos_override_period_id']);
$posmainIdentity = [
    'cashier_name' => (string) ($_SESSION['pos_acting_user_name'] ?? $_SESSION['pos_user_name'] ?? $_SESSION['login'] ?? 'الموظف'),
    'cashier_user_id' => (int) $current_user_id,
    'terminal_name' => null,
    'terminal_user_id' => (int) (function_exists('pos_terminal_user_id') ? pos_terminal_user_id() : ($_SESSION['userid'] ?? 0)),
    'is_takeover' => false,
    'is_override' => false,
    'preceding_cashier_name' => null,
    'preceding_user_id' => null,
    'authorized_by_name' => null,
    'authorized_by_user_id' => null,
    'drawer_session_id' => null,
];
try {
    $posmainIdentity = (new ShiftSessionService())->resolvePosIdentity($conn);
} catch (Throwable $ignored) {
}
try {
    $countService = new ShiftCountService();
    $posmainHandoverEnabled = $countService->handoverEnabled($conn);
    $posmainCanOpenShift = $current_user_id > 0
        && function_exists('auth_guard_has_permission')
        && auth_guard_has_permission('pos.shift.open', $conn);
    $needsOpeningCount = $posmainHandoverEnabled
        && $countService->needsOpeningCount($conn, $current_user_id);
    $posmainNeedsOpenCount = !$posmainShiftBlocked
        && !$posmainOverrideActive
        && (($needsOpeningCount && $posmainCanOpenShift)
            || $posmainShiftEntryState === 'open_count_pending');
    $posmainOpenCountDenied = !$posmainShiftBlocked
        && !$posmainOverrideActive
        && $needsOpeningCount && !$posmainCanOpenShift
        && $posmainShiftEntryState !== 'open_count_pending';
    if ($posmainShiftBlocked || $posmainOverrideActive) {
        unset($_SESSION['pos_unlocked_pending_open']);
    } elseif ($posmainOpenCountDenied) {
        unset($_SESSION['pos_unlocked_pending_open']);
    } elseif (!$posmainNeedsOpenCount) {
        (new ShiftSessionService())->openForCashier($conn, $current_user_id);
    } else {
        $_SESSION['pos_unlocked_pending_open'] = true;
    }
} catch (Throwable $drawerOpenException) {
    error_log('POS drawer session ensure failed: ' . $drawerOpenException->getMessage());
    if (in_array($drawerOpenException->getMessage(), [
        'BRANCH_DRAWER_ALREADY_OPEN',
        'REGISTER_DRAWER_ALREADY_OPEN',
        'USER_DRAWER_ALREADY_OPEN',
    ], true)) {
        $posmainShiftBlocked = true;
        $posmainShiftEntryState = 'branch_blocked';
        $posmainShiftEntryMessage = 'يوجد صندوق مفتوح لموظف آخر على هذا الجهاز';
        unset($_SESSION['pos_unlocked_pending_open']);
        $posmainNeedsOpenCount = false;
        $posmainOpenCountDenied = false;
    }
}

$id = 0;
$rowed = [];
require_once __DIR__ . '/includes/business_day.php';
$posdate = posmain_current_business_day(
    $conn,
    (int) ($_SESSION['pos_tenant'] ?? 0),
    (int) ($_SESSION['pos_branch'] ?? 0)
);
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
if (!empty($posmainIdentity['is_override'])) {
    $pos_body_class .= ' pos-override-active';
}
include('includes/pos_simple_header.php');
?>

<!-- Assets (CSS & JS) -->
<?php include('includes/pos_assets.php'); ?>
<link rel="stylesheet" href="css/pos_supermarket.css?v=<?= time() ?>">
<?= csrf_meta_tag('pos_browser', 'posmain-csrf-token') ?>
<?= csrf_meta_tag('pos_override', 'pos-override-csrf-token') ?>
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

                    <?php if ($posmainCanRecordDrawerCash) { ?>
                    <button type="button" class="btn btn-outline-info btn-sm me-2 position-relative" data-bs-toggle="modal"
                        data-bs-target="#shiftExpenseModal" title="حركة نقدية للدرج">
                        <i class="fas fa-wallet me-1"></i> نقدية الدرج
                    </button>
                    <?php } ?>

                    <?php if ($posmainCanCloseShift) { ?>
                    <button type="button" class="btn btn-outline-warning btn-sm me-2" data-bs-toggle="modal"
                        data-bs-target="#closeShiftModal" title="إغلاق الشيفت">
                        <i class="fas fa-power-off me-1"></i> إغلاق الشيفت
                    </button>
                    <?php } ?>
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
<?php include __DIR__ . '/elements/pos/shift_override_banner.php'; ?>
<?php include __DIR__ . '/elements/pos/shift_recovery_overlay.php'; ?>
<?php include __DIR__ . '/elements/pos/shift_open_count_overlay.php'; ?>
<?php
$action_url = "do/doadd_invoice.php";
include('includes/pos_supermarket_content.php');
?>
</div>

<?php include('includes/pos_simple_footer.php'); ?>
