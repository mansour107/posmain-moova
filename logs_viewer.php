<?php 
include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');

// التحقق من الصلاحيات
if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:index.php');
    exit();
}

// معالجة الفلاتر
$level = $_GET['level'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$limit = (int)($_GET['limit'] ?? 50);
$offset = (int)($_GET['offset'] ?? 0);

// الحصول على السجلات
$logs = [];
$stats = [];
if (isset($logger)) {
    $logs = $logger->getLogs($level, $limit, $offset);
    $stats = $logger->getLogStats();
}
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>📊 نظام السجلات الشامل</h1>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <!-- إحصائيات سريعة -->
            <div class="row">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?= $stats['total'] ?? 0 ?></h3>
                            <p>إجمالي السجلات</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-list"></i>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?= $stats['today'] ?? 0 ?></h3>
                            <p>سجلات اليوم</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?= $stats['by_level']['WARNING'] ?? 0 ?></h3>
                            <p>تحذيرات</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <h3><?= ($stats['by_level']['ERROR'] ?? 0) + ($stats['by_level']['CRITICAL'] ?? 0) ?></h3>
                            <p>أخطاء</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- فلاتر البحث -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🔍 فلاتر البحث</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-2">
                            <label>مستوى السجل</label>
                            <select name="level" class="form-control">
                                <option value="">الكل</option>
                                <option value="DEBUG" <?= $level === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
                                <option value="INFO" <?= $level === 'INFO' ? 'selected' : '' ?>>INFO</option>
                                <option value="WARNING" <?= $level === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                                <option value="ERROR" <?= $level === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                                <option value="CRITICAL" <?= $level === 'CRITICAL' ? 'selected' : '' ?>>CRITICAL</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label>من تاريخ</label>
                            <input type="date" name="date_from" value="<?= $date_from ?>" class="form-control">
                        </div>
                        
                        <div class="col-md-2">
                            <label>إلى تاريخ</label>
                            <input type="date" name="date_to" value="<?= $date_to ?>" class="form-control">
                        </div>
                        
                        <div class="col-md-2">
                            <label>عدد السجلات</label>
                            <select name="limit" class="form-control">
                                <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                                <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-block">بحث</button>
                        </div>
                        
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <a href="logs_viewer.php" class="btn btn-secondary btn-block">إعادة تعيين</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- جدول السجلات -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📋 السجلات</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>المستوى</th>
                                    <th>الرسالة</th>
                                    <th>المستخدم</th>
                                    <th>IP</th>
                                    <th>التفاصيل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr class="<?= $log['level'] === 'ERROR' || $log['level'] === 'CRITICAL' ? 'table-danger' : 
                                           ($log['level'] === 'WARNING' ? 'table-warning' : '') ?>">
                                    <td><?= $log['timestamp'] ?></td>
                                    <td>
                                        <span class="badge badge-<?= $log['level'] === 'ERROR' || $log['level'] === 'CRITICAL' ? 'danger' : 
                                                      ($log['level'] === 'WARNING' ? 'warning' : 
                                                      ($log['level'] === 'INFO' ? 'info' : 'secondary')) ?>">
                                            <?= $log['level'] ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($log['message']) ?></td>
                                    <td><?= htmlspecialchars($log['username'] ?? 'Guest') ?></td>
                                    <td><?= htmlspecialchars($log['ip_address'] ?? 'Unknown') ?></td>
                                    <td>
                                        <?php if ($log['context']): ?>
                                            <button class="btn btn-sm btn-info" onclick="showContext(<?= $log['id'] ?>)">
                                                عرض التفاصيل
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- رسوم بيانية -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">📈 السجلات حسب المستوى</h3>
                        </div>
                        <div class="card-body">
                            <canvas id="levelChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">📊 إحصائيات المستخدمين</h3>
                        </div>
                        <div class="card-body">
                            <canvas id="userChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal لعرض التفاصيل -->
<div class="modal fade" id="contextModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل السجل</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <pre id="contextContent"></pre>
            </div>
        </div>
    </div>
</div>

<script>
function showContext(logId) {
    // هنا يمكن إضافة AJAX لجلب تفاصيل السجل
    document.getElementById('contextContent').textContent = 'جاري تحميل التفاصيل...';
    $('#contextModal').modal('show');
}

// الرسوم البيانية
const levelData = <?= json_encode($stats['by_level'] ?? []) ?>;
const ctx1 = document.getElementById('levelChart').getContext('2d');
new Chart(ctx1, {
    type: 'doughnut',
    data: {
        labels: Object.keys(levelData),
        datasets: [{
            data: Object.values(levelData),
            backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6c757d']
        }]
    }
});
</script>

<?php include('includes/footer.php'); ?>
