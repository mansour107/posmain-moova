<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
page_guard('reports.view', $conn);
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/classes/ShiftReport.php';

if (!isset($_SESSION['userid'])) {
    header('Location: index.php');
    exit;
}

$user_id = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];
$shiftSessions = new ShiftSessionService();
$reportContext = $shiftSessions->buildShiftReportContext($conn, (int) $user_id);
$report = new ShiftReport($conn, (int) $user_id, $reportContext['business_day'], $reportContext['scope']);

$totals = $report->getTotals();
$timeBounds = $report->getSaleTimeBounds();
$items_query = $report->getItemsBreakdown();
$cashier_name = $report->getCashierUsername() ?: 'الكاشير';

$settings_query = $conn->query('SELECT * FROM settings WHERE id = 1');
$settings = $settings_query ? $settings_query->fetch_assoc() : [];

$sales_data = [
    'total_invoices' => (int) ($totals['total_orders'] ?? 0),
    'total_sales' => (float) ($totals['total_gross'] ?? 0),
    'total_discounts' => (float) ($totals['total_discount'] ?? 0),
    'total_refunds' => (float) ($totals['total_refunds'] ?? 0),
    'net_sales' => (float) ($totals['total_net'] ?? 0),
    'first_sale_time' => $timeBounds['first_sale_time'],
    'last_sale_time' => $timeBounds['last_sale_time'],
];

$shiftOpenedAt = $report->getShiftOpenedAt();
$shift_start = $shiftOpenedAt
    ? date('H:i', strtotime($shiftOpenedAt))
    : ($sales_data['first_sale_time'] ? date('H:i', strtotime((string) $sales_data['first_sale_time'])) : date('H:i'));
$shift_end = $sales_data['last_sale_time']
    ? date('H:i', strtotime((string) $sales_data['last_sale_time']))
    : date('H:i');

$invoices_query = $report->getInvoices();

include('includes/header.php');
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>
                            تقرير مبيعات الشيفت - <?= $cashier_name ?>
                        </h4>
                        <div>
                            <button class="btn btn-light btn-sm me-2" onclick="window.print()">
                                <i class="fas fa-print me-1"></i>طباعة
                            </button>
                            <a href="pos_barcode.php" class="btn btn-outline-light btn-sm">
                                <i class="fas fa-arrow-left me-1"></i>عودة
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    
                    <!-- معلومات الشيفت -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>معلومات الشيفت</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <td><strong>التاريخ:</strong></td>
                                            <td><?= date('Y-m-d') ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>الكاشير:</strong></td>
                                            <td><?= $cashier_name ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>رقم الشيفت:</strong></td>
                                            <td><?= date('Ymd') . '_' . $user_id ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>بداية الشيفت:</strong></td>
                                            <td><?= $shift_start ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>نهاية الشيفت:</strong></td>
                                            <td><?= $shift_end ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-success">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>ملخص المبيعات</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <td><strong>عدد الفواتير:</strong></td>
                                            <td class="text-end"><?= $sales_data['total_invoices'] ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>الإجمالي:</strong></td>
                                            <td class="text-end"><?= number_format($sales_data['total_sales'], 2) ?> ج.م</td>
                                        </tr>
                                        <?php if ($sales_data['total_discounts'] > 0): ?>
                                        <tr>
                                            <td><strong>الخصم:</strong></td>
                                            <td class="text-end text-danger"><?= number_format($sales_data['total_discounts'], 2) ?> ج.م</td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($sales_data['total_refunds'] > 0): ?>
                                        <tr>
                                            <td><strong>المرتجعات:</strong></td>
                                            <td class="text-end text-danger">- <?= number_format($sales_data['total_refunds'], 2) ?> ج.م</td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr class="table-success">
                                            <td><strong>الصافي:</strong></td>
                                            <td class="text-end"><strong><?= number_format($sales_data['net_sales'], 2) ?> ج.م</strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- تفاصيل الأصناف -->
                    <?php if ($items_query->num_rows > 0): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="fas fa-boxes me-2"></i>تفاصيل الأصناف المباعة</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>#</th>
                                                    <th>اسم الصنف</th>
                                                    <th>الباركود</th>
                                                    <th>الكمية المباعة</th>
                                                    <th>السعر</th>
                                                    <th>إجمالي القيمة</th>
                                                    <th>عدد الطلبات</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $counter = 1;
                                                while ($item = $items_query->fetch_assoc()): 
                                                ?>
                                                <tr>
                                                    <td><?= $counter++ ?></td>
                                                    <td><?= $item['iname'] ?></td>
                                                    <td><code><?= $item['barcode'] ?: 'غير محدد' ?></code></td>
                                                    <td class="text-center"><?= number_format($item['total_qty'], 1) ?></td>
                                                    <td class="text-center"><?= number_format($item['price'], 2) ?> ج.م</td>
                                                    <td class="text-center"><strong><?= number_format($item['total_value'], 2) ?> ج.م</strong></td>
                                                    <td class="text-center"><?= $item['order_count'] ?></td>
                                                </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- تفاصيل الفواتير -->
                    <?php if ($invoices_query->num_rows > 0): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="mb-0"><i class="fas fa-receipt me-2"></i>تفاصيل الفواتير</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>رقم الفاتورة</th>
                                                    <th>الوقت</th>
                                                    <th>نوع الطلب</th>
                                                    <th>المبلغ</th>
                                                    <th>ملاحظات</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($invoice = $invoices_query->fetch_assoc()): ?>
                                                <tr>
                                                    <td><strong>#<?= $invoice['id'] ?></strong></td>
                                                    <td><?= date('H:i:s', strtotime($invoice['crtime'])) ?></td>
                                                    <td>
                                                        <span class="badge bg-primary"><?= $invoice['order_type'] ?></span>
                                                    </td>
                                                    <td class="text-end"><strong><?= number_format($invoice['fat_net'], 2) ?> ج.م</strong></td>
                                                    <td><?= $invoice['info'] ?: '-' ?></td>
                                                </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                        <h5>لا توجد مبيعات لك اليوم</h5>
                        <p>لم تقم بأي عمليات بيع في هذا الشيفت</p>
                    </div>
                    <?php endif; ?>

                </div>
                <div class="card-footer text-center text-muted">
                    <small>تم إنشاء التقرير في: <?= date('Y-m-d H:i:s') ?></small>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print, .card-header .btn, .navbar, .sidebar { 
        display: none !important; 
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .container-fluid {
        padding: 0 !important;
    }
}
</style>

<?php include('includes/footer.php'); ?>
