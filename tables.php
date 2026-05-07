<?php 
include('includes/header.php');

// معالجة تسجيل الخروج
if (isset($_GET['logout'])) {
    unset($_SESSION['pos_authenticated']);
    unset($_SESSION['pos_user_id']);
    unset($_SESSION['pos_user_name']);
    header('Location: pos_barcode.php');
    exit();
}

// جلب الإعدادات
$rowstg = $conn->query("SELECT * FROM settings WHERE id = 1")->fetch_assoc();

// نظام الحماية البسيط
if (isset($rowstg['pos_has_password']) && $rowstg['pos_has_password'] == 1) {
    if (!isset($_SESSION['pos_authenticated']) || $_SESSION['pos_authenticated'] !== true) {
        header('Location: pos_barcode.php');
        exit();
    }
}
?>

<style>
/* Modern Color Palette */
:root {
    --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
    --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    --surface-color: #ffffff;
    --bg-color: #f3f4f6;
    --text-primary: #1f2937;
    --text-secondary: #6b7280;
}

body {
    background-color: var(--bg-color);
    font-family: 'Tajawal', 'Cairo', sans-serif;
}

/* Page Header */
.page-header {
    background: var(--primary-gradient);
    color: white;
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.4);
    position: relative;
    overflow: hidden;
}

.page-header::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    background: url('data:image/svg+xml,<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><g fill="%23ffffff" fill-opacity="0.05"><circle cx="1" cy="1" r="1"/></g></svg>');
}

/* Table Cards */
.tables-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 1.5rem;
    padding: 1rem 0;
}

.table-btn {
    min-height: 140px;
    border-radius: 24px;
    border: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    text-decoration: none !important;
    overflow: hidden;
    background: white;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.table-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 6px;
    background: #e5e7eb;
    transition: background 0.3s;
}

/* Empty Table State */
.table-btn.bg-success {
    background: white !important;
    color: var(--text-primary) !important;
}
.table-btn.bg-success::before {
    background: #10b981;
}
.table-btn.bg-success .fa-utensils, 
.table-btn.bg-success .fa-check-circle {
    color: #10b981;
    background: #ecfdf5;
    padding: 15px;
    border-radius: 50%;
    margin-bottom: 12px;
}

/* Occupied Table State */
.table-btn.bg-danger {
    background: white !important;
    color: var(--text-primary) !important;
}
.table-btn.bg-danger::before {
    background: #ef4444;
}
.table-btn.bg-danger .fa-clock {
    color: #ef4444;
    background: #fef2f2;
    padding: 15px;
    border-radius: 50%;
    margin-bottom: 12px;
    animation: pulse 2s infinite;
}

.table-btn:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.table-btn.selected {
    ring: 4px solid rgba(99, 102, 241, 0.5);
    transform: scale(1.05);
}

/* Summary Card */
.summary-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    border: none;
    overflow: hidden;
    position: sticky;
    top: 20px;
}

.summary-card .card-header {
    background: white;
    border-bottom: 1px solid #f3f4f6;
    padding: 1.5rem;
}

.summary-card .card-header h5 {
    color: var(--text-primary);
    font-weight: 700;
}

.price-box {
    padding: 1.5rem;
    border-radius: 16px;
    text-align: center;
    transition: transform 0.3s;
}
.price-box:hover {
    transform: scale(1.02);
}
.price-box.total {
    background: #eff6ff;
    color: #1e40af;
}
.price-box.net {
    background: #ecfdf5;
    color: #065f46;
}

