<?php

require_once 'InvoiceElementBase.php';

/**
 * فئة تفاصيل الفاتورة - تطبيق polymorphism
 * Invoice Details class - implementing polymorphism
 */
class InvoiceDetails extends InvoiceElementBase
{
    private $items = [];
    private $existingDetails = [];

    public function __construct($invoiceType, $isEditMode = false, $data = null, $conn = null)
    {
        parent::__construct($invoiceType, $isEditMode, $data, $conn);
        $this->loadItems();
        if ($this->isEditMode) {
            $this->loadExistingDetails();
        }
    }

    /**
     * تحميل الأصناف
     */
    private function loadItems()
    {
        // تم تعطيل تحميل الأصناف - يتم استخدام Live Search بدلاً منه
        // Items are now loaded via AJAX on-demand
        $this->items = [];
        return;
    }

    /**
     * تحميل التفاصيل الموجودة (في حالة التعديل)
     */
    private function loadExistingDetails()
    {
        if (!$this->conn || !$this->data) return;

        try {
            $invoiceId = intval($this->data['id'] ?? 0);
            if ($invoiceId > 0) {
                $query = "SELECT fd.*, mi.iname 
                         FROM fat_details fd 
                         JOIN myitems mi ON fd.item_id = mi.id 
                         WHERE fd.pro_id = ? AND fd.isdeleted = 0";
                $result = $this->executeSecureQuery($query, [$invoiceId], 'i');
                $this->existingDetails = $result->fetch_all(MYSQLI_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error loading existing details: " . $e->getMessage());
        }
    }

    /**
     * عرض تفاصيل الفاتورة
     */
    public function render()
    {
        ob_start();
        ?>
        <div class="row">
            <div class="col">
                <div class="table-responsive itemtable" style="height: 300px">
                    <table id="fatTable" class="table table-hover table-striped table-bordered">
                        <thead class="bg-light">
                            <tr class="bg- border">
                                <th>م</th>
                                <th class="col-5">اسم الصنف</th>
                                <th>الوحدة</th>
                                <th>كمية</th>
                                <th>سعر</th>
                                <th>خصم</th>
                                <th>القيمة</th>
                                <th></th>
                            </tr>
                        </thead>
                        <!-- صف إدخال الصنف الجديد - ثابت تحت الهيدر -->
                        <thead id="searchTable">
                            <tr>
                                <td class="col-1">
                                    <div class="tool">
                                        <a id="addNewElement" class="btn bg-lime-200 btn-sm hadi-white-flash"
                                           href="add_item.php" target="_blank">+</a>
                                        <div class="tooltext">إضافة صنف جديد</div>
                                    </div>
                                </td>

                                <!-- الصنف -->
                                <td id="itmTd" class="col-lg-5" style="position:relative;">
                                    <input type="text"
                                           id="itemSearchInput"
                                           class="form-control"
                                           placeholder="ابحث عن صنف (حرفين على الأقل)..."
                                           autocomplete="off"
                                           style="width:100%">
                                    <input type="hidden" id="selectedItemId">
                                    <div id="searchResults" style="position:absolute; left:0; right:0; top:100%; z-index:1050; background:white; border:1px solid #ddd; max-height:300px; overflow-y:auto; display:none; width:100%; box-shadow:0 4px 12px rgba(0,0,0,0.12);"></div>
                                    <input id="itmprice2" type="number" hidden onclick="sT(this)">
                                </td>

                                <!-- الوحدة -->
                                <td>
                                    <select id="inputUnitSelect" class="form-control form-control-sm" style="width:100px;">
                                        <option value="">اختر وحدة</option>
                                    </select>
                                </td>

                                <!-- الكمية -->
                                <td>
                                    <input type="number" hidden>
                                    <input id="itmqty" value="1.00" type="number" onclick="sT(this)"
                                           class="itmqty form-control form-control-sm nozero" style="width:90px;">
                                </td>

                                <!-- السعر -->
                                <td>
                                    <input id="itmprice" value="0.00" type="number" onclick="sT(this)"
                                           class="itmprice form-control form-control-sm nozero" style="width:90px;" step="0.001">
                                </td>

                                <!-- الخصم -->
                                <td>
                                    <input id="itmdisc" value="0.00" type="number" onclick="sT(this)"
                                           class="itmdisc form-control form-control-sm nozero" style="width:120px;" step="0.001">
                                </td>

                                <!-- القيمة -->
                                <td>
                                    <input readonly id="itmval" value="0.00" type="number"
                                           class="itmval bg-light form-control form-control-sm nozero" style="width:150px;" step="0.001">
                                </td>

                                <input id="itmprofit" hidden>
                                <td>
                                    <button type="button" id="addRow" class="btn btn-light">إضافة</button>
                                </td>
                            </tr>
                        </thead>
                        <!-- صفوف الفاتورة -->
                        <tbody id="itmrow">
                            <?php $this->renderExistingRows(); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<!-- Live Search Script -->
<style>
    #searchResults .search-result-item.search-result-active {
        background: #dbeafe !important;
    }
</style>
<script>
(function() {
    const searchInput = document.getElementById('itemSearchInput');
    const searchResults = document.getElementById('searchResults');
    const selectedItemId = document.getElementById('selectedItemId');
    const priceInput = document.getElementById('itmprice');

    let searchTimeout;
    let selectedItem = null;
    let highlightIndex = -1;

    function getResultItems() {
        return searchResults.querySelectorAll('.search-result-item');
    }

    function setHighlight(index) {
        const items = getResultItems();
        if (!items.length) {
            highlightIndex = -1;
            return;
        }
        highlightIndex = Math.max(0, Math.min(index, items.length - 1));
        items.forEach(function (el, i) {
            el.classList.toggle('search-result-active', i === highlightIndex);
        });
        items[highlightIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function clearHighlight() {
        highlightIndex = -1;
        getResultItems().forEach(function (el) {
            el.classList.remove('search-result-active');
        });
    }

    function bindResultItems() {
        clearHighlight();
        document.querySelectorAll('#searchResults .search-result-item').forEach(function (item, idx) {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                selectItem({
                    id: this.dataset.id,
                    name: this.dataset.name,
                    price: this.dataset.price,
                    barcode: this.dataset.barcode
                });
            });

            item.addEventListener('mouseenter', function() {
                highlightIndex = idx;
                getResultItems().forEach(function (el, i) {
                    el.classList.toggle('search-result-active', i === idx);
                });
            });
        });
    }

    // البحث المباشر
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();

        clearTimeout(searchTimeout);

        if (query.length < 2) {
            searchResults.style.display = 'none';
            clearHighlight();
            return;
        }

        searchTimeout = setTimeout(() => {
            searchResults.innerHTML = '<div style="padding:10px; text-align:center;">جاري البحث...</div>';
            searchResults.style.display = 'block';
            clearHighlight();

            fetch(`ajax/load_items_lazy.php?search=${encodeURIComponent(query)}&limit=20`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.items.length > 0) {
                        let html = '';
                        data.items.forEach(item => {
                            html += `
                                <div class="search-result-item"
                                     data-id="${item.id}"
                                     data-name="${item.iname}"
                                     data-price="${item.price1}"
                                     data-barcode="${item.barcode}"
                                     style="padding:10px; cursor:pointer; border-bottom:1px solid #eee;">
                                    <strong>${item.iname}</strong>
                                    ${item.name2 ? ' // ' + item.name2 : ''}
                                    <span style="float:left; color:#10b981;">${item.price1} ج.م</span>
                                </div>
                            `;
                        });
                        searchResults.innerHTML = html;
                        bindResultItems();
                    } else {
                        searchResults.innerHTML = '<div style="padding:10px; text-align:center; color:#999;">لا توجد نتائج</div>';
                        clearHighlight();
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    searchResults.innerHTML = '<div style="padding:10px; text-align:center; color:#ef4444;">خطأ في البحث</div>';
                    clearHighlight();
                });
        }, 300);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (searchResults.style.display === 'none') {
            return;
        }
        const items = getResultItems();
        if (!items.length) {
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (highlightIndex < 0) {
                setHighlight(0);
            } else if (highlightIndex < items.length - 1) {
                setHighlight(highlightIndex + 1);
            }
            return;
        }

        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (highlightIndex <= 0) {
                clearHighlight();
            } else {
                setHighlight(highlightIndex - 1);
            }
            return;
        }

        if (e.key === 'Enter') {
            var idx = highlightIndex >= 0 ? highlightIndex : 0;
            if (items[idx]) {
                e.preventDefault();
                var row = items[idx];
                selectItem({
                    id: row.dataset.id,
                    name: row.dataset.name,
                    price: row.dataset.price,
                    barcode: row.dataset.barcode
                });
            }
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            searchResults.style.display = 'none';
            clearHighlight();
        }
    });

