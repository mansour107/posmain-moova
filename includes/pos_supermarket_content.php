<?php
require_once __DIR__ . '/pos_default_accounts.php';

if (!isset($action_url)) {
    $action_url = "do/doadd_invoice.php";
}

$posmainPosDefaults = posmain_resolve_pos_defaults($conn, is_array($rowstg ?? null) ? $rowstg : []);
?>
<!-- Main Content -->
<form action="<?= $action_url ?>" method="post" id="posForm" class="h-100 d-flex flex-column m-0">
        <?php if (function_exists('csrf_input')) { echo csrf_input('pos_browser'); } ?>
    <div class="container-fluid p-1 flex-grow-1 d-flex flex-column h-100" style="overflow: hidden;">
        <div class="row g-1 m-0 flex-grow-1 h-100" style="overflow: hidden;">
            <!-- القسم الأيمن - الفاتورة وإدخال الباركود -->
            <div class="col-lg-8 d-flex flex-column">
                <div class="card shadow-sm h-100 d-flex flex-column border-0 rounded-3">
                    
                    <!-- شريط الإدخال والبحث العلوي -->
                    <div class="card-header bg-white border-bottom p-2 d-flex gap-2">
                        <!-- Hidden Fields -->
                        <input type="hidden" name="pro_tybe" value="9">
                        <input type="hidden" name="pro_serial" value="0">
                        <input type="hidden" name="pro_id" value="1">
                        <input type="hidden" name="age" value="1"> <!-- تيك اواي افتراضي -->

                        <div class="flex-grow-1 position-relative">
                            <input type="text" class="form-control form-control-lg frst fw-bold"
                                placeholder="امسح الباركود هنا (Alt+B)" id="barcodeInput"
                                style="border: 2px solid #0d6efd; font-size: 1.5rem; background: #f8fbff; text-align: center; border-radius: 10px;" autofocus autocomplete="off">
                            <i class="fas fa-barcode position-absolute top-50 start-0 translate-middle-y ms-3 text-primary fs-3"></i>
                        </div>
                        
                        <div style="width: 250px;">
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control scnd" id="searchInput"
                                    placeholder="بحث باسم الصنف (Alt+S)" autocomplete="off">
                            </div>
                        </div>
                    </div>

                    <!-- جدول الفاتورة (كبير وواضح) -->
                    <div class="card-body p-0 d-flex flex-column" style="flex: 1 1 0; min-height: 0; overflow: hidden;">
                        <div class="table-responsive flex-grow-1" style="overflow-y: auto;" id="itemDataContainer">
                            <table class="table table-hover table-striped table-bordered mb-0 align-middle supermarket-table">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th width="5%" class="text-center">#</th>
                                        <th width="35%">الصنف</th>
                                        <th width="15%" class="text-center">الكمية</th>
                                        <th width="15%" class="text-center">السعر</th>
                                        <th width="20%" class="text-center">الإجمالي</th>
                                        <th width="10%" class="text-center">حذف</th>
                                    </tr>
                                </thead>
                                <tbody id="itemData">
                                    <!-- الأصناف تضاف هنا -->
                                    <?php
                                    if (isset($_GET['edit'])){
                                        $editId = isset($id) ? (int) $id : (int) $_GET['edit'];
                                        $stmtDet = $conn->prepare(
                                            "SELECT fd.*, m.iname as item_name, m.barcode
                                             FROM fat_details fd
                                             LEFT JOIN myitems m ON m.id = fd.item_id
                                             WHERE fd.pro_id = ? AND fd.isdeleted = 0"
                                        );
                                        $resdet = false;
                                        if ($stmtDet) {
                                            $stmtDet->bind_param('i', $editId);
                                            $stmtDet->execute();
                                            $resdet = $stmtDet->get_result();
                                        }
                                        $x = 0;
                                        while ($resdet && ($rowdet = $resdet->fetch_assoc())) {
                                            $x++;
                                            $item_name = $rowdet['item_name'] ?: 'صنف غير معروف';
                                            $qty = floatval($rowdet['qty_out']) - floatval($rowdet['qty_in']);
                                            $price = floatval($rowdet['price']);
                                            $subtotal = floatval($rowdet['det_value']);
                                            $barcode = $rowdet['barcode'] ?: $rowdet['item_id'];
                                            $u_val = floatval($rowdet['u_val']) ?: 1;
                                    ?>
                                        <tr class="item-card-order" data-itemid="<?= htmlspecialchars($barcode) ?>">
                                            <td class="text-center fw-bold text-muted"><?= $x ?></td>
                                            <td>
                                                <input type="hidden" value='<?= $rowdet['item_id'] ?>' name="itmname[]">
                                                <input type="hidden" class="barcode" value="<?= htmlspecialchars($barcode) ?>">
                                                <input type="hidden" name="u_val[]" value="<?= $u_val ?>">
                                                <span class="fw-bold fs-6"><?= htmlspecialchars($item_name) ?></span>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control form-control-lg text-center quantityInput fw-bold text-primary" 
                                                       value="<?= $qty ?>" name="itmqty[]" min="0.01" step="0.01">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control text-center priceInput fw-bold" 
                                                       value="<?= number_format($price, 2, '.', '') ?>" name="itmprice[]" step="0.01" readonly>
                                            </td>
                                            <td>
                                                <input type="hidden" name="itmdisc[]" value="0">
                                                <input type="text" class="form-control text-center subtotal fw-bold bg-light text-danger fs-5 border-0" 
                                                       readonly value="<?= number_format($subtotal, 2, '.', '') ?>" name="itmval[]">
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-danger btn-lg delRow"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php
                                        }
                                        if ($stmtDet) {
                                            $stmtDet->close();
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- شريط الإجماليات والدفع السريع -->
                    <div class="card-footer bg-white border-top p-2">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <div class="bg-light p-2 rounded border">
                                    <small class="text-muted d-block fw-bold mb-1">الكمية الإجمالية</small>
                                    <h4 class="mb-0 text-dark" id="total_qty_display">0</h4>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="bg-success bg-opacity-10 p-2 rounded border border-success d-flex justify-content-between align-items-center h-100">
                                    <div>
                                        <small class="text-success fw-bold d-block mb-1">الإجمالي المطلوب</small>
                                        <h2 class="mb-0 text-success fw-bolder" id="net_display" style="font-size: 2.5rem;">0.00</h2>
                                    </div>
                                    <span class="fs-4 text-success fw-bold">ج.م</span>
                                    
                                    <input type="hidden" name="headtotal" id="total" value="0.00">
                                    <input type="hidden" name="headnet" id="net_val" value="0.00">
                                    <input type="hidden" name="headdisc" id="discount" value="0">
                                    <input name="headplus" type="hidden">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex flex-column gap-2 h-100 justify-content-center">
                                    <button type="button" class="btn btn-success btn-lg py-3 fw-bold fs-4" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                        <i class="fas fa-money-bill-wave me-2"></i> دفع (F12)
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" onclick="clearAllItems();">
                                        <i class="fas fa-trash me-2"></i> مسح الفاتورة
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- القسم الأيسر - الإعدادات والمعلومات -->
            <div class="col-lg-4 d-flex flex-column">
                <!-- الإعدادات الأساسية (تخفى في السوبر ماركت وتكون افتراضية، لكن نظهرها كمعلومات) -->
                <div class="card shadow-sm mb-2 rounded-3 border-0">
                    <div class="card-header bg-dark text-white p-2">
                        <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>بيانات الفاتورة الأساسية</h6>
                    </div>
                    <div class="card-body p-2 bg-light">
                        <div class="row g-2">
                            <!-- المخزن -->
                            <?php
                            $posOperationalStores = posmain_inventory_store_select_options($conn);
                            $posSingleStoreMode = posmain_single_store_mode_enabled() && count($posOperationalStores) <= 1;
                            ?>
                            <div class="col-6<?= $posSingleStoreMode ? ' d-none' : '' ?>">
                                <label class="small text-muted fw-bold">المخزن</label>
                                <select name="store_id" class="form-select form-select-sm fw-bold" required>
                                    <?php
                                    foreach ($posOperationalStores as $rowstore) {
                                        $selected = ((int) $rowstore['id'] === (int) ($posmainPosDefaults['store_id'] ?? 0)) ? 'selected' : '';
                                    ?>
                                    <option <?= $selected ?> value="<?= (int) $rowstore['id'] ?>"><?= htmlspecialchars((string) $rowstore['aname'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <?php if ($posSingleStoreMode) { ?>
                            <input type="hidden" name="store_id" value="<?= (int) ($posmainPosDefaults['store_id'] ?? 0) ?>">
                            <?php } ?>
                            <!-- الموظف -->
                            <div class="col-6">
                                <label class="small text-muted fw-bold">البائع</label>
                                <select name="emp_id" class="form-select form-select-sm fw-bold" required>
                                    <?php
                                    $resemp = $conn->query("SELECT * FROM `acc_head` WHERE parent_id = 35 AND is_basic = 0 AND isdeleted = 0;");
                                    $first_emp = true;
                                    while ($rowemp = $resemp->fetch_assoc()) { 
                                        $selected = '';
                                        if ((int) $rowemp['id'] === (int) ($posmainPosDefaults['emp_id'] ?? 0)) { $selected = "selected"; } 
                                        elseif(isset($_GET['edit']) && $rowed['emp_id'] == $rowemp['id']){ $selected = "selected"; } 
                                        $first_emp = false;
                                    ?>
                                    <option <?= $selected ?> value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <!-- العميل -->
                            <div class="col-6">
                                <label class="small text-muted fw-bold">العميل</label>
                                <select name="acc2_id" class="form-select form-select-sm fw-bold" required>
                                    <?php
                                    $resclient = $conn->query("SELECT * FROM `acc_head` WHERE code like '122%'  AND is_basic = 0 AND isdeleted = 0;");
                                    $first_client = true;
                                    while ($rowclient = $resclient->fetch_assoc()) { 
                                        $selected = '';
                                        if ((int) $rowclient['id'] === (int) ($posmainPosDefaults['client_id'] ?? 0)) { $selected = "selected"; } 
                                        elseif(isset($_GET['edit']) && $rowed['acc1'] == $rowclient['id']){ $selected = "selected"; } 
                                        $first_client = false;
                                    ?>
                                    <option <?= $selected ?> value="<?= $rowclient['id'] ?>"><?= $rowclient['aname'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <!-- الصندوق -->
                            <div class="col-6">
                                <label class="small text-muted fw-bold">الصندوق</label>
                                <select name="fund_id" class="form-select form-select-sm fw-bold" required>
                                    <?php
                                    $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0;");
                                    $first_fund = true;
                                    while ($rowfund = $resfund->fetch_assoc()) { 
                                        $selected = '';
                                        if ((int) $rowfund['id'] === (int) ($posmainPosDefaults['fund_id'] ?? 0)) { $selected = "selected"; } 
                                        elseif((isset($_GET['edit'])) && $rowed['acc_fund'] == $rowfund['id']){ $selected = "selected"; } 
                                        $first_fund = false;
                                    ?>
                                    <option <?= $selected ?> value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <!-- التواريخ مخفية -->
                        <input type="hidden" name="pro_date" value="<?= $posdate ?>">
                        <input type="hidden" name="accural_date" value="<?php echo isset($_GET['edit']) ? $rowed['accural_date'] : date('Y-m-d'); ?>">
                        <input type="hidden" name="table_id" value="0">
                    </div>
                </div>

                <!-- قائمة الأصناف السريعة (اختياري) -->
                <div class="card shadow-sm flex-grow-1 border-0 rounded-3 d-flex flex-column">
                    <div class="card-header bg-primary text-white p-2 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-tags me-2"></i>الأصناف السريعة المضافة حديثاً</h6>
                        <span class="badge bg-light text-primary">AJAX</span>
                    </div>
                    <div class="card-body p-0 d-flex flex-column flex-grow-1">
                        <!-- هنا يمكن عرض أزرار للأقسام الرئيسية التي يكثر استخدامها، ولكن سنضع رسالة للتركيز على الباركود -->
                        <div class="p-4 text-center text-muted d-flex flex-column align-items-center justify-content-center h-100 opacity-50">
                            <i class="fas fa-barcode fa-4x mb-3 text-secondary"></i>
                            <h5>نظام السوبر ماركت</h5>
                            <p class="mb-0">يرجى استخدام قارئ الباركود للإضافة السريعة.</p>
                            <small class="mt-2 text-primary fw-bold">يدعم باركود الميزان وتعدد الوحدات</small>
                        </div>
                        
                        <!-- نافذة نتائج البحث الحي ستظهر هنا فوق المحتوى أو كـ Dropdown -->
                        <div id="searchResults" class="position-absolute w-100 bg-white border rounded shadow-lg z-3" style="display:none; max-height: 400px; overflow-y: auto; top: 0;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Modal الدفع (نسخ من الأساسي مع تعديلات طفيفة للحجم) -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white py-3">
                <h4 class="modal-title fw-bold">
                   <i class="fas fa-cash-register me-2"></i> شاشة الدفع
                </h4>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <div class="row g-4">
                    <!-- الإجمالي والصافي -->
                    <div class="col-12">
                        <div class="card border-0 shadow-sm bg-success bg-opacity-10">
                            <div class="card-body py-4 text-center">
                                <h5 class="text-success fw-bold mb-2">المبلغ المطلوب للدفع</h5>
                                <h1 class="display-3 fw-bolder text-success mb-0" id="modal_net_large">0.00 ج.م</h1>
                            </div>
                        </div>
                    </div>

                    <!-- المدفوع -->
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-4">
                                <h5 class="fw-bold text-dark mb-3">المبلغ المدفوع (نقدياً)</h5>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-primary text-white"><i class="fas fa-money-bill-wave"></i></span>
                                    <input class="form-control text-center fw-bolder fs-2" type="number" 
                                           id="modal_paid_cash" placeholder="0.00" step="0.01" min="0" style="color: #0d6efd;">
                                    <span class="input-group-text fw-bold">ج.م</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- الباقي -->
                    <div class="col-12">
                        <div class="card border-0 shadow-sm bg-danger bg-opacity-10">
                            <div class="card-body p-3 text-center">
                                <h6 class="text-danger fw-bold mb-1">الباقي للعميل (الصرف)</h6>
                                <h2 class="fw-bolder text-danger mb-0" id="modal_change">0.00 ج.م</h2>
                            </div>
                        </div>
                    </div>
                    
                    <!-- حقول مخفية للدفع (الصندوق والبنك) -->
                    <div class="d-none">
                        <select id="payment_fund_id">
                            <?php
                            $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund = 1 AND is_basic = 0 AND isdeleted = 0 LIMIT 1");
                            if($rowfund = $resfund->fetch_assoc()) { 
                                echo "<option value='{$rowfund['id']}' selected></option>";
                            }
                            ?>
                        </select>
                        <input type="number" id="modal_paid_bank" value="0">
                        <select id="payment_bank_id"><option value="" selected></option></select>
                        <input type="number" id="modal_discperc" value="0">
                        <input type="number" id="modal_discount" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top p-3 d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-lg px-4" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" id="btn_save_print" class="btn btn-success btn-lg px-5 fw-bold" onclick="submitSupermarketPOS('cash');">
                    <i class="fas fa-print me-2"></i> حفظ وطباعة (Enter)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal إغلاق الشيفت -->
<?php include __DIR__ . '/../elements/pos/shift_expense_modal.php'; ?>
<?php if (function_exists('csrf_token')): ?>
<script>
    window.POSMAIN_SHIFT_CSRF_TOKEN = <?= json_encode(csrf_token('shift_close'), JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php endif; ?>
<div class="modal fade pos-close-shift-modal-fade" id="closeShiftModal" tabindex="-1"
    aria-labelledby="closeShiftModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered pos-close-shift-dialog">
        <div class="modal-content pos-close-shift-content">
            <div class="modal-header pos-close-shift-header">
                <button type="button" class="btn-close pos-close-shift-close" data-bs-dismiss="modal"
                    aria-label="إغلاق"></button>
                <div class="pos-close-shift-heading">
                    <h5 class="modal-title pos-close-shift-title" id="closeShiftModalLabel">
                        <i class="fas fa-power-off me-2"></i>إغلاق الشيفت
                    </h5>
                    <p class="pos-close-shift-subtitle mb-0">تأكيد الإغلاق ومراجعة مبيعات اليوم</p>
                </div>
            </div>
            <div class="modal-body pos-close-shift-body">
                <div class="pos-close-shift-warning">
                    <i class="fas fa-exclamation-triangle pos-close-shift-warning-icon" aria-hidden="true"></i>
                    <h5 class="pos-close-shift-warning-title">هل أنت متأكد من إغلاق الشيفت؟</h5>
                    <p class="pos-close-shift-warning-text">سيتم حساب إجمالي مبيعاتك وإغلاق الشيفت نهائياً</p>
                </div>

                <section class="pos-close-shift-section">
                    <div class="pos-close-shift-section-header">
                        <i class="fas fa-chart-bar" aria-hidden="true"></i>
                        <h6>معاينة سريعة لمبيعات اليوم</h6>
                    </div>
                    <div class="pos-close-shift-section-body" id="shiftPreview">
                        <div class="pos-close-shift-loading">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">جاري التحميل...</span>
                            </div>
                            <p>جاري حساب المبيعات...</p>
                        </div>
                    </div>
                </section>

                <section class="pos-close-shift-section">
                    <div class="pos-close-shift-section-header">
                        <i class="fas fa-calculator" aria-hidden="true"></i>
                        <h6>بيانات إغلاق الشيفت</h6>
                    </div>
                    <div class="pos-close-shift-section-body">
                        <div class="row g-3 pos-close-shift-fields">
                            <div class="col-md-6">
                                <label class="pos-close-shift-field-label" for="shift_expenses">المصاريف</label>
                                <input type="number" class="form-control pos-close-shift-input" id="shift_expenses"
                                    placeholder="0.00" step="0.01" readonly title="يُحسب تلقائياً من مصروفات الشيفت">
                            </div>
                            <div class="col-md-6">
                                <label class="pos-close-shift-field-label" for="shift_cash">تسليم الكاش</label>
                                <input type="number" class="form-control pos-close-shift-input" id="shift_cash"
                                    placeholder="0.00" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="pos-close-shift-field-label" for="shift_fund_after">نهاية الدرج</label>
                                <input type="number" class="form-control pos-close-shift-input" id="shift_fund_after"
                                    placeholder="0.00" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="pos-close-shift-field-label" for="shift_exp_notes">بيان المصاريف</label>
                                <input type="text" class="form-control pos-close-shift-input" id="shift_exp_notes"
                                    placeholder="تفاصيل المصاريف">
                            </div>
                            <div class="col-12">
                                <label class="pos-close-shift-field-label" for="shift_notes">ملاحظات</label>
                                <textarea class="form-control pos-close-shift-input" id="shift_notes" rows="3"
                                    placeholder="ملاحظات إضافية"></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <div class="modal-footer pos-close-shift-footer">
                <button type="button" class="btn pos-close-shift-btn-cancel" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <a href="z_report.php" class="btn pos-close-shift-btn-zreport">
                    <i class="fas fa-file-invoice me-1"></i>تقرير الإغلاق (Z-Report)
                </a>
                <button type="button" class="btn pos-close-shift-btn-confirm" onclick="closeSupermarketShift()">
                    <i class="fas fa-power-off me-1"></i>إغلاق الشيفت
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<link href="plugins/jquery-ui/jquery-ui.css" rel="stylesheet">
<script src="plugins/jquery-ui/jquery-ui.js"></script>
<script src="assets/libs/bootstrap.bundle.min.js"></script>
<script src="js/pos_config_loader.js?v=<?= time() ?>"></script>
<script>
(function ($) {
    window.closeSupermarketShift = function () {
        if (window.posmainMarkShiftClosing) {
            window.posmainMarkShiftClosing();
        } else {
            try {
                sessionStorage.setItem('pos_shift_closed', '1');
                sessionStorage.setItem('pos_locked', '1');
            } catch (e) {
                // ignore storage failures
            }
        }

        const form = $('<form>', { method: 'POST', action: 'close_shift.php' });
        form.append($('<input>', { type: 'hidden', name: 'expenses', value: $('#shift_expenses').val() || 0 }));
        form.append($('<input>', { type: 'hidden', name: 'exp_notes', value: $('#shift_exp_notes').val() || '' }));
        form.append($('<input>', { type: 'hidden', name: 'cash', value: $('#shift_cash').val() || 0 }));
        form.append($('<input>', { type: 'hidden', name: 'fund_after', value: $('#shift_fund_after').val() || 0 }));
        form.append($('<input>', { type: 'hidden', name: 'notes', value: $('#shift_notes').val() || '' }));
        if (window.POSMAIN_SHIFT_CSRF_TOKEN) {
            form.append($('<input>', { type: 'hidden', name: 'csrf_token', value: window.POSMAIN_SHIFT_CSRF_TOKEN }));
        }
        $('body').append(form);
        form.trigger('submit');
    };

    $('#closeShiftModal').on('show.bs.modal', function () {
        $('#shiftPreview').html(
            '<div class="pos-close-shift-loading"><div class="spinner-border" role="status"></div><p>جاري حساب المبيعات...</p></div>'
        );
        $.ajax({
            url: 'do/get_shift_preview.php',
            method: 'GET',
            success: function (data) {
                try {
                    const response = (typeof data === 'object') ? data : JSON.parse(data);
                    if (response.success) {
                        const expenses = response.data.expenses || {};
                        let expenseHtml = '';
                        if (expenses.mid_shift_enabled || Number(expenses.total || 0) > 0) {
                            expenseHtml =
                                '<div class="pos-close-shift-stat">' +
                                '<i class="fas fa-wallet pos-close-shift-stat-icon is-expenses" aria-hidden="true"></i>' +
                                '<strong class="pos-close-shift-stat-value">' + (expenses.total_formatted || '0.00') + ' ج.م</strong>' +
                                '<span class="pos-close-shift-stat-label">مصروفات الشيفت</span></div>';
                        }
                        $('#shiftPreview').html(
                            '<div class="pos-close-shift-stats">' +
                            '<div class="pos-close-shift-stat">' +
                            '<i class="fas fa-receipt pos-close-shift-stat-icon is-orders" aria-hidden="true"></i>' +
                            '<strong class="pos-close-shift-stat-value">' + response.data.total_orders + '</strong>' +
                            '<span class="pos-close-shift-stat-label">عدد الطلبات</span></div>' +
                            '<div class="pos-close-shift-stat">' +
                            '<i class="fas fa-money-bill-wave pos-close-shift-stat-icon is-sales" aria-hidden="true"></i>' +
                            '<strong class="pos-close-shift-stat-value">' + response.data.total_sales + ' ج.م</strong>' +
                            '<span class="pos-close-shift-stat-label">إجمالي المبيعات</span></div>' +
                            expenseHtml +
                            '</div>'
                        );
                        if (window.posShiftExpenseApplyCloseFields) {
                            window.posShiftExpenseApplyCloseFields(expenses);
                        }
                    } else {
                        $('#shiftPreview').html('<div class="pos-close-shift-alert is-warning">' + (response.error || 'لا توجد مبيعات لك اليوم') + '</div>');
                    }
                } catch (e) {
                    $('#shiftPreview').html('<div class="pos-close-shift-alert is-danger">تعذر تحميل المعاينة</div>');
                }
            },
            error: function () {
                $('#shiftPreview').html('<div class="pos-close-shift-alert is-danger">تعذر تحميل المعاينة</div>');
            }
        });
    });
})(jQuery);
</script>
<script src="js/pos_order_draft.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_order_draft.js') ?: 1) ?>"></script>
<script src="js/pos_order_api.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_order_api.js') ?: 1) ?>"></script>
<script src="js/pos_supermarket.js?v=<?= time() ?>"></script>
<script src="js/pos_shift_expenses.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_shift_expenses.js') ?: 1) ?>"></script>
