<?php
require_once __DIR__ . '/production_guard.php';
require_once __DIR__ . '/pos_default_accounts.php';
require_once __DIR__ . '/../classes/Pos/Service/LegacyOrderLinePresentationService.php';

if (!isset($action_url)) {
    $action_url = "do/doadd_invoice.php";
}

$posmainPosDefaults = posmain_resolve_pos_defaults($conn, is_array($rowstg ?? null) ? $rowstg : []);
$posmainLegacyLinePresentation = new LegacyOrderLinePresentationService();

$posOrderMode = 1;
$posEditTableId = 0;
$posEditTableName = '';
$posEditOrderId = '';
if (isset($rowed) && is_array($rowed)) {
    $posEditOrderId = (string) (int) ($id ?? $rowed['id'] ?? 0);
    $orderType = (string) ($rowed['order_type'] ?? 'takeaway');
    if ($orderType === 'table') {
        $posOrderMode = 2;
        $posEditTableId = (int) ($rowed['table_id'] ?? 0);
        if ($posEditTableId > 0) {
            $tableLookup = $conn->query("SELECT tname FROM tables WHERE id = {$posEditTableId} AND isdeleted = 0 LIMIT 1");
            if ($tableLookup && ($tableLookupRow = $tableLookup->fetch_assoc())) {
                $posEditTableName = (string) $tableLookupRow['tname'];
            }
        }
    } elseif ($orderType === 'delivery') {
        $posOrderMode = 3;
    }
} elseif (isset($_GET['table'])) {
    $posOrderMode = 2;
}

$legacyOfflinePrototypeEnabled = !production_guard_is_production()
    || production_guard_env_bool('POSMAIN_ENABLE_LEGACY_OFFLINE_PROTOTYPE', false);
