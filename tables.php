<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:index.php');
    exit;
}

include('includes/connect.php');
require_once __DIR__ . '/classes/Financial/Money.php';
require_once __DIR__ . '/classes/Pos/Service/LegacyOrderLinePresentationService.php';

if (
    !isset($_SESSION['pos_authenticated']) ||
    $_SESSION['pos_authenticated'] !== true ||
    (int) ($_SESSION['pos_user_id'] ?? 0) !== (int) $_SESSION['userid']
) {
    header('location:pos_barcode.php');
    exit;
}

include('includes/header.php');

// جلب الإعدادات
$rowstg = $conn->query("SELECT * FROM settings WHERE id = 1")->fetch_assoc();
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
    font-family: 'Inter', 'IBM Plex Sans Arabic', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
$move_table_options = [];
$merge_table_options = [];

// إذا تم اختيار طاولة، جلب بيانات الطلب
$selected_table_name = '';
if ($selected_table) {
    // جلب اسم الطاولة
    $table_name_query = "SELECT tname FROM tables WHERE id = $selected_table";
    $table_name_result = $conn->query($table_name_query);
    if ($table_name_result && $table_name_result->num_rows > 0) {
        $selected_table_name = $table_name_result->fetch_assoc()['tname'];
    }
    
    // جلب الطلب النشط للطاولة من العلاقة الفعلية table_id.
    $order_query = "SELECT * FROM ot_head
                    WHERE table_id = $selected_table
                      AND pro_tybe = 9
                      AND isdeleted = 0
                      AND COALESCE(order_status, 'active') = 'active'
                      AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
                    ORDER BY id DESC
                    LIMIT 1";
    $order_result = $conn->query($order_query);
    
    if ($order_result && $order_result->num_rows > 0) {
        $order_data = $order_result->fetch_assoc();
        $order_id = $order_data['id'];
        
        // جلب أصناف الطلب من fat_details
        $items_query = "SELECT fd.*, i.iname, i.price1 as sprice,
                       (fd.qty_out - fd.qty_in) as actual_qty
                       FROM fat_details fd 
                       LEFT JOIN myitems i ON fd.item_id = i.id 
                       WHERE fd.fatid = $order_id AND fd.isdeleted = 0";
        $items_result = $conn->query($items_query);
        
        if ($items_result) {
            $tableLinePresentation = new LegacyOrderLinePresentationService();
            while ($item = $items_result->fetch_assoc()) {
                $presentedLine = $tableLinePresentation->presentSaleLine($item);
                $item['presented_qty'] = $tableLinePresentation->inputValue($presentedLine['qty']);
                $item['presented_total'] = Money::from($item['det_value'] ?? '0')->toString();
                $order_items[] = $item;
            }
        }
        
        // حساب الإجماليات
        $order_totals['total'] = Money::from($order_data['fat_total'] ?? '0')->toString();
        $order_totals['discount'] = Money::from($order_data['fat_disc'] ?? '0')->toString();
        $order_totals['extra'] = Money::from($order_data['fat_plus'] ?? '0')->toString();
        $netMoney = Money::from($order_totals['total'])
            ->subtract(Money::from($order_totals['discount']))
            ->add(Money::from($order_totals['extra']));
        if ($netMoney->isNegative()) {
            $netMoney = Money::zero();
        }
        $order_totals['net'] = $netMoney->toString();
        $order_totals['paid'] = Money::from($order_data['paid_amount'] ?? '0')->toString();
        if (array_key_exists('remaining_amount', $order_data) && $order_data['remaining_amount'] !== null) {
            $remainingMoney = Money::from($order_data['remaining_amount']);
        } else {
            $remainingMoney = $netMoney->subtract(Money::from($order_totals['paid']));
            if ($remainingMoney->isNegative()) {
                $remainingMoney = Money::zero();
            }
        }
        $order_totals['remaining'] = $remainingMoney->toString();

        $move_stmt = $conn->prepare("
            SELECT t.id, t.tname
            FROM tables t
            WHERE t.isdeleted = 0
              AND t.id <> ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM ot_head oh
                  WHERE oh.table_id = t.id
                    AND oh.pro_tybe = 9
                    AND oh.isdeleted = 0
                    AND COALESCE(oh.order_status, 'active') = 'active'
                    AND COALESCE(oh.payment_status, 'unpaid') IN ('unpaid', 'partial')
              )
            ORDER BY t.id ASC
        ");
        $move_stmt->bind_param('i', $selected_table);
        $move_stmt->execute();
        $move_result = $move_stmt->get_result();
        while ($move_table = $move_result->fetch_assoc()) {
            $move_table_options[] = $move_table;
        }
        $move_stmt->close();

        $merge_stmt = $conn->prepare("
            SELECT t.id, t.tname, oh.id AS order_id, oh.mutation_version
            FROM tables t
            INNER JOIN ot_head oh ON oh.table_id = t.id
                AND oh.pro_tybe = 9
                AND oh.isdeleted = 0
                AND COALESCE(oh.order_status, 'active') = 'active'
                AND COALESCE(oh.payment_status, 'unpaid') IN ('unpaid', 'partial')
            WHERE t.isdeleted = 0
              AND t.id <> ?
            ORDER BY t.id ASC, oh.id DESC
        ");
        $merge_stmt->bind_param('i', $selected_table);
        $merge_stmt->execute();
        $merge_result = $merge_stmt->get_result();
        while ($merge_table = $merge_result->fetch_assoc()) {
            $merge_table_options[] = $merge_table;
        }
        $merge_stmt->close();
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
                                <h4 class="mb-0 fw-bold text-primary"><?= htmlspecialchars($order_totals['total']) ?></h4>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 text-center border">
                                <small class="text-muted d-block mb-1">الصافي</small>
                                <h4 class="mb-0 fw-bold text-success"><?= htmlspecialchars($order_totals['net']) ?></h4>
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
                            <div class="col-12">
                                <div class="input-group">
                                    <select class="form-select" id="move_destination_table" aria-label="نقل الطلب إلى طاولة">
                                        <option value="">نقل الطلب إلى...</option>
                                        <?php foreach ($move_table_options as $move_table): ?>
                                            <option value="<?= (int) $move_table['id'] ?>"><?= htmlspecialchars($move_table['tname']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline-primary" type="button" onclick="moveTableOrder(<?= (int) $selected_table ?>, <?= (int) $order_data['id'] ?>)">
                                        <i class="fas fa-exchange-alt me-1"></i>نقل
                                    </button>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="input-group">
                                    <select class="form-select" id="merge_destination_table" aria-label="دمج الطلب مع طاولة">
                                        <option value="">دمج مع طاولة مشغولة...</option>
                                        <?php foreach ($merge_table_options as $merge_table): ?>
                                            <option value="<?= (int) $merge_table['id'] ?>" data-order-id="<?= (int) $merge_table['order_id'] ?>" data-mutation-version="<?= max(1, (int) ($merge_table['mutation_version'] ?? 1)) ?>">
                                                <?= htmlspecialchars($merge_table['tname']) ?> - طلب #<?= (int) $merge_table['order_id'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline-success" type="button" onclick="mergeTableOrders(<?= (int) $selected_table ?>, <?= (int) $order_data['id'] ?>)">
                                        <i class="fas fa-compress-arrows-alt me-1"></i>دمج
                                    </button>
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
                                        <small class="text-muted">الكمية: <?= htmlspecialchars((string) ($item['presented_qty'] ?? '0')) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold text-primary"><?= htmlspecialchars((string) ($item['presented_total'] ?? '0.00')) ?></span>
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
                <input type="hidden" id="currentOrderId" value="<?= !empty($order_data['id']) ? (int) $order_data['id'] : '' ?>">
                <input type="hidden" id="currentOrderMutationVersion" value="<?= !empty($order_data) ? max(1, (int) ($order_data['mutation_version'] ?? 1)) : '' ?>">
                <input type="hidden" id="currentOrderRemainingAmount" value="0.00">
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
                                                   type="number" id="modal_discperc" value="0" min="0" max="100" step="0.000001" readonly>
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-bold">قيمة الخصم</label>
                                        <div class="input-group">
                                            <input class="form-control text-center" 
                                                   type="number" id="modal_discount" value="0" min="0" step="0.01" readonly>
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
                    <div class="col-12">
                        <div class="small text-muted">
                            الإجمالي: <span id="splitGross">0.00</span> ج.م
                            · الخصم الموزع: <span id="splitDiscount">0.00</span> ج.م
                        </div>
                        <h5>المطلوب سداده: <span id="splitTotal" class="text-success">0.00</span> ج.م</h5>
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
<?= csrf_meta_tag('pos_browser', 'posmain-csrf-token') ?>
<script src="js/pos_order_api.js?v=<?= (int) (@filemtime(__DIR__ . '/js/pos_order_api.js') ?: 1) ?>"></script>
<script>
const posTableCsrfTokenElement = document.querySelector('meta[name="posmain-csrf-token"]');
window.POSMAIN_CSRF_TOKEN = posTableCsrfTokenElement ? posTableCsrfTokenElement.getAttribute('content') : '';
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

const posTablePageRequestKeys = {};
const posTablePageMutationActive = {};

function posTablePageMoneyApi() {
    if (!window.POSOrderApi || typeof window.POSOrderApi.decimalString !== 'function') {
        throw new Error('POS_MONEY_KERNEL_UNAVAILABLE');
    }

    return window.POSOrderApi;
}

function posTablePageMoney(value) {
    return posTablePageMoneyApi().decimalString(value === null || value === undefined || value === '' ? '0' : value, 2, '0');
}

function posTablePagePercentage(value) {
    return posTablePageMoneyApi().decimalString(value === null || value === undefined || value === '' ? '0' : value, 6, '0');
}

function posTablePageEscapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function posTablePageIsPositive(value) {
    return posTablePageMoneyApi().compareDecimalStrings(posTablePageMoney(value), '0.00', 2) > 0;
}

function createPOSTablePageIdempotencyKey(scope) {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return scope + ':' + window.crypto.randomUUID();
    }

    return scope + ':' + Date.now() + ':' + Math.random().toString(16).slice(2);
}

function getPOSTablePageIdempotencyKey(scope) {
    if (!posTablePageRequestKeys[scope]) {
        posTablePageRequestKeys[scope] = createPOSTablePageIdempotencyKey(scope);
    }

    return posTablePageRequestKeys[scope];
}

function clearPOSTablePageIdempotencyKey(scope) {
    delete posTablePageRequestKeys[scope];
}

$(document).ready(function() {
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
    const money = posTablePageMoneyApi();
    const due = posTablePageMoney($('#currentOrderRemainingAmount').val());
    const paid = posTablePageMoney($('#modal_paid').val());
    const sufficient = money.compareDecimalStrings(paid, due, 2) >= 0;
    const change = sufficient
        ? money.subtractDecimalStrings(paid, due, 2)
        : '-' + money.subtractDecimalStrings(due, paid, 2);
    $('#modal_change').val(change);
    
    // تغيير لون الباقي
    const changeInput = $('#modal_change');
    const changeSpan = changeInput.next('.input-group-text');
    
    if (sufficient) {
        changeInput.removeClass('bg-danger text-white').addClass('bg-success text-white');
        changeSpan.removeClass('bg-danger text-white').addClass('bg-success text-white');
    } else {
        changeInput.removeClass('bg-success text-white').addClass('bg-danger text-white');
        changeSpan.removeClass('bg-success text-white').addClass('bg-danger text-white');
    }
}

function processAdvancedPayment() {
    const requestScope = 'pos.payment.table';
    if (posTablePageMutationActive[requestScope]) {
        return;
    }
    
    const tableId = $('#currentTableId').val();
    const orderId = $('#currentOrderId').val();
    const mutationVersion = parseInt($('#currentOrderMutationVersion').val() || '0', 10) || 0;
    const total = posTablePageMoney($('#modal_total').attr('data-money') || '0');
    const discount = posTablePageMoney($('#modal_discount').val());
    const net = posTablePageMoney($('#modal_net').attr('data-money') || '0');
    const paid = posTablePageMoney($('#modal_paid').val());
    
    if (!tableId) {
        alert('يرجى اختيار طاولة');
        return;
    }
    
    if (!orderId || mutationVersion < 1) {
        alert('تعذر تحديد نسخة الطلب الحالية. أعد تحميل الطاولة وحاول مرة أخرى.');
        return;
    }

    if (!posTablePageIsPositive(paid)) {
        alert('يرجى إدخال مبلغ صحيح');
        return;
    }

    posTablePageMutationActive[requestScope] = true;
    $('#paymentConfirmBtn').prop('disabled', true);
    $.ajax({
        url: 'ajax/process_table_payment.php',
        method: 'POST',
        data: { 
            table_id: tableId,
            order_id: orderId,
            mutation_version: mutationVersion,
            total: total,
            discount: discount,
            net: net,
            paid: paid,
            payment_method: 'cash',
            idempotency_key: getPOSTablePageIdempotencyKey(requestScope)
        },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                clearPOSTablePageIdempotencyKey(requestScope);
                closeModal();
                const orderId = $('#currentOrderId').val();
                // التحويل مباشرة إلى صفحة الفاتورة
                window.location.href = 'print/receipt.php?id=' + orderId;
            } else {
                posTablePageMutationActive[requestScope] = false;
                $('#paymentConfirmBtn').prop('disabled', false);
                alert('حدث خطأ: ' + (data.message || 'خطأ غير محدد'));
            }
        },
        error: function(xhr, status, error) {
            posTablePageMutationActive[requestScope] = false;
            $('#paymentConfirmBtn').prop('disabled', false);
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
            if (data.success) {
                $('#currentTableId').val(tableId);
                $('#currentOrderId').val(data.order_id); // حفظ معرف الطلب
                $('#currentOrderMutationVersion').val(parseInt(data.mutation_version || '0', 10) || '');
                const total = posTablePageMoney(data.total);
                const discount = posTablePageMoney(data.discount);
                const net = posTablePageMoney(data.net);
                const remaining = posTablePageMoney(data.remaining);
                $('#currentOrderRemainingAmount').val(remaining);
                $('#modal_total').attr('data-money', total).text(total + ' ج.م');
                $('#modal_discount').val(discount);
                $('#modal_net').attr('data-money', net).text(net + ' ج.م');
                $('#modal_paid').val(remaining);
                $('#modal_discperc').val(posTablePageMoneyApi().percentageFromMoney(discount, total));
                
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
    const requestScope = 'pos.order.cancel';
    const mutationVersion = parseInt($('#currentOrderMutationVersion').val() || '0', 10) || 0;
    if(confirm('هل تريد تفريغ الطاولة تفريغ عادي؟\nسيتم حفظ الطلب في النظام وتفريغ الطاولة')) {
        $.ajax({
            url: 'ajax/clear_table_normal.php',
            method: 'POST',
            data: {
                table_id: tableId,
                table_name: 'Table ' + tableId,
                order_id: $('#currentOrderId').val() || '',
                mutation_version: mutationVersion > 0 ? mutationVersion : '',
                idempotency_key: getPOSTablePageIdempotencyKey(requestScope)
            },
            success: function(data) {
                try {
                    let response = (typeof data === 'string') ? JSON.parse(data) : data;
                    if (response.success) {
                        clearPOSTablePageIdempotencyKey(requestScope);
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
    const requestScope = 'pos.order.cancel';
    const mutationVersion = parseInt($('#currentOrderMutationVersion').val() || '0', 10) || 0;
    if(confirm('هل تريد تفريغ الطاولة مباشرة بدون سداد؟')) {
        $.ajax({
            url: 'ajax/update_table_status.php',
            method: 'POST',
            data: {
                table_id: tableId,
                action: 'clear',
                order_id: $('#currentOrderId').val() || '',
                mutation_version: mutationVersion > 0 ? mutationVersion : '',
                idempotency_key: getPOSTablePageIdempotencyKey(requestScope)
            },
            success: function(data) {
                try {
                    let response = (typeof data === 'string') ? JSON.parse(data) : data;
                    if (response.success) {
                        clearPOSTablePageIdempotencyKey(requestScope);
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

function moveTableOrder(sourceTableId, orderId) {
    const destinationTableId = $('#move_destination_table').val();
    const requestScope = 'pos.table.move';
    if (!sourceTableId || !orderId) {
        alert('لا يوجد طلب نشط لنقله');
        return;
    }
    if (!destinationTableId) {
        alert('اختر الطاولة الجديدة أولاً');
        return;
    }
    if (parseInt(destinationTableId, 10) === parseInt(sourceTableId, 10)) {
        alert('اختر طاولة مختلفة');
        return;
    }
    if (!confirm('هل تريد نقل الطلب إلى الطاولة المختارة؟')) {
        return;
    }

    $.ajax({
        url: 'ajax/move_table_order.php',
        method: 'POST',
        dataType: 'json',
        data: {
            source_table_id: sourceTableId,
            destination_table_id: destinationTableId,
            order_id: orderId,
            mutation_version: parseInt($('#currentOrderMutationVersion').val() || '0', 10) || '',
            idempotency_key: getPOSTablePageIdempotencyKey(requestScope)
        },
        success: function(response) {
            if (response.success) {
                clearPOSTablePageIdempotencyKey(requestScope);
                alert('تم نقل الطلب بنجاح');
                window.location.href = 'tables.php?table_id=' + encodeURIComponent(destinationTableId);
            } else {
                alert('خطأ: ' + (response.message || 'فشل نقل الطلب'));
            }
        },
        error: function(xhr, status, error) {
            console.error(xhr.responseText);
            alert('حدث خطأ: ' + error);
        }
    });
}

function mergeTableOrders(sourceTableId, sourceOrderId) {
    const destinationTableId = $('#merge_destination_table').val();
    const destinationOrderId = $('#merge_destination_table option:selected').data('order-id') || '';
    const destinationMutationVersion = parseInt($('#merge_destination_table option:selected').data('mutation-version') || '0', 10) || 0;
    const sourceMutationVersion = parseInt($('#currentOrderMutationVersion').val() || '0', 10) || 0;
    const requestScope = 'pos.table.merge';
    if (!sourceTableId || !sourceOrderId) {
        alert('لا يوجد طلب نشط لدمجه');
        return;
    }
    if (!destinationTableId) {
        alert('اختر طاولة مشغولة للدمج أولاً');
        return;
    }
    if (parseInt(destinationTableId, 10) === parseInt(sourceTableId, 10)) {
        alert('اختر طاولة مختلفة');
        return;
    }
    if (!confirm('هل تريد دمج طلب هذه الطاولة مع الطاولة المختارة؟ سيتم نقل الأصناف إلى الطاولة الهدف.')) {
        return;
    }

    $.ajax({
        url: 'ajax/merge_table_orders.php',
        method: 'POST',
        dataType: 'json',
        data: {
            source_table_id: sourceTableId,
            destination_table_id: destinationTableId,
            source_order_id: sourceOrderId,
            destination_order_id: destinationOrderId,
            source_mutation_version: sourceMutationVersion > 0 ? sourceMutationVersion : '',
            destination_mutation_version: destinationMutationVersion > 0 ? destinationMutationVersion : '',
            idempotency_key: getPOSTablePageIdempotencyKey(requestScope)
        },
        success: function(response) {
            if (response.success) {
                clearPOSTablePageIdempotencyKey(requestScope);
                alert('تم دمج الطلب بنجاح');
                window.location.href = 'tables.php?table_id=' + encodeURIComponent(destinationTableId);
            } else {
                alert('خطأ: ' + (response.message || 'فشل دمج الطلب'));
            }
        },
        error: function(xhr, status, error) {
            console.error(xhr.responseText);
            alert('حدث خطأ: ' + error);
        }
    });
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
let currentSplitMutationVersion = 0;
let currentSplitOrderGross = '0.00';
let currentSplitOrderDiscount = '0.00';

function openSplitPaymentModal(tableId, orderId) {
    currentSplitTableId = tableId;
    currentSplitOrderId = orderId;
    
    // Load items
    $.get('ajax/get_table_items.php', { order_id: orderId }, function(data) {
        let response = (typeof data === 'string') ? JSON.parse(data) : data;
        if (response.success) {
            currentSplitMutationVersion = parseInt(response.mutation_version || '0', 10) || 0;
            currentSplitOrderGross = posTablePageMoney(response.order_total);
            currentSplitOrderDiscount = posTablePageMoney(response.order_discount);
            let html = '';
            response.items.forEach(item => {
                const detailId = parseInt(item.id || '0', 10) || 0;
                const amount = posTablePageMoney(item.total);
                html += `
                    <tr>
                        <td>
                            <input type="checkbox" class="split-item-check" 
                                   value="${detailId}"
                                   data-amount="${posTablePageEscapeHtml(amount)}"
                                   onchange="updateSplitTotal()">
                        </td>
                        <td>${posTablePageEscapeHtml(item.name)}</td>
                        <td>${posTablePageEscapeHtml(item.qty)}</td>
                        <td>${posTablePageMoney(item.price)}</td>
                        <td>${amount}</td>
                    </tr>
                `;
            });
            $('#splitItemsBody').html(html);
            $('#splitGross').text('0.00');
            $('#splitDiscount').text('0.00');
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
    const money = posTablePageMoneyApi();
    let selectedGross = '0.00';
    $('.split-item-check:checked').each(function() {
        selectedGross = money.addDecimalStrings(
            selectedGross,
            posTablePageMoney($(this).attr('data-amount')),
            2
        );
    });
    const selectedDiscount = money.compareDecimalStrings(selectedGross, '0.00', 2) > 0
        ? money.allocateProportionalMoney(currentSplitOrderDiscount, selectedGross, currentSplitOrderGross)
        : '0.00';
    const payable = money.subtractDecimalStrings(selectedGross, selectedDiscount, 2);
    $('#splitGross').text(selectedGross);
    $('#splitDiscount').text(selectedDiscount);
    $('#splitTotal').text(payable);
}

function confirmSplitPayment() {
    const requestScope = 'pos.payment.split';
    if (posTablePageMutationActive[requestScope]) {
        return;
    }
    let selectedItems = [];
    $('.split-item-check:checked').each(function() {
        selectedItems.push($(this).val());
    });
    
    if (selectedItems.length === 0) {
        alert('يرجى اختيار صنف واحد على الأقل');
        return;
    }
    
    const amount = posTablePageMoney($('#splitTotal').text());
    if (currentSplitMutationVersion < 1 || !posTablePageIsPositive(amount)) {
        alert('تعذر تحديد قيمة الطلب أو نسخته الحالية. أعد فتح نافذة السداد.');
        return;
    }
    
    if (confirm('هل أنت متأكد من سداد الأصناف المختارة بقيمة ' + amount + ' ج.م؟')) {
        posTablePageMutationActive[requestScope] = true;
        $('#splitPaymentModal .btn-success').prop('disabled', true);
        $.ajax({
            url: 'ajax/process_split_payment.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                order_id: currentSplitOrderId,
                table_id: currentSplitTableId,
                items: selectedItems,
                paid_amount: amount,
                payment_method: 'cash',
                mutation_version: currentSplitMutationVersion,
                idempotency_key: getPOSTablePageIdempotencyKey(requestScope)
            }),
            success: function(data) {
                let response = (typeof data === 'string') ? JSON.parse(data) : data;
                if (response.success) {
                    clearPOSTablePageIdempotencyKey(requestScope);
                    $('#splitPaymentModal').modal('hide');
                    alert('تم السداد بنجاح');
                    if (response.new_invoice_id) {
                         window.open('print/receipt.php?id=' + response.new_invoice_id, '_blank');
                    }
                    location.reload();
                } else {
                    posTablePageMutationActive[requestScope] = false;
                    $('#splitPaymentModal .btn-success').prop('disabled', false);
                    alert('خطأ: ' + response.message);
                }
            },
            error: function() {
                posTablePageMutationActive[requestScope] = false;
                $('#splitPaymentModal .btn-success').prop('disabled', false);
                alert('حدث خطأ في الاتصال');
            }
        });
    }
}
function activateTable(tableId) {
    const requestScope = 'pos.order.cancel';
    $.ajax({
        url: 'ajax/update_table_status.php',
        method: 'POST',
        data: {
            table_id: tableId,
            action: 'activate',
            idempotency_key: getPOSTablePageIdempotencyKey(requestScope)
        },
        success: function(data) {
            let response = (typeof data === 'string') ? JSON.parse(data) : data;
            if (response.success) {
                clearPOSTablePageIdempotencyKey(requestScope);
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
<?php include('includes/pos_lock_system.php'); ?>