/* Action Buttons */
.action-btn {
    border-radius: 16px;
    padding: 1rem;
    font-weight: 600;
    border: none;
    transition: all 0.3s;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.action-btn.btn-warning {
    background: var(--warning-gradient);
    color: white;
}
.action-btn.btn-secondary {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    border: none;
}
.action-btn.btn-success {
    background: var(--success-gradient);
    border: none;
}
.action-btn.btn-danger {
    background: var(--danger-gradient);
    border: none;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    filter: brightness(110%);
}

/* Floating POS Button */
.floating-pos-btn {
    position: fixed;
    bottom: 30px;
    left: 30px;
    width: 64px;
    height: 64px;
    background: var(--primary-gradient);
    color: white;
    border-radius: 20px;
    box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    z-index: 9999;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.floating-pos-btn:hover {
    transform: scale(1.1) rotate(-5deg);
    box-shadow: 0 20px 25px -5px rgba(79, 70, 229, 0.6);
}

@keyframes pulse {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}

/* Scrollbar Styling */
::-webkit-scrollbar {
    width: 8px;
}
::-webkit-scrollbar-track {
    background: #f1f1f1;
}
::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>
<?php
$sql = "CREATE TABLE IF NOT EXISTS tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tname VARCHAR(255) NOT NULL,
    table_case INT NOT NULL DEFAULT 0,
    crtime DATETIME DEFAULT CURRENT_TIMESTAMP,
    mdtime DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    isdeleted TINYINT(1) NOT NULL DEFAULT 0,
    branch VARCHAR(255) DEFAULT NULL,
    tatnet VARCHAR(255) DEFAULT NULL
)";
$conn->query($sql);

// إضافة طاولات تجريبية إذا لم تكن موجودة
$check_tables = $conn->query("SELECT COUNT(*) as count FROM tables WHERE isdeleted = 0");
$tables_count = $check_tables->fetch_assoc()['count'];

if ($tables_count == 0) {
    // إضافة 10 طاولات تجريبية
    for ($i = 1; $i <= 10; $i++) {
        $table_name = "طاولة " . $i;
        $conn->query("INSERT INTO tables (tname, table_case) VALUES ('$table_name', 0)");
    }
}

// جلب الطاولات من قاعدة البيانات
$tables_query = "SELECT * FROM tables WHERE isdeleted = 0 ORDER BY id ASC";
$tables_result = $conn->query($tables_query);

// الطاولة المختارة
$selected_table = isset($_GET['table_id']) ? intval($_GET['table_id']) : null;
$order_data = [];
$order_items = [];
$order_totals = [
    'total' => 0.00,
    'discount' => 0.00,
    'extra' => 0.00,
    'net' => 0.00,
    'paid' => 0.00,
    'remaining' => 0.00
];

// إذا تم اختيار طاولة، جلب بيانات الطلب
$selected_table_name = '';
if ($selected_table) {
    // جلب اسم الطاولة
    $table_name_query = "SELECT tname FROM tables WHERE id = $selected_table";
    $table_name_result = $conn->query($table_name_query);
    if ($table_name_result && $table_name_result->num_rows > 0) {
        $selected_table_name = $table_name_result->fetch_assoc()['tname'];
    }
    
    // جلب الطلب النشط للطاولة (يتم البحث باستخدام حقل info الذي يحتوي على اسم الطاولة)
    // ملاحظة: يمكن تحسين هذا لاحقاً بإضافة عمود table_id لجدول ot_head
    $order_query = "SELECT * FROM ot_head WHERE info LIKE '%$selected_table_name%' AND pro_tybe = 9 ORDER BY id DESC LIMIT 1";
    $order_result = $conn->query($order_query);
    
    if ($order_result && $order_result->num_rows > 0) {
        $order_data = $order_result->fetch_assoc();
        $order_id = $order_data['id'];
        
        // جلب أصناف الطلب من fat_details
        $items_query = "SELECT fd.*, i.iname, i.price1 as sprice,
                       (fd.qty_out - fd.qty_in) as actual_qty
                       FROM fat_details fd 
                       LEFT JOIN myitems i ON fd.item_id = i.id 
                       WHERE fd.pro_id = $order_id AND fd.isdeleted = 0";
        $items_result = $conn->query($items_query);
        
        if ($items_result) {
            while ($item = $items_result->fetch_assoc()) {
                $order_items[] = $item;
            }
        }
        
        // حساب الإجماليات
        $order_totals['total'] = floatval($order_data['fat_total'] ?? 0);
        $order_totals['discount'] = floatval($order_data['fat_disc'] ?? 0);
        $order_totals['extra'] = floatval($order_data['fat_plus'] ?? 0);
        $net = $order_totals['total'] - $order_totals['discount'] + $order_totals['extra'];
        $order_totals['net'] = $net;
        $order_totals['paid'] = 0; // يمكن إضافة حقل للمدفوع لاحقاً
        $order_totals['remaining'] = $net;
    }
}
?>

<div class="container-fluid h-100 p-0 overflow-hidden">
    <div class="row h-100 g-0">
        <!-- Tables Grid (Right Side) -->
        <div class="col-lg-8 h-100 d-flex flex-column p-4" style="background: #f8f9fa;">
            <div class="page-header mb-3 flex-shrink-0 shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0 fw-bold text-white"><i class="fas fa-utensils me-2"></i>إدارة الطاولات</h4>
                        <small class="text-white-50">اختر طاولة لبدء الطلب</small>
                    </div>
                    <div>
                         <!-- Optional: Add filters or extra buttons here -->
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm flex-grow-1 overflow-hidden" style="background: transparent;">
                <div class="card-body p-0 h-100 overflow-auto custom-scrollbar">
                    <div class="tables-grid pb-5">
                        <?php 
                        if ($tables_result && $tables_result->num_rows > 0) {
                            while ($table = $tables_result->fetch_assoc()) {
                                $table_id = $table['id'];
                                $table_name = $table['tname'];
                                $table_case = $table['table_case'];
                                
                                $bg_color = ($table_case == 0) ? 'bg-white border-success text-success' : 'bg-white border-danger text-danger';
                                $icon = ($table_case == 0) ? 'fas fa-check-circle' : 'fas fa-clock';
                                $status = ($table_case == 0) ? 'فارغة' : 'محجوزة';
                                $selected_class = ($selected_table == $table_id) ? 'ring-4 ring-primary' : '';
                                
                                // Simplified Button Style
                                echo '<a href="tables.php?table_id=' . $table_id . '" class="btn table-btn ' . $selected_class . '" style="border: 2px solid ' . ($table_case == 0 ? '#198754' : '#dc3545') . '; color: ' . ($table_case == 0 ? '#198754' : '#dc3545') . '; background: white;">';
                                echo '<div class="text-center">';
                                echo '<i class="' . $icon . ' fa-2x mb-2"></i><br>';
                                echo '<h6 class="fw-bold mb-1">' . htmlspecialchars($table_name) . '</h6>';
                                echo '<small>' . $status . '</small>';
                                echo '</div>';
                                echo '</a>';
                            }
                        } else {
                            echo '<div class="col-12 text-center text-muted p-5">';
                            echo '<i class="fas fa-table fa-4x mb-4 opacity-25"></i><br>';
                            echo '<h5>لا توجد طاولات متاحة</h5>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Summary -->
        <!-- Order Summary (Left Side) -->
        <div class="col-lg-4 h-100 bg-white border-start shadow-sm d-flex flex-column">
            <?php if ($selected_table): ?>
                <!-- Header -->
                <div class="p-3 border-bottom flex-shrink-0">
                    <h5 class="mb-0 fw-bold text-dark d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-receipt me-2 text-primary"></i><?= htmlspecialchars($selected_table_name) ?></span>
                        <span class="badge bg-light text-primary"><?= date('h:i A') ?></span>
                    </h5>
                </div>

                <!-- Scrollable Content -->
                <div class="flex-grow-1 overflow-auto custom-scrollbar p-3">
                    <!-- Stats Cards -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 text-center border">
                                <small class="text-muted d-block mb-1">الإجمالي</small>
                                <h4 class="mb-0 fw-bold text-primary"><?= number_format($order_totals['total'], 2) ?></h4>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 text-center border">
                                <small class="text-muted d-block mb-1">الصافي</small>
                                <h4 class="mb-0 fw-bold text-success"><?= number_format($order_totals['net'], 2) ?></h4>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($order_data)): ?>
                        <!-- Actions Grid -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <a href="pos_barcode.php?edit=<?= $order_data['id'] ?>" class="btn btn-warning w-100 py-2 h-100 d-flex flex-column justify-content-center align-items-center">
                                    <i class="fas fa-edit mb-1"></i><small class="fw-bold">تعديل</small>
                                </a>
                            </div>
                            <div class="col-6">
                                <button class="btn btn-secondary w-100 py-2 h-100 d-flex flex-column justify-content-center align-items-center" onclick="printInvoice(<?= $selected_table ?>)">
                                    <i class="fas fa-print mb-1"></i><small class="fw-bold">طباعة</small>
                                </button>
                            </div>
                            <div class="col-6">
                                <button class="btn btn-success w-100 py-2 h-100 d-flex flex-column justify-content-center align-items-center" onclick="openSplitPaymentModal(<?= $selected_table ?>, <?= $order_data['id'] ?>)">
                                    <i class="fas fa-money-bill-wave mb-1"></i><small class="fw-bold">سداد</small>
                                </button>
                            </div>
                            <div class="col-6">
                                <div class="btn-group w-100 h-100 dropdown">
                                    <button type="button" class="btn btn-danger w-100 py-2 dropdown-toggle h-100 d-flex flex-column justify-content-center align-items-center" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-trash-alt mb-1"></i><small class="fw-bold">إفراغ</small>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 p-2" style="z-index: 1060; min-width: 200px;">
                                        <li><a class="dropdown-item text-danger rounded-2 js-clear-table-direct py-2 mb-1" href="#" data-table-id="<?= $selected_table ?>">
                                            <i class="fas fa-times me-2"></i>إلغاء الطلب (حذف)
                                        </a></li>
                                        <li><a class="dropdown-item text-warning rounded-2 js-clear-table-normal py-2" href="#" data-table-id="<?= $selected_table ?>">
                                            <i class="fas fa-save me-2"></i>حفظ كأجل (تفريغ)
                                        </a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($order_items)): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2 mt-4">
                            <h6 class="fw-bold text-muted mb-0"><i class="fas fa-list me-2"></i>الأصناف</h6>
                            <span class="badge bg-light text-dark"><?= count($order_items) ?> صنف</span>
                        </div>
                        <div class="list-group list-group-flush border rounded-3">
                            <?php foreach ($order_items as $item): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                    <div>
                                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($item['iname'] ?? 'غير محدد') ?></h6>
                                        <small class="text-muted">الكمية: <?= number_format($item['actual_qty'] ?? 0, 2) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold text-primary"><?= number_format(($item['actual_qty'] ?? 0) * ($item['price'] ?? 0), 2) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            
            <?php else: ?>
                <div class="h-100 d-flex flex-column justify-content-center align-items-center text-center p-4">
                    <div class="mb-4 text-muted opacity-25">
                        <i class="fas fa-hand-pointer fa-6x"></i>
                    </div>
                    <h4 class="text-muted fw-bold">لم يتم اختيار طاولة</h4>
                    <p class="text-muted">اختر طاولة من القائمة لعرض التفاصيل</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- زر عائم للذهاب للـ POS -->
