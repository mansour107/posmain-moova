<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/pos_main_pin_entry.php';
require_permission('pos.table.open', $conn);
posmain_apply_main_pin_pos_entry($conn, 'pos_tables.php');
if (!auth_guard_is_pos_barcode_unlocked()) {
    include __DIR__ . '/includes/pos_login_screen.php';
    exit;
}
include('includes/header.php');
?>
<style>
    #pos-container {
        height: 100vh;
        overflow: hidden;
    }
    #tables-section {
        height: 100vh;
        overflow-y: auto;
        background-color: #f8f9fa;
    }
    #pos-section {
        height: 100vh;
        overflow-y: auto;
    }
    .table-btn {
        min-width: 100px;
        min-height: 100px;
        font-size: 1.2rem;
        margin: 10px;
        transition: all 0.3s;
    }
    .table-btn:hover {
        transform: scale(1.05);
    }
    .table-available {
        background-color: #60a5fa;
        color: white;
    }
    .table-occupied {
        background-color: #fca5a5;
        color: white;
    }
    .table-selected {
        border: 4px solid #1e40af;
        box-shadow: 0 0 15px rgba(30, 64, 175, 0.5);
    }
    #items-grid {
        max-height: 400px;
        overflow-y: auto;
    }
    .item-card {
        cursor: pointer;
        transition: all 0.3s;
        min-height: 100px;
    }
    .item-card:hover {
        background-color: #ec4899;
        color: white;
        transform: translateY(-5px);
    }
    .category-btn {
        margin: 5px;
        min-width: 100px;
    }
    #order-items {
        max-height: 300px;
        overflow-y: auto;
    }
    .loading {
        opacity: 0.5;
        pointer-events: none;
    }
    
    /* زر POS العائم */
    .floating-pos-btn {
        position: fixed;
        bottom: 30px;
        left: 30px;
        width: 70px;
        height: 70px;
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        border-radius: 50%;
        box-shadow: 0 8px 16px rgba(0,0,0,0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        z-index: 9999;
        transition: all 0.3s;
        cursor: pointer;
        text-decoration: none;
    }
    .floating-pos-btn:hover {
        transform: scale(1.1) rotate(-10deg);
        box-shadow: 0 12px 24px rgba(0,0,0,0.4);
        background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%);
        color: white;
        text-decoration: none;
    }
</style>

<?php
// إنشاء جدول الطاولات
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

// إضافة طاولات تجريبية
$check_tables = $conn->query("SELECT COUNT(*) as count FROM tables WHERE isdeleted = 0");
$tables_count = $check_tables->fetch_assoc()['count'];
if ($tables_count == 0) {
    for ($i = 1; $i <= 12; $i++) {
        $table_name = "طاولة " . $i;
        $conn->query("INSERT INTO tables (tname, table_case) VALUES ('$table_name', 0)");
    }
}
?>

<nav class="navbar navbar-expand font-xs font-light p-2 bg-primary text-white">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h1">
            <i class="fas fa-store"></i> نظام POS - الطاولات
        </span>
        <ul class="navbar-nav ms-auto">
            <li class="nav-item">
                <a href="pos_barcode.php" class="nav-link text-white">
                    <i class="fas fa-cash-register"></i> POS الكاشير
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php" class="nav-link text-white">
                    <i class="fas fa-home"></i> الرئيسية
                </a>
            </li>
            <li class="nav-item">
                <a href="do/do_logout.php" class="nav-link text-white">
                    <i class="fas fa-sign-out-alt"></i> خروج
                </a>
            </li>
        </ul>
    </div>
</nav>

