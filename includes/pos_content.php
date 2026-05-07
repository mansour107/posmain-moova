<?php
if (!isset($action_url)) {
    $action_url = "do/doadd_invoice.php";
}
?>
<!-- Main Content -->
<form action="<?= $action_url ?>" method="post" id="posForm">
        <div class="container-fluid h-100" style="height: calc(100vh - 60px);">
            <div class="row h-100 g-1">
                <!-- القسم الأيمن - معلومات الطلب -->
                <div class="col-lg-4">
                    <div class="card shadow-sm h-100 d-flex flex-column">
                        <div
                            class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                            معلومات الطلب
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

                            <!-- نوع الطلب -->
                            <div class="mb-2">
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" id="age1" name="age" value="1" checked>
                                    <label class="btn btn-outline-primary btn-sm" for="age1">
                                        تيك اواي
                                    </label>

                                                                                                                                                        <input type="radio" class="btn-check" id="age2" name="age" value="2"
                                        <?php if (isset($_GET['table'])) {echo " checked ";} ?>>
                                    <label class="btn btn-outline-primary btn-sm" for="age2">
                                        طاولة
                                    </label>

                                    <input type="radio" class="btn-check" id="age3" name="age" value="3">
                                    <label class="btn btn-outline-primary btn-sm" for="age3"
                                        onclick="openDeliveryModal()">
                                        دليفري
                                    </label>
                                </div>
                            </div>

                            <!-- الباركود والبحث -->
                            <div class="row g-1 mb-2">
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

                            <!-- الحقول الثانوية - في الناحية التانية -->
                            <div class="row g-1 mb-2">
                                <!-- التواريخ -->
                                <div class="col-4">
                                    <input type="date" name="pro_date" class="form-control form-control-sm"
                                        value="<?= $posdate ?>" title="التاريخ" style="font-size: 0.75rem;">
                                </div>
                                <div class="col-4">
                                    <input type="date" name="accural_date" class="form-control form-control-sm"
                                        value="<?php echo isset($_GET['edit']) ? $rowed['accural_date'] : date('Y-m-d'); ?>"
                                        title="تاريخ الاستحقاق" style="font-size: 0.75rem;">
                                </div>

                                <!-- اختيار الطاولة -->
                                <div class="col-4">
                                    <button type="button" class="btn btn-outline-primary btn-sm w-100"
                                        data-bs-toggle="modal" data-bs-target="#tablesModal" title="اختر الطاولة"
                                        style="font-size: 0.75rem;">
                                        
                                        <span id="selected_table_display">اختر طاولة</span>
                                    </button>
                                    <input type="hidden" id="selected_table_id" name="table_id" value="0">
                                    <input type="hidden" id="selected_table_name" name="table_name" value="">
                                    <input type="hidden" id="selected_order_id" name="edit" value="0">
                                </div>
                            </div>

                            <!-- الحقول الصغيرة -->
                            <div class="row g-1 mb-2">
                                <!-- المخزن -->
                                <div class="col-3">
                                    <select name="store_id" class="form-select form-select-sm" title="المخزن"
                                        style="font-size: 0.75rem;" required>
                                        <?php
                                        $resstore = $conn->query("SELECT * FROM `acc_head` WHERE is_stock =1 AND isdeleted = 0;");
                                        $first = true;
                                        while ($rowstore = $resstore->fetch_assoc()) { 
                                            $selected = '';
                                            if($rowstg['def_pos_store'] == $rowstore['id']){
                                                $selected = "selected";
                                            } elseif ($first && empty($rowstg['def_pos_store'])) {
                                                $selected = "selected";
                                            }
                                            $first = false;
                                        ?>
                                        <option <?= $selected ?> value="<?= $rowstore['id'] ?>">
                                            <?= $rowstore['aname'] ?></option>
                                        <?php } ?>
                                    </select>
                                </div>

                                <!-- الموظف -->
                                <div class="col-3">
                                    <select name="emp_id" class="form-select form-select-sm" title="الموظف"
                                        style="font-size: 0.75rem;" required>
                                        <?php
                                        $resemp = $conn->query("SELECT * FROM `acc_head` WHERE parent_id = 35 AND is_basic = 0 AND isdeleted = 0;");
                                        $first_emp = true;
                                        while ($rowemp = $resemp->fetch_assoc()) { 
                                            $selected = '';
                                            if($rowstg['def_pos_employee'] == $rowemp['id']){
                                                $selected = "selected";
                                            } elseif(isset($_GET['edit']) && $rowed['emp_id'] == $rowemp['id']){
                                                $selected = "selected";
                                            } elseif ($first_emp && empty($rowstg['def_pos_employee']) && !isset($_GET['edit'])) {
                                                $selected = "selected";
                                            }
                                            $first_emp = false;
                                        ?>
                                        <option <?= $selected ?> value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                </div>

                                <!-- العميل -->
                                <div class="col-3">
                                    <select name="acc2_id" class="form-select form-select-sm" title="العميل"
                                        style="font-size: 0.75rem;" required>
                                        <?php
                                        $resclient = $conn->query("SELECT * FROM `acc_head` WHERE code like '122%'  AND is_basic = 0 AND isdeleted = 0;");
                                        if(isset($_GET['edit'])){$rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();};
                                        $first_client = true;
                                        while ($rowclient = $resclient->fetch_assoc()) { 
                                            $selected = '';
                                            if($rowstg['def_pos_client'] == $rowclient['id']){
                                                $selected = "selected";
                                            } elseif(isset($_GET['edit']) && $rowed['acc1'] == $rowclient['id']){
                                                $selected = "selected";
                                            } elseif ($first_client && empty($rowstg['def_pos_client']) && !isset($_GET['edit'])) {
                                                $selected = "selected";
                                            }
                                            $first_client = false;
                                        ?>
                                        <option <?= $selected ?> value="<?= $rowclient['id'] ?>">
                                            <?= $rowclient['aname'] ?></option>
                                        <?php } ?>
                                    </select>
                                </div>

                                <!-- الصندوق -->
                                <div class="col-3">
                                    <select name="fund_id" class="form-select form-select-sm" title="الصندوق"
                                        style="font-size: 0.75rem;" required>
                                        <?php
                                        if(isset($_GET['edit'])){$rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();};
                                        $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0;");
                                        $first_fund = true;
                                        while ($rowfund = $resfund->fetch_assoc()) { 
                                            $selected = '';
                                            if($rowstg['def_pos_fund'] == $rowfund['id']){
                                                $selected = "selected";
                                            } elseif((isset($_GET['edit'])) && $rowed['acc_fund'] == $rowfund['id']){
                                                $selected = "selected";
                                            } elseif ($first_fund && empty($rowstg['def_pos_fund']) && !isset($_GET['edit'])) {
                                                $selected = "selected";
                                            }
                                            $first_fund = false;
                                        ?>
                                        <option <?= $selected ?> value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>

                            <!-- الأصناف المُضافة -->
                            <div class="mb-2 flex-grow-1 d-flex flex-column">
                                <div class="card flex-grow-1 d-flex flex-column border-primary">
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
                                                      WHERE fd.pro_id = $id AND fd.isdeleted = 0";
                                            $resdet = $conn->query($sqldet);
                                            $x = 0;
                                            while ($rowdet = $resdet->fetch_assoc()) {
                                                $x++;
                                                $item_name = $rowdet['item_name'] ?: 'صنف غير معروف';
                                                // Fix: Use correct column names from database schema
                                                // qty should be qty_out (for sales) or qty_in - qty_out
                                                $qty = floatval($rowdet['qty_out']) - floatval($rowdet['qty_in']);
                                                $price = floatval($rowdet['price']);
                                                // Fix: Use det_value instead of val
                                                $subtotal = floatval($rowdet['det_value']);
                                                $barcode = $rowdet['barcode'] ?: $rowdet['item_id'];
                                                ?>
                                        <div class="card mb-1 item-card-order shadow-sm border-start border-3"
                                            data-itemid="<?= $barcode ?>"
                                            style="border-color: #0a7ea4 !important; max-width: 100%;">
                                            <div class="card-body p-1">
                                                <div class="d-flex align-items-center gap-1"
                                                    style="font-size: 0.75rem;">
                                                    <span class="badge bg-primary"
                                                        style="font-size: 0.7rem; min-width: 25px;">#<?= $x ?></span>

                                                    <div style="flex: 1; min-width: 0;">
                                                        <input type="hidden" value='<?= $rowdet['item_id'] ?>'
                                                            name="itmname[]">
                                                        <input type="hidden" class="barcode" value="<?= $barcode ?>">
                                                        <div class="text-truncate fw-bold" style="font-size: 0.75rem;"
                                                            title="<?= $item_name ?>"><?= $item_name ?></div>
                                                    </div>

                                                    <div style="width: 65px;">
                                                        <small class="d-block text-center text-muted"
                                                            style="font-size: 0.6rem; margin-bottom: 1px;">كمية</small>
                                                        <input type="number"
                                                            class="form-control form-control-sm text-center quantityInput nozero fw-bold"
                                                            value="<?= $qty ?>" name="itmqty[]" min="1" step="0.1"
                                                            style="width: 100%; font-size: 0.75rem; padding: 3px; border: 2px solid #ff6347; height: 26px;"
                                                            title="الكمية">
                                                        <input type="hidden" name="u_val[]" value="1">
                                                    </div>

                                                    <div style="width: 55px;">
                                                        <small class="d-block text-center text-muted"
                                                            style="font-size: 0.6rem; margin-bottom: 1px;">سعر</small>
                                                        <input type="number"
                                                            class="form-control form-control-sm text-center priceInput nozero"
                                                            value="<?= number_format($price, 2, '.', '') ?>"
                                                            name="itmprice[]" step="0.01"
                                                            style="width: 100%; font-size: 0.7rem; padding: 3px; height: 26px;"
                                                            title="السعر">
                                                    </div>

                                                    <div style="width: 60px;">
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
                            <div class="card border-primary mt-1">
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
                                    <div class="d-flex gap-1 justify-content-between align-items-center">
                                        <button type="button" class="btn btn-primary flex-grow-1" data-bs-toggle="modal"
                                            data-bs-target="#paymentModal" style="font-size: 0.8rem; padding: 0.4rem;">
                                            <i class="fas fa-money-bill-wave me-1"></i>دفع وحفظ
                                            <div style="font-size: 0.7rem; font-weight: bold;" id="total_display_btn">
                                                0.00 ج.م</div>
                                        </button>
                                        <div class="d-flex align-items-center gap-1">
                                            <!-- <button type="button" class="btn btn-outline-info btn-sm recent-orders-btn" title="الطلبات الأخيرة">
                                                <i class="fas fa-history"></i>
                                            </button>
                                            <a href="tables.php" class="btn btn-outline-primary" style="font-size: 0.7rem; padding: 0.4rem 0.6rem;" title="الطاولات">
                                                <i class="fas fa-th-large"></i>
                                            </a> -->
                                            <div id="selectedTableDisplay" class="badge bg-primary text-white"
                                                style="font-size: 0.8rem; display: none;">
                                                <i class="fas fa-chair me-1"></i><span id="selectedTableName"></span>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger"
                                            style="font-size: 0.7rem; padding: 0.4rem 0.6rem;"
                                            onclick="clearAllItems();" title="مسح">
                                            <i class="fas fa-eraser"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- القسم الأوسط - الأصناف -->
                <div class="col-lg-8">
                    <div class="card shadow-sm items-section-card">
                        <div class="card-header bg-primary text-white py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">
                                    <i class="fas fa-boxes me-2"></i>الأصناف المتاحة
                                </h6>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="input-group" style="width: 280px;">
                                       
                                        <input type="text" class="scnd form-control border-start-0" id="itemFilterInput"
                                            placeholder="فلترة الأصناف..." autocomplete="off"
                                            title="اضغط Ctrl+F للتركيز | Escape للمسح"
                                            style="font-size: 0.9rem;">
                                        <button class="btn btn-outline-secondary" type="button" id="clearFilter"
                                            title="مسح الفلتر">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
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
                            <div class="row g-3" id="itemsGrid">
                                <?php
                            // استعلام مع join للحصول على الصورة من جدول imgs
                            $sqlitems = "SELECT m.*, i.iname as img_filename
                                        FROM myitems m 
                                        LEFT JOIN imgs i ON i.itemid = m.id 
                                        WHERE m.isdeleted = 0 
                                        GROUP BY m.id
                                        ORDER BY m.iname";
                            $resitems = $conn->query($sqlitems);
                            
                            if ($resitems && $resitems->num_rows > 0) {
                                while ($rowitem = $resitems->fetch_assoc()) {
                                    $itemId = isset($rowitem['id']) ? $rowitem['id'] : '';
                                    $itemName = isset($rowitem['iname']) ? htmlspecialchars($rowitem['iname']) : 'صنف غير محدد';
                                    
                                    // تحديد السعر - جرب price1 أو price
                                    $itemPrice = 0;
                                    if (isset($rowitem['price1']) && !empty($rowitem['price1'])) {
                                        $itemPrice = floatval($rowitem['price1']);
                                    } elseif (isset($rowitem['price']) && !empty($rowitem['price'])) {
                                        $itemPrice = floatval($rowitem['price']);
                                    }
                                    
                                    $itemBarcode = isset($rowitem['barcode']) ? htmlspecialchars($rowitem['barcode']) : '';
                                    $itemCategory = isset($rowitem['group1']) ? $rowitem['group1'] : '';
                                    
                                    // الصورة من جدول imgs
                                    $itemImage = '';
                                    if (isset($rowitem['img_filename']) && !empty($rowitem['img_filename'])) {
                                        $itemImage = 'uploads/' . htmlspecialchars($rowitem['img_filename']);
                                    }
                                    
                                    $itemDesc = isset($rowitem['info']) ? htmlspecialchars($rowitem['info']) : '';
                            ?>
                                <div class="col-lg-3  col-md-4 col-sm-6 item-wrapper"
                                    data-category="<?= $itemCategory ?>">
                                    <div class="card item-card itemButton  shadow-sm border-0"
                                        data-item-id="<?= $itemId ?>" data-item-name="<?= $itemName ?>"
                                        data-item-price="<?= $itemPrice ?>" data-item-barcode="<?= $itemBarcode ?>"
                                        data-item-desc="<?= $itemDesc ?>" style="transition: all 0.3s ease;">
                                        <div class="card-body p-2 text-center">
                                            <!-- الصورة -->
                                            <div class="item-image-container mb-2 ratio ratio-1x1 rounded overflow-hidden"
                                                style="cursor: pointer; background: #f8f9fa;">
                                                <?php if (!empty($itemImage) && file_exists($itemImage)): ?>
                                                <img src="<?= $itemImage ?>"
                                                    class="item-image-click object-fit-cover w-100 h-100"
                                                    style="width: 100%; height: 100%;">
                                                <?php else: ?>
                                                <div
                                                    class="d-flex align-items-center justify-content-center item-image-click">
                                                    <i class="fas fa-utensils fa-3x text-primary opacity-50"></i>
                                                </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- اسم الصنف -->
                                            <h6 class="card-title text-truncate mb-1" style="font-size: 0.85rem;"
                                                title="<?= $itemName ?>">
                                                <?= $itemName ?>
                                            </h6>

                                            <!-- السعر -->
                                            <div class="bg-primary rounded px-2 py-1 mb-2">
                                                <p class="card-text fw-bold text-white mb-0" style="font-size: 1.1rem;">
                                                    <?= number_format($itemPrice, 2) ?> <span
                                                        class="text-white opacity-75">ج.م</span>
                                                </p>
                                            </div>

                                            <!-- زر التفاصيل -->
                                            <button class="btn btn-outline-primary btn-sm w-100 item-details-btn"
                                                style="font-size: 0.75rem;">
                                                <i class="fas fa-info-circle me-1"></i>التفاصيل
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php 
                                }
                            } else {
                                echo '<div class="col-12 text-center text-muted"><p>لا توجد أصناف متاحة</p></div>';
                            }
                            ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Modal الدفع -->
    <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="paymentModalLabel">
                       الدفع والإجماليات
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- الإجمالي -->
                        <div class="col-12">
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
                        <div class="col-12">
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
                        <div class="col-12">
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
                        <div class="col-12">
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
                                                        <select class="form-select" id="payment_fund_id">
                                                            <?php
                                                            $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund = 1 AND is_basic = 0 AND isdeleted = 0 ORDER BY aname");
                                                            while ($rowfund = $resfund->fetch_assoc()) { 
                                                                $selected = '';
                                                                if($rowstg['def_pos_fund'] == $rowfund['id']){
                                                                    $selected = "selected";
                                                                }
                                                            ?>
                                                            <option <?= $selected ?> value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?></option>
                                                            <?php } ?>
                                                        </select>
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
                                                        <select class="form-select" id="payment_bank_id">
                                                            <option value="">-- اختر البنك --</option>
                                                            <?php
                                                            $resbank = $conn->query("SELECT * FROM `acc_head` WHERE (parent_id = 124 OR code LIKE '124%') AND is_basic = 0 AND isdeleted = 0 ORDER BY aname");
                                                            while ($rowbank = $resbank->fetch_assoc()) { ?>
                                                            <option value="<?= $rowbank['id'] ?>"><?= $rowbank['aname'] ?></option>
                                                            <?php } ?>
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
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <?php if(isset($id)): ?>
                    <button type="button" class="btn btn-warning text-dark fw-bold" onclick="submitPOS('save');">
                        <i class="fas fa-edit me-1"></i>حفظ التعديل
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-success" onclick="submitPOS('save');">
                       حفظ الطلب
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="submitPOS('cash');">
                        حفظ وطباعة
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
                    <div class="row g-3" id="tablesGrid">
                        <?php
                        // Get all tables
                        $restables = $conn->query("SELECT t.*, 
                            (SELECT COUNT(*) FROM ot_head o 
                             WHERE o.info LIKE CONCAT('%', t.tname, '%') 
                             AND o.pro_tybe = 9 
                             AND o.isdeleted = 0 
                             AND o.fat_net > 0) as has_active_order
                        FROM tables t 
                        WHERE t.isdeleted = 0 
                        ORDER BY t.tname");
                        
                        if ($restables && $restables->num_rows > 0) {
                            while ($rowtable = $restables->fetch_assoc()) {
                                $tableId = $rowtable['id'];
                                $tableName = htmlspecialchars($rowtable['tname']);
                                $hasActiveOrder = $rowtable['has_active_order'] > 0;
                                $tableCase = $hasActiveOrder ? 1 : 0; // 1 for occupied, 0 for available
                                
                                // Update table status in database if needed
                                if ($tableCase != $rowtable['table_case']) {
                                    $conn->query("UPDATE tables SET table_case = $tableCase WHERE id = $tableId");
                                }
                                
                                // Set status class and text
                                $statusClass = $hasActiveOrder ? 'btn-danger' : 'btn-success';
                                $statusIcon = $hasActiveOrder ? 'fa-utensils' : 'fa-check-circle';
                                $statusText = $hasActiveOrder ? 'مشغولة' : 'متاحة';
                                
                                // Get order details if table is occupied
                                $orderTotal = 0;
                                $orderId = null;
                                if ($hasActiveOrder) {
                                    $orderQuery = $conn->query("
                                        SELECT id, fat_net 
                                        FROM ot_head 
                                        WHERE info LIKE '%$tableName%' 
                                        AND pro_tybe = 9 
                                        AND isdeleted = 0
                                        AND fat_net > 0
                                        ORDER BY id DESC 
                                        LIMIT 1");
                                    if ($orderQuery && $orderQuery->num_rows > 0) {
                                        $orderData = $orderQuery->fetch_assoc();
                                        $orderId = $orderData['id'];
                                        $orderTotal = floatval($orderData['fat_net']);
                                    }
                                }
                        ?>
                        <div class="col-md-4 col-sm-6">
                            <button type="button"
                                class="btn <?= $statusClass ?> w-100 table-select-btn position-relative"
                                data-table-id="<?= $tableId ?>" data-table-name="<?= $tableName ?>"
                                data-table-case="<?= $tableCase ?>" data-order-id="<?= $orderId ?>"
                                style="min-height: 120px; font-size: 1.1rem;">
                                <div class="d-flex flex-column align-items-center justify-content-center">
                                    <i class="fas fa-utensils fa-2x mb-2"></i>
                                    <h6 class="mb-1"><?= $tableName ?></h6>
                                    <small class="d-flex align-items-center">
                                        <i class="fas <?= $statusIcon ?> me-1"></i>
                                        <?= $statusText ?>
                                    </small>
                                    <?php if ($tableCase != 0 && $orderTotal > 0): ?>
                                    <div class="mt-2 badge bg-white text-dark">
                                        <?= number_format($orderTotal, 2) ?> ج.م
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </button>
                        </div>
                        <?php
                            }
                        } else {
                            echo '<div class="col-12 text-center text-muted">
                                    <i class="fas fa-exclamation-circle fa-3x mb-3"></i>
                                    <p>لا توجد طاولات متاحة</p>
                                  </div>';
                        }
                        ?>
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
                        <i class="fas fa-plus me-1"></i>إضافة للطلب
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
                        <i class="fas fa-check me-1"></i>تأكيد الطلب
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- زر عائم للطاولات -->
    <a href="tables.php" class="btn btn-primary position-fixed"
        style="bottom: 20px; right: 20px; z-index: 1000; border-radius: 50px; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.3);"
        title="عرض الطاولات">
        <i class="fas fa-th-large fa-lg"></i>
    </a>

    <!-- Scripts - jQuery (CDN for reliability) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        if (typeof jQuery === 'undefined') { 
            document.write('<script src="plugins/jquery/jquery.min.js"><\/script>'); 
        }
    </script>
    <script src="assets/libs/bootstrap.bundle.min.js"></script>
    <script src="js/pos_config_loader.js?v=<?= time() ?>"></script>
    <script src="js/pos_offline_adapter.js?v=<?= time() ?>"></script>
    <script src="js/pos_barcode.js?v=<?= time() ?>"></script>
    
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

            // وظائف الدليفري
            window.openDeliveryModal = function () {
                $('#deliveryModal').modal('show');
            };

            // البحث الديناميكي عن العملاء
            let searchTimeout;
            let lastSearchedPhone = '';
            
            $('#customer_phone').on('input', function() {
                const phone = $(this).val().trim();
                
                // إزالة الألوان عند بدء الكتابة
                $(this).removeClass('border-success border-info border-danger border-warning');
                
                // مسح النتائج السابقة إذا كان الرقم أقل من 3 أرقام
                if (phone.length < 3) {
                    $('#customer_result').html('');
                    $('#saveCustomerBtn').show().html('<i class="fas fa-save me-1"></i>حفظ');
                    $('#confirmOrderBtn').hide();
                    lastSearchedPhone = '';
                    return;
                }
                
                // تجنب البحث المتكرر عن نفس الرقم
                if (phone === lastSearchedPhone) {
                    return;
                }
                
                // إلغاء البحث السابق
                clearTimeout(searchTimeout);
                
                // بدء البحث بعد 500ms من التوقف عن الكتابة
                searchTimeout = setTimeout(function() {
                    if (phone.length >= 3 && phone !== lastSearchedPhone) {
                        lastSearchedPhone = phone;
                        searchCustomerDynamic(phone);
                    }
                }, 500);
            });

            function searchCustomerDynamic(phone) {
                // إضافة مؤشر بصري لحقل الإدخال
                $('#customer_phone').addClass('border-warning').attr('placeholder', 'جاري البحث...');
                
                // عرض مؤشر التحميل
                $('#customer_result').html(`
                    <div class="text-center py-2">
                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                            <span class="visually-hidden">جاري البحث...</span>
                        </div>
                        <small class="d-block mt-1 text-muted">جاري البحث عن العميل...</small>
                    </div>
                `);

                $.ajax({
                    url: 'do/search_customer.php',
                    method: 'POST',
                    data: { phone: phone },
                    success: function (data) {
                        console.log('Dynamic search response:', data);
                        
                        // إزالة مؤشر البحث
                        $('#customer_phone').removeClass('border-warning').attr('placeholder', 'أدخل رقم العميل (البحث يبدأ بعد 3 أرقام)');
                        
                        try {
                            var response = JSON.parse(data);
                            if (response.found) {
                                // عميل موجود - ملء الحقول
                                $('#customer_phone').addClass('border-success');
                                $('#customer_result').html(`
                                    <div class="alert alert-success mb-3">
                                        <i class="fas fa-check-circle me-2"></i>تم العثور على العميل
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">رقم الموبايل</label>
                                        <input type="text" class="form-control" id="customer_phone_display" value="${phone}" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">اسم العميل</label>
                                        <input type="text" class="form-control" id="customer_name" value="${response.name}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">العنوان</label>
                                        <textarea class="form-control" id="customer_address" rows="2">${response.address}</textarea>
                                    </div>
                                `);
                                $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ التعديل');
                                $('#confirmOrderBtn').show();
                            } else {
                                // عميل غير موجود - عرض حقول الإدخال
                                $('#customer_phone').addClass('border-info');
                                showNewCustomerForm();
                            }
                        } catch (e) {
                            console.error('Parse error in dynamic search:', e);
                            $('#customer_phone').addClass('border-danger');
                            showNewCustomerForm();
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Dynamic search AJAX Error:', error);
                        $('#customer_phone').removeClass('border-warning').addClass('border-danger').attr('placeholder', 'خطأ في البحث - حاول مرة أخرى');
                        showNewCustomerForm();
                    }
                });
            }

            window.searchCustomer = function () {
                const phone = $('#customer_phone').val().trim();
                if (!phone) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'تنبيه',
                        text: 'يرجى إدخال رقم العميل'
                    });
                    return;
                }
                
                if (phone.length < 3) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'تنبيه',
                        text: 'يرجى إدخال 3 أرقام على الأقل'
                    });
                    return;
                }
                
                searchCustomerDynamic(phone);
            };

            function showNewCustomerForm() {
                const currentPhone = $('#customer_phone').val().trim();
                $('#customer_result').html(`
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-user-plus me-2"></i>عميل جديد - يرجى إدخال بياناته
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">رقم الموبايل</label>
                        <input type="text" class="form-control" id="customer_phone_display" value="${currentPhone}" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم العميل</label>
                        <input type="text" class="form-control" id="customer_name" placeholder="اسم العميل" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">العنوان</label>
                        <textarea class="form-control" id="customer_address" rows="2" placeholder="عنوان العميل" required></textarea>
                    </div>
                `);
                $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ');
                $('#confirmOrderBtn').show();
            }

            // دالة مساعدة لتنظيف النماذج
            window.clearDeliveryForm = function () {
                $('#customer_phone').val('').removeClass('border-success border-info border-danger border-warning').attr('placeholder', 'أدخل رقم العميل (البحث يبدأ بعد 3 أرقام)');
                $('#customer_name').val('');
                $('#customer_address').val('');
                $('#customer_result').html('');
                $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ').show();
                $('#confirmOrderBtn').hide();
                lastSearchedPhone = ''; // إعادة تعيين متغير البحث
                clearTimeout(searchTimeout); // إلغاء أي بحث معلق
            };

            // دالة لإعادة تعيين نموذج الدليفري عند إغلاق المودال
            $('#deliveryModal').on('hidden.bs.modal', function () {
                clearDeliveryForm();
            });

            // Listen for changes on the 'age' radio buttons
            $('input[name="age"]').change(function(){
                if($(this).val() == '2') { // Table option
                    Swal.fire({
                        icon: 'info',
                        title: 'تنبيه',
                        text: 'يرجى اختيار طاولة',
                        confirmButtonText: 'حسناً'
                    });
                }
            });

            window.confirmDeliveryOrder = function () {
                const phone = $('#customer_phone').val().trim();
                const name = $('#customer_name').val().trim();
                const address = $('#customer_address').val().trim();

                if (!phone || !name || !address) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'تنبيه',
                        text: 'يرجى ملء جميع الحقول'
                    });
                    return;
                }

                // إضافة بيانات العميل للفورم
                $('<input>').attr({
                    type: 'hidden',
                    name: 'delivery_customer_name',
                    value: name
                }).appendTo('#posForm');
                $('<input>').attr({
                    type: 'hidden',
                    name: 'delivery_customer_phone',
                    value: phone
                }).appendTo('#posForm');
                $('<input>').attr({
                    type: 'hidden',
                    name: 'delivery_customer_address',
                    value: address
                }).appendTo('#posForm');

                // حفظ أو تحديث البيانات تلقائياً
                const isUpdate = $('#saveCustomerBtn').text().includes('تعديل');
                const url = isUpdate ? 'do/update_customer.php' : 'do/save_customer.php';

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: {
                        phone: phone,
                        name: name,
                        address: address
                    },
                    success: function (data) {
                        $('#deliveryModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'تم بنجاح',
                            text: 'تم تأكيد طلب الدليفري وحفظ بيانات العميل',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    },
                    error: function () {
                        $('#deliveryModal').modal('hide');
                        Swal.fire({
                            icon: 'success', // Assuming success even if error callback for some reason, or should be error? Original logic was alert success ish? No, original said 'confirmed' even on error?
                            title: 'تم',
                            text: 'تم تأكيد طلب الدليفري',
                            timer: 2000
                        });
                    }
                });
            };

            window.saveCustomerData = function () {
                const phone = $('#customer_phone').val().trim();
                const name = $('#customer_name').val().trim();
                const address = $('#customer_address').val().trim();

                if (!phone || !name || !address) {
                    alert('يرجى ملء جميع الحقول');
                    return;
                }

                const isUpdate = $('#saveCustomerBtn').text().includes('تعديل');
                const url = isUpdate ? 'do/update_customer.php' : 'do/save_customer.php';

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: {
                        phone: phone,
                        name: name,
                        address: address
                    },
                    success: function (data) {
                        console.log('Response:', data);
                        try {
                            var response = JSON.parse(data);
                            console.log('Parsed:', response);
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'تم بنجاح',
                                    text: isUpdate ? 'تم تحديث بيانات العميل بنجاح' : 'تم حفظ بيانات العميل بنجاح',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                $('#saveCustomerBtn').hide();
                                $('#confirmOrderBtn').show();
                            } else {
                                var errorMsg = 'حدث خطأ في ' + (isUpdate ? 'تحديث' : 'حفظ') +
                                    ' البيانات';
                                if (response.error) {
                                    errorMsg += ': ' + response.error;
                                }
                                Swal.fire({
                                    icon: 'error',
                                    title: 'خطأ',
                                    text: errorMsg
                                });
                                console.error('Save error:', response);
                            }
                        } catch (e) {
                            console.log('Parse error:', e);
                            console.log('Raw response:', data);
                            Swal.fire({
                                icon: 'error',
                                title: 'خطأ',
                                text: 'حدث خطأ في معالجة الاستجابة. تحقق من وحدة التحكم للتفاصيل.'
                            });
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error:', {
                            status: status,
                            error: error,
                            responseText: xhr.responseText
                        });
                        Swal.fire({
                            icon: 'error',
                            title: 'خطأ',
                            text: 'حدث خطأ في الاتصال: ' + error
                        });
                    }
                });
            };
        });
    </script>
    <script>
    // override submitPOS to ensure new logic is used immediately (bypassing cache)
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
        
        if (typeof validatePOSForm === 'function' && !validatePOSForm()) {
            return false;
        }
        
        // جمع بيانات الدفع
        let paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
        let paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
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
        if (paidCash > 0 && (!fundId || fundId == '0')) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يجب اختيار الصندوق عند الدفع كاش'
            });
            return false;
        }
        
        if (paidBank > 0 && (!bankId || bankId == '0' || bankId == '')) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يجب اختيار البنك عند الدفع صرافة'
            });
            return false;
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

        // Check for Edit ID
        let editId = $('#edit_order_id').val();
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
        }

        const existingSubmits = form.querySelectorAll('input[name="submit"]');
        existingSubmits.forEach(input => input.remove());
        
        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'submit';
        submitInput.value = action;
        form.appendChild(submitInput);
        
        let saveBtn = $("button:contains('حفظ الطلب')");
        let printBtn = $("button:contains('حفظ وطباعة')");
        
        if (saveBtn.length > 0) saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
        if (printBtn.length > 0) printBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
        
        $('#paymentModal').modal('hide');
        form.submit();
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
            $('#recentOrdersBtn2').click(function() {
                var offcanvas = new bootstrap.Offcanvas(document.getElementById('recentOrdersModal'));
                offcanvas.show();
                loadRecentOrders();
            });

            function loadRecentOrders() {
                $('#recentOrdersList').html('<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">جاري تحميل الطلبات...</p></td></tr>');
                
                $.ajax({
                    url: 'ajax/get_recent_orders.php',
                    method: 'GET',
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
                                        var statusBadge = order.status === 'ملغى' ? 'bg-danger' : 'bg-success';
                                        var typeBadge = order.type === 'دليفري' ? 'bg-info text-dark' : (order.type === 'طاولة' ? 'bg-warning text-dark' : 'bg-secondary');
                                        
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
                                                        ${order.status !== 'ملغى' ? `
                                                        <button type="button" class="btn btn-danger" onclick="deleteOrder(${order.id})" title="حذف">
                                                            <i class="fas fa-trash"></i>
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

            window.deleteOrder = function(orderId) {
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
                            data: { id: orderId },
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
                                            'فشل الحذف: ' + (response.error || 'خطأ غير معروف'),
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
        });
    </script>