<a href="pos_barcode.php" class="floating-pos-btn" title="POS الكاشير">
    <i class="fas fa-cash-register"></i>
</a>

<!-- Scripts are located at the bottom of the file -->

<!-- مودال الدفع المتقدم -->
<div class="modal fade" id="posPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-cash-register me-2"></i>الدفع والإجماليات
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="currentTableId">
                <input type="hidden" id="currentOrderId">
                <div class="row g-3">
                    <!-- الإجمالي -->
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-4">
                                        <label class="mb-0 fw-bold text-primary">
                                            <i class="fas fa-coins me-2"></i>الإجمالي
                                        </label>
                                    </div>
                                    <div class="col-8">
                                        <h4 class="mb-0 text-primary text-end" id="modal_total">0.00 ج.م</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- الخصم -->
                    <div class="col-12">
                        <div class="card border-primary">
                            <div class="card-header bg-primary bg-opacity-10">
                                <h6 class="mb-0 text-primary">
                                    <i class="fas fa-percentage me-2"></i>الخصم
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label fw-bold">الخصم %</label>
                                        <div class="input-group">
                                            <input class="form-control text-center" 
                                                   type="number" id="modal_discperc" value="0" min="0" max="100" step="0.1">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-bold">قيمة الخصم</label>
                                        <div class="input-group">
                                            <input class="form-control text-center" 
                                                   type="number" id="modal_discount" value="0" step="0.01">
                                            <span class="input-group-text bg-primary text-white">ج.م</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- الصافي -->
                    <div class="col-12">
                        <div class="card bg-success bg-opacity-10 border-success">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-4">
                                        <label class="mb-0 fw-bold text-success">
                                            <i class="fas fa-check-circle me-2"></i>الصافي
                                        </label>
                                    </div>
                                    <div class="col-8">
                                        <h3 class="mb-0 text-success text-end" id="modal_net">0.00 ج.م</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- المدفوع والباقي -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold">
                            <i class="fas fa-money-bill-wave me-2"></i>المدفوع
                        </label>
                        <div class="input-group input-group-lg">
                            <input class="form-control text-center fw-bold" 
                                   type="number" id="modal_paid" value="0.00" step="0.01">
                            <span class="input-group-text">ج.م</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">
                            <i class="fas fa-arrow-left me-2"></i>الباقي
                        </label>
                        <div class="input-group input-group-lg">
                            <input class="form-control text-center fw-bold bg-danger text-white" 
                                   type="text" id="modal_change" value="0.00" readonly>
                            <span class="input-group-text bg-danger text-white">ج.م</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="closeModal()">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-success" onclick="processAdvancedPayment()" id="paymentConfirmBtn">
                    <i class="fas fa-print me-1"></i>سداد وطباعة
                </button>
            </div>
        </div>
    </div>
