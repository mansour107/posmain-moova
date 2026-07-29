<?php
require_once __DIR__ . '/production_guard.php';
require_once __DIR__ . '/pos_default_accounts.php';
require_once __DIR__ . '/../classes/Pos/Service/LegacyOrderLinePresentationService.php';
require_once __DIR__ . '/../classes/Pos/Service/PreparationSelectionService.php';
require_once __DIR__ . '/pos_cart_row.php';

if (!isset($action_url)) {
    $action_url = "do/doadd_invoice.php";
}

$posmainPosDefaults = posmain_resolve_pos_defaults($conn, is_array($rowstg ?? null) ? $rowstg : []);
$posmainLegacyLinePresentation = new LegacyOrderLinePresentationService();
$posmainPreparationSelection = new PreparationSelectionService();

$posOrderMode = 1;
$posEditTableId = 0;
$posEditTableName = '';
$posEditOrderId = '';
$posEditMutationVersion = 0;
if (isset($rowed) && is_array($rowed)) {
    $posEditOrderId = (string) (int) ($id ?? $rowed['id'] ?? 0);
    $posEditMutationVersion = max(1, (int) ($rowed['mutation_version'] ?? 1));
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
} elseif (!empty($_GET['table'])) {
    $posOrderMode = 2;
}

$legacyOfflinePrototypeEnabled = !production_guard_is_production()
    || production_guard_env_bool('POSMAIN_ENABLE_LEGACY_OFFLINE_PROTOTYPE', false);