<div class="container-fluid" id="pos-container">
    <div class="row h-100">
        <!-- قسم الطاولات -->
        <div class="col-md-3" id="tables-section">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">الطاولات</h4>
                </div>
                <div class="card-body" id="tables-container">
                    <!-- سيتم تحميل الطاولات هنا -->
                </div>
            </div>
        </div>

        <!-- قسم POS -->
        <div class="col-md-9" id="pos-section">
            <div class="row">
                <!-- معلومات الطلب -->
                <div class="col-md-4">
                    <div class="card mb-3">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">معلومات الطلب</h5>
                        </div>
                        <div class="card-body">
                            <input type="hidden" id="selected_table_id">
                            <input type="hidden" id="current_order_id">
                            
                            <div class="form-group mb-2">
                                <label>الطاولة</label>
                                <input type="text" class="form-control" id="table_name" readonly>
                            </div>
                            
                            <div class="form-group mb-2">
                                <label>التاريخ</label>
                                <input type="date" class="form-control" id="order_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <?php
                            if (!function_exists('posmain_inventory_store_select_options')) {
                                require_once __DIR__ . '/includes/pos_default_accounts.php';
                            }
                            $posTablesStores = posmain_inventory_store_select_options($conn);
                            $posTablesSingleStore = function_exists('posmain_single_store_mode_enabled') && posmain_single_store_mode_enabled() && count($posTablesStores) <= 1;
                            ?>
                            <?php if ($posTablesSingleStore) { ?>
                            <input type="hidden" id="store_id" value="<?= (int) posmain_operational_store_id($conn) ?>">
                            <?php } else { ?>
                            <div class="form-group mb-2">
                                <label>المخزن</label>
                                <select class="form-control" id="store_id">
                                    <?php foreach ($posTablesStores as $rowstore) { ?>
                                        <option <?php if ((int) ($rowstg['def_pos_store'] ?? 0) === (int) $rowstore['id']) { echo 'selected'; } ?> value="<?= (int) $rowstore['id'] ?>"><?= htmlspecialchars((string) $rowstore['aname'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <?php } ?>
                            
                            <div class="form-group mb-2">
                                <label>الموظف</label>
                                <select class="form-control" id="emp_id">
                                    <?php
                                    $resemp = $conn->query("SELECT * FROM `acc_head` WHERE parent_id = 35 AND is_basic = 0 AND isdeleted = 0");
                                    while ($rowemp = $resemp->fetch_assoc()) { ?>
                                        <option <?php if($rowstg['def_pos_employee'] == $rowemp['id']){echo "selected";} ?> value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            
                            <div class="form-group mb-2">
                                <label>الصندوق</label>
                                <select class="form-control" id="fund_id">
                                    <?php
                                    $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0");
                                    while ($rowfund = $resfund->fetch_assoc()) { ?>
                                        <option <?php if($rowstg['def_pos_fund'] == $rowfund['id']){echo "selected";} ?> value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            
                            <div class="form-group mb-2">
                                <label>قارئ الباركود</label>
                                <input type="text" class="form-control" id="barcode-input" placeholder="امسح الباركود...">
                            </div>
                        </div>
                    </div>

                    <!-- الأصناف المضافة -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">الأصناف</h5>
                        </div>
                        <div class="card-body p-0">
                            <div id="order-items">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>الصنف</th>
                                            <th>كمية</th>
                                            <th>سعر</th>
                                            <th>قيمة</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="items-tbody">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">لا توجد أصناف</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- الإجماليات -->
                        <div class="card-footer">
                            <div class="row mb-2">
                                <div class="col-6"><strong>الإجمالي:</strong></div>
                                <div class="col-6"><input type="number" class="form-control form-control-sm" id="total" value="0.00" readonly></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6"><strong>خصم %:</strong></div>
                                <div class="col-3"><input type="number" class="form-control form-control-sm" id="disc_percent" value="0" min="0" max="100" step="0.000001"></div>
                                <div class="col-3"><input type="number" class="form-control form-control-sm" id="discount" value="0.00" min="0" step="0.01"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6"><strong>الصافي:</strong></div>
                                <div class="col-6"><input type="number" class="form-control form-control-sm text-success font-weight-bold" id="net" value="0.00" readonly></div>
                            </div>
                            
                            <button class="btn btn-success btn-block mt-3 pos-save-order-btn" id="save-order" data-pos-save-state="empty">
                                <i class="fas fa-save"></i> حفظ الطلب
                            </button>
                            <button class="btn btn-primary btn-block" id="payment-btn">
                                <i class="fas fa-credit-card"></i> سداد الطلب
                            </button>
                            <button class="btn btn-warning btn-block" id="print-order">
                                <i class="fas fa-print"></i> طباعة الطلب
                            </button>
                            <button class="btn btn-danger btn-block" id="cancel-order">
                                <i class="fas fa-times"></i> إلغاء الطلب
                            </button>
                        </div>
                    </div>
                </div>

                <!-- الأصناف المتاحة -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-warning">
                            <input type="text" class="form-control" id="item-search" placeholder="ابحث عن صنف...">
                        </div>
                        <div class="card-body">
                            <!-- الفئات -->
                            <div class="mb-3" id="categories">
                                <button class="btn btn-sm btn-outline-primary category-btn active" data-category="all">الكل</button>
                                <?php 
                                $rescat = $conn->query("SELECT * from item_group where isdeleted = 0");
                                while($rowcat = $rescat->fetch_assoc()) { ?>
                                    <button class="btn btn-sm btn-outline-primary category-btn" data-category="<?= $rowcat['id'] ?>"><?= $rowcat['gname'] ?></button>
                                <?php } ?>
                            </div>
                            
                            <!-- شبكة الأصناف -->
                            <div id="items-grid">
                                <div class="row" id="items-container">
                                    <!-- سيتم تحميل الأصناف هنا -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- زر عائم للذهاب للـ POS -->
<a href="pos_barcode.php" class="floating-pos-btn" title="POS الكاشير">
    <i class="fas fa-cash-register"></i>
</a>

<!-- تضمين مودال السداد -->
<?php include('elements/pos/payment_modal.php'); ?>

<?= csrf_meta_tag('pos_browser', 'posmain-csrf-token') ?>
<script>
(function () {
    const tokenElement = document.querySelector('meta[name="posmain-csrf-token"]');
    window.POSMAIN_CSRF_TOKEN = tokenElement ? tokenElement.getAttribute('content') : '';
    window.POSMAIN_CSRF_HEADER = 'X-CSRF-Token';
})();
</script>
<script src="js/pos_order_draft.js?v=<?= (int) (@filemtime(__DIR__ . '/js/pos_order_draft.js') ?: 1) ?>"></script>
<script src="js/pos_order_api.js?v=<?= (int) (@filemtime(__DIR__ . '/js/pos_order_api.js') ?: 1) ?>"></script>
<script src="js/pos_tables.js"></script>

<?php include('includes/footer.php');?>