</div>



<!-- Split Payment Modal -->
<div class="modal fade" id="splitPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-double me-2"></i>سداد أصناف محددة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="splitItemsList" class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllItems"></th>
                                <th>الصنف</th>
                                <th>الكمية</th>
                                <th>السعر</th>
                                <th>الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody id="splitItemsBody"></tbody>
                    </table>
                </div>
                <div class="row mt-3 border-top pt-3">
                    <div class="col-6">
                        <h5>الإجمالي المحدد: <span id="splitTotal" class="text-success">0.00</span> ج.م</h5>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-success" onclick="confirmSplitPayment()">
                    <i class="fas fa-money-bill-wave me-1"></i> سداد وطباعة
                </button>
            </div>
        </div>
    </div>
</div>

<?php include('includes/footer.php') ?>

<!-- Scripts are located after footer to ensure jQuery is loaded -->
<script>
// حساب الخصم والصافي
$(document).ready(function() {
    $(document).on('input', '#modal_discperc, #modal_discount', function() {
        const total = parseFloat($('#modal_total').text().replace(' ج.م', '')) || 0;
        let discount = 0;
        
        if ($(this).attr('id') === 'modal_discperc') {
            const discPerc = parseFloat($(this).val()) || 0;
            discount = (total * discPerc) / 100;
            $('#modal_discount').val(discount.toFixed(2));
        } else {
            discount = parseFloat($(this).val()) || 0;
            const discPerc = total > 0 ? (discount / total) * 100 : 0;
            $('#modal_discperc').val(discPerc.toFixed(1));
        }
        
        const net = total - discount;
        $('#modal_net').text(net.toFixed(2) + ' ج.م');
        calculateChange();
    });

    // حساب الباقي
    $(document).on('input', '#modal_paid', calculateChange);

    // Event Handlers for Clear Table
    $(document).on('click', '.js-clear-table-direct', function(e) {
        e.preventDefault();
        const tableId = $(this).data('table-id');
        clearTableDirect(tableId);
    });

    $(document).on('click', '.js-clear-table-normal', function(e) {
        e.preventDefault();
        const tableId = $(this).data('table-id');
        clearTableNormal(tableId);
    });
});

