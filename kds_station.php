<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/kds_access.php';
require_once __DIR__ . '/classes/Pos/Service/KdsStationService.php';

require_permission('kds.view', $conn);
posmain_ensure_kds_schema($conn);

$stationUuid = trim((string) ($_GET['station'] ?? ''));
$stationService = new KdsStationService();
$station = $stationUuid !== '' ? $stationService->getStationByUuid($conn, $stationUuid) : null;

if (!$station || !$station['is_active']) {
    header('Location: kds.php');
    exit;
}

if (!kds_is_admin($conn) && !$stationService->userCanAccessStation($conn, (int) $station['id'], current_user_id())) {
    header('Location: kds.php');
    exit;
}

$canComplete = auth_guard_has_permission('kds.complete', $conn) || kds_is_admin($conn);
$kdsCsrf = csrf_token('kds');
$cssVer = (int) (@filemtime(__DIR__ . '/dist/css/kds.css') ?: 1);
$jsVer = (int) (@filemtime(__DIR__ . '/js/kds_board.js') ?: 1);

$pos_body_class = 'kds-body';
$posmainHeaderPermission = 'kds.view';
include __DIR__ . '/includes/pos_simple_header.php';
?>
<link rel="stylesheet" href="dist/css/kds.css?v=<?= $cssVer ?>">

<div class="kds-screen" id="kdsScreen"
     data-station="<?= htmlspecialchars($station['uuid'], ENT_QUOTES) ?>"
     data-csrf="<?= htmlspecialchars($kdsCsrf, ENT_QUOTES) ?>"
     data-can-complete="<?= $canComplete ? '1' : '0' ?>"
     data-warn="<?= (int) $station['warn_after_seconds'] ?>"
     data-late="<?= (int) $station['late_after_seconds'] ?>">

    <header class="kds-topbar" style="--station-color: <?= htmlspecialchars($station['color']) ?>;">
        <div class="kds-topbar__left">
            <a href="kds.php" class="kds-back" title="رجوع"><i class="fas fa-arrow-right"></i></a>
            <span class="kds-station-dot"></span>
            <h1 class="kds-title"><?= htmlspecialchars($station['name']) ?></h1>
        </div>
        <div class="kds-topbar__right">
            <span class="kds-stat"><span id="kdsActiveCount">0</span> تذاكر نشطة</span>
            <span class="kds-clock" id="kdsClock">--:--</span>
            <button type="button" class="kds-btn-ghost" id="kdsSoundToggle" title="الصوت" aria-pressed="true"><i class="fas fa-volume-up"></i></button>
            <button type="button" class="kds-btn-ghost" id="kdsHistoryBtn" title="السجل"><i class="fas fa-history"></i></button>
            <button type="button" class="kds-btn-ghost" id="kdsFullscreenBtn" title="ملء الشاشة"><i class="fas fa-expand"></i></button>
        </div>
    </header>

    <div class="kds-connection" id="kdsConnection" hidden><i class="fas fa-triangle-exclamation"></i> إعادة الاتصال…</div>

    <div class="kds-board" id="kdsBoard">
        <main class="kds-grid" id="kdsGrid"></main>
        <div class="kds-empty" id="kdsEmpty" hidden><i class="fas fa-mug-hot"></i><p>لا توجد تذاكر حالياً</p></div>
    </div>

    <!-- History drawer -->
    <aside class="kds-drawer" id="kdsDrawer" hidden>
        <div class="kds-drawer__head">
            <h2>سجل التذاكر</h2>
            <button type="button" class="kds-btn-ghost" id="kdsDrawerClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="kds-drawer__body" id="kdsHistoryList"></div>
    </aside>
    <div class="kds-drawer__backdrop" id="kdsDrawerBackdrop" hidden></div>

    <!-- Ticket detail modal (history) -->
    <div class="kds-modal" id="kdsDetailModal" hidden>
        <div class="kds-modal__backdrop" id="kdsDetailBackdrop"></div>
        <div class="kds-modal__panel" role="dialog" aria-modal="true" aria-labelledby="kdsDetailTitle">
            <div class="kds-modal__head">
                <h2 id="kdsDetailTitle">تفاصيل التذكرة</h2>
                <button type="button" class="kds-btn-ghost" id="kdsDetailClose" title="إغلاق"><i class="fas fa-times"></i></button>
            </div>
            <div class="kds-modal__body" id="kdsDetailBody"></div>
            <div class="kds-modal__foot" id="kdsDetailFoot"></div>
        </div>
    </div>
</div>

<script src="js/kds_board.js?v=<?= $jsVer ?>"></script>
<?php include __DIR__ . '/includes/pos_simple_footer.php'; ?>
