<?php

require_once 'InvoiceElementBase.php';

/**
 * فئة نافذة إضافة الأصناف - تطبيق polymorphism
 * Add Item Modal class - implementing polymorphism
 */
class AddItemModal extends InvoiceElementBase
{
    private $units = [];
    private $groups1 = [];
    private $groups2 = [];
    private $nextItemCode = 1;

    public function __construct($invoiceType, $isEditMode = false, $data = null, $conn = null)
    {
        parent::__construct($invoiceType, $isEditMode, $data, $conn);
        $this->loadSelectOptions();
        $this->getNextItemCode();
    }

    /**
     * تحميل خيارات القوائم المنسدلة
     */
    private function loadSelectOptions()
    {
        if (!$this->conn) return;

        try {
            // تحميل الوحدات
            $query = "SELECT * FROM myunits ORDER BY uname";
            $result = $this->executeSecureQuery($query);
            $this->units = $result->fetch_all(MYSQLI_ASSOC);

            // تحميل المجموعات الأولى
            $query = "SELECT * FROM item_group WHERE isdeleted = 0 ORDER BY gname";
            $result = $this->executeSecureQuery($query);
            $this->groups1 = $result->fetch_all(MYSQLI_ASSOC);

            // تحميل المجموعات الثانية
            $query = "SELECT * FROM item_group2 WHERE isdeleted = 0 ORDER BY gname";
            $result = $this->executeSecureQuery($query);
            $this->groups2 = $result->fetch_all(MYSQLI_ASSOC);

        } catch (Exception $e) {
            error_log("Error loading select options for AddItemModal: " . $e->getMessage());
        }
    }

    /**
     * الحصول على الكود التالي للصنف
     */
    private function getNextItemCode()
    {
        if (!$this->conn) return;

        try {
            $query = "SELECT MAX(code) as max_code FROM myitems";
            $result = $this->executeSecureQuery($query);
            $row = $result->fetch_assoc();
            $this->nextItemCode = $row && $row['max_code'] ? ($row['max_code'] + 1) : 1;
        } catch (Exception $e) {
            $this->nextItemCode = 1;
        }
    }