function calculateChange() {
    const net = parseFloat($('#modal_net').text().replace(' ج.م', '')) || 0;
    const paid = parseFloat($('#modal_paid').val()) || 0;
    const change = paid - net;
    $('#modal_change').val(change.toFixed(2));
    
    // تغيير لون الباقي
    const changeInput = $('#modal_change');
    const changeSpan = changeInput.next('.input-group-text');
    
    if (change >= 0) {
        changeInput.removeClass('bg-danger text-white').addClass('bg-success text-white');
        changeSpan.removeClass('bg-danger text-white').addClass('bg-success text-white');
    } else {
        changeInput.removeClass('bg-success text-white').addClass('bg-danger text-white');
        changeSpan.removeClass('bg-success text-white').addClass('bg-danger text-white');
    }
}

function processAdvancedPayment() {
    console.log('تم استدعاء processAdvancedPayment');
    
    const tableId = $('#currentTableId').val();
    const total = parseFloat($('#modal_total').text().replace(' ج.م', '')) || 0;
    const discount = parseFloat($('#modal_discount').val()) || 0;
    const net = parseFloat($('#modal_net').text().replace(' ج.م', '')) || 0;
    const paid = parseFloat($('#modal_paid').val()) || 0;
    
    console.log('بيانات الدفع:', { tableId, total, discount, net, paid });
    
    if (!tableId) {
        alert('يرجى اختيار طاولة');
        return;
    }
    
    if (paid <= 0) {
        alert('يرجى إدخال مبلغ صحيح');
        return;
    }
    
    $.ajax({
        url: 'ajax/process_table_payment.php',
        method: 'POST',
        data: { 
            table_id: tableId,
            total: total,
            discount: discount,
            net: net,
            paid: paid
        },
        dataType: 'json',
        success: function(data) {
            console.log('استجابة الخادم:', data);
            if (data.success) {
                closeModal();
                const orderId = $('#currentOrderId').val();
                // التحويل مباشرة إلى صفحة الفاتورة
                window.location.href = 'print/receipt.php?id=' + orderId;
            } else {
                alert('حدث خطأ: ' + (data.message || 'خطأ غير محدد'));
            }
        },
        error: function(xhr, status, error) {
            console.error('Ajax error:', {
                status: status,
                error: error,
                responseText: xhr.responseText
            });
            alert('حدث خطأ في الاتصال: ' + error);
        }
    });
}

