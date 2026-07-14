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
require_once __DIR__ . '/includes/layout_capabilities.php';
require_permission('pos.open', $conn);

$posmainCanCloseShift = auth_guard_has_permission('pos.shift.close', $conn);
$posmainCanRecordShiftExpense = auth_guard_has_permission('pos.cashdrawer.count', $conn);
$posmainCanRecordShiftPayIn = auth_guard_has_permission('pos.drawer.payin', $conn);
$posmainCanRecordShiftSafeDrop = auth_guard_has_permission('pos.drawer.safe_drop', $conn);
$posmainCanRecordDrawerCash = $posmainCanRecordShiftExpense || $posmainCanRecordShiftPayIn || $posmainCanRecordShiftSafeDrop;

require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/includes/pos_main_pin_entry.php';
$pinService = new PinService();
$pos_pin_mode = false;
$pos_legacy_fallback = true;
$pos_main_pin_auth = function_exists('posmain_is_pin_main_auth') && posmain_is_pin_main_auth();
try {
    posmain_pin_secret();
    $pos_pin_mode = $pinService->anyActiveUserHasPin($conn);
    $pos_legacy_fallback = !$pos_pin_mode;
} catch (RuntimeException $pinSecretException) {
    if ($pinSecretException->getMessage() !== 'PIN_SECRET_MISSING') {
        throw $pinSecretException;
    }
}

posmain_apply_main_pin_pos_entry($conn, 'pos_barcode.php');

if (isset($_GET['logout'])) {
    require_once __DIR__ . '/includes/auth_guard.php';
    $hasShiftCloseAck = !empty($_SESSION['pos_shift_close_result']) && is_array($_SESSION['pos_shift_close_result']);
    if ($pos_main_pin_auth) {
        require_once __DIR__ . '/classes/Security/MainAuthenticationService.php';
        (new MainAuthenticationService())->lockToLoginScreen();
        if ($hasShiftCloseAck) {
            // Show the shift-close ack modal before landing on the main login page.
            // Session identity is already cleared, so the ack must go straight to
            // index.php (do_logout.php would reject the request as unauthenticated).
            $closeAckRedirectUrl = 'index.php';
            include('includes/pos_login_screen.php');
            exit;
        }
        header('location:index.php');
        exit;
    }
    posmain_clear_pos_shift_session(false);
    header('location:pos_barcode.php');
    exit;
}

$terminal_user_id = (int) $_SESSION['userid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pos_barcode']) && $pos_legacy_fallback) {
    $entered_code = trim($_POST['pos_barcode']);

    $stmt = $conn->prepare('SELECT id, uname, password, display_name FROM users WHERE id = ? AND isdeleted = 0 LIMIT 1');
    $stmt->bind_param("i", $terminal_user_id);
    $stmt->execute();
    $current_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $is_valid_user_code = false;
    if ($current_user) {
        $stored_password = (string) $current_user['password'];
        $is_valid_user_code = PasswordService::verifyPassword($entered_code, $stored_password);
    }

    if ($is_valid_user_code) {
        require_once __DIR__ . '/classes/Pos/Service/ShiftEntryService.php';
        $actingId = (int) $current_user['id'];
        $entry = (new ShiftEntryService())->resolveForUser($conn, $actingId, [
            'opening_cash' => $_POST['opening_cash'] ?? '0',
        ]);
        if (($entry['state'] ?? '') === ShiftEntryService::STATE_REGISTER_UNPAIRED) {
            header('location:register_pair.php');
            exit;
        }
        pos_set_acting_user($actingId, (string) ($current_user['display_name'] ?? $current_user['uname']));
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = $actingId;
        $_SESSION['posmain_shift_entry_state'] = (string) ($entry['state'] ?? '');
        $_SESSION['posmain_shift_entry_message'] = (string) ($entry['message'] ?? '');
        if (!empty($entry['drawer_session']['id'])) {
            $_SESSION['pos_drawer_session_id'] = (int) $entry['drawer_session']['id'];
        }
        $_SESSION['pos_user_name'] = (string) ($current_user['display_name'] ?? $current_user['uname']);
        unset($_SESSION['pos_shift_closed_for_session']);
        pos_touch_activity();
        header('location:' . (string) ($entry['redirect'] ?? 'pos_barcode.php'));
        exit;
    }

    $login_error = 'كود هذا المستخدم غير صحيح';
}

