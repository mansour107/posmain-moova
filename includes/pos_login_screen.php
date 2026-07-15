<?php
/**
 * POS terminal unlock screen — shared four-digit PIN pad or password fallback.
 *
 * Expects: $login_error (optional), $pos_pin_mode (bool), $pos_legacy_fallback (bool)
 */
require_once __DIR__ . '/csrf.php';
if (!isset($pos_pin_mode) && isset($conn) && $conn instanceof mysqli) {
    require_once __DIR__ . '/../config/app_config.php';
    require_once __DIR__ . '/../classes/Security/PinService.php';
    try {
        posmain_pin_secret();
        $pos_pin_mode = (new PinService())->anyActiveUserHasPin($conn);
        $pos_legacy_fallback = !$pos_pin_mode;
    } catch (RuntimeException $pinSecretException) {
        if ($pinSecretException->getMessage() !== 'PIN_SECRET_MISSING') {
            throw $pinSecretException;
        }
    }
}
$pos_pin_mode = !empty($pos_pin_mode);
$pos_legacy_fallback = !empty($pos_legacy_fallback);

$shiftCloseResult = null;
if (!empty($_SESSION['pos_shift_close_result']) && is_array($_SESSION['pos_shift_close_result'])) {
    $shiftCloseResult = $_SESSION['pos_shift_close_result'];
    unset($_SESSION['pos_shift_close_result'], $_SESSION['success_message']);
}
$closeTone = (string) ($shiftCloseResult['tone'] ?? 'success');
$closeTitle = (string) ($shiftCloseResult['title'] ?? '');
$closeDetail = (string) ($shiftCloseResult['detail'] ?? '');
$showCloseAckOnly = is_array($shiftCloseResult);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $showCloseAckOnly ? 'إغلاق الشيفت' : 'فتح نقطة البيع' ?></title>
    <link href="assets/libs/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fonts/fonts.css" rel="stylesheet">
    <link href="assets/libs/fontawesome.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-family: 'IBM Plex Sans Arabic', 'Inter', sans-serif;
        }
        .unlock-shell { width: min(420px, 94vw); }
        .unlock-card {
            background: #fff;
            border-radius: 20px;
            padding: 2rem 1.75rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
        }
        .unlock-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: .25rem; text-align: center; }
        .unlock-sub { color: #64748b; text-align: center; margin-bottom: 1.5rem; font-size: .95rem; }
        .legacy-panel { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px dashed #e2e8f0; }
        .legacy-panel .form-control {
            text-align: center; padding: .85rem; border-radius: 10px; border: 2px solid #e2e8f0;
        }
        .btn-legacy {
            width: 100%; margin-top: .75rem; padding: .85rem; border: none; border-radius: 10px;
            background: #334155; color: #fff; font-weight: 600;
        }
        .hidden { display: none !important; }
        .shift-close-backdrop {
            position: fixed; inset: 0; z-index: 40;
            background: #0f172a;
            display: flex; align-items: center; justify-content: center;
            padding: 1rem;
        }
        .shift-close-card {
            position: relative;
            width: min(420px, 94vw);
            background: #fff;
            border-radius: 20px;
            padding: 1.75rem 1.5rem 1.35rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.45);
            text-align: center;
            overflow: hidden;
        }
        .shift-close-x {
            position: absolute;
            top: .85rem;
            left: .85rem;
            width: 36px; height: 36px;
            border: none; border-radius: 10px;
            background: #f1f5f9; color: #475569;
            font-size: 1.1rem; line-height: 1;
            cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .shift-close-x:hover { background: #e2e8f0; color: #0f172a; }
        .shift-close-icon {
            width: 64px; height: 64px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.75rem; margin-bottom: 1rem;
        }
        .shift-close-card.is-success .shift-close-icon { background: #ecfdf5; color: #059669; }
        .shift-close-card.is-over .shift-close-icon { background: #fff7ed; color: #c2410c; }
        .shift-close-card.is-under .shift-close-icon { background: #fef2f2; color: #b91c1c; }
        .shift-close-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 .5rem; }
        .shift-close-detail { color: #475569; margin: 0 0 .85rem; line-height: 1.6; }
        .shift-close-hint { color: #64748b; font-size: .85rem; margin: 0 0 1.15rem; }
        .shift-close-timer {
            height: 4px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
            direction: ltr;
        }
        .shift-close-timer-bar {
            display: block;
            height: 100%;
            width: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            transform-origin: left center;
            animation: shiftCloseTimerShrink 5s linear forwards;
        }
        .shift-close-card.is-success .shift-close-timer-bar { background: linear-gradient(90deg, #10b981, #059669); }
        .shift-close-card.is-over .shift-close-timer-bar { background: linear-gradient(90deg, #f59e0b, #c2410c); }
        .shift-close-card.is-under .shift-close-timer-bar { background: linear-gradient(90deg, #f87171, #b91c1c); }
        @keyframes shiftCloseTimerShrink {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }
    </style>
    <?php if (!$showCloseAckOnly): ?>
        <?php include __DIR__ . '/pin_pad_styles.php'; ?>
    <?php endif; ?>
</head>
<body class="<?= $showCloseAckOnly ? '' : 'ppm-page ppm-page--pos-unlock' ?>">
<?php if ($showCloseAckOnly): ?>
<div class="shift-close-backdrop" id="shiftCloseResultModal" role="dialog" aria-modal="true" aria-labelledby="shiftCloseResultTitle">
    <div class="shift-close-card is-<?= htmlspecialchars($closeTone, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="shift-close-x" id="shiftCloseResultDismiss" aria-label="إغلاق والعودة لتسجيل الدخول">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
        <div class="shift-close-icon" aria-hidden="true">
            <?php if ($closeTone === 'success'): ?>
                <i class="fas fa-check"></i>
            <?php elseif ($closeTone === 'over'): ?>
                <i class="fas fa-arrow-up"></i>
            <?php else: ?>
                <i class="fas fa-arrow-down"></i>
            <?php endif; ?>
        </div>
        <h2 class="shift-close-title" id="shiftCloseResultTitle"><?= htmlspecialchars($closeTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="shift-close-detail"><?= htmlspecialchars($closeDetail, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="shift-close-hint">سيتم تحويلك لشاشة تسجيل الدخول خلال ثوانٍ…</p>
        <div class="shift-close-timer" aria-hidden="true">
            <span class="shift-close-timer-bar" id="shiftCloseResultTimerBar"></span>
        </div>
    </div>
</div>
<script>
(function () {
    var LOGIN_URL = <?= json_encode(isset($closeAckRedirectUrl) && $closeAckRedirectUrl !== '' ? $closeAckRedirectUrl : 'do/do_logout.php') ?>;
    var DURATION_MS = 5000;
    var done = false;

    function goLogin() {
        if (done) return;
        done = true;
        try {
            sessionStorage.setItem('pos_locked', '1');
            sessionStorage.removeItem('pos_shift_closed');
        } catch (e) {}
        window.location.replace(LOGIN_URL);
    }

    var btn = document.getElementById('shiftCloseResultDismiss');
    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            goLogin();
        });
    }

    window.setTimeout(goLogin, DURATION_MS);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Enter') {
            e.preventDefault();
            goLogin();
        }
    });
})();
</script>
<?php else: ?>
<div class="unlock-shell">
    <?php if ($pos_pin_mode): ?>
        <?php
        $pinPadId = 'posUnlockPinPad';
        $pinPadCsrf = csrf_token('pos_pin');
        $pinPadEndpoint = 'ajax/pos_pin_login.php';
        $pinPadEyebrow = 'نقطة البيع';
        $pinPadTitle = 'فتح الشاشة';
        $pinPadSubtitle = 'الجهاز: ' . ($_SESSION['login'] ?? '') . ' — أدخل رمزك المكوّن من 4 أرقام';
        $pinPadError = $login_error ?? null;
        $pinPadDigits = 4;
        $pinPadMode = 'login';
        $pinPadExtraFields = '';
        include __DIR__ . '/pin_pad_fragment.php';
        ?>
    <?php else: ?>
    <div class="unlock-card">
        <?php if ($pos_legacy_fallback): ?>
        <div class="legacy-panel" id="legacyPanel">
            <p class="text-center text-muted" style="font-size:.9rem">أدخل كلمة مرور المستخدم الحالي</p>
            <form method="POST" action="">
                <input type="password" name="pos_barcode" class="form-control" placeholder="كلمة المرور" autocomplete="current-password" <?= $pos_pin_mode ? '' : 'autofocus required' ?>>
                <button type="submit" class="btn-legacy"><i class="fas fa-sign-in-alt"></i> دخول</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($pos_pin_mode): ?>
<script src="js/pin_pad.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pin_pad.js') ?: 1) ?>"></script>
<script>window.PosmainPinPad && window.PosmainPinPad.init('posUnlockPinPad');</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
