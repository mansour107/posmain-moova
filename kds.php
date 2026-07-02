<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/kds_access.php';
require_once __DIR__ . '/classes/Pos/Service/KdsStationService.php';

require_permission('kds.view', $conn);
posmain_ensure_kds_schema($conn);

$isAdmin = kds_is_admin($conn);
$stationService = new KdsStationService();
$stations = $stationService->stationsForUser($conn, current_user_id(), $isAdmin);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper" style="padding: 20px;">
    <section class="content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                <h3 class="m-0"><i class="fas fa-tv"></i> شاشات المطبخ</h3>
                <?php if ($isAdmin) { ?>
                    <a href="kds_settings.php" class="btn btn-outline-secondary"><i class="fas fa-cog"></i> إعدادات الشاشات</a>
                <?php } ?>
            </div>

            <?php if (!$stations) { ?>
                <div class="alert alert-info">
                    لا توجد محطات متاحة لك حالياً.
                    <?php if ($isAdmin) { ?>
                        <a href="kds_settings.php">أنشئ محطة جديدة من الإعدادات</a>.
                    <?php } else { ?>
                        تواصل مع المدير لإسنادك لمحطة.
                    <?php } ?>
                </div>
            <?php } ?>

            <div class="row">
                <?php foreach ($stations as $st) { ?>
                    <div class="col-md-4 col-lg-3 mb-4">
                        <a href="kds_station.php?station=<?= urlencode($st['uuid']) ?>" class="text-decoration-none">
                            <div class="card h-100 shadow-sm" style="border-top: 6px solid <?= htmlspecialchars($st['color']) ?>;">
                                <div class="card-body text-center" style="padding: 30px 16px;">
                                    <div style="font-size: 42px; color: <?= htmlspecialchars($st['color']) ?>;"><i class="fas fa-utensils"></i></div>
                                    <h4 class="mt-3 mb-1" style="color:#222;"><?= htmlspecialchars($st['name']) ?></h4>
                                    <?php if ($st['is_default']) { ?>
                                        <span class="badge bg-secondary">افتراضية</span>
                                    <?php } ?>
                                    <div class="mt-3"><span class="btn btn-primary btn-sm"><i class="fas fa-play"></i> فتح الشاشة</span></div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php } ?>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