?>
<!-- Main Content -->
<form action="<?= $action_url ?>" method="post" id="posForm">
        <?php if (function_exists('csrf_input')) { echo csrf_input('pos_browser'); } ?>
        <?php if (function_exists('csrf_token')): ?>
        <script>
            window.POSMAIN_SHIFT_CSRF_TOKEN = <?= json_encode(csrf_token('shift_close'), JSON_UNESCAPED_SLASHES) ?>;
            window.POSMAIN_CAN_RECIPE_STOCK_OVERRIDE = <?= (function_exists('auth_guard_has_permission') && isset($conn) && $conn instanceof mysqli && auth_guard_has_permission('pos.recipe_stock_override', $conn)) ? 'true' : 'false' ?>;
        </script>
        <?php endif; ?>
        <div class="container-fluid h-100 pos-shell" style="height: calc(100vh - 60px);">
            <div class="row h-100 g-2 pos-layout-row">
                <!-- القسم الأيمن - معلومات الطلب -->
                <div class="col-lg-3 pos-order-column">
                    <div class="card shadow-sm h-100 d-flex flex-column">
                        <div
                            class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center pos-current-order-header">
                            <h6 class="mb-0">
                                <i class="fas fa-shopping-cart me-1"></i>الطلب الحالي
                            </h6>
                            <button type="button" id="recentOrdersBtn2" class="btn btn-light btn-sm recent-orders-btn">
                                 عرض الطلبات السابقة
                            </button>
                        </div>
                        <div class="card-body flex-grow-1 overflow-auto d-flex flex-column">
                            <!-- Hidden Fields -->
                            <input type="hidden" name="pro_tybe" value="9">
                            <input type="hidden" name="pro_serial" value="0">
                            <input type="hidden" name="pro_id" value="1">
                            <div class="pos-current-order-controls">
                                <div class="pos-customer-mount"></div>
                                <div class="pos-table-mount"></div>
                            </div>

                            <!-- نوع الطلب -->
                            <div class="mb-2 pos-order-type-control">
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" id="age1" name="age" value="1"
                                        <?php if ($posOrderMode === 1) { echo 'checked'; } ?>>
                                    <label class="btn btn-outline-primary btn-sm" for="age1">
                                        تيك اواي
                                    </label>

                                                                                                                                                        <input type="radio" class="btn-check" id="age2" name="age" value="2"
                                        <?php if ($posOrderMode === 2) { echo 'checked'; } ?>>
                                    <label class="btn btn-outline-primary btn-sm" for="age2">
                                        طاولة
                                    </label>

                                    <input type="radio" class="btn-check" id="age3" name="age" value="3"
                                        <?php if ($posOrderMode === 3) { echo 'checked'; } ?>>
                                    <label class="btn btn-outline-primary btn-sm" for="age3"
                                        onclick="openDeliveryModal()">
                                        دليفري
                                    </label>
                                </div>
                            </div>

                            <!-- الباركود والبحث -->
                            <div class="row g-1 mb-2 pos-barcode-search-control">
                                <!-- البحث -->
                                <div class="col-6">
                                    <div class="input-group input-group-sm">
                                        <!-- <span class="input-group-text">
                                            <i class="fas fa-search"></i>
                                        </span> -->
                                        <input type="text" class="scnd form-control" id="searchInput"
                                            placeholder="ابحث عن الصنف..."
                                            title="ابحث عن الصنف واضغط Enter | Alt+S للتركيز">
                                    </div>
                                </div>

                                <!-- الباركود -->
                                <div class="col-6">
                                    <input type="text" class="form-control form-control-sm frst"
                                        placeholder="امسح الباركود..." id="barcodeInput"
                                        title="قارئ الباركود | Alt+B للتركيز"
                                        style="border: 2px solid #28a745; background: #f8fff8;">
                                </div>
                            </div>

                            <!-- الطاولة الحالية -->
                            <div class="mb-2 pos-table-visible-control pos-table-field">
                                <button type="button" class="btn btn-outline-primary btn-sm w-100"
                                    data-bs-toggle="modal" data-bs-target="#tablesModal" title="اختر الطاولة">
                                    <i class="fas fa-chair me-1"></i>
                                    <span id="selected_table_display"><?php
                                        if ($posEditTableName !== '') {
                                            echo '<i class="fas fa-chair me-1"></i>' . htmlspecialchars($posEditTableName, ENT_QUOTES, 'UTF-8');
                                        } else {
                                            echo 'اختر طاولة';
                                        }
                                    ?></span>
                                </button>
                                <button type="button"
                                    class="btn btn-outline-primary btn-sm w-100 pos-transfer-table-btn mt-1"
                                    id="transferTableBtn"
                                    onclick="openTableTransferFlow();"
                                    style="display: none;">
                                    <i class="fas fa-exchange-alt me-1"></i>
                                    نقل الطاولة
                                </button>
                                <input type="hidden" id="selected_table_id" name="table_id" value="<?= (int) $posEditTableId ?>">
                                <input type="hidden" id="selected_table_name" name="table_name" value="<?= htmlspecialchars($posEditTableName, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="selected_table_case" name="selected_table_case" value="<?= $posEditTableId > 0 ? '1' : '0' ?>">
                                <input type="hidden" id="selected_order_id" name="selected_order_id" value="<?= $posOrderMode === 2 ? htmlspecialchars($posEditOrderId, ENT_QUOTES, 'UTF-8') : '' ?>">
                            </div>

                            <div class="collapse" id="posAdvancedSetup">
                                <div class="border rounded-3 p-2 mb-2 bg-light">
                                    <!-- الحقول الثانوية - في الناحية التانية -->
                                    <div class="row g-1 mb-2 pos-date-table-control">
                                        <!-- التواريخ -->
                                        <div class="col-6 pos-date-field">
                                    <input type="date" name="pro_date" class="form-control form-control-sm"
                                        value="<?= $posdate ?>" title="التاريخ" style="font-size: 0.75rem;">
                                        </div>
                                        <div class="col-6 pos-date-field">
                                    <input type="date" name="accural_date" class="form-control form-control-sm"
                                        value="<?php echo isset($_GET['edit']) ? $rowed['accural_date'] : date('Y-m-d'); ?>"
                                        title="تاريخ الاستحقاق" style="font-size: 0.75rem;">
                                        </div>
                                    </div>

                                    <!-- الحقول الصغيرة -->
                                    <div class="row g-1 mb-2 pos-setup-control">
                                        <!-- المخزن -->
                                        <div class="col-3 pos-store-field">
                                            <select id="pos_setup_store_id" class="form-select form-select-sm" title="المخزن"
                                                style="font-size: 0.75rem;">
                                                <?php
                                                $resstore = $conn->query("SELECT * FROM `acc_head` WHERE is_stock =1 AND isdeleted = 0;");
                                                $defaultStoreId = (int) ($posmainPosDefaults['store_id'] ?? 0);
                                                while ($resstore && ($rowstore = $resstore->fetch_assoc())) {
                                                    $selected = ((int) $rowstore['id'] === $defaultStoreId) ? 'selected' : '';
                                                ?>
                                                <option <?= $selected ?> value="<?= $rowstore['id'] ?>">
                                                    <?= $rowstore['aname'] ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>

                                        <!-- الموظف -->
                                        <div class="col-3 pos-employee-field">
                                            <select id="pos_setup_emp_id" class="form-select form-select-sm" title="الموظف"
                                                style="font-size: 0.75rem;">
                                                <?php
                                                $resemp = $conn->query("SELECT * FROM `acc_head` WHERE parent_id = 35 AND is_basic = 0 AND isdeleted = 0;");
                                                $defaultEmpId = (int) ($posmainPosDefaults['emp_id'] ?? 0);
                                                while ($resemp && ($rowemp = $resemp->fetch_assoc())) {
                                                    $selected = '';
                                                    if ((int) $rowemp['id'] === $defaultEmpId) {
                                                        $selected = "selected";
                                                    } elseif (isset($_GET['edit']) && $rowed['emp_id'] == $rowemp['id']) {
                                                        $selected = "selected";
                                                    }
                                                ?>
                                                <option <?= $selected ?> value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?>
                                                </option>
                                                <?php } ?>
                                            </select>
                                        </div>

                                        <!-- العميل -->
                                        <div class="col-3 pos-customer-field">
                                            <?php
                                            $tableDefaultClientId = (int) ($posmainPosDefaults['client_id'] ?? 0);
                                            $shouldUseTableDefaultClient = !isset($_GET['edit']) && isset($_GET['table']) && $tableDefaultClientId > 0;
                                            if(isset($_GET['edit'])){$rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();};
                                            $editClientId = isset($rowed['acc1']) ? intval($rowed['acc1']) : 0;
                                            $selectedCustomerId = (int) ($posmainPosDefaults['client_id'] ?? 0);
                                            if (isset($_GET['edit']) && $editClientId > 0) {
                                                $selectedCustomerId = $editClientId;
                                            } elseif ($shouldUseTableDefaultClient) {
                                                $selectedCustomerId = $tableDefaultClientId;
                                            }
                                            $selectedCustomerId = posmain_resolve_pos_customer_id(
                                                $conn,
                                                $selectedCustomerId,
                                                is_array($rowstg ?? null) ? $rowstg : []
                                            );
                                            $initialCustomerOptions = [];
                                            $initialCustomerResult = $conn->query("SELECT id, aname FROM `acc_head` WHERE id = {$selectedCustomerId} AND isdeleted = 0 LIMIT 1");
                                            if ($initialCustomerResult && $initialCustomerResult->num_rows > 0) {
                                                $initialCustomer = $initialCustomerResult->fetch_assoc();
                                                $initialCustomerOptions[(int) $initialCustomer['id']] = $initialCustomer['aname'];
                                            }
                                            ?>
                                            <select name="acc2_id" class="form-select form-select-sm" title="العميل"
                                                style="font-size: 0.75rem;" required
                                                data-options-loaded="0"
                                                data-initial-customer-id="<?= htmlspecialchars((string) $selectedCustomerId, ENT_QUOTES, 'UTF-8') ?>"
                                                data-table-default-customer-id="<?= htmlspecialchars((string) $tableDefaultClientId, ENT_QUOTES, 'UTF-8') ?>">
                                                <?php
                                                foreach ($initialCustomerOptions as $rowClientId => $rowClientName) {
                                                    $selected = $selectedCustomerId === intval($rowClientId) ? "selected" : "";
                                                ?>
                                                <option <?= $selected ?> value="<?= htmlspecialchars((string) $rowClientId, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($rowClientName, ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>

                                        <!-- الصندوق -->
                                        <div class="col-3 pos-fund-field">
                                            <select id="pos_setup_fund_id" class="form-select form-select-sm" title="الصندوق"
                                                style="font-size: 0.75rem;">
                                                <?php
                                                if(isset($_GET['edit'])){$rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();};
                                                $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0;");
                                                $defaultFundId = (int) ($posmainPosDefaults['fund_id'] ?? 0);
                                                while ($resfund && ($rowfund = $resfund->fetch_assoc())) {
                                                    $selected = '';
                                                    if ((int) $rowfund['id'] === $defaultFundId) {
                                                        $selected = "selected";
                                                    } elseif((isset($_GET['edit'])) && $rowed['acc_fund'] == $rowfund['id']){
                                                        $selected = "selected";
                                                    }
                                                ?>
                                                <option <?= $selected ?> value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?>
                                                </option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="store_id" value="<?= (int) ($posmainPosDefaults['store_id'] ?? 0) ?>">
                            <input type="hidden" name="emp_id" value="<?= (int) ($posmainPosDefaults['emp_id'] ?? 0) ?>">
                            <input type="hidden" name="fund_id" value="<?= (int) ($posmainPosDefaults['fund_id'] ?? 0) ?>">

                            <!-- الأصناف المُضافة -->
                            <div class="mb-2 flex-grow-1 d-flex flex-column pos-order-items-section">
                                <div class="card flex-grow-1 d-flex flex-column border-primary pos-order-items-card">
                                    <div class="card-header bg-gradient bg-primary text-white py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0" style="font-size: 0.95rem;">
                                                الأصناف المُضافة
                                            </h6>
                                            <span class="badge bg-white text-primary" id="itemCount">0</span>
                                        </div>
                                    </div>
                                    <div class="card-body p-1 flex-grow-1"
                                        style="min-height: 40vh; max-height: 40vh; overflow-y: auto; overflow-x: auto; background: #f8f9fa;"
                                        id="itemData">
                                        <?php
                                        if (isset($_GET['edit'])){
                                            $id = $_GET['edit'];
                                            $sqldet = "SELECT fd.*, m.iname as item_name, m.barcode
                                                      FROM fat_details fd
                                                      LEFT JOIN myitems m ON m.id = fd.item_id
	                                                      WHERE fd.fatid = $id AND fd.isdeleted = 0";
                                            $resdet = $conn->query($sqldet);
                                            $x = 0;
                                            while ($rowdet = $resdet->fetch_assoc()) {
                                                $x++;
                                                $item_name = $rowdet['item_name'] ?: 'صنف غير معروف';
                                                $presentedLine = $posmainLegacyLinePresentation->presentSaleLine($rowdet);
                                                $qty = $posmainLegacyLinePresentation->inputValue($presentedLine['qty']);
                                                $price = floatval($presentedLine['price']);
                                                $u_val = $posmainLegacyLinePresentation->inputValue($presentedLine['u_val']);
                                                // Fix: Use det_value instead of val
                                                $subtotal = floatval($rowdet['det_value']);
                                                $barcode = $rowdet['barcode'] ?: $rowdet['item_id'];
                                                $line_note = $rowdet['notes'] ?? '';
                                                ?>
                                        <div class="card mb-1 item-card-order pos-cart-row shadow-sm border-start border-3"
                                            data-itemid="<?= $barcode ?>"
                                            style="border-color: #0a7ea4 !important; max-width: 100%;">
                                            <div class="card-body p-1">
                                                <div class="d-flex align-items-center gap-1 pos-cart-row-inner"
                                                    style="font-size: 0.75rem;">
                                                    <span class="badge bg-primary pos-cart-index"
                                                        style="font-size: 0.7rem; min-width: 25px;">#<?= $x ?></span>

                                                    <div class="pos-cart-main" style="flex: 1; min-width: 0;">
                                                        <input type="hidden" value='<?= $rowdet['item_id'] ?>'
                                                            name="itmname[]">
                                                        <input type="hidden" class="barcode" value="<?= $barcode ?>">
                                                        <div class="fw-bold pos-cart-name"
                                                            title="<?= htmlspecialchars($item_name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item_name, ENT_QUOTES, 'UTF-8') ?></div>
                                                        <input type="hidden"
                                                            class="lineNoteInput"
                                                            name="itmnote[]"
                                                            value="<?= htmlspecialchars($line_note, ENT_QUOTES, 'UTF-8') ?>">
                                                    </div>

                                                    <div class="pos-cart-note">
                                                        <button type="button"
                                                            class="btn lineNoteButton line-note-empty"
                                                            title="إضافة ملاحظة للمطبخ"
                                                            aria-label="إضافة ملاحظة للمطبخ">
                                                            <i class="fas fa-sticky-note"></i>
                                                        </button>
                                                    </div>

                                                    <div class="pos-cart-qty" style="width: 65px;">
                                                        <small class="d-block text-center text-muted"
                                                            style="font-size: 0.6rem; margin-bottom: 1px;">كمية</small>
                                                        <button type="button" class="btn qty-step qty-decrease"
                                                            title="تقليل">−</button>
                                                        <input type="number"
                                                            class="form-control form-control-sm text-center quantityInput nozero fw-bold"
                                                            value="<?= $qty ?>" name="itmqty[]" min="1" step="1"
                                                            style="width: 100%; font-size: 0.75rem; padding: 3px; border: 2px solid #ff6347; height: 26px;"
                                                            title="الكمية">
                                                        <button type="button" class="btn qty-step qty-increase"
                                                            title="زيادة">+</button>
                                                        <input type="hidden" name="u_val[]" value="<?= htmlspecialchars($u_val, ENT_QUOTES, 'UTF-8') ?>">
                                                    </div>

                                                    <div class="pos-cart-price" style="width: 55px;">
                                                        <small class="d-block text-center text-muted"
                                                            style="font-size: 0.6rem; margin-bottom: 1px;">سعر</small>
                                                        <input type="number"
                                                            class="form-control form-control-sm text-center priceInput nozero"
                                                            value="<?= number_format($price, 2, '.', '') ?>"
                                                            name="itmprice[]" step="0.01"
                                                            style="width: 100%; font-size: 0.7rem; padding: 3px; height: 26px;"
                                                            title="السعر">
                                                    </div>

                                                    <div class="pos-cart-value" style="width: 60px;">
                                                        <small class="d-block text-center text-muted"
                                                            style="font-size: 0.6rem; margin-bottom: 1px;">قيمة</small>
                                                        <input type="hidden" name="itmdisc[]" value="0">
                                                        <input type="text"
                                                            class="form-control form-control-sm text-center subtotal fw-bold"
                                                            readonly value="<?= number_format($subtotal, 2, '.', '') ?>"
                                                            name="itmval[]"
                                                            style="width: 100%; font-size: 0.7rem; padding: 3px; background: #fff3cd; height: 26px;"
                                                            title="القيمة">
                                                    </div>

                                                    <button type="button" class="btn btn-danger btn-sm delRow"
                                                        style="padding: 2px 6px; font-size: 0.7rem;" title="حذف">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <!-- قسم الدفع والحسابات -->
                            <div class="card border-primary mt-1 pos-payment-summary-card">
                                <div class="card-header bg-primary text-white py-1">
                                    <h6 class="mb-0" style="font-size: 0.8rem;">
                                        <i class="fas fa-calculator me-1"></i>الحسابات والدفع
                                    </h6>
                                </div>
                                <div class="card-body p-1">
                                    <!-- الإجمالي والصافي -->
                                    <div class="row g-1 mb-1">
                                        <div class="col-6 text-center">
                                            <small class="text-muted d-block"
                                                style="font-size: 0.65rem;">الإجمالي</small>
                                            <h5 class="mb-0 text-primary" id="total_display" style="font-size: 0.9rem;">
                                                0.00 ج.م</h5>
                                            <input type="hidden" name="headtotal" id="total" value="0.00">
                                            <input name="headplus" type="hidden">
                                        </div>
                                        <div class="col-6 text-center">
                                            <small class="text-muted d-block" style="font-size: 0.65rem;">الصافي</small>
                                            <h5 class="mb-0 text-success" id="net_display" style="font-size: 0.9rem;">
                                                0.00 ج.م</h5>
                                            <input type="hidden" name="headnet" id="net_val" value="0">
                                            <input type="hidden" name="headdisc" id="discount" value="0">
                                        </div>
                                    </div>

                                    <!-- ملاحظات -->
                                    <div class="mb-1">
                                        <textarea class="form-control form-control-sm" name="info" id="info" rows="1"
                                            placeholder="ملاحظات..."
                                            style="font-size: 0.7rem; padding: 0.2rem;"><?php echo isset($_GET['edit']) ? htmlspecialchars($rowed['info']) : ''; ?></textarea>
                                    </div>

                                    <!-- أزرار الإجراءات -->
                                    <div class="pos-action-stack">
                                        <button type="button" class="btn btn-outline-primary pos-save-order-btn"
                                            onclick="submitPOS('save');">
                                            <i class="fas fa-bookmark me-1"></i>
                                            <?php if(isset($id)): ?>حفظ التعديل<?php else: ?>حفظ الطلب<?php endif; ?>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary pos-print-order-btn"
                                            onclick="submitPOS('print_receipt');">
                                            <i class="fas fa-print me-1"></i>طباعة
                                        </button>
                                        <button type="button" class="btn btn-primary pos-pay-order-btn"
                                            data-bs-toggle="modal" data-bs-target="#paymentModal">
                                            <span><i class="fas fa-money-bill-wave me-1"></i>دفع</span>
                                            <strong id="total_display_btn">0.00 ج.م</strong>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger pos-clear-btn"
                                            onclick="clearAllItems();" title="مسح">
                                            <i class="fas fa-trash-alt me-1"></i><span>إلغاء</span>
                                        </button>
                                    </div>
                                    <div id="selectedTableDisplay" class="badge bg-primary text-white pos-selected-table"
                                        style="display: none;">
                                        <i class="fas fa-chair me-1"></i><span id="selectedTableName"></span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- القسم الأوسط - الأصناف -->
                <div class="col-lg-9 pos-items-column">
                    <div class="card shadow-sm items-section-card">
                        <div class="card-header bg-primary text-white py-2">
                            <div class="pos-catalog-toolbar">
                                <div class="pos-search-tools">
                                    <button type="button" class="btn pos-barcode-btn" id="focusUnifiedSearch" title="بحث أو باركود">
                                        <i class="fas fa-barcode"></i>
                                    </button>
                                    <div class="input-group pos-unified-search">
                                        <input type="text" class="scnd form-control" id="posUnifiedSearch"
                                            placeholder="بحث أو باركود" autocomplete="off"
                                            title="اكتب للفلترة أو اضغط Enter للبحث بالباركود">
                                        <button class="btn btn-outline-secondary" type="button" id="clearFilter"
                                            title="مسح الفلتر">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="pos-mode-tabs" role="group" aria-label="نوع الطلب">
                                    <button type="button" class="pos-mode-tab active" data-age-target="age1">
                                        <i class="fas fa-shopping-bag"></i><span>تيك اواي</span>
                                    </button>
                                    <button type="button" class="pos-mode-tab" data-age-target="age2">
                                        <i class="fas fa-chair"></i><span>طاولة</span>
                                    </button>
                                    <button type="button" class="pos-mode-tab" data-age-target="age3">
                                        <i class="fas fa-motorcycle"></i><span>دليفري</span>
                                        <span class="pos-delivery-pending-badge d-none" id="posDeliveryPendingBadge">0</span>
                                    </button>
                                </div>
                                <div id="posDeliveryBar" class="pos-delivery-bar-wrap d-none" aria-live="polite"></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- التصنيفات -->
                            <div class="mb-2">
                                <div class="d-flex flex-wrap gap-1" id="categoriesContainer">
                                    <?php
                                $rescategories = $conn->query("SELECT * FROM item_group WHERE isdeleted = 0 ORDER BY gname");
                                if ($rescategories && $rescategories->num_rows > 0) {
                                    // زر "الكل"
                                    echo '<button type="button" class="btn btn-primary btn-sm category-btn active" data-category="all">
                                            <i class="fas fa-th me-1"></i>الكل
                                          </button>';

                                    while ($rowcategory = $rescategories->fetch_assoc()) {
                                        $categoryId = isset($rowcategory['id']) ? $rowcategory['id'] : '';
                                        $categoryName = isset($rowcategory['gname']) ? htmlspecialchars($rowcategory['gname']) : '';
                                        echo '<button type="button" class="btn btn-outline-primary btn-sm category-btn" data-category="'.$categoryId.'">
                                                <i class="fas fa-folder me-1"></i>'.$categoryName.'
                                              </button>';
                                    }
                                } else {
                                    echo '<button type="button" class="btn btn-primary btn-sm category-btn active" data-category="all">
                                            <i class="fas fa-th me-1"></i>الكل
                                          </button>';
                                }
                                ?>
                                </div>
                            </div>

                            <!-- شبكة الأصناف -->
                            <?php
                            require_once __DIR__ . '/pos_item_card.php';
                            require_once __DIR__ . '/../classes/Pos/Service/ItemAvailabilityService.php';
                            require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';
                            require_once __DIR__ . '/../classes/Items/ItemCatalogStatus.php';
                            $initialPosItemsLimit = 48;
                            $initialPosItemsPage = 1;
                            $posHasVariantTable = false;
                            $posVariantTableResult = $conn->query("SHOW TABLES LIKE 'item_variants'");
                            $posHasVariantTable = $posVariantTableResult && $posVariantTableResult->num_rows > 0;
                            $posVariantSelect = $posHasVariantTable
                                ? "(EXISTS (SELECT 1 FROM item_variants iv WHERE iv.parent_item_id = m.id AND iv.is_active = 1)) AS has_variants"
                                : "0 AS has_variants";
                            $posVariantChildFilter = $posHasVariantTable
                                ? "AND NOT EXISTS (SELECT 1 FROM item_variants ivc WHERE ivc.variant_item_id = m.id AND ivc.is_active = 1)"
                                : "";
                            $posActiveFilter = ItemCatalogStatus::activeOnlySql($conn, 'm')
                                . ItemCatalogStatus::posSellableOnlySql($conn, 'm');
                            ?>
                            <div class="row g-3" id="itemsGrid"
                                data-initial-page="<?= $initialPosItemsPage ?>"
                                data-page-size="<?= $initialPosItemsLimit ?>">
                                <?php
                            // استعلام مع join للحصول على الصورة من جدول imgs
                            $sqlitems = "SELECT m.*, i.iname as img_filename, {$posVariantSelect}
                                        FROM myitems m
                                        LEFT JOIN (
                                            SELECT itemid, MIN(id) AS image_id
                                            FROM imgs
                                            WHERE isdeleted = 0
                                            GROUP BY itemid
                                        ) image_pick ON image_pick.itemid = m.id
                                        LEFT JOIN imgs i ON i.id = image_pick.image_id
                                        WHERE m.isdeleted = 0
                                        {$posActiveFilter}
                                        {$posVariantChildFilter}
                                        ORDER BY COALESCE(m.salesqty, 0) DESC, m.iname
                                        LIMIT {$initialPosItemsLimit}";
                            $resitems = $conn->query($sqlitems);

                            if ($resitems && $resitems->num_rows > 0) {
                                $posInitialItems = [];
                                $posVariantParentIds = [];
                                while ($rowitem = $resitems->fetch_assoc()) {
                                    if (!empty($rowitem['has_variants'])) {
                                        $posVariantParentIds[] = (int) $rowitem['id'];
                                    }
                                    $posInitialItems[] = $rowitem;
                                }
                                if ($posVariantParentIds) {
                                    $posVariantsByParent = (new ItemVariantService())->activeVariantsForParents($conn, $posVariantParentIds);
                                    foreach ($posInitialItems as &$rowitem) {
                                        if (!empty($rowitem['has_variants'])) {
                                            $rowitem['variants'] = $posVariantsByParent[(int) $rowitem['id']] ?? [];
                                        }
                                    }
                                    unset($rowitem);
                                }
                                $branchConfig = function_exists('posmain_app_config')
                                    ? (posmain_app_config()['branch'] ?? [])
                                    : [];
                                $availabilityScope = [
                                    'tenant' => (int)($branchConfig['pos_tenant'] ?? 0),
                                    'branch' => (int)($branchConfig['pos_branch'] ?? 0),
                                    'channel' => 'pos',
                                    'order_type' => 'takeaway',
                                ];
                                $posInitialItems = (new ItemAvailabilityService())->decorateItems($conn, $posInitialItems, $availabilityScope);
                                foreach ($posInitialItems as $rowitem) {
                                    echo pos_render_item_card($rowitem);
                                }
                            } else {
                                echo '<div class="col-12 text-center text-muted"><p>لا توجد أصناف متاحة</p></div>';
                            }
                            ?>
                            </div>
                            <div id="itemsGridLoader" class="text-center text-muted small py-2 d-none">
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                جاري تحميل باقي الأصناف...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Modal الدفع -->
    <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg pos-payment-modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="paymentModalLabel">
                       الدفع والإجماليات
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body pos-payment-modal-body">
                    <div class="row g-3 pos-payment-grid">
                        <div class="col-12 pos-empty-table-option">
                            <div class="card border-secondary">
                                <div class="card-body py-2">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="pos_empty_table_after_payment" checked>
                                        <label class="form-check-label fw-bold" for="pos_empty_table_after_payment">
                                            إفراغ الطاولة
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- الإجمالي -->
                        <div class="col-12 pos-payment-total-section">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-4">
                                            <label class="mb-0 fw-bold text-primary">
                                                الإجمالي
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
                        <div class="col-12 pos-payment-discount-section">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">
                                       الخصم
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label fw-bold text-dark">الخصم %</label>
                                            <div class="input-group">
                                                <input class="form-control text-center" type="number"
                                                    id="modal_discperc" value="0" min="0" max="100" step="0.1">

                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label fw-bold text-dark">قيمة الخصم</label>
                                            <div class="input-group">
                                                <input class="form-control text-center" type="number"
                                                    id="modal_discount" value="0" step="0.01">

                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- الصافي -->
                        <div class="col-12 pos-payment-net-section">
                            <div class="card bg-success bg-opacity-10 border-success">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-4">
                                            <label class="mb-0 fw-bold text-success">
                                                الصافي
                                            </label>
                                        </div>
                                        <div class="col-8">
                                            <h3 class="mb-0 text-success text-end" id="modal_net">0.00 ج.م</h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- قسم الدفع -->
                        <div class="col-12 pos-payment-method-section">
                            <div class="card border-success">
                                <div class="card-header bg-success bg-opacity-10">
                                    <h6 class="mb-0 text-success">
                                        <i class="fas fa-money-bill-wave me-2"></i>طريقة الدفع
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <!-- مدفوع كاش -->
                                        <div class="col-md-6">
                                            <div class="card border-primary h-100">
                                                <div class="card-header bg-primary text-white py-2">
                                                    <h6 class="mb-0">
                                                        <i class="fas fa-money-bill me-2"></i>مدفوع كاش
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-2">
                                                        <label class="form-label fw-bold">اختر الصندوق</label>
                                                        <select class="form-select" id="payment_fund_id" data-options-source="fund_id"></select>
                                                    </div>
                                                    <div>
                                                        <label class="form-label fw-bold">المبلغ المدفوع كاش</label>
                                                        <div class="input-group input-group-lg">
                                                            <input class="form-control text-center fw-bold" type="number"
                                                                   id="modal_paid_cash" value="0.00" step="0.01" min="0">
                                                            <span class="input-group-text">ج.م</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- مدفوع صرافة -->
                                        <div class="col-md-6">
                                            <div class="card border-info h-100">
                                                <div class="card-header bg-info text-white py-2">
                                                    <h6 class="mb-0">
                                                        <i class="fas fa-credit-card me-2"></i>مدفوع صرافة
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-2">
                                                        <label class="form-label fw-bold">اختر البنك</label>
                                                        <select class="form-select" id="payment_bank_id" data-options-loaded="0">
                                                            <option value="">-- اختر البنك --</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="form-label fw-bold">المبلغ المدفوع صرافة</label>
                                                        <div class="input-group input-group-lg">
                                                            <input class="form-control text-center fw-bold" type="number"
                                                                   id="modal_paid_bank" value="0.00" step="0.01" min="0">
                                                            <span class="input-group-text">ج.م</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- الباقي -->
                                        <div class="col-12">
                                            <div class="alert alert-warning mb-0">
                                                <div class="row align-items-center">
                                                    <div class="col-6">
                                                        <h6 class="mb-0">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>الباقي
                                                        </h6>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <h4 class="mb-0 text-danger" id="modal_change">0.00 ج.م</h4>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 pos-payment-split-section">
                            <div class="card border-warning">
                                <div class="card-header bg-warning bg-opacity-10">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="pos_split_payment_enabled">
                                        <label class="form-check-label fw-bold text-warning-emphasis" for="pos_split_payment_enabled">
                                            سداد أصناف محددة
                                        </label>
                                    </div>
                                </div>
                                <div class="card-body p-2" id="pos_split_payment_panel" style="display: none;">
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-2">
                                            <thead>
                                                <tr>
                                                    <th style="width: 42px;"></th>
                                                    <th>الصنف</th>
                                                    <th style="width: 110px;">الكمية</th>
                                                    <th style="width: 110px;">القيمة</th>
                                                </tr>
                                            </thead>
                                            <tbody id="pos_split_payment_rows"></tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center border-top pt-2">
                                        <span class="fw-bold">إجمالي المحدد</span>
                                        <strong class="text-success" id="pos_split_payment_total">0.00 ج.م</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="button" class="btn btn-warning pos-split-pay-confirm-btn" onclick="submitPOS('split_cash');" style="display: none;">
                        <i class="fas fa-receipt me-1"></i>دفع المحدد وطباعة
                    </button>
                    <button type="button" class="btn btn-primary pos-pay-confirm-btn" onclick="submitPOS('cash');">
                        <i class="fas fa-receipt me-1"></i>دفع وطباعة
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal الطاولات -->
    <div class="modal fade" id="tablesModal" tabindex="-1" aria-labelledby="tablesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="tablesModalLabel">
                        <i class="fas fa-th-large me-2"></i>اختر الطاولة
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-primary d-none mb-3" id="tableTransferHint" role="status">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-exchange-alt mt-1"></i>
                            <div>
                                <strong>اختر الطاولة الجديدة</strong>
                                <div class="small mb-0">
                                    الطاولة الفارغة تنقل الطلب المحفوظ بالكامل. الطاولة المشغولة تدمج الطلبين بعد التأكيد. احفظ أي تعديل قبل النقل.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3" id="tablesGrid">
                        <div class="col-12 text-center text-muted py-4" id="tablesGridLoading">
                            <div class="spinner-border text-primary mb-2" role="status" aria-hidden="true"></div>
                            <p class="mb-0">جاري تحميل الطاولات...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="button" class="btn btn-primary" onclick="selectNoTable();">
                        <i class="fas fa-shopping-bag me-1"></i>بدون طاولة (تيك أواي)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal تفاصيل الصنف -->
    <div class="modal fade" id="itemDetailsModal" tabindex="-1" aria-labelledby="itemDetailsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="itemDetailsModalLabel">
                        <i class="fas fa-info-circle me-2"></i>تفاصيل الصنف
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <div id="modal_item_image"
                            style="height: 200px; overflow: hidden; border-radius: 12px; background: #f8f9fa;">
                            <!-- سيتم ملؤها ديناميكياً -->
                        </div>
                    </div>
                    <h4 class="text-center mb-3" id="modal_item_name"></h4>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="text-muted small">الباركود</label>
                            <p class="fw-bold" id="modal_item_barcode">-</p>
                        </div>
                        <div class="col-6">
                            <label class="text-muted small">السعر</label>
                            <p class="fw-bold text-success fs-5" id="modal_item_price">0.00 ج.م</p>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small">الوصف</label>
                            <p id="modal_item_desc">لا يوجد وصف</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إغلاق
                    </button>
                    <button type="button" class="btn btn-primary" id="modal_add_item">
                        <i class="fas fa-cart-arrow-down me-1"></i>إضافة للطلب
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- Modal إغلاق الشيفت -->
    <div class="modal fade" id="closeShiftModal" tabindex="-1" aria-labelledby="closeShiftModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="closeShiftModalLabel">
                        <i class="fas fa-power-off me-2"></i>إغلاق الشيفت
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <h5>هل أنت متأكد من إغلاق الشيفت؟</h5>
                        <p class="text-muted">سيتم حساب إجمالي مبيعاتك وإغلاق الشيفت نهائياً</p>
                    </div>

                    <!-- معاينة سريعة للمبيعات -->
                    <div class="card border-primary mb-3">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>معاينة سريعة لمبيعات اليوم</h6>
                        </div>
                        <div class="card-body" id="shiftPreview">
                            <div class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">جاري التحميل...</span>
                                </div>
                                <p class="mt-2">جاري حساب المبيعات...</p>
                            </div>
                        </div>
                    </div>

                    <!-- بيانات إغلاق الشيفت -->
                    <div class="card border-secondary mb-3">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="mb-0"><i class="fas fa-calculator me-2"></i>بيانات إغلاق الشيفت</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">المصاريف</label>
                                    <input type="number" class="form-control" id="shift_expenses" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">تسليم الكاش</label>
                                    <input type="number" class="form-control" id="shift_cash" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">نهاية الدرج</label>
                                    <input type="number" class="form-control" id="shift_fund_after" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">بيان المصاريف</label>
                                    <input type="text" class="form-control" id="shift_exp_notes" placeholder="تفاصيل المصاريف">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">ملاحظات</label>
                                    <textarea class="form-control" id="shift_notes" rows="3" placeholder="ملاحظات إضافية"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>


                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                     <a href="z_report.php" class="btn btn-danger">
                        <i class="fas fa-file-invoice me-1"></i> الانتقال لتقرير الإغلاق (Z-Report)
                     </a>
                    <button type="button" class="btn btn-success" onclick="printShiftSalesReport()">
                        <i class="fas fa-user me-1"></i> طباعة  مبيعاتي
                    </button>

                    <button type="button" class="btn btn-warning" onclick="closeShift()">
                        <i class="fas fa-power-off me-1"></i>إغلاق الشيفت
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal الدليفري -->
    <div class="modal fade" id="deliveryModal" tabindex="-1" aria-labelledby="deliveryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="deliveryModalLabel">
                        <i class="fas fa-motorcycle me-2"></i>بيانات العميل - دليفري
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="deliveryModalHint" class="alert alert-warning d-none mb-3"></div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">رقم العميل</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="customer_phone" placeholder="أدخل رقم العميل (البحث يبدأ بعد 3 أرقام)">
                            <!-- <button class="btn btn-primary" type="button" onclick="searchCustomer()">
                                <i class="fas fa-search"></i> بحث
                            </button> -->
                        </div>
                        <small class="text-muted">سيتم البحث تلقائياً بعد كتابة 3 أرقام</small>
                    </div>

                    <div id="customer_result">
                        <!-- سيتم عرض النتيجة هنا -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="button" class="btn btn-primary" id="saveCustomerBtn" onclick="saveCustomerData()">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                    <button type="button" class="btn btn-success" onclick="confirmDeliveryOrder()" style="display:none;"
                        id="confirmOrderBtn">
                        <i class="fas fa-check me-1"></i>تأكيد بيانات العميل
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- POS scripts stay local-first; internet connectivity is only for optional sync/update flows. -->
    <script>
        if (window.jQuery
            && typeof window.jQuery.ajaxSetup === 'function'
            && typeof window.POSMAIN_ATTACH_CSRF_HEADER === 'function') {
            window.jQuery.ajaxSetup({ beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER });
        }
    </script>
    <script src="assets/libs/bootstrap.bundle.min.js"></script>
    <script src="js/pos_config_loader.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_config_loader.js') ?: 1) ?>"></script>
    <?php if ($legacyOfflinePrototypeEnabled): ?>
    <script src="js/pos_offline_adapter.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_offline_adapter.js') ?: 1) ?>"></script>
    <?php endif; ?>
    <script src="js/pos_barcode.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_barcode.js') ?: 1) ?>"></script>
    <script src="js/pos_delivery.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_delivery.js') ?: 1) ?>"></script>

    <?php if ($legacyOfflinePrototypeEnabled): ?>
    <script>
        // تفعيل النظام الأوفلاين فور تحميل الصفحة
        $(document).ready(function() {
            console.log('🚀 Starting POS Offline System...');

            // التحقق من حالة الاتصال
            if (!navigator.onLine) {
                console.log('📴 Device is offline - Offline mode activated');
            } else {
                console.log('🌐 Device is online - Offline adapter ready');
            }
        });
    </script>
    <?php endif; ?>

    <script>
        // دالة طباعة تقرير المبيعات اليومية
        function printDailySalesReport() {
            console.log('Opening daily sales report...');
            window.open('print/daily_sales_receipt.php', '_blank');
        }

        // دالة طباعة تقرير مبيعات الشيفت الشخصية
        function printShiftSalesReport() {
            console.log('Opening shift sales report...');
            window.open('print/shift_sales_receipt.php', '_blank');
        }

        // كود البحث المباشر
        $(document).ready(function () {
            console.log('Search script loaded');

            // F1 للتركيز على العنصر الذي يحمل كلاس frst
            // F2 للتركيز على العنصر الذي يحمل كلاس scnd
            // F1 للتركيز على العنصر الذي يحمل كلاس frst
            // F2 للتركيز على العنصر الذي يحمل كلاس scnd
            $(document).keydown(function (e) {
                if (e.key === 'F1') {
                    e.preventDefault();
                    $('.frst').focus();
                } else if (e.key === 'F2') {
                    e.preventDefault();
                    $('.scnd').focus();
                }
            });

            // ملاحظة: كود السيرش والفلترة موجود في pos_barcode.js (محسّن للأداء)

            // وظيفة إغلاق الشيفت
            window.closeShift = function () {
                const expenses = $('#shift_expenses').val() || 0;
                const expNotes = $('#shift_exp_notes').val() || '';
                const cash = $('#shift_cash').val() || 0;
                const fundAfter = $('#shift_fund_after').val() || 0;
                const notes = $('#shift_notes').val() || '';

                console.log('Shift data:', { expenses, expNotes, cash, fundAfter, notes });

                // إنشاء form وإرسال البيانات
                const form = $('<form>', {
                    method: 'POST',
                    action: 'close_shift.php'
                });

                form.append($('<input>', { type: 'hidden', name: 'expenses', value: expenses }));
                form.append($('<input>', { type: 'hidden', name: 'exp_notes', value: expNotes }));
                form.append($('<input>', { type: 'hidden', name: 'cash', value: cash }));
                form.append($('<input>', { type: 'hidden', name: 'fund_after', value: fundAfter }));
                form.append($('<input>', { type: 'hidden', name: 'notes', value: notes }));
                if (window.POSMAIN_SHIFT_CSRF_TOKEN) {
                    form.append($('<input>', { type: 'hidden', name: 'csrf_token', value: window.POSMAIN_SHIFT_CSRF_TOKEN }));
                }

                $('body').append(form);
                form.submit();
            };

            // تحميل معاينة المبيعات عند فتح modal إغلاق الشيفت
            $('#closeShiftModal').on('show.bs.modal', function () {
                loadShiftPreview();
            });

            function loadShiftPreview() {
                $.ajax({
                    url: 'do/get_shift_preview.php',
                    method: 'GET',
                    success: function(data) {
                        try {
                            // If data is object, use it directly, otherwise parse it
                            var response = (typeof data === 'object') ? data : JSON.parse(data);

                            if (response.success) {
                                var html = `
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="text-center">
                                                <i class="fas fa-receipt fa-2x text-info mb-2"></i>
                                                <h4 class="text-info">${response.data.total_orders}</h4>
                                                <p class="text-muted mb-0">عدد الطلبات</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="text-center">
                                                <i class="fas fa-money-bill-wave fa-2x text-success mb-2"></i>
                                                <h4 class="text-success">${response.data.total_sales} ج.م</h4>
                                                <p class="text-muted mb-0">إجمالي المبيعات</p>
                                            </div>
                                        </div>
                                    </div>
                                `;
                                $('#shiftPreview').html(html);
                            } else {
                                var errorMsg = response.error || 'لا توجد مبيعات لك اليوم';
                                $('#shiftPreview').html('<div class="alert alert-warning text-center">' + errorMsg + '</div>');
                            }
                        } catch (e) {
                            console.error('Error parsing shift preview:', e);
                            console.error('Raw response:', data);

                            // Show a snippet of the raw response to help debugging
                            var snippet = (typeof data === 'string') ? data.substring(0, 100) : 'Invalid Data';
                            $('#shiftPreview').html(`
                                <div class="alert alert-danger text-center">
                                    <strong>خطأ في تحميل البيانات</strong><br>
                                    <small class="text-muted" dir="ltr">${e.message}</small><br>
                                    <small class="d-block mt-2 text-wrap bg-light border p-1" style="font-family:monospace; font-size:10px;">
                                        Server: ${snippet}...
                                    </small>
                                </div>
                            `);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        $('#shiftPreview').html(`
                            <div class="alert alert-danger text-center">
                                <strong>خطأ في الاتصال</strong><br>
                                <small>${error}</small>
                            </div>
                        `);
                    }
                });
            }

            // Delivery UI handled by js/pos_delivery.js
        });
    </script>
    <script>
    // override submitPOS to ensure new logic is used immediately (bypassing cache)
    function createPOSIdempotencyKey(scope) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return scope + ':' + window.crypto.randomUUID();
        }

        return scope + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
    }

    function ensureFormIdempotencyKey(form, action) {
        const scope = action === 'save'
            ? 'pos.order.save'
            : (action === 'print_receipt'
                ? 'pos.order.print'
                : (action === 'free_table' ? 'pos.table.free' : 'pos.order.pay'));
        let keyInput = form.querySelector('input[name="idempotency_key"]');
        if (!keyInput) {
            keyInput = document.createElement('input');
            keyInput.type = 'hidden';
            keyInput.name = 'idempotency_key';
            form.appendChild(keyInput);
        }

        if (!keyInput.value || keyInput.dataset.action !== action) {
            keyInput.value = createPOSIdempotencyKey(scope);
            keyInput.dataset.action = action;
        }

        return keyInput.value;
    }

    window.submitPOS = function(action) {
        console.log('✅ submitPOS (Inline Override) called with action:', action);

        const form = document.getElementById('posForm');
        if (!form) {
            console.error('❌ Form with id "posForm" not found!');
            Swal.fire({
                icon: 'error',
                title: 'خطأ نظام',
                text: 'حدث خطأ في النظام. يرجى إعادة تحميل الصفحة.'
            });
            return false;
        }

        const isFreeTableOnly = action === 'free_table';
        if (isFreeTableOnly && typeof window.POSMainIsHeldTableWithoutActiveOrder === 'function' && !window.POSMainIsHeldTableWithoutActiveOrder()) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'إفراغ الطاولة متاح فقط لطاولة مشغولة بدون طلب مفتوح'
            });
            return false;
        }

	        if (!isFreeTableOnly && typeof validatePOSForm === 'function' && !validatePOSForm()) {
	            return false;
	        }

        if (action === 'cash' && $('#pos_split_payment_enabled').prop('checked')) {
            action = 'split_cash';
        }

        // جمع بيانات الدفع
        const isSaveOnly = action === 'save';
        const isPrintReceiptOnly = action === 'print_receipt';
        const isSplitLinePayment = action === 'split_cash';
        let paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
        let paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
        if (isSaveOnly || isPrintReceiptOnly || isFreeTableOnly) {
            paidCash = 0;
            paidBank = 0;
        }
        if (typeof window.POSMainSyncPaymentOptions === 'function') {
            window.POSMainSyncPaymentOptions();
        }
        let fundId = $('#payment_fund_id').val();
        let bankId = $('#payment_bank_id').val();
        let net = parseFloat($('#net_val').val()) || 0;

        console.log('=== INLINE PAYMENT DATA DEBUG ===');
        console.log('modal_paid_cash value:', $('#modal_paid_cash').val());
        console.log('modal_paid_bank value:', $('#modal_paid_bank').val());
        console.log('payment_fund_id value:', $('#payment_fund_id').val());
        console.log('payment_bank_id value:', $('#payment_bank_id').val());
        console.log('Processed:', {
            paidCash: paidCash,
            paidBank: paidBank,
            fundId: fundId,
            bankId: bankId,
            net: net
        });
        console.log('==================================');

        // التحقق من صحة البيانات
        if (!isSaveOnly && !isPrintReceiptOnly && !isFreeTableOnly && !isSplitLinePayment && net > 0 && paidCash + paidBank <= 0) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يجب إدخال مبلغ الدفع قبل تأكيد الدفع'
            });
            return false;
        }

        if (!isSaveOnly && !isPrintReceiptOnly && !isFreeTableOnly && paidCash > 0 && (!fundId || fundId == '0')) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يجب اختيار الصندوق عند الدفع كاش'
            });
            return false;
        }

        if (!isSaveOnly && !isPrintReceiptOnly && !isFreeTableOnly && paidBank > 0 && (!bankId || bankId == '0' || bankId == '')) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يجب اختيار البنك عند الدفع صرافة'
            });
            return false;
        }

        if (isSplitLinePayment && typeof window.POSMainPrepareSplitPaymentFields === 'function') {
            if (!window.POSMainPrepareSplitPaymentFields(form, {
                paidCash: paidCash,
                paidBank: paidBank,
                fundId: fundId,
                bankId: bankId
            })) {
                return false;
            }
            paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
            paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
        }

        // إضافة حقول الدفع المخفية
        let paidCashInput = form.querySelector('input[name="paid_cash"]');
        if (!paidCashInput) {
            paidCashInput = document.createElement('input');
            paidCashInput.type = 'hidden';
            paidCashInput.name = 'paid_cash';
            form.appendChild(paidCashInput);
        }
        paidCashInput.value = paidCash;

        let paidBankInput = form.querySelector('input[name="paid_bank"]');
        if (!paidBankInput) {
            paidBankInput = document.createElement('input');
            paidBankInput.type = 'hidden';
            paidBankInput.name = 'paid_bank';
            form.appendChild(paidBankInput);
        }
        paidBankInput.value = paidBank;

        let paymentFundInput = form.querySelector('input[name="payment_fund_id"]');
        if (!paymentFundInput) {
            paymentFundInput = document.createElement('input');
            paymentFundInput.type = 'hidden';
            paymentFundInput.name = 'payment_fund_id';
            form.appendChild(paymentFundInput);
        }
        paymentFundInput.value = fundId;

        let paymentBankInput = form.querySelector('input[name="payment_bank_id"]');
        if (!paymentBankInput) {
            paymentBankInput = document.createElement('input');
            paymentBankInput.type = 'hidden';
            paymentBankInput.name = 'payment_bank_id';
            form.appendChild(paymentBankInput);
        }
        paymentBankInput.value = bankId || '';

        // إضافة المدفوع الإجمالي (للتوافق مع الكود القديم)
        let totalPaid = paidCash + paidBank;
        let paidInput = form.querySelector('input[name="paid"]');
        if (!paidInput) {
            paidInput = document.createElement('input');
            paidInput.type = 'hidden';
            paidInput.name = 'paid';
            form.appendChild(paidInput);
        }
        paidInput.value = totalPaid;

        let emptyTableInput = form.querySelector('input[name="empty_table_after_payment"]');
        if (!emptyTableInput) {
            emptyTableInput = document.createElement('input');
            emptyTableInput.type = 'hidden';
            emptyTableInput.name = 'empty_table_after_payment';
            form.appendChild(emptyTableInput);
        }
        emptyTableInput.value = $('#pos_empty_table_after_payment').prop('checked') ? '1' : '0';

        // Check for Edit ID
        let editId = $('#edit_order_id').val() || $('#selected_order_id').val();
        if (editId) {
            console.log('✏️ Edit Mode: ID', editId);
            let editIdInput = form.querySelector('input[name="edit_id"]');
            if (!editIdInput) {
                editIdInput = document.createElement('input');
                editIdInput.type = 'hidden';
                editIdInput.name = 'edit_id';
                form.appendChild(editIdInput);
            }
            editIdInput.value = editId;
        } else {
            let editIdInput = form.querySelector('input[name="edit_id"]');
            if (editIdInput) {
                editIdInput.remove();
            }
        }

        const existingSubmits = form.querySelectorAll('input[name="submit"]');
        existingSubmits.forEach(input => input.remove());

        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'submit';
        submitInput.value = action;
        form.appendChild(submitInput);
        ensureFormIdempotencyKey(form, action);

        let saveBtn = $(".pos-save-order-btn");
        let printOrderBtn = $(".pos-print-order-btn");
        let printBtn = $(".pos-pay-confirm-btn");

        if (saveBtn.length > 0) saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
        if (printOrderBtn.length > 0) printOrderBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الطباعة...');
        if (printBtn.length > 0) printBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الدفع...');

        $('#paymentModal').modal('hide');
        HTMLFormElement.prototype.submit.call(form);
        return true;
    };
    </script>


    <!-- Modal إغلاق الشيفت -->
    <div class="modal fade" id="shiftPreviewModal" tabindex="-1" aria-labelledby="shiftPreviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="shiftPreviewModalLabel">
                        <i class="fas fa-receipt me-2"></i>ملخص الشيفت الحالي
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="shiftPreview">
                        <div class="text-center py-4">
                            <div class="spinner-border text-success" role="status">
                                <span class="visually-hidden">جاري التحميل...</span>
                            </div>
                            <p class="mt-2 text-muted">جاري تحميل بيانات الشيفت...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <form action="close_shift.php" method="POST" id="closeShiftForm" class="d-inline">
                        <?php if (function_exists('csrf_input')) { echo csrf_input('shift_close'); } ?>
                        <!-- بيانات المصروفات والعهدة -->
                        <div class="input-group mb-3">
                            <span class="input-group-text">مصروفات</span>
                            <input type="number" class="form-control" name="expenses" value="0" step="0.01">
                        </div>
                         <div class="input-group mb-3">
                            <span class="input-group-text">السبب</span>
                            <input type="text" class="form-control" name="exp_notes" placeholder="سبب المصروفات...">
                        </div>
                        <div class="input-group mb-3">
                            <span class="input-group-text">عهدة تالية</span>
                            <input type="number" class="form-control" name="fund_after" value="0" step="0.01">
                        </div>
                        <div class="input-group mb-3">
                            <span class="input-group-text">الكاش الفعلي</span>
                            <input type="number" class="form-control" name="cash" step="0.01" required placeholder="المبلغ الموجود بالدرج">
                        </div>
                         <div class="input-group mb-3">
                            <span class="input-group-text">ملاحظات</span>
                            <input type="text" class="form-control" name="notes" placeholder="ملاحظات الإغلاق...">
                        </div>
                        <button type="submit" class="btn btn-success fw-bold w-100">
                            <i class="fas fa-check-circle me-1"></i>تأكيد إغلاق الشيفت
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Orders Offcanvas -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="recentOrdersModal" aria-labelledby="recentOrdersModalLabel"
        style="width: 80%; max-width: 1200px;">
        <div class="offcanvas-header bg-primary text-white">
            <h5 class="offcanvas-title" id="recentOrdersModalLabel">
                الطلبات الأخيرة (آخر 10 طلبات)
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered mb-0">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th>#</th>
                            <th>رقم الفاتورة</th>
                            <th>التاريخ</th>
                            <th>العميل</th>
                            <th>النوع</th>
                            <th>الإجمالي</th>
                            <th>الحالة</th>
                            <th>العمليات</th>
                        </tr>
                    </thead>
                    <tbody id="recentOrdersList">
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">جاري التحميل...</span>
                                </div>
                                <p class="mt-2">جاري تحميل الطلبات...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Recent Orders Button Handler
            $('#recentOrdersBtn2').click(function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof window.showRecentOrdersOffcanvas === 'function') {
                    window.showRecentOrdersOffcanvas();
                } else {
                    var recentOrdersModal = document.getElementById('recentOrdersModal');
                    if (recentOrdersModal) {
                        var offcanvas = typeof bootstrap.Offcanvas.getOrCreateInstance === 'function'
                            ? bootstrap.Offcanvas.getOrCreateInstance(recentOrdersModal)
                            : new bootstrap.Offcanvas(recentOrdersModal);
                        offcanvas.show();
                    }
                }
                loadRecentOrders();
            });

            function loadRecentOrders() {
                $('#recentOrdersList').html('<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">جاري تحميل الطلبات...</p></td></tr>');

	                $.ajax({
	                    url: 'ajax/get_recent_orders.php',
	                    method: 'GET',
	                    cache: false,
	                    data: { _: Date.now() },
	                    success: function(response) {
                        try {
                            // If response is a string (due to accidental whitespace/BOM), parse it
                            if (typeof response === 'string') {
                                // Try to extract JSON if mixed with HTML
                                const jsonMatch = response.match(/\{[\s\S]*\}/);
                                if (jsonMatch) {
                                    response = JSON.parse(jsonMatch[0]);
                                } else {
                                    response = JSON.parse(response);
                                }
                            }

                            if (response.success && response.orders) {
                                var html = '';
                                if (response.orders.length === 0) {
                                    html = '<tr><td colspan="8" class="text-center py-4">لا توجد طلبات حديثة</td></tr>';
                                } else {
                                    response.orders.forEach(function(order, index) {
                                        var statusBadge = (order.status === 'ملغى' || order.status === 'مسترد') ? 'bg-danger' : 'bg-success';
                                        var typeBadge = order.type === 'دليفري' ? 'bg-info text-dark' : (order.type === 'طاولة' ? 'bg-warning text-dark' : 'bg-secondary');
                                        var canRefund = !!order.can_refund;
                                        var canVoid = !!order.can_void;

                                        html += `
                                            <tr>
                                                <td>${index + 1}</td>
                                                <td class="fw-bold">${order.invoice_number}</td>
                                                <td>${order.date}</td>
                                                <td>${order.customer_name}</td>
                                                <td><span class="badge ${typeBadge}">${order.type}</span></td>
                                                <td class="fw-bold text-primary">${parseFloat(order.total).toFixed(2)}</td>
                                                <td><span class="badge ${statusBadge}">${order.status}</span></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="pos_barcode.php?edit=${order.id}" class="btn btn-warning" title="تعديل">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-secondary" onclick="reprintOrder(${order.id})" title="طباعة">
                                                            <i class="fas fa-print"></i>
                                                        </button>
	                                                        ${order.can_delete ? `
	                                                        <button type="button" class="btn btn-danger" onclick="deleteOrder(${order.id}, ${parseInt(order.table_id || 0, 10)})" title="حذف">
	                                                            <i class="fas fa-trash"></i>
	                                                        </button>` : `
		                                                        <button type="button" class="btn btn-outline-secondary" disabled title="لا يمكن حذف طلب مكتمل أو مدفوع من هنا">
	                                                            <i class="fas fa-trash"></i>
		                                                        </button>`}
                                                        ${(canRefund || canVoid) ? `
                                                        <button type="button" class="btn btn-outline-danger" onclick="reversePaidOrder(${order.id}, ${canRefund ? 'true' : 'false'}, ${canVoid ? 'true' : 'false'})" title="استرداد أو إلغاء مدفوع">
                                                            <i class="fas fa-undo"></i>
                                                        </button>` : ''}
                                                    </div>
                                                </td>
                                            </tr>
                                        `;
                                    });
                                }
                                $('#recentOrdersList').html(html);
                            } else {
                                $('#recentOrdersList').html('<tr><td colspan="8" class="text-center text-danger py-4">فشل تحميل البيانات: ' + (response.error || 'خطأ غير معروف') + '</td></tr>');
                            }
                        } catch (e) {
                            console.error('Error parsing recent orders:', e);
                            $('#recentOrdersList').html('<tr><td colspan="8" class="text-center text-danger py-4">خطأ في معالجة البيانات</td></tr>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        $('#recentOrdersList').html('<tr><td colspan="8" class="text-center text-danger py-4">خطأ في الاتصال بالخادم</td></tr>');
                    }
                });
            }

            // Global functions for actions
            window.reprintOrder = function(orderId) {
                // Use existing print function logic or redirect
                // Usually calling the print endpoint directly
                 window.open('print/receipt.php?order_id=' + orderId, '_blank');
	            };

	            window.deleteOrder = function(orderId, tableId) {
	                tableId = parseInt(tableId || 0, 10);

	                Swal.fire({
                    title: 'هل أنت متأكد؟',
                    text: "هل أنت متأكد من حذف هذا الطلب؟ لا يمكن التراجع عن هذا الإجراء.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'نعم، احذفه!',
                    cancelButtonText: 'إلغاء'
                }).then((result) => {
                    if (result.isConfirmed) {
	                        $.ajax({
	                            url: 'ajax/delete_order.php',
	                            method: 'POST',
	                            data: {
                                    order_id: orderId,
                                    table_id: tableId,
                                    idempotency_key: createPOSIdempotencyKey('pos.order.cancel')
                                },
                            success: function(response) {
                                try {
                                    if (typeof response === 'string') response = JSON.parse(response);
                                    if (response.success) {
                                        Swal.fire(
                                            'تم الحذف!',
                                            'تم حذف الطلب بنجاح.',
                                            'success'
                                        );
                                        loadRecentOrders(); // Reload list
                                    } else {
	                                        Swal.fire(
	                                            'خطأ!',
	                                            'فشل الحذف: ' + (response.message || response.error || 'خطأ غير معروف'),
	                                            'error'
	                                        );
                                    }
                                } catch (e) {
                                    Swal.fire(
                                        'خطأ!',
                                        'خطأ في استجابة الخادم',
                                        'error'
                                    );
                                }
                            },
                            error: function() {
                                Swal.fire(
                                    'خطأ!',
                                    'خطأ في الاتصال',
                                    'error'
                                );
                            }
                        });
                    }
                });
            };

            window.reversePaidOrder = function(orderId, canRefund, canVoid) {
                var actionOptions = '';
                if (canRefund) {
                    actionOptions += '<option value="refund">استرداد</option>';
                }
                if (canVoid) {
                    actionOptions += '<option value="void">إلغاء مدفوع</option>';
                }

                Swal.fire({
                    title: 'استرداد أو إلغاء طلب مدفوع',
                    html:
                        '<div class="text-start" dir="rtl">' +
                        '<label class="form-label">نوع العملية</label>' +
                        '<select id="paid-reversal-action" class="form-select mb-3">' + actionOptions + '</select>' +
                        '<label class="form-label">سياسة المخزون</label>' +
                        '<select id="paid-reversal-policy" class="form-select mb-3">' +
                        '<option value="waste">لا يرجع المكونات للمخزون</option>' +
                        '<option value="return_to_stock">يرجع المكونات للمخزون</option>' +
                        '</select>' +
                        '<label class="form-label">السبب</label>' +
                        '<textarea id="paid-reversal-reason" class="form-control" rows="2" maxlength="255"></textarea>' +
                        '</div>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'تنفيذ',
                    cancelButtonText: 'إلغاء',
                    preConfirm: function() {
                        return {
                            action: $('#paid-reversal-action').val(),
                            policy: $('#paid-reversal-policy').val(),
                            reason: ($('#paid-reversal-reason').val() || '').trim()
                        };
                    }
                }).then((result) => {
                    if (!result.isConfirmed || !result.value) {
                        return;
                    }

                    var action = result.value.action === 'void' ? 'void' : 'refund';
                    $.ajax({
                        url: 'ajax/refund_order.php',
                        method: 'POST',
                        data: {
                            order_id: orderId,
                            action: action,
                            refund_stock_policy: result.value.policy,
                            reason: result.value.reason,
                            idempotency_key: createPOSIdempotencyKey(action === 'void' ? 'pos.order.void' : 'pos.order.refund')
                        },
                        success: function(response) {
                            try {
                                if (typeof response === 'string') response = JSON.parse(response);
                                if (response.success) {
                                    Swal.fire('تم التنفيذ', action === 'void' ? 'تم إلغاء الطلب المدفوع.' : 'تم استرداد الطلب.', 'success');
                                    loadRecentOrders();
                                } else {
                                    Swal.fire('خطأ!', response.message || response.error || 'خطأ غير معروف', 'error');
                                }
                            } catch (e) {
                                Swal.fire('خطأ!', 'خطأ في استجابة الخادم', 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('خطأ!', 'خطأ في الاتصال', 'error');
                        }
                    });
                });
            };
        });
    </script>