    // اختيار صنف
    function selectItem(item) {
        selectedItem = item;
        searchInput.value = item.name;
        selectedItemId.value = item.id;
        priceInput.value = item.price;
        searchResults.style.display = 'none';
        clearHighlight();

        // جلب بيانات الصنف الكاملة وتحديث الحقول مباشرة
        const isSale = window.location.href.indexOf('q=sale') !== -1;

        $.ajax({
            url: 'get/get_iteminfo.php?id=' + item.id,
            method: 'GET',
            dataType: 'json',
            cache: true,
            success: function(data) {
                if (data.error) {
                    console.error('get_iteminfo error:', data.error, '| item id:', item.id);
                    return;
                }
                const price = isSale ? (data.last_price || data.price1) : data.price1;

                // تحديث حقول صف الإدخال
                $('#itmprice').val(price || 0);
                $('#itmqty').val(1);
                $('#itmdisc').val('0.00');
                $('#itmval').val(price || 0);

                // تحديث حقول المعلومات
                $('#storeqty').text(data.itmqty ? parseFloat(data.itmqty).toFixed(2) : '0');
                $('#price1').text(data.price1 || '0');
                $('#market_price').text(data.market_price || '0');
                $('#storemdtime').text(data.mdtime || '');
                $('#cost_price').text(data.cost_price || '0');
                $('#last_price').text(data.last_price || '0');

                // تحديث الوحدات
                const unitSelect = $('#inputUnitSelect');
                unitSelect.empty();
                if (data.units && data.units.length) {
                    data.units.forEach(function(unit) {
                        unitSelect.append('<option value="' + unit.unit_value + '">' + unit.unit_name + '</option>');
                    });

                    unitSelect.off('change').on('change', function() {
                        const selectedUnit = data.units.find(u => u.unit_value == $(this).val());
                        if (selectedUnit) {
                            const newPrice = isSale
                                ? (data.last_price * selectedUnit.unit_value)
                                : selectedUnit.uprice1;
                            $('#itmprice').val(newPrice);
                            $('#itmqty').val(1);
                            $('#itmval').val(newPrice);

                            $('#storeqty').text((data.itmqty / selectedUnit.unit_value).toFixed(2) + ' (' + selectedUnit.unit_value + ')');
                            $('#price1').text(selectedUnit.uprice1);
                            $('#market_price').text(selectedUnit.uprice3);
                            $('#cost_price').text(data.cost_price * selectedUnit.unit_value);
                            $('#last_price').text(data.last_price * selectedUnit.unit_value);
                        }
                    });
                } else {
                    unitSelect.append('<option value="">لا توجد وحدات</option>');
                }

                // تفعيل حقل الكمية
                document.getElementById('itmqty').focus();
            },
            error: function() {
                document.getElementById('itmqty').focus();
            }
        });
    }