?>
<script>
window.POSMAIN_EDIT_DELIVERY = <?= json_encode(
    is_array($posmainEditDeliveryContext ?? null) ? $posmainEditDeliveryContext : null,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
</script>
<!-- Main Content -->
<form action="<?= $action_url ?>" method="post" id="posForm">
        <?php if (($posmainEditLoadError ?? '') !== '') { ?>
        <div class="alert alert-danger m-2" role="alert" data-pos-edit-load-error>
            تعذر فتح الطلب للتعديل. لم يتم تحميل أو تغيير أي بيانات.
        </div>
        <?php } ?>
        <?php if ($posEditOrderId !== '') { ?>
        <input type="hidden" name="edit_id" value="<?= htmlspecialchars($posEditOrderId, ENT_QUOTES, 'UTF-8') ?>">
        <?php } ?>
        <?php if (function_exists('csrf_input')) { echo csrf_input('pos_browser'); } ?>
        <?php if (function_exists('csrf_token')): ?>
        <script>
            window.POSMAIN_SHIFT_CSRF_TOKEN = <?= json_encode(csrf_token('shift_close'), JSON_UNESCAPED_SLASHES) ?>;
            window.POSMAIN_SHIFT_OPEN_CSRF_TOKEN = <?= json_encode(csrf_token('shift_open_count'), JSON_UNESCAPED_SLASHES) ?>;
            window.POSMAIN_SHIFT_CLOSE_COUNT_CSRF_TOKEN = <?= json_encode(csrf_token('shift_close_count'), JSON_UNESCAPED_SLASHES) ?>;
            window.POSMAIN_SHIFT_TAKEOVER_CSRF_TOKEN = <?= json_encode(csrf_token('shift_takeover'), JSON_UNESCAPED_SLASHES) ?>;
            window.POSMAIN_SHIFT_TAKEOVER_COUNT_CSRF_TOKEN = <?= json_encode(csrf_token('shift_takeover_count'), JSON_UNESCAPED_SLASHES) ?>;
            window.POSMAIN_POS_OVERRIDE_CSRF_TOKEN = <?= json_encode(csrf_token('pos_override'), JSON_UNESCAPED_SLASHES) ?>;
            if (!window.POSMAIN_CAPABILITIES) {
                window.POSMAIN_CAPABILITIES = {};
            }
            window.POSMAIN_CAN_RECIPE_STOCK_OVERRIDE = window.POSMAIN_CAPABILITIES['pos.recipe_stock_override'] === true;
        </script>
        <?php
        $posHandoverCss = __DIR__ . '/../css/pos-shift-handover.css';
        $posHandoverJs = __DIR__ . '/../js/pos_shift_count_wizard.js';
        if (is_file($posHandoverCss) && is_file($posHandoverJs)):
        ?>
        <link rel="stylesheet" href="css/pos-shift-handover.css?v=<?= (int) filemtime($posHandoverCss) ?>">
        <script src="js/pos_shift_count_wizard.js?v=<?= (int) filemtime($posHandoverJs) ?>"></script>
        <?php endif; ?>
        <?php
        include_once __DIR__ . '/pin_pad_styles.php';
        ?>
        <script src="js/pin_pad.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pin_pad.js') ?: 1) ?>"></script>
        <?php endif; ?>
        <div class="container-fluid h-100 pos-shell">
            <div class="row h-100 g-2 pos-layout-row">
                <!-- القسم الأيسر - معلومات الطلب -->
                <div class="col-lg-3 pos-order-column">
                    <div class="pos-order-panel h-100 d-flex flex-column">
                        <div class="pos-order-header">
                            <h6>الطلب الحالي</h6>
                            <div class="pos-order-header-actions">
                                <button type="button" id="recentOrdersBtn2" class="pos-header-icon-btn recent-orders-btn"
                                    title="عرض الطلبات السابقة" aria-label="عرض الطلبات السابقة">
                                    <i class="fas fa-history"></i>
                                </button>
                                <button type="button" id="posClearOrderBtn" class="pos-header-icon-btn"
                                    title="مسح الطلب" aria-label="مسح الطلب" onclick="clearAllItems();">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="pos-order-type-tabs pos-mode-tabs" role="group" aria-label="نوع الطلب">
                            <button type="button" class="pos-mode-tab<?= $posOrderMode === 1 ? ' active' : '' ?>" data-age-target="age1">
                                تيك اواي
                            </button>
                            <button type="button" class="pos-mode-tab<?= $posOrderMode === 2 ? ' active' : '' ?>" data-age-target="age2">
                                طاولة
                            </button>
                            <button type="button" class="pos-mode-tab<?= $posOrderMode === 3 ? ' active' : '' ?>" data-age-target="age3">
                                دليفري
                                <span class="pos-delivery-pending-badge d-none" id="posDeliveryPendingBadge"
                                    data-delivery-queue-trigger title="متابعة طلبات التوصيل">0</span>
                            </button>
                        </div>

                        <div class="pos-order-body">
                            <!-- Hidden Fields -->
                            <input type="hidden" name="pro_tybe" value="9">
                            <input type="hidden" name="pro_serial" value="0">
                            <input type="hidden" name="pro_id" value="1">
                            <div class="pos-current-order-controls">
                                <div class="pos-customer-mount">
                                    <div class="pos-customer-strip" id="posCustomerStrip">
                                        <div class="pos-customer-search-row" id="posCustomerSearchRow">
                                            <div class="pos-customer-search-wrap">
                                                <i class="fas fa-user pos-customer-search-icon" aria-hidden="true"></i>
                                                <input type="tel"
                                                    class="form-control pos-customer-phone-input"
                                                    id="posCustomerPhoneInput"
                                                    placeholder="ابحث عن عميل برقم الهاتف…"
                                                    inputmode="tel"
                                                    autocomplete="off"
                                                    aria-label="ابحث عن عميل برقم الهاتف"
                                                    title="اختياري — اربط عميلاً بالطلب">
                                            </div>
                                        </div>
                                        <div class="pos-customer-attached d-none" id="posCustomerAttached">
                                            <div class="pos-customer-chip">
                                                <div class="pos-customer-chip-main">
                                                    <strong id="posCustomerChipName">—</strong>
                                                    <span id="posCustomerChipPhone" class="pos-customer-chip-phone"></span>
                                                </div>
                                                <div class="pos-customer-chip-stats" id="posCustomerChipStats"></div>
                                                <div class="pos-customer-chip-actions">
                                                    <button type="button" class="btn btn-sm pos-customer-edit-btn" id="posCustomerEditBtn">تعديل</button>
                                                    <button type="button" class="btn btn-sm pos-customer-detach-btn" id="posCustomerDetachBtn" aria-label="إلغاء الربط">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="pos-customer-suggestions d-none" id="posCustomerSuggestions"></div>
                                        <div class="pos-customer-create d-none" id="posCustomerCreate">
                                            <input type="text" class="form-control form-control-sm mb-2" id="posCustomerCreateName" placeholder="اسم العميل">
                                            <textarea class="form-control form-control-sm mb-2" id="posCustomerCreateNotes" rows="2" placeholder="ملاحظات (اختياري)"></textarea>
                                            <button type="button" class="btn btn-sm btn-primary w-100" id="posCustomerCreateBtn">حفظ وربط</button>
                                        </div>
                                        <div class="pos-customer-editor d-none" id="posCustomerEditor"></div>
                                    </div>
                                </div>
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
                                <input type="hidden" id="order_mutation_version" name="mutation_version" value="<?= $posEditMutationVersion ?>">
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
                                        value="<?php echo $posEditOrderId !== '' ? htmlspecialchars((string) $rowed['accural_date'], ENT_QUOTES, 'UTF-8') : date('Y-m-d'); ?>"
                                        title="تاريخ الاستحقاق" style="font-size: 0.75rem;">
                                        </div>
                                    </div>

                                    <!-- الحقول الصغيرة -->
                                    <div class="row g-1 mb-2 pos-setup-control">
                                        <!-- المخزن -->
                                        <?php
                                        $posOperationalStores = posmain_inventory_store_select_options($conn);
                                        $posSingleStoreMode = posmain_single_store_mode_enabled() && count($posOperationalStores) <= 1;
                                        ?>
                                        <div class="col-3 pos-store-field<?= $posSingleStoreMode ? ' d-none' : '' ?>">
                                            <select id="pos_setup_store_id" class="form-select form-select-sm" title="المخزن"
                                                style="font-size: 0.75rem;">
                                                <?php
                                                $defaultStoreId = (int) ($posmainPosDefaults['store_id'] ?? 0);
                                                foreach ($posOperationalStores as $rowstore) {
                                                    $selected = ((int) $rowstore['id'] === $defaultStoreId) ? 'selected' : '';
                                                ?>
                                                <option <?= $selected ?> value="<?= (int) $rowstore['id'] ?>">
                                                    <?= htmlspecialchars((string) $rowstore['aname'], ENT_QUOTES, 'UTF-8') ?></option>
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
                                                    } elseif ($posEditOrderId !== '' && $rowed['emp_id'] == $rowemp['id']) {
                                                        $selected = "selected";
                                                    }
                                                ?>
                                                <option <?= $selected ?> value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?>
                                                </option>
                                                <?php } ?>
                                            </select>
                                        </div>

                                        <!-- العميل المحاسبي الافتراضي (مخفي) -->
                                        <div class="col-3 pos-customer-field d-none">
                                            <?php
                                            $tableDefaultClientId = (int) ($posmainPosDefaults['client_id'] ?? 0);
                                            $selectedCustomerId = posmain_resolve_pos_customer_id(
                                                $conn,
                                                $tableDefaultClientId,
                                                is_array($rowstg ?? null) ? $rowstg : []
                                            );
                                            ?>
                                            <input type="hidden" name="acc2_id" value="<?= (int) $selectedCustomerId ?>">
                                        </div>

                                        <!-- الصندوق -->
                                        <div class="col-3 pos-fund-field">
                                            <select id="pos_setup_fund_id" class="form-select form-select-sm" title="الصندوق"
                                                style="font-size: 0.75rem;">
                                                <?php
                                                $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0;");
                                                $defaultFundId = (int) ($posmainPosDefaults['fund_id'] ?? 0);
                                                while ($resfund && ($rowfund = $resfund->fetch_assoc())) {
                                                    $selected = '';
                                                    if ((int) $rowfund['id'] === $defaultFundId) {
                                                        $selected = "selected";
                                                    } elseif ($posEditOrderId !== '' && $rowed['acc_fund'] == $rowfund['id']) {
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
                            <div class="pos-order-items-section flex-grow-1 d-flex flex-column">
                                <div class="pos-order-items-card d-none">
                                    <div class="card-header">
                                        <span id="itemCount">0</span>
                                    </div>
                                </div>
                                <div class="pos-order-items-scroll flex-grow-1" id="itemData">
                                        <?php
                                        $pos_initial_cart_total_value = 0.0;
                                        if ($posEditOrderId !== '') {
                                            $detailOrderId = (int) $posEditOrderId;
                                            $detailStmt = $conn->prepare(
                                                "SELECT fd.*, m.iname AS item_name, m.barcode
                                                 FROM fat_details fd
                                                 LEFT JOIN myitems m ON m.id = fd.item_id
                                                 WHERE fd.fatid = ? AND fd.isdeleted = 0"
                                            );
                                            $detailStmt->bind_param('i', $detailOrderId);
                                            $detailStmt->execute();
                                            $resdet = $detailStmt->get_result();
                                            while ($rowdet = $resdet->fetch_assoc()) {
                                                $item_name = $rowdet['item_name'] ?: 'صنف غير معروف';
                                                $presentedLine = $posmainLegacyLinePresentation->presentSaleLine($rowdet);
                                                $qty = $posmainLegacyLinePresentation->inputValue($presentedLine['qty']);
                                                $price = floatval($presentedLine['price']);
                                                $u_val = $posmainLegacyLinePresentation->inputValue($presentedLine['u_val']);
                                                $subtotal = floatval($rowdet['det_value']);
                                                $pos_initial_cart_total_value += $subtotal;
                                                $barcode = $rowdet['barcode'] ?: $rowdet['item_id'];
                                                $line_note = $rowdet['notes'] ?? '';
                                                $preparation_values = $posmainPreparationSelection->fetchLineValues(
                                                    $conn,
                                                    $detailOrderId,
                                                    (int) $rowdet['id']
                                                );
                                                echo pos_render_cart_row([
                                                    'item_id' => (int) $rowdet['item_id'],
                                                    'item_name' => $item_name,
                                                    'qty' => $qty,
                                                    'price' => $price,
                                                    'subtotal' => $subtotal,
                                                    'barcode' => $barcode,
                                                    'line_note' => $line_note,
                                                    'preparation_values' => $preparation_values,
                                                    'sugar_allowed' => $posmainPreparationSelection->itemAllowsSugarSpoons(
                                                        $conn,
                                                        (int) $rowdet['item_id']
                                                    ),
                                                    'u_val' => $u_val,
                                                    'persisted' => true,
                                                    'persisted_qty' => $qty,
                                                ]);
                                            }
                                            $detailStmt->close();
                                        }
                                        $pos_initial_cart_total = number_format($pos_initial_cart_total_value, 2, '.', '');
                                        ?>
                                </div>
                            </div>

                            <div class="accordion mb-2" id="posOrderExtras">
                                <div class="accordion-item border-0 bg-transparent">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-2" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#posOrderExtrasBody"
                                            aria-expanded="false" aria-controls="posOrderExtrasBody">
                                            ملاحظات
                                        </button>
                                    </h2>
                                    <div id="posOrderExtrasBody" class="accordion-collapse collapse"
                                        data-bs-parent="#posOrderExtras">
                                        <div class="accordion-body p-2">
                                            <div class="mb-2">
                                                <label class="form-label small text-muted mb-1" for="info">ملاحظات الطلب</label>
                                                <textarea class="form-control form-control-sm" name="info" id="info" rows="2"
                                                    placeholder="ملاحظات..."><?php echo $posEditOrderId !== '' ? htmlspecialchars((string) $rowed['info'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
                                            </div>
                                            <input type="hidden" name="headnet" id="net_val" value="0">
                                            <input type="hidden" name="headdisc" id="discount" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pos-order-footer">
                            <div class="pos-order-total">
                                <span class="pos-order-total-label">المجموع</span>
                                <h5 class="mb-0" id="total_display"><?= htmlspecialchars($pos_initial_cart_total ?? '0.00', ENT_QUOTES, 'UTF-8') ?> ج.م</h5>
                                <input type="hidden" name="headtotal" id="total" value="<?= htmlspecialchars($pos_initial_cart_total ?? '0.00', ENT_QUOTES, 'UTF-8') ?>">
                                <input name="headplus" type="hidden">
                                <span id="net_display" class="d-none">0.00 ج.م</span>
                            </div>

                            <div class="pos-action-stack">
                                <button type="button" class="btn btn-outline-primary pos-save-order-btn"
                                    onclick="submitPOS('save');">
                                    <i class="fas fa-bookmark me-1"></i>
                                    <?php if(isset($id)): ?>حفظ التعديل<?php else: ?>حفظ<?php endif; ?>
                                </button>
                                <button type="button" class="btn btn-outline-secondary pos-print-order-btn"
                                    onclick="submitPOS('print_receipt');">
                                    <i class="fas fa-print me-1"></i>طباعة
                                </button>
                                <button type="button" class="btn btn-primary pos-pay-order-btn"
                                    data-bs-toggle="modal" data-bs-target="#paymentModal">
                                    <span><i class="fas fa-credit-card me-1"></i>دفع</span>
                                    <strong id="total_display_btn"><?= htmlspecialchars($pos_initial_cart_total ?? '0.00', ENT_QUOTES, 'UTF-8') ?> ج.م</strong>
                                </button>
                                <button type="button" class="btn btn-outline-danger pos-clear-btn d-none"
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

                <!-- القسم الأوسط - الأصناف -->
                <div class="col-lg-9 pos-items-column">
                    <div class="card shadow-sm items-section-card h-100">
                        <div class="pos-catalog-panel h-100 d-flex flex-column">
                            <div class="pos-catalog-search">
                                <div class="pos-search-wrap">
                                    <input type="text" class="scnd form-control" id="posUnifiedSearch"
                                        placeholder="ابحث عن الصنف..." autocomplete="off"
                                        title="اكتب للفلترة أو اضغط Enter للبحث بالباركود">
                                    <span class="pos-search-icon"><i class="fas fa-search"></i></span>
                                    <button class="btn d-none" type="button" id="focusUnifiedSearch" title="بحث أو باركود"></button>
                                    <button class="btn d-none" type="button" id="clearFilter" title="مسح الفلتر">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="posDeliveryBar" class="pos-delivery-bar-wrap d-none" aria-live="polite"></div>
                            <div class="pos-catalog-body">
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
                                $availabilityScope = posmain_pos_availability_scope($conn);
                                $posInitialItems = (new ItemAvailabilityService())->decorateItems($conn, $posInitialItems, $availabilityScope);
                                $posInitialItems = $posmainPreparationSelection->decorateItems($conn, $posInitialItems);
                                foreach ($posInitialItems as $rowitem) {
                                    echo pos_render_item_card_compact($rowitem);
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
        </div>
    </form>

    <!-- Modal الدفع -->
    <div class="modal fade pos-payment-modal-fade" id="paymentModal" tabindex="-1"
        aria-labelledby="paymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered pos-payment-modal-dialog">
            <div class="modal-content pos-payment-modal-content">
                <div class="modal-header pos-payment-modal-header">
                    <button type="button" class="btn-close pos-payment-modal-close" data-bs-dismiss="modal"
                        aria-label="إغلاق"></button>
                    <div class="pos-payment-modal-heading">
                        <h5 class="modal-title pos-payment-modal-title" id="paymentModalLabel">الدفع</h5>
                        <p class="pos-payment-modal-subtitle mb-0">أكمل العملية واطبع الإيصال</p>
                    </div>
                </div>
                <div class="modal-body pos-payment-modal-body">
                    <div class="pos-payment-grid">
                        <div class="pos-empty-table-option pos-payment-option-row">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="pos_empty_table_after_payment" checked>
                                <label class="form-check-label" for="pos_empty_table_after_payment">إفراغ الطاولة</label>
                            </div>
                        </div>

                        <div class="pos-payment-summary pos-payment-total-section">
                            <div class="pos-payment-summary-row">
                                <span class="pos-payment-summary-label">الإجمالي</span>
                                <span class="pos-payment-summary-value" id="modal_total">0.00 ج.م</span>
                            </div>
                            <div class="pos-payment-discount-section pos-payment-discount-fields">
                                <div class="pos-payment-discount-field">
                                    <label class="pos-payment-field-label" for="modal_discperc">الخصم %</label>
                                    <input class="form-control pos-payment-input" type="number"
                                        id="modal_discperc" value="0" min="0" max="100" step="0.1">
                                </div>
                                <div class="pos-payment-discount-field">
                                    <label class="pos-payment-field-label" for="modal_discount">قيمة الخصم</label>
                                    <input class="form-control pos-payment-input" type="number"
                                        id="modal_discount" value="0" step="0.01">
                                </div>
                            </div>
                            <div class="pos-payment-summary-row pos-payment-net-section pos-payment-net-row">
                                <span class="pos-payment-summary-label">الصافي</span>
                                <span class="pos-payment-summary-value pos-payment-net-value" id="modal_net">0.00 ج.م</span>
                            </div>
                        </div>

                        <div class="pos-payment-method-section">
                            <span class="pos-payment-section-label">طريقة الدفع</span>
                            <div class="pos-payment-method-picker" role="radiogroup" aria-label="طريقة الدفع">
                                <label class="pos-payment-method-option">
                                    <input type="radio" class="pos-payment-method-input" name="pos_payment_method"
                                        value="cash" checked>
                                    <span class="pos-payment-method-chip">
                                        <i class="fas fa-money-bill-wave" aria-hidden="true"></i>
                                        <span>كاش</span>
                                    </span>
                                </label>
                                <label class="pos-payment-method-option">
                                    <input type="radio" class="pos-payment-method-input" name="pos_payment_method"
                                        value="bank">
                                    <span class="pos-payment-method-chip">
                                        <i class="fas fa-credit-card" aria-hidden="true"></i>
                                        <span>صرافة</span>
                                    </span>
                                </label>
                                <label class="pos-payment-method-option">
                                    <input type="radio" class="pos-payment-method-input" name="pos_payment_method"
                                        value="mixed">
                                    <span class="pos-payment-method-chip">
                                        <i class="fas fa-coins" aria-hidden="true"></i>
                                        <span>مختلط</span>
                                    </span>
                                </label>
                            </div>

                            <div class="pos-payment-amounts pos-payment-mode-cash">
                                <div class="pos-payment-amount-cash">
                                    <label class="pos-payment-field-label" for="modal_paid_cash">المبلغ كاش</label>
                                    <div class="pos-payment-amount-wrap">
                                        <input class="form-control pos-payment-amount-input" type="number"
                                            id="modal_paid_cash" value="0.00" step="0.01" min="0">
                                        <span class="pos-payment-currency">ج.م</span>
                                    </div>
                                </div>
                                <div class="pos-payment-amount-bank">
                                    <label class="pos-payment-field-label" for="modal_paid_bank">المبلغ صرافة</label>
                                    <div class="pos-payment-amount-wrap">
                                        <input class="form-control pos-payment-amount-input" type="number"
                                            id="modal_paid_bank" value="0.00" step="0.01" min="0">
                                        <span class="pos-payment-currency">ج.م</span>
                                    </div>
                                </div>
                            </div>

                            <div class="pos-payment-change-row">
                                <span class="pos-payment-change-label">الباقي</span>
                                <span class="pos-payment-change-value" id="modal_change">0.00 ج.م</span>
                            </div>
                        </div>

                        <div class="pos-payment-split-section">
                            <label class="pos-payment-split-header" for="pos_split_payment_enabled">
                                <span class="pos-payment-split-header-copy">
                                    <span class="pos-payment-split-title">سداد أصناف محددة</span>
                                    <span class="pos-payment-split-hint">اختر الأصناف المراد دفعها الآن</span>
                                </span>
                                <span class="pos-payment-split-switch-wrap">
                                    <input class="pos-payment-split-switch" type="checkbox"
                                        id="pos_split_payment_enabled" role="switch">
                                </span>
                            </label>
                            <div class="pos-payment-split-panel" id="pos_split_payment_panel" style="display: none;">
                                <div class="pos-split-lines" id="pos_split_payment_rows"></div>
                                <div class="pos-payment-split-total">
                                    <span class="pos-payment-split-total-label">إجمالي المحدد</span>
                                    <strong id="pos_split_payment_total">0.00 ج.م</strong>
                                </div>
                            </div>
                        </div>

                        <select id="payment_fund_id" data-options-source="fund_id" class="d-none"
                            aria-hidden="true" tabindex="-1"></select>
                        <select id="payment_bank_id" class="d-none" aria-hidden="true" tabindex="-1"
                            data-options-loaded="0">
                            <option value=""></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer pos-payment-modal-footer">
                    <button type="button" class="btn pos-payment-btn-cancel" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn pos-payment-btn-split pos-split-pay-confirm-btn" onclick="submitPOS('split_cash');" style="display: none;">دفع المحدد</button>
                    <button type="button" class="btn pos-payment-btn-confirm pos-pay-confirm-btn"
                        onclick="submitPOS('cash');">دفع وطباعة</button>
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
                        <div class="col-12 text-center pos-tables-state py-4" id="tablesGridLoading">
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
    <?php include __DIR__ . '/../elements/pos/shift_expense_modal.php'; ?>
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
                        <p class="pos-close-shift-subtitle mb-0">عدّ النقد أولاً ثم تأكيد الإغلاق</p>
                    </div>
                </div>
                <div class="modal-body pos-close-shift-body">
                    <div class="pos-close-shift-warning">
                        <i class="fas fa-exclamation-triangle pos-close-shift-warning-icon" aria-hidden="true"></i>
                        <h5 class="pos-close-shift-warning-title">هل تريد إغلاق الشيفت الآن؟</h5>
                        <p class="pos-close-shift-warning-text">ابدأ بعدّ محتويات الدرج ثم راجع المبلغ قبل التأكيد</p>
                    </div>

                    <section class="pos-close-shift-section pos-close-shift-wizard-step" id="pshCloseStep-summary">
                        <div class="pos-close-shift-section-header">
                            <i class="fas fa-calculator" aria-hidden="true"></i>
                            <h6>عدّ محتويات الدرج</h6>
                        </div>
                        <div class="pos-close-shift-section-body text-center" data-testid="close-shift-guidance">
                            <p class="mb-0 fw-bold">اضغط «عدّ الدرج» للبدء، ثم أدخل المبلغ الموجود.</p>
                        </div>
                    </section>

                    <section class="pos-close-shift-section pos-close-shift-wizard-step psh-hidden" id="pshCloseStep-count">
                        <div class="pos-close-shift-section-header">
                            <i class="fas fa-calculator" aria-hidden="true"></i>
                            <h6>عدّ النقد في الدرج</h6>
                        </div>
                        <div class="pos-close-shift-section-body pos-close-shift-count-panel">
                            <p class="text-muted text-center mb-2" id="pshCloseAttemptLabel">كم وجدت في الدرج؟</p>
                            <div id="pshCloseCountStep">
                                <input type="number" id="pshCloseAmount" class="psh-amount-input" placeholder="0.00" step="0.01" min="0">
                                <div id="pshCloseMessage" class="psh-message psh-hidden"></div>
                            </div>
                            <div id="pshCloseVariance" class="psh-variance-card psh-hidden">
                                <p class="psh-variance-label" id="pshCloseVarianceLabel"></p>
                                <p class="psh-variance-amount" id="pshCloseVarianceAmount"></p>
                                <p class="psh-variance-label">سيتم إغلاق الشيفت وتسجيل الحالة للمراجعة</p>
                            </div>
                            <div class="row g-3 pos-close-shift-fields mt-2">
                                <div class="col-12">
                                    <label class="pos-close-shift-field-label" for="shift_notes">ملاحظات (اختياري)</label>
                                    <textarea class="form-control pos-close-shift-input" id="shift_notes" rows="2"
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
                    <button type="button" class="btn pos-close-shift-btn-cancel psh-hidden" data-psh-close-back>
                        <i class="fas fa-arrow-right me-1"></i>رجوع
                    </button>
                    <button type="button" class="btn pos-close-shift-btn-confirm" data-psh-close-next>
                        <i class="fas fa-calculator me-1"></i>عدّ الدرج
                    </button>
                    <button type="button" class="btn pos-close-shift-btn-confirm psh-hidden" data-psh-close-submit-count>
                        <i class="fas fa-check me-1"></i>تأكيد العد
                    </button>
                    <button type="button" class="btn pos-close-shift-btn-confirm psh-hidden" data-psh-close-final>
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
                </div>
                <div class="modal-body">
                    <div id="deliveryModalHint" class="alert alert-warning d-none mb-3"></div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">رقم العميل</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="customer_phone">
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

    <!-- متابعة التوصيل داخل شاشة الكاشير -->
    <div class="offcanvas offcanvas-end pos-delivery-queue" tabindex="-1" id="posDeliveryQueue"
        aria-labelledby="posDeliveryQueueLabel" data-bs-scroll="false">
        <div class="offcanvas-header pos-delivery-queue__header">
            <h5 class="offcanvas-title" id="posDeliveryQueueLabel">طلبات التوصيل</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button>
        </div>
        <div class="pos-delivery-queue__summary" aria-live="polite">
            <button type="button" class="pos-delivery-filter is-active" data-delivery-filter="all">
                الكل <strong id="posDeliveryCountActive">0</strong>
            </button>
            <button type="button" class="pos-delivery-filter" data-delivery-filter="waiting">
                في التجهيز <strong id="posDeliveryCountWaiting">0</strong>
            </button>
            <button type="button" class="pos-delivery-filter" data-delivery-filter="out">
                مع المندوب <strong id="posDeliveryCountOut">0</strong>
            </button>
        </div>
        <div class="offcanvas-body pos-delivery-queue__body">
            <div class="pos-delivery-queue__state" id="posDeliveryQueueState">جارٍ تحميل الطلبات…</div>
            <div class="pos-delivery-order-list d-none" id="posDeliveryOrderList"></div>
        </div>
    </div>

    <div class="modal fade pos-delivery-action-modal" id="posDeliveryDispatchModal" tabindex="-1"
        aria-labelledby="posDeliveryDispatchLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <form class="modal-content" id="posDeliveryDispatchForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="posDeliveryDispatchLabel">خرج للتوصيل</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="order_id" value="">
                    <div class="pos-delivery-worker-switch" role="group" aria-label="نوع المندوب">
                        <label><input type="radio" name="worker_mode" value="registered" checked><span>مندوب مسجل</span></label>
                        <label><input type="radio" name="worker_mode" value="external"><span>مندوب خارجي</span></label>
                    </div>
                    <div class="mt-3" data-worker-mode-panel="registered">
                        <label class="form-label" for="posDeliveryWorkerSelect">المندوب</label>
                        <select class="form-select" id="posDeliveryWorkerSelect" name="delivery_worker_id" required></select>
                    </div>
                    <div class="mt-3 d-none" data-worker-mode-panel="external">
                        <label class="form-label" for="posDeliveryExternalName">الاسم <small>اختياري</small></label>
                        <input class="form-control" id="posDeliveryExternalName" name="driver_name" maxlength="160"
                            placeholder="مثال: مندوب خارجي">
                        <label class="form-label mt-2" for="posDeliveryExternalPhone">الهاتف <small>اختياري</small></label>
                        <input class="form-control" id="posDeliveryExternalPhone" name="driver_phone" maxlength="60" inputmode="tel">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">رجوع</button>
                    <button type="submit" class="btn pos-delivery-primary-action">تأكيد الخروج</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade pos-delivery-action-modal" id="posDeliveryFailureModal" tabindex="-1"
        aria-labelledby="posDeliveryFailureLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <form class="modal-content" id="posDeliveryFailureForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="posDeliveryFailureLabel">تعذر التسليم</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="order_id" value="">
                    <label class="form-label" for="posDeliveryFailureReason">السبب</label>
                    <textarea class="form-control" id="posDeliveryFailureReason" name="reason" rows="3" maxlength="500"
                        placeholder="مثال: العميل لم يرد" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">رجوع</button>
                    <button type="submit" class="btn btn-danger">تسجيل التعذر</button>
                </div>
            </form>
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
    <script src="js/pos_order_draft.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_order_draft.js') ?: 1) ?>"></script>
    <script src="js/pos_order_api.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_order_api.js') ?: 1) ?>"></script>
    <script src="js/pos_virtual_keyboard.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_virtual_keyboard.js') ?: 1) ?>"></script>
    <script src="js/pos_barcode.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_barcode.js') ?: 1) ?>"></script>
    <script src="js/pos_customer.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_customer.js') ?: 1) ?>"></script>
    <script src="js/pos_delivery.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_delivery.js') ?: 1) ?>"></script>
    <script src="js/pos_delivery_queue.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_delivery_queue.js') ?: 1) ?>"></script>
    <script src="js/pos_shift_expenses.js?v=<?= (int) (@filemtime(__DIR__ . '/../js/pos_shift_expenses.js') ?: 1) ?>"></script>

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

            // وظيفة إغلاق الشيفت (legacy fallback when handover wizard unavailable)
            window.closeShift = function () {
                if (window.PosShiftCountWizard && typeof window.PosShiftCountWizard.finalizeClose === 'function') {
                    window.PosShiftCountWizard.finalizeClose();
                    return;
                }
                const expensePayload = (typeof window.posShiftExpenseClosePayload === 'function')
                    ? window.posShiftExpenseClosePayload()
                    : { expenses: 0, exp_notes: '' };
                const expenses = expensePayload.expenses || 0;
                const expNotes = expensePayload.exp_notes || '';
                const fundAfter = $('#pshCloseAmount').val() || $('#shift_fund_after').val() || 0;
                const notes = $('#shift_notes').val() || '';

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
                form.append($('<input>', { type: 'hidden', name: 'expenses', value: expenses }));
                form.append($('<input>', { type: 'hidden', name: 'exp_notes', value: expNotes }));
                form.append($('<input>', { type: 'hidden', name: 'cash', value: fundAfter }));
                form.append($('<input>', { type: 'hidden', name: 'fund_after', value: fundAfter }));
                form.append($('<input>', { type: 'hidden', name: 'notes', value: notes }));
                if (window.POSMAIN_SHIFT_CSRF_TOKEN) {
                    form.append($('<input>', { type: 'hidden', name: 'csrf_token', value: window.POSMAIN_SHIFT_CSRF_TOKEN }));
                }
                $('body').append(form);
                form.submit();
            };

            // Delivery UI handled by js/pos_delivery.js
        });
    </script>
    <!-- submitPOS is owned by js/pos_barcode.js + js/pos_order_api.js -->
    <div class="modal fade pos-success-modal-fade" id="posOrderSuccessModal" tabindex="-1"
        aria-labelledby="posOrderSuccessTitle" aria-hidden="true" style="display:none">
        <div class="modal-dialog modal-dialog-centered pos-success-dialog">
            <div class="modal-content pos-success-content">
                <div class="pos-success-body">
                    <div class="pos-success-icon" aria-hidden="true">&#10003;</div>
                    <h3 class="pos-success-title" id="posOrderSuccessTitle">تم بنجاح!</h3>
                    <p class="pos-success-text" id="posOrderSuccessText"></p>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        window.POSShowOrderSuccess = function(message) {
            var modalEl = document.getElementById('posOrderSuccessModal');
            var textEl = document.getElementById('posOrderSuccessText');
            if (!modalEl || !textEl) {
                return;
            }

            textEl.textContent = message || 'تم حفظ الطلب بنجاح';
            window.POS_SUCCESS_HOLD = true;

            var durationMs = 1500;
            var hideTimer = null;

            function releaseHold() {
                window.POS_SUCCESS_HOLD = false;
            }

            function showModal() {
                if (!window.bootstrap || typeof window.bootstrap.Modal !== 'function') {
                    window.setTimeout(showModal, 30);
                    return;
                }

                modalEl.style.display = '';
                var instance = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                modalEl.addEventListener('hidden.bs.modal', function onHidden() {
                    modalEl.removeEventListener('hidden.bs.modal', onHidden);
                    if (hideTimer) {
                        window.clearTimeout(hideTimer);
                        hideTimer = null;
                    }
                    releaseHold();
                }, { once: true });

                instance.show();
                hideTimer = window.setTimeout(function() {
                    var current = window.bootstrap.Modal.getInstance(modalEl);
                    if (current) {
                        current.hide();
                    } else {
                        releaseHold();
                    }
                }, durationMs);
            }

            showModal();
        };
    })();
    </script>


    <!-- Recent Orders Modal -->
    <div class="modal fade pos-recent-orders-modal-fade" id="recentOrdersModal" tabindex="-1"
        aria-labelledby="recentOrdersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered pos-recent-orders-dialog">
            <div class="modal-content pos-recent-orders-content">
                <div class="modal-header pos-recent-orders-header">
                    <button type="button" class="btn-close pos-recent-orders-close" data-bs-dismiss="modal"
                        aria-label="إغلاق"></button>
                    <h5 class="modal-title" id="recentOrdersModalLabel">
                        الطلبات الأخيرة
                    </h5>
                </div>
                <div class="modal-body pos-recent-orders-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered mb-0 pos-recent-orders-table">
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
                <div class="modal-footer pos-recent-orders-footer">
                    <button type="button" class="btn btn-outline-primary d-none" id="recentOrdersLoadMoreBtn">
                        <i class="fas fa-chevron-down me-1"></i>تحميل المزيد
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إغلاق
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Order Customer Detail Modal -->
    <div class="modal fade pos-recent-order-customer-modal-fade" id="recentOrderCustomerModal" tabindex="-1"
        aria-labelledby="recentOrderCustomerModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered pos-recent-order-customer-dialog">
            <div class="modal-content pos-recent-order-customer-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="recentOrderCustomerModalLabel">
                        <i class="fas fa-user me-2"></i>بيانات العميل
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body pos-recent-order-customer-body" id="recentOrderCustomerBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">جاري التحميل...</span>
                        </div>
                        <p class="mt-2 mb-0">جاري تحميل بيانات العميل...</p>
                    </div>
                </div>
                <div class="modal-footer pos-recent-order-customer-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إغلاق
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Paid Order Reversal Modal -->
    <div class="modal fade pos-paid-reversal-modal-fade" id="paidOrderReversalModal" tabindex="-1"
        aria-labelledby="paidOrderReversalModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered pos-paid-reversal-dialog">
            <div class="modal-content pos-paid-reversal-content">
                <div class="modal-header pos-paid-reversal-header">
                    <button type="button" class="btn-close pos-paid-reversal-close" data-bs-dismiss="modal"
                        aria-label="إغلاق"></button>
                    <div class="pos-paid-reversal-heading">
                        <h5 class="modal-title" id="paidOrderReversalModalLabel">
                            <i class="fas fa-undo me-2"></i>استرداد مبلغ أو إلغاء طلب مدفوع
                        </h5>
                        <p class="pos-paid-reversal-subtitle mb-0">اختر طريقة صرف الاسترداد، وحدد أثر المخزون، ثم اكتب السبب.</p>
                    </div>
                </div>
                <div class="modal-body pos-paid-reversal-body">
                    <div class="alert alert-danger d-none mb-3" id="paidReversalValidationAlert" role="alert"></div>
                    <label class="form-label" for="paid-reversal-action">ما العملية المطلوبة؟</label>
                    <select id="paid-reversal-action" class="form-select pos-paid-reversal-control mb-3"></select>
                    <div id="paid-reversal-tender-section">
                        <div class="alert alert-info border mb-3" id="paid-reversal-balance-summary"></div>
                        <label class="form-label" for="paid-reversal-refund-mode">نطاق الاسترداد</label>
                        <select id="paid-reversal-refund-mode" class="form-select pos-paid-reversal-control mb-3">
                            <option value="full">كل المبلغ المتبقي</option>
                            <option value="items">أصناف وكميات محددة</option>
                            <option value="amount">مبلغ محدد</option>
                        </select>
                        <div class="d-none mb-3" id="paid-reversal-amount-section">
                            <label class="form-label" for="paid-reversal-amount">المبلغ المطلوب استرداده</label>
                            <div class="input-group">
                                <input id="paid-reversal-amount" class="form-control pos-paid-reversal-control"
                                    type="number" min="0.01" step="0.01" inputmode="decimal" autocomplete="off">
                                <span class="input-group-text">ج.م</span>
                            </div>
                            <div class="form-text">سيُوزع المبلغ على سطور البيع الأصلية بالترتيب مع نسب الضريبة والخصم المحفوظة.</div>
                        </div>
                        <div class="d-none mb-3" id="paid-reversal-items-section">
                            <label class="form-label">الأصناف والكميات المطلوب استردادها</label>
                            <div class="table-responsive border rounded">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">اختيار</th>
                                            <th scope="col">الصنف</th>
                                            <th scope="col">المتاح</th>
                                            <th scope="col">الكمية</th>
                                            <th scope="col">القيمة التقريبية</th>
                                        </tr>
                                    </thead>
                                    <tbody id="paid-reversal-items-list"></tbody>
                                </table>
                            </div>
                            <div class="form-text" id="paid-reversal-items-total"></div>
                        </div>
                        <div class="alert alert-light border mb-3" id="paid-reversal-original-tenders"></div>
                        <label class="form-label" for="paid-reversal-tender">طريقة صرف مبلغ الاسترداد</label>
                        <select id="paid-reversal-tender" class="form-select pos-paid-reversal-control mb-2">
                            <option value="">اختر طريقة صرف الاسترداد</option>
                        </select>
                        <label class="form-label" for="paid-reversal-reference">مرجع الاسترداد الخارجي (اختياري)</label>
                        <input id="paid-reversal-reference" class="form-control pos-paid-reversal-control"
                            type="text" maxlength="120" autocomplete="off"
                            placeholder="رقم عملية البطاقة أو البنك بعد تنفيذها">
                        <div class="form-text mb-3" id="paid-reversal-settlement-hint">
                            الاسترداد غير النقدي بدون مرجع سيُسجل كعملية معلقة حتى إدخال مرجع التسوية.
                        </div>
                    </div>
                    <label class="form-label" for="paid-reversal-policy">ماذا يحدث للمخزون؟</label>
                    <select id="paid-reversal-policy" class="form-select pos-paid-reversal-control mb-3">
                        <option value="waste">لا تُعِد المرتجع إلى المخزون</option>
                        <option value="return_to_stock">أعِد المرتجع إلى المخزون</option>
                    </select>
                    <label class="form-label" for="paid-reversal-reason">سبب الاسترداد أو الإلغاء</label>
                    <textarea id="paid-reversal-reason" class="form-control pos-paid-reversal-control" rows="3"
                        maxlength="255" placeholder="مثال: طلب خاطئ من العميل"></textarea>
                </div>
                <div class="modal-footer pos-paid-reversal-footer">
                    <button type="button" class="btn pos-paid-reversal-btn-cancel" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="button" class="btn pos-paid-reversal-btn-submit" id="paidReversalSubmitBtn">
                        <i class="fas fa-check me-1"></i>تأكيد العملية
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            window.reprintOrder = function(orderId) {
                const openReceipt = function () {
                    window.open('print/receipt.php?order_id=' + orderId, '_blank');
                };
                if (window.POSMAIN && typeof window.POSMAIN.can === 'function' && window.POSMAIN.can('pos.reprint.receipt') === true) {
                    openReceipt();
                    return;
                }
                if (window.POSMAIN && typeof window.POSMAIN.requestManagerOverride === 'function') {
                    window.POSMAIN.requestManagerOverride('pos.reprint.receipt', {
                        message: 'يتطلب اعتماد مدير لإعادة طباعة الإيصال',
                        target_type: 'order',
                        target_id: orderId,
                    }).done(openReceipt);
                    return;
                }
                openReceipt();
            };

        });
    </script>