if (!auth_guard_is_pos_barcode_unlocked()) {
    if (isset($_SESSION['pos_login_error'])) {
        $login_error = (string) $_SESSION['pos_login_error'];
        unset($_SESSION['pos_login_error']);
    }
    include('includes/pos_login_screen.php');
    exit;
}

pos_enforce_active_pos_lane($conn);

require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/classes/Security/PermissionService.php';
require_once __DIR__ . '/classes/Security/RolePermissionSyncService.php';
$acting_user_id = pos_acting_user_id();
if ($acting_user_id > 0) {
    $actingRoleStmt = $conn->prepare('SELECT userrole FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
    $actingRoleStmt->bind_param('i', $acting_user_id);
    $actingRoleStmt->execute();
    $actingRoleRow = $actingRoleStmt->get_result()->fetch_assoc();
    $actingRoleStmt->close();
    $actingRoleId = (int) ($actingRoleRow['userrole'] ?? 0);
    if ($actingRoleId > 0) {
        RolePermissionSyncService::repairPresetRoleCapabilitiesIfNeeded($conn, $actingRoleId);
    }
}
$posmainActingCanVoidPersistedItem = $acting_user_id > 0
    && PermissionService::forConnection($conn)->check($acting_user_id, 'pos.void.item_after_send');
$posmainShiftEntryState = (string) ($_SESSION['posmain_shift_entry_state'] ?? '');
$posmainShiftEntryMessage = (string) ($_SESSION['posmain_shift_entry_message'] ?? '');
$posmainBlockingSession = is_array($_SESSION['posmain_shift_blocking'] ?? null)
    ? $_SESSION['posmain_shift_blocking']
    : [];
// After a successful takeover the drawer is closed, but a stale branch_blocked
// entry state would re-show the busy-drawer recovery overlay on reload.
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

// If branch_blocked points at a drawer that is no longer open, re-resolve from DB
// so refresh does not keep a dead recovery gate.
if ($posmainShiftEntryState === 'branch_blocked' && $acting_user_id > 0) {
    $blockingId = (int) ($posmainBlockingSession['id'] ?? 0);
    $blockingStillOpen = false;
    if ($blockingId > 0) {
        $blockCheck = $conn->prepare(
            "SELECT status FROM drawer_sessions WHERE id = ? LIMIT 1"
        );
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
            $freshEntry = (new ShiftEntryService())->resolveForUser($conn, $acting_user_id, [
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
            error_log('POS branch_blocked heal failed: ' . $healException->getMessage());
        }
    }
}
$posmainShiftRecoveryStates = [
    'branch_blocked',
    'register_transfer_required',
    'stale_shift',
    'permission_denied',
    'entry_error',
];
$posmainShiftBlocked = in_array($posmainShiftEntryState, $posmainShiftRecoveryStates, true);
$posmainOverrideActive = !empty($_SESSION['pos_override_period_id']);

try {
    require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php';
    $shiftCountService = new ShiftCountService();
    $posmainHandoverEnabled = $shiftCountService->handoverEnabled($conn);
    $posmainCanOpenShift = $acting_user_id > 0
        && auth_guard_has_permission('pos.shift.open', $conn);
    $needsOpeningCount = $posmainHandoverEnabled
        && $shiftCountService->needsOpeningCount($conn, $acting_user_id);
    $posmainNeedsOpenCount = !$posmainShiftBlocked
        && !$posmainOverrideActive
        && (($needsOpeningCount && $posmainCanOpenShift)
            || $posmainShiftEntryState === 'open_count_pending');
    $posmainOpenCountDenied = !$posmainShiftBlocked
        && !$posmainOverrideActive
        && $needsOpeningCount && !$posmainCanOpenShift
        && $posmainShiftEntryState !== 'open_count_pending';

    if ($posmainShiftBlocked || $posmainOverrideActive) {
        // Do not auto-open or sell until recovery completes / while override is bound.
        unset($_SESSION['pos_unlocked_pending_open']);
    } elseif ($posmainOpenCountDenied) {
        // Waiter/kitchen (etc.): unlocked for tables, but must not open a drawer.
        unset($_SESSION['pos_unlocked_pending_open']);
    } elseif (!$posmainNeedsOpenCount) {
        (new ShiftSessionService())->openForCashier($conn, $acting_user_id, [
            'register_id' => $_SESSION['pos_register_id'] ?? null,
        ]);
        unset($_SESSION['posmain_shift_entry_state'], $_SESSION['posmain_shift_entry_message']);
    } else {
        $_SESSION['pos_unlocked_pending_open'] = true;
    }
    pos_touch_activity();
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

$posmainIdentity = [
    'cashier_name' => (string) ($_SESSION['pos_acting_user_name'] ?? $_SESSION['pos_user_name'] ?? $_SESSION['login'] ?? 'الموظف'),
    'cashier_user_id' => (int) $acting_user_id,
    'terminal_name' => null,
    'terminal_user_id' => (int) pos_terminal_user_id(),
    'is_takeover' => false,
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
require_once __DIR__ . '/includes/business_day.php';
$posdate = posmain_current_business_day(
    $conn,
    (int) ($_SESSION['pos_tenant'] ?? 0),
    (int) ($_SESSION['pos_branch'] ?? 0)
);
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

$pos_body_class = 'pos-premium-dark pos-immersive';
if (!empty($posmainIdentity['is_override'])) {
    $pos_body_class .= ' pos-override-active';
}
include('includes/pos_simple_header.php');
?>

<!-- Assets (CSS & JS) -->
<?php include('includes/pos_assets.php'); ?>
<?= csrf_meta_tag('pos_browser', 'posmain-csrf-token') ?>
<?= csrf_meta_tag('pos_pin', 'pos-pin-csrf-token') ?>
<?= csrf_meta_tag('pos_override', 'pos-override-csrf-token') ?>
<?php
$posCapsVer = (int) (@filemtime(__DIR__ . '/js/posmain_capabilities.js') ?: 1);
?>
<script src="js/posmain_capabilities.js?v=<?= $posCapsVer ?>"></script>
<?= posmain_render_acting_pos_context_script($conn, (int) $acting_user_id) ?>
<script>window.POSMAIN_ACTING_CAN_VOID_PERSISTED = <?= $posmainActingCanVoidPersistedItem ? 'true' : 'false' ?>;</script>
<script>
window.POSMAIN_IDENTITY = <?= json_encode($posmainIdentity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
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
<?php include('includes/pos_lock_system.php'); ?>
<?php include('includes/pos_session_guard.php'); ?>

<!-- Hidden input for Edit Mode -->
<input type="hidden" id="edit_order_id" value="<?= isset($id) ? $id : '' ?>">
<input type="hidden" id="posActingUserId" data-acting-user-id="<?= (int) $acting_user_id ?>">

<div class="pos-corner-menu" aria-label="قائمة نقاط البيع">
    <div id="posIdentityBadge"
         class="pos-identity-badge<?= !empty($posmainIdentity['is_takeover']) ? ' is-takeover' : '' ?>"
         role="status"
         aria-live="polite"
         data-cashier-id="<?= (int) ($posmainIdentity['cashier_user_id'] ?? 0) ?>"
         data-takeover="<?= !empty($posmainIdentity['is_takeover']) ? '1' : '0' ?>">
        <span class="pos-identity-avatar" aria-hidden="true">
            <i class="fas fa-user"></i>
        </span>
        <span class="pos-identity-copy">
            <span class="pos-identity-label">الكاشير</span>
            <strong class="pos-identity-name" id="posIdentityCashierName"><?= htmlspecialchars((string) ($posmainIdentity['cashier_name'] ?? 'الموظف'), ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="pos-identity-meta<?= empty($posmainIdentity['is_takeover']) ? ' is-empty' : '' ?>" id="posIdentityMeta">
                <?php if (!empty($posmainIdentity['is_takeover'])): ?>
                    استلم من <?= htmlspecialchars((string) ($posmainIdentity['preceding_cashier_name'] ?? 'كاشير سابق'), ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($posmainIdentity['authorized_by_name'])): ?>
                        · اعتمد <?= htmlspecialchars((string) $posmainIdentity['authorized_by_name'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
        </span>
    </div>
    <button type="button" class="pos-corner-btn" id="cornerRecentOrdersBtn" title="الطلبات السابقة" aria-label="الطلبات السابقة">
        <i class="fas fa-history"></i>
    </button>
    <div class="moova-navbar-widget" aria-label="Moova POS widget">
        <?php include('elements/pos/cofe_widget.php'); ?>
    </div>
    <button type="button" class="pos-corner-btn" id="posKeyboardToggleBtn"
        title="لوحة المفاتيح" aria-label="لوحة المفاتيح" aria-pressed="false">
        <i class="fas fa-keyboard"></i>
    </button>
    <?php if ($posmainCanRecordDrawerCash) { ?>
    <button type="button" class="pos-corner-btn" id="posDrawerNoSaleBtn" title="فتح درج بدون بيع" aria-label="فتح درج بدون بيع">
        <i class="fas fa-cash-register"></i>
    </button>
    <button type="button" class="pos-corner-btn" data-bs-toggle="modal"
        data-bs-target="#shiftExpenseModal" title="حركة نقدية للدرج" aria-label="حركة نقدية للدرج">
        <i class="fas fa-wallet"></i>
    </button>
    <?php } ?>
    <?php if ($posmainCanCloseShift) { ?>
    <button type="button" class="pos-corner-btn" data-bs-toggle="modal"
        data-bs-target="#closeShiftModal" title="إغلاق الشيفت" aria-label="إغلاق الشيفت">
        <i class="fas fa-power-off"></i>
    </button>
    <?php } ?>
    <a href="do/do_logout.php" class="pos-corner-btn" title="تسجيل الخروج" aria-label="تسجيل الخروج">
        <i class="fas fa-sign-out-alt"></i>
    </a>
</div>

<!-- Navbar (hidden in immersive mode) -->
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
                    <i class="fas fa-user me-1"></i>الكاشير: <?= htmlspecialchars((string) ($posmainIdentity['cashier_name'] ?? $_SESSION['pos_acting_user_name'] ?? $_SESSION['pos_user_name'] ?? $_SESSION['login'] ?? 'الموظف'), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <ul class="navbar-nav me-auto"></ul>

            <ul class="navbar-nav">
                <?php if ($posmainCanRecordDrawerCash) { ?>
                <li class="nav-item">
                    <button type="button" class="btn btn-outline-light btn-sm me-2 position-relative" data-bs-toggle="modal"
                        data-bs-target="#shiftExpenseModal" title="حركة نقدية للدرج">
                        <i class="fas fa-wallet me-1"></i> نقدية الدرج
                    </button>
                </li>
                <?php } ?>
                <?php if ($posmainCanCloseShift) { ?>
                <li class="nav-item">
                    <button type="button" class="btn btn-outline-warning btn-sm me-2" data-bs-toggle="modal"
                        data-bs-target="#closeShiftModal" title="إغلاق الشيفت">
                        <i class="fas fa-power-off me-1"></i> إغلاق الشيفت
                    </button>
                </li>
                <?php } ?>
                <li class="nav-item">
                    <button type="button" class="btn btn-outline-light btn-sm me-2" id="posHeaderLockBtn" title="قفل الجهاز">
                        <i class="fas fa-lock me-1"></i> قفل
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

<!-- Main Content -->
<?php include __DIR__ . '/elements/pos/shift_override_banner.php'; ?>
<?php include __DIR__ . '/elements/pos/shift_recovery_overlay.php'; ?>
<?php include __DIR__ . '/elements/pos/shift_open_count_overlay.php'; ?>
<?php 
$action_url = "do/doadd_invoice.php";
include('includes/pos_content.php');
?>

<?php include('includes/pos_simple_footer.php');?>