    // إخفاء النتائج عند الضغط خارجها
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    // مسح عند focus
    searchInput.addEventListener('focus', function() {
        if (this.value && searchResults.children.length > 0) {
            searchResults.style.display = 'block';
        }
    });
})();
</script>
        <?php
        return ob_get_clean();
    }

    /**
     * عرض الصفوف الموجودة (في حالة التعديل)
     */
    private function renderExistingRows()
    {
        if (!$this->isEditMode || empty($this->existingDetails)) {
            return;
        }

        foreach ($this->existingDetails as $index => $detail) {
            $rowNumber = $index + 1;
            $this->renderDetailRow($detail, $rowNumber);
        }
    }

    /**
     * عرض صف تفاصيل واحد
     */
    private function renderDetailRow($detail, $rowNumber)
    {
        $quantity = abs($detail['qty_in'] - $detail['qty_out']) / $detail['u_val'];
        $price = $detail['price'] * $detail['u_val'];
        
        ?>
        <tr>
            <td class="col-1">
                <?php echo $rowNumber; ?>
                <input type="text" name="det_id[]" hidden value="<?php echo $detail['id']; ?>">
                <input type="text" name="detcrtime[]" hidden value="<?php echo $detail['crtime']; ?>">
            </td>
            
            <!-- الصنف -->
            <td id="itmTd" class="col-lg-5">
                <p><?php echo $this->sanitizeInput($detail['iname']); ?></p>
                <input id="itmprice2" type="number" name="itmname[]" hidden 
                       onclick="sT(this)" value="<?php echo $detail['item_id']; ?>">
            </td>
            
            <!-- الوحدة -->
            <td>
                <?php $this->renderUnitSelect($detail['item_id'], $detail['u_val']); ?>
            </td>
            
            <!-- الكمية -->
            <td>
                <input id="itmqty" value="<?php echo $quantity; ?>" type="number" 
                       name="itmqty[]" onclick="sT(this)" 
                       class="itmqty form-control form-control-sm" style="width:90px;">
            </td>
            
            <!-- السعر -->
            <td>
                <input id="itmprice" type="number" name="itmprice[]" onclick="sT(this)" 
                       class="itmprice form-control form-control-sm" style="width:90px;" 
                       value="<?php echo $price; ?>">
            </td>
            
            <!-- الخصم -->
            <td>
                <input id="itmdisc" value="<?php echo $detail['discount']; ?>" 
                       type="number" name="itmdisc[]" onclick="sT(this)" 
                       class="itmdisc form-control form-control-sm" style="width:120px;">
            </td>
            
            <!-- القيمة -->
            <td>
                <input readonly id="itmval" value="<?php echo $detail['det_value']; ?>" 
                       type="number" name="itmval[]" 
                       class="itmval bg-light form-control form-control-sm" style="width:150px;">
            </td>
            
            <td>
                <input id="itmprofit" name="itmprofit" hidden>
                <button type="button" class="deleteRow btn btn-danger">X</button>
            </td>
        </tr>
        <?php
    }

    /**
     * عرض قائمة الوحدات للصنف
     */
    private function renderUnitSelect($itemId, $selectedUnitVal = null)
    {
        if (!$this->conn) {
            echo '<select name="u_val[]" class="form-control form-control-sm" style="width:100px;"><option value="">لا توجد وحدات</option></select>';
            return;
        }

        try {
            $query = "SELECT iu.*, mu.uname 
                     FROM item_units iu 
                     JOIN myunits mu ON iu.unit_id = mu.id 
                     WHERE iu.item_id = ?";
            $result = $this->executeSecureQuery($query, [$itemId], 'i');
            $units = $result->fetch_all(MYSQLI_ASSOC);

            echo '<select name="u_val[]" class="form-control form-control-sm" style="width:100px;">';
            foreach ($units as $unit) {
                $selected = ($selectedUnitVal && $unit['u_val'] == $selectedUnitVal) ? 'selected' : '';
                echo "<option value='{$unit['u_val']}' {$selected}>{$this->sanitizeInput($unit['uname'])}</option>";
            }
            echo '</select>';

        } catch (Exception $e) {
            echo '<select name="u_val[]" class="form-control form-control-sm" style="width:100px;"><option value="">خطأ في التحميل</option></select>';
        }
    }

    /**
     * التحقق من صحة البيانات
     */
    public function validate()
    {
        $errors = [];

        // التحقق من وجود أصناف
        if (empty($_POST['itmname']) || !is_array($_POST['itmname'])) {
            $errors[] = 'يجب إضافة صنف واحد على الأقل';
            return $errors;
        }

        // التحقق من كل صنف
        foreach ($_POST['itmname'] as $index => $itemId) {
            if (empty($itemId)) {
                continue; // تجاهل الصفوف الفارغة
            }

            // التحقق من الكمية
            $quantity = $_POST['itmqty'][$index] ?? 0;
            if ($quantity <= 0) {
                $errors[] = "الكمية غير صحيحة في الصف " . ($index + 1);
            }

            // التحقق من السعر
            $price = $_POST['itmprice'][$index] ?? 0;
            if ($price < 0) {
                $errors[] = "السعر غير صحيح في الصف " . ($index + 1);
            }
        }

        return $errors;
    }

    /**
     * إنشاء صف جديد فارغ لإضافة صنف
     */
    public function renderNewRow()
    {
        ob_start();
        ?>
        <div class="row">
            <div class="table-responsive">
                <table class="table table-condensed table-hover table-striped table-bordered" id="searchTable">
                    <tbody>
                        <tr>
                            <td class="col-1">
                                <div class="tool">
                                    <a id="addNewElement" class="btn bg-lime-200 btn-sm hadi-white-flash" 
                                       href="add_item.php" target="_blank">+</a>
                                    <div class="tooltext">إضافة صنف جديد</div>  
                                </div>
                            </td>
                             
                            <!-- الصنف -->
                            <td id="itmTd" class="col-lg-5">
                                <select style="width:100%" name="myitm[]" id="mySelectitm" 
                                        class="frst mySelectitm form-control">
                                    <option value="">اختر صنف</option>
                                    <?php $this->renderItemOptions(); ?>
                                </select>
                                <input id="itmprice2" type="number" hidden onclick="sT(this)">
                            </td>
                            
                            <!-- الوحدة -->
                            <td>
                                <select id="inputUnitSelect" class="form-control form-control-sm" style="width:100px;">
                                    <option value="">اختر وحدة</option>
                                </select>
                            </td>
                            
                            <!-- الكمية -->
                            <td>
                                <input type="number" hidden>
                                <input id="itmqty" value="1.00" type="number"
                                       onclick="sT(this)" class="itmqty form-control form-control-sm nozero" 
                                       style="width:90px;">
                            </td>
                            
                            <!-- السعر -->
                            <td>
                                <input id="itmprice" value="0.00" type="number"
                                       onclick="sT(this)" class="itmprice form-control form-control-sm nozero" 
                                       style="width:90px;" step="0.001">
                            </td>
                            
                            <!-- الخصم -->
                            <td>
                                <input id="itmdisc" value="0.00" type="number"
                                       onclick="sT(this)" class="itmdisc form-control form-control-sm nozero" 
                                       style="width:120px;" step="0.001">
                            </td>
                            
                            <!-- القيمة -->
                            <td>
                                <input readonly id="itmval" value="0.00" type="number"
                                       class="itmval bg-light form-control form-control-sm nozero" 
                                       style="width:150px;" step="0.001">
                            </td>
                            
                            <td>
                                <input id="itmprofit" name="itmprofit" hidden>
                                <button type="button" id="addRow" class="btn btn-light">إضافة</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * عرض خيارات الأصناف
     */
    private function renderItemOptions()
    {
        foreach ($this->items as $item) {
            $displayName = $item['iname'];
            if (!empty($item['name2'])) {
                $displayName .= ' // ' . $item['name2'];
            }
            echo "<option value='{$item['id']}'>{$this->sanitizeInput($displayName)}</option>";
        }
    }
}