<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/kds_bootstrap.php';
require_once __DIR__ . '/classes/Pos/Service/KdsStationService.php';

require_permission('kds.manage', $conn);
posmain_ensure_kds_schema($conn);

$kdsCsrf = csrf_token('kds_manage');
$stationService = new KdsStationService();
$stations = $stationService->listStations($conn, false);
$categories = $stationService->categories($conn);
$categoryMap = $stationService->categoryMap($conn);

$usersResult = $conn->query("SELECT id, uname FROM users WHERE COALESCE(isdeleted,0) = 0 ORDER BY uname ASC");
$kdsUsers = [];
if ($usersResult) {
    while ($u = $usersResult->fetch_assoc()) {
        $kdsUsers[] = ['id' => (int) $u['id'], 'uname' => (string) ($u['uname'] ?? ('#' . $u['id']))];
    }
}

$assignmentsByStation = [];
foreach ($stations as $st) {
    $assignmentsByStation[(int) $st['id']] = $stationService->userIdsForStation($conn, (int) $st['id']);
}

$flashOk = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
$flashError = isset($_GET['error']) ? (string) $_GET['error'] : '';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper" style="padding: 18px;">
    <section class="content">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                <h3 class="m-0"><i class="fas fa-tv"></i> إعدادات شاشة المطبخ (KDS)</h3>
                <a href="kds.php" class="btn btn-outline-primary"><i class="fas fa-external-link-alt"></i> فتح الشاشات</a>
            </div>

            <?php if ($flashOk !== '') { ?>
                <div class="alert alert-success">تم الحفظ بنجاح.</div>
            <?php } ?>
            <?php if ($flashError !== '') { ?>
                <div class="alert alert-danger">خطأ: <?= htmlspecialchars($flashError) ?></div>
            <?php } ?>

            <!-- Stations ------------------------------------------------->
            <div class="card mb-4" id="stations">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-layer-group"></i> المحطات</h3></div>
                <div class="card-body">
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>المحطة</th>
                                    <th width="90">اللون</th>
                                    <th width="80">ترتيب</th>
                                    <th width="90">نشطة</th>
                                    <th width="100">افتراضية</th>
                                    <th>رابط الشاشة</th>
                                    <th width="170">العمليات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$stations) { ?>
                                    <tr><td colspan="7" class="text-center text-muted">لا توجد محطات بعد</td></tr>
                                <?php } ?>
                                <?php foreach ($stations as $st) {
                                    $stationUrl = 'kds_station.php?station=' . urlencode($st['uuid']);
                                ?>
                                <tr>
                                    <form action="do/kds_station_save.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($kdsCsrf) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                                        <td><input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($st['name']) ?>" required></td>
                                        <td><input type="color" name="color" class="form-control form-control-sm form-control-color" value="<?= htmlspecialchars($st['color']) ?>"></td>
                                        <td><input type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int) $st['sort_order'] ?>"></td>
                                        <td class="text-center"><input type="checkbox" name="is_active" <?= $st['is_active'] ? 'checked' : '' ?>></td>
                                        <td class="text-center"><input type="checkbox" name="is_default" <?= $st['is_default'] ? 'checked' : '' ?>></td>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control kds-url" readonly value="<?= htmlspecialchars($stationUrl) ?>">
                                                <button type="button" class="btn btn-outline-secondary kds-copy-url" data-url="<?= htmlspecialchars($stationUrl) ?>"><i class="fas fa-copy"></i></button>
                                            </div>
                                        </td>
                                        <td class="text-nowrap">
                                            <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-save"></i></button>
                                            <a href="<?= htmlspecialchars($stationUrl) ?>" target="_blank" class="btn btn-sm btn-success"><i class="fas fa-play"></i></a>
                                    </form>
                                            <form action="do/kds_station_delete.php" method="post" class="d-inline" onsubmit="return confirm('حذف هذه المحطة؟');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($kdsCsrf) ?>">
                                                <input type="hidden" name="station_id" value="<?= (int) $st['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="mb-2"><i class="fas fa-plus-circle"></i> إضافة محطة جديدة</h5>
                    <form action="do/kds_station_save.php" method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($kdsCsrf) ?>">
                        <div class="col-md-3"><label class="form-label">الاسم</label><input type="text" name="name" class="form-control" required></div>
                        <div class="col-md-2"><label class="form-label">اللون</label><input type="color" name="color" class="form-control form-control-color" value="#e8a020"></div>
                        <div class="col-md-2"><label class="form-label">تنبيه بعد (ثانية)</label><input type="number" name="warn_after_seconds" class="form-control" value="360"></div>
                        <div class="col-md-2"><label class="form-label">متأخر بعد (ثانية)</label><input type="number" name="late_after_seconds" class="form-control" value="720"></div>
                        <div class="col-md-3">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="newActive" checked><label class="form-check-label" for="newActive">نشطة</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="auto_complete_on_paid" id="newAuto"><label class="form-check-label" for="newAuto">إنهاء تلقائي عند الدفع</label></div>
                        </div>
                        <div class="col-12"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة المحطة</button></div>
                    </form>
                </div>
            </div>

            <!-- Routing -------------------------------------------------->
            <div class="card mb-4" id="routing">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-route"></i> توجيه الأصناف للمحطات</h3></div>
                <div class="card-body">
                    <p class="text-muted">كل تصنيف يذهب لمحطة واحدة. التصنيفات غير المحددة تذهب للمحطة الافتراضية.</p>
                    <form action="do/kds_category_map_save.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($kdsCsrf) ?>">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="bg-light"><tr><th>التصنيف</th><th width="320">المحطة</th></tr></thead>
                                <tbody>
                                    <?php if (!$categories) { ?>
                                        <tr><td colspan="2" class="text-center text-muted">لا توجد تصنيفات</td></tr>
                                    <?php } ?>
                                    <?php foreach ($categories as $cat) {
                                        $selected = (int) ($categoryMap[$cat['id']] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($cat['name']) ?></td>
                                        <td>
                                            <select name="cat_station[<?= (int) $cat['id'] ?>]" class="form-select form-select-sm">
                                                <option value="0">— المحطة الافتراضية —</option>
                                                <?php foreach ($stations as $st) { ?>
                                                    <option value="<?= (int) $st['id'] ?>" <?= $selected === (int) $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['name']) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التوجيه</button>
                    </form>
                </div>
            </div>

            <!-- Workers -------------------------------------------------->
            <div class="card mb-4" id="workers">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-users"></i> عمال المحطات</h3></div>
                <div class="card-body">
                    <p class="text-muted">إذا لم تُسند أي عامل لمحطة، يمكن لأي مستخدم لديه صلاحية KDS فتحها. الإسناد يقيّد الفتح على المحددين فقط.</p>
                    <div class="row">
                        <?php foreach ($stations as $st) {
                            $assigned = $assignmentsByStation[(int) $st['id']] ?? [];
                        ?>
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3">
                                <h6 class="mb-2"><span class="badge" style="background: <?= htmlspecialchars($st['color']) ?>">&nbsp;</span> <?= htmlspecialchars($st['name']) ?></h6>
                                <form action="do/kds_worker_assign.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($kdsCsrf) ?>">
                                    <input type="hidden" name="station_id" value="<?= (int) $st['id'] ?>">
                                    <div style="max-height: 180px; overflow-y: auto;">
                                        <?php foreach ($kdsUsers as $usr) { ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?= $usr['id'] ?>" id="u<?= (int) $st['id'] ?>_<?= $usr['id'] ?>" <?= in_array($usr['id'], $assigned, true) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="u<?= (int) $st['id'] ?>_<?= $usr['id'] ?>"><?= htmlspecialchars($usr['uname']) ?></label>
                                            </div>
                                        <?php } ?>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary mt-2"><i class="fas fa-save"></i> حفظ العمال</button>
                                </form>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<script>
document.addEventListener('click', function (event) {
    var btn = event.target.closest('.kds-copy-url');
    if (!btn) return;
    var url = window.location.origin + window.location.pathname.replace(/kds_settings\.php$/, '') + btn.getAttribute('data-url');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url);
    }
    var original = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i>';
    setTimeout(function () { btn.innerHTML = original; }, 1200);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
