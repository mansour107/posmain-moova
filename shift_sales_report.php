<?php 
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['userid'];
$today = date('Y-m-d');

// جلب اسم المستخدم
$user_query = $conn->query("SELECT aname FROM acc_head WHERE id = $user_id");
$user_data = $user_query->fetch_assoc();
$cashier_name = $user_data['aname'] ?? 'الكاشير';

// جلب إعدادات الشركة
$settings_query = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_query->fetch_assoc();

// جلب إجمالي مبيعات اليوم للمستخدم المسجل فقط
$sales_query = $conn->query("SELECT 
    COUNT(*) as total_invoices,
    COALESCE(SUM(fat_total), 0) as total_sales,
    COALESCE(SUM(fat_disc), 0) as total_discounts,
    COALESCE(SUM(fat_net), 0) as net_sales,
    MIN(crtime) as first_sale_time,
    MAX(crtime) as last_sale_time
    FROM ot_head 
    WHERE DATE(pro_date) = '$today'
    AND user = '$user_id'
    AND pro_tybe = 9
    AND isdeleted = 0");
$sales_data = $sales_query->fetch_assoc();

// جلب تفاصيل أصناف اليوم للمستخدم المسجل فقط
$items_query = $conn->query("SELECT 
    mi.iname,
    mi.barcode,
    SUM(fd.qty_out - fd.qty_in) as total_qty,
    fd.price,
    SUM(fd.det_value) as total_value,
    COUNT(DISTINCT oh.id) as order_count
    FROM ot_head oh
    JOIN fat_details fd ON oh.id = fd.pro_id
    JOIN myitems mi ON fd.item_id = mi.id
    WHERE DATE(oh.pro_date) = '$today'
    AND oh.user = '$user_id'
    AND oh.pro_tybe = 9
    AND oh.isdeleted = 0
    AND fd.isdeleted = 0
    GROUP BY fd.item_id, fd.price
    ORDER BY total_value DESC");

// جلب تفاصيل الفواتير
$invoices_query = $conn->query("SELECT 
    oh.id,
    oh.crtime,
    oh.fat_net,
    oh.info,
    CASE 
        WHEN oh.info LIKE '%تيك أواي%' THEN 'تيك أواي'
        WHEN oh.info LIKE '%طاولة%' THEN 'طاولة'
        WHEN oh.info LIKE '%دليفري%' THEN 'دليفري'
        ELSE 'غير محدد'
    END as order_type
    FROM ot_head oh
    WHERE DATE(oh.pro_date) = '$today'
    AND oh.user = '$user_id'
    AND oh.pro_tybe = 9
    AND oh.isdeleted = 0
    ORDER BY oh.crtime DESC");

// حساب أوقات الشيفت
$shift_start = $sales_data['first_sale_time'] ? date('H:i', strtotime($sales_data['first_sale_time'])) : date('H:i', strtotime('today'));
$shift_end = $sales_data['last_sale_time'] ? date('H:i', strtotime($sales_data['last_sale_time'])) : date('H:i');

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