    /**
     * عرض نافذة إضافة الأصناف
     */
    public function render()
    {
        ob_start();
        ?>
        <div class="modal fade" id="modal-xl" style="display: none;" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title">إضافة صنف جديد</h4>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">×</span>
                        </button>
                    </div>
                    
                    <div class="modal-body">
                        <p id="msgitem" class="text-lime-400"></p>
                        <?php $this->renderModalContent(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * عرض محتوى النافذة
     */
    private function renderModalContent()
    {
        if (!$this->checkPermissions()) {
            echo '<div class="alert alert-danger">ليس لديك صلاحية لإضافة الأصناف</div>';
            return;
        }
        ?>
        <div class="card card-primary">
            <form action="" method="post" id="addItemForm">
                <div class="card-body">
                    <?php $this->renderBasicInfo(); ?>
                    <?php $this->renderUnitsSection(); ?>
                    <?php $this->renderGroupsSection(); ?>
                    <?php $this->renderPricesSection(); ?>
                </div>
                
                <div class="card-footer">
                    <div class="row">
                        <div class="col">
                            <button type="submit" id="addItemBtn" 
                                    class="btn btn-success btn-lg float-right btn-block">
                                حفظ
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * عرض المعلومات الأساسية
     */
    private function renderBasicInfo()
    {
        ?>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="code">الكود</label>
                    <input readonly value="<?php echo $this->nextItemCode; ?>" 
                           class="form-control form-control-sm col-4" 
                           type="text" name="code" id="code">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="barcode">الباركود</label>
                    <input required value="<?php echo $this->nextItemCode; ?>" 
                           class="form-control form-control-sm" 
                           type="text" name="barcode" id="barcode">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="iname">اسم الصنف</label>
                    <?php $this->renderItemNameDatalist(); ?>
                    <input list="inamelist" required 
                           class="form-control form-control-sm" 
                           type="text" name="iname" id="iname">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="name2">اسم ثاني</label>
                    <input class="form-control form-control-sm" 
                           type="text" name="name2" id="name2">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="info">تفاصيل</label>
                    <input class="form-control form-control-sm" 
                           type="text" name="info" id="info">
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * عرض قائمة أسماء الأصناف للمساعدة في الكتابة
     */
    private function renderItemNameDatalist()
    {
        // تم تعطيل تحميل الأصناف - يتم استخدام AJAX بدلاً منه
        echo '<datalist id="inamelist"></datalist>';
        return;
    }

    /**
     * عرض قسم الوحدات
     */
    private function renderUnitsSection()
    {
        ?>
        <div class="col-md-12 bg-light">
            <b>الوحدات</b>
            <p id="addUnit" class="btn btn-success">إضافة وحدة</p>
            <div class="row urow">
                <div class="col-md-4">
                    <label for="">الوحدة</label>
                    <select name="unit_id[]" class="form-control">
                        <?php $this->renderUnitOptions(); ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="">معامل التحويل</label>
                    <input class="form-control" type="number" readonly 
                           name="u_val[]" value="1">
                </div>
                <div class="col-md-5">
                    <label for="">باركود</label>
                    <input class="form-control" type="text" name="unit_barcode[]" 
                           id="unitCode" value="<?php echo $this->nextItemCode; ?>">
                </div>
                <div class="col-md-1">
                    <p class="btn btn-danger deleteRow">X</p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * عرض خيارات الوحدات
     */
    private function renderUnitOptions()
    {
        foreach ($this->units as $unit) {
            echo "<option value='{$unit['id']}'>{$this->sanitizeInput($unit['uname'])}</option>";
        }
    }

    /**
     * عرض قسم المجموعات
     */
    private function renderGroupsSection()
    {
        ?>
        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <label for="group1">مجموعة</label>
                    <select name="group1" class="form-control form-control-sm float-right">
                        <?php $this->renderGroup1Options(); ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label for="group2">تصنيف</label>
                    <select name="group2" class="form-control form-control-sm float-right">
                        <?php $this->renderGroup2Options(); ?>
                    </select>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * عرض خيارات المجموعة الأولى
     */
    private function renderGroup1Options()
    {
        foreach ($this->groups1 as $group) {
            echo "<option value='{$group['id']}'>{$this->sanitizeInput($group['gname'])}</option>";
        }
    }

    /**
     * عرض خيارات المجموعة الثانية
     */
    private function renderGroup2Options()
    {
        foreach ($this->groups2 as $group) {
            echo "<option value='{$group['id']}'>{$this->sanitizeInput($group['gname'])}</option>";
        }
    }

    /**
     * عرض قسم الأسعار
     */
    private function renderPricesSection()
    {
        ?>
        <div class="table-responsive bg-light col-md-12">
            <table class="table table-hovered table-responsive table-bordered horsTable">
                <thead>
                    <tr>
                        <th>سعر التكلفة</th>
                        <th>سعر البيع</th>
                        <th>سعر البيع 2</th>
                        <th>سعر السوق</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <input type="number" value="0.00" name="cost_price" 
                                   class="form-control form-control-sm nozero" step="any">
                        </td>
                        <td>
                            <input type="number" value="0.00" name="price1" 
                                   class="form-control form-control-sm nozero" step="any">
                        </td>
                        <td>
                            <input type="number" value="0.00" name="price2" 
                                   class="form-control form-control-sm nozero" step="any">
                        </td>
                        <td>
                            <input type="number" value="0.00" name="market_price" 
                                   class="form-control form-control-sm nozero" step="any">
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * التحقق من الصلاحيات
     */
    private function checkPermissions()
    {
        // هنا يجب فحص صلاحيات المستخدم
        // مؤقتاً سنعيد true
        global $role;
        return isset($role['add_items']) && $role['add_items'] == 1;
    }

    /**
     * التحقق من صحة البيانات
     */
    public function validate()
    {
        $errors = [];

        // التحقق من اسم الصنف
        if (empty($_POST['iname'])) {
            $errors[] = 'اسم الصنف مطلوب';
        }

        // التحقق من الباركود
        if (empty($_POST['barcode'])) {
            $errors[] = 'الباركود مطلوب';
        }

        // التحقق من وجود وحدة واحدة على الأقل
        if (empty($_POST['unit_id']) || !is_array($_POST['unit_id'])) {
            $errors[] = 'يجب إضافة وحدة واحدة على الأقل';
        }

        // التحقق من عدم تكرار الباركود
        if (!empty($_POST['barcode']) && $this->conn) {
            try {
                $query = "SELECT COUNT(*) as count FROM myitems WHERE barcode = ?";
                $result = $this->executeSecureQuery($query, [$_POST['barcode']], 's');
                $row = $result->fetch_assoc();
                if ($row && $row['count'] > 0) {
                    $errors[] = 'الباركود موجود مسبقاً';
                }
            } catch (Exception $e) {
                $errors[] = 'خطأ في التحقق من الباركود';
            }
        }

        return $errors;
    }
}