function processTablePayment(tableId) {
    // جلب بيانات الطاولة والمبلغ المطلوب
    $.ajax({
        url: 'ajax/get_table_amount.php',
        method: 'POST',
        data: { table_id: tableId },
        dataType: 'json',
        success: function(data) {
            console.log('بيانات الطاولة:', data);
            if (data.success) {
                $('#currentTableId').val(tableId);
                $('#currentOrderId').val(data.order_id); // حفظ معرف الطلب
                $('#modal_total').text(data.total.toFixed(2) + ' ج.م');
                $('#modal_discount').val(data.discount || 0);
                const net = data.total - (data.discount || 0);
                $('#modal_net').text(net.toFixed(2) + ' ج.م');
                $('#modal_paid').val(net.toFixed(2));
                $('#modal_discperc').val('0.0');
                
                // حساب الباقي
                calculateChange();
                
                // فتح المودال
                $('#posPaymentModal').modal('show');
            } else {
                alert('خطأ في جلب بيانات الطاولة: ' + (data.message || 'خطأ غير معروف'));
            }
        },
        error: function() {
            alert('خطأ في الاتصال بالخادم');
        }
    });
}

function clearTableNormal(tableId) {
    if (!tableId) { alert('خطأ: رقم الطاولة غير موجود'); return; }
    if(confirm('هل تريد تفريغ الطاولة تفريغ عادي؟\nسيتم حفظ الطلب في النظام وتفريغ الطاولة')) {
        $.ajax({
            url: 'ajax/clear_table_normal.php',
            method: 'POST',
            data: { table_id: tableId, table_name: 'Table ' + tableId },
            success: function(data) {
                try {
                    let response = (typeof data === 'string') ? JSON.parse(data) : data;
                    if (response.success) {
                        alert('تم تفريغ الطاولة بنجاح\nإجمالي المبيعات: ' + response.total + ' ج.م');
                        location.reload();
                    } else {
                        alert('خطأ: ' + (response.message || 'خطأ غير محدد'));
                    }
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    alert('خطأ في معالجة البيانات من الخادم');
                }
            },
            error: function(xhr, status, error) {
                console.error(xhr.responseText);
                alert('حدث خطأ في الاتصال بالخادم: ' + error);
            }
        });
    }
}

function clearTableDirect(tableId) {
    if (!tableId) { alert('خطأ: رقم الطاولة غير موجود'); return; }
    if(confirm('هل تريد تفريغ الطاولة مباشرة بدون سداد؟')) {
        $.ajax({
            url: 'ajax/update_table_status.php',
            method: 'POST',
            data: { table_id: tableId, action: 'clear' },
            success: function(data) {
                try {
                    let response = (typeof data === 'string') ? JSON.parse(data) : data;
                    if (response.success) {
                        alert('تم تفريغ الطاولة بنجاح');
                        location.reload();
                    } else {
                        alert('خطأ: ' + (response.message || 'فشل العملية'));
                    }
                } catch(e) {
                    console.error('JSON Parse Error:', e);
                    alert('خطأ في معالجة البيانات');
                }
            },
            error: function(xhr, status, error) {
                console.error(xhr.responseText);
                alert('حدث خطأ: ' + error);
            }
        });
    }
}

function printPreparation(tableId) {
    window.open('print/preparation.php?table_id=' + tableId, '_blank');
}

function printInvoice(tableId) {
    // جلب معرف الطلب أولاً
    $.ajax({
        url: 'ajax/get_table_amount.php',
        method: 'POST',
        data: { table_id: tableId },
        dataType: 'json',
        success: function(data) {
            if (data.success && data.order_id) {
                window.open('print/receipt.php?id=' + data.order_id, '_blank');
            } else {
                alert('لا يوجد طلب نشط لهذه الطاولة');
            }
        },
        error: function() {
            alert('خطأ في الاتصال بالخادم');
        }
    });
}

function closeModal() {
    $('#posPaymentModal').modal('hide');
}

let currentSplitTableId = 0;
let currentSplitOrderId = 0;

function openSplitPaymentModal(tableId, orderId) {
    currentSplitTableId = tableId;
    currentSplitOrderId = orderId;
    
    // Load items
    $.get('ajax/get_table_items.php', { order_id: orderId }, function(data) {
        let response = (typeof data === 'string') ? JSON.parse(data) : data;
        if (response.success) {
            let html = '';
            response.items.forEach(item => {
                html += `
                    <tr>
                        <td>
                            <input type="checkbox" class="split-item-check" 
                                   value="${item.id}" 
                                   data-amount="${item.total}"
                                   onchange="updateSplitTotal()">
                        </td>
                        <td>${item.name}</td>
                        <td>${item.qty}</td>
                        <td>${item.price.toFixed(2)}</td>
                        <td>${item.total.toFixed(2)}</td>
                    </tr>
                `;
            });
            $('#splitItemsBody').html(html);
            $('#splitTotal').text('0.00');
            $('#splitPaymentModal').modal('show');
        } else {
            alert('خطأ في تحميل الأصناف');
        }
    });
}

$('#selectAllItems').change(function() {
    $('.split-item-check').prop('checked', $(this).prop('checked'));
    updateSplitTotal();
});

function updateSplitTotal() {
    let total = 0;
    $('.split-item-check:checked').each(function() {
        total += parseFloat($(this).data('amount'));
    });
    $('#splitTotal').text(total.toFixed(2));
}

function confirmSplitPayment() {
    let selectedItems = [];
    $('.split-item-check:checked').each(function() {
        selectedItems.push($(this).val());
    });
    
    if (selectedItems.length === 0) {
        alert('يرجى اختيار صنف واحد على الأقل');
        return;
    }
    
    let amount = parseFloat($('#splitTotal').text());
    
    if (confirm('هل أنت متأكد من سداد الأصناف المختارة بقيمة ' + amount + ' ج.م؟')) {
        $.ajax({
            url: 'ajax/process_split_payment.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                order_id: currentSplitOrderId,
                table_id: currentSplitTableId,
                items: selectedItems,
                paid_amount: amount
            }),
            success: function(data) {
                let response = (typeof data === 'string') ? JSON.parse(data) : data;
                if (response.success) {
                    $('#splitPaymentModal').modal('hide');
                    alert('تم السداد بنجاح');
                    if (response.new_invoice_id) {
                         window.open('print/receipt.php?id=' + response.new_invoice_id, '_blank');
                    }
                    location.reload();
                } else {
                    alert('خطأ: ' + response.message);
                }
            },
            error: function() {
                alert('حدث خطأ في الاتصال');
            }
        });
    }
}
function activateTable(tableId) {
    $.ajax({
        url: 'ajax/update_table_status.php',
        method: 'POST',
        data: { table_id: tableId, action: 'activate' },
        success: function(data) {
            let response = (typeof data === 'string') ? JSON.parse(data) : data;
            if (response.success) {
                alert('تم تشغيل الطاولة');
                location.reload();
            } else {
                alert('خطأ: ' + (response.message || 'فشل العملية'));
            }
        },
        error: function(xhr, status, error) {
            console.error(xhr.responseText);
            alert('حدث خطأ: ' + error);
        }
    });
}
</script>


<?php if(isset($rowstg['pos_has_password']) && $rowstg['pos_has_password'] == 1): ?>
<!-- نظام القفل البسيط -->
<script>
    // القفل عند تبديل التاب
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && sessionStorage.getItem('pos_hidden')) {
            window.location.href = 'pos_barcode.php?logout=1';
        }
        if (document.hidden) {
            sessionStorage.setItem('pos_hidden', '1');
        }
    });
    
    // القفل عند الضغط على أي رابط غير tables.php و pos_barcode.php
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href && 
            !link.href.includes('tables.php') && 
            !link.href.includes('pos_barcode.php') && 
            link.target !== '_blank') {
            // قفل الجلسة قبل المغادرة
            sessionStorage.setItem('pos_locked', '1');
        }
    });
    
    // فحص عند تحميل الصفحة: لو راجع من صفحة تانية، اقفل
    if (sessionStorage.getItem('pos_locked') === '1') {
        sessionStorage.removeItem('pos_locked');
        window.location.href = 'pos_barcode.php?logout=1';
    }
</script>
<?php endif; ?>
