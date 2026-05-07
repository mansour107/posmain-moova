<?php

require_once 'InvoiceElementBase.php';

/**
 * فئة رأس الفاتورة - تطبيق polymorphism
 * Invoice Header class - implementing polymorphism
 */
class InvoiceHeader extends InvoiceElementBase
{
    private $accounts = [];
    private $stores = [];
    private $employees = [];

    public function __construct($invoiceType, $isEditMode = false, $data = null, $conn = null)
    {
        parent::__construct($invoiceType, $isEditMode, $data, $conn);
        $this->loadSelectOptions();
    }

    /**
     * تحميل خيارات القوائم المنسدلة
     */
    private function loadSelectOptions()
    {
        if (!$this->conn) return;

        try {
            // تحميل العملاء/الموردين
            $accountCode = $this->getAccountCode();
            if ($accountCode) {
                $query = "SELECT * FROM acc_head WHERE code LIKE ? AND is_basic = 0 AND isdeleted = 0 ORDER BY id";
                $result = $this->executeSecureQuery($query, [$accountCode . '%'], 's');
                $this->accounts = $result->fetch_all(MYSQLI_ASSOC);
            }

            // تحميل المخازن
            $query = "SELECT * FROM acc_head WHERE is_stock = 1";
            $result = $this->executeSecureQuery($query);
            $this->stores = $result->fetch_all(MYSQLI_ASSOC);

            // تحميل الموظفين
            $query = "SELECT * FROM acc_head WHERE parent_id = 35 AND is_basic = 0";
            $result = $this->executeSecureQuery($query);
            $this->employees = $result->fetch_all(MYSQLI_ASSOC);

        } catch (Exception $e) {
            error_log("Error loading select options: " . $e->getMessage());
        }
    }

    /**
     * عرض رأس الفاتورة
     */
    public function render()
    {
        $clientLabel = $this->getClientLabel();
        $addAccountLink = $this->getAddAccountLink();

        ob_start();
        ?>
        <div class="row frst-row bg--200">
            <!-- العميل/المورد -->
            <div class="col-lg-2">
                <div class="tool">
                    <label for="">
                        <?php echo $clientLabel; ?>
                        <a class="btn bg-lime-200 btn-sm" href="<?php echo $addAccountLink; ?>" target="_blank">+</a>
                    </label>
                    <div class="tooltext">إضافة جديد</div>
                </div>
                <select class="select2 form-control form-control-sm" name="acc2_id" id="mySelectEmp">
                    <?php $this->renderAccountOptions(); ?>
                </select>
            </div>

            <!-- المخزن -->
            <div class="col-md-2">
                <label for="">المخزن</label>
                <select name="store_id" class="form-control form-control-sm">
                    <?php $this->renderStoreOptions(); ?>
                </select>
            </div>

            <!-- الموظف -->
            <div class="col-md-2">
                <label for="">الموظف</label>
                <select class="form-control form-control-sm" name="emp_id">
                    <?php $this->renderEmployeeOptions(); ?>
                </select>
            </div>

            <!-- التاريخ -->
            <div class="col-md-2">
                <label for="">التاريخ</label>
                <input type="date" class="form-control bg-secondary" name="pro_date" id="pro_date" 
                       value="<?php echo $this->getProDate(); ?>">
            </div>

            <!-- تاريخ الاستحقاق -->
            <div class="col-md-2">
                <label for="">تاريخ الاستحقاق</label>
                <input type="date" class="form-control" name="accural_date" 
                       value="<?php echo $this->getAccuralDate(); ?>">
            </div>

            <!-- رقم الفاتورة -->
            <div class="col-md-1">
                <label for="">رقم الفاتورة</label>
                <input name="pro_id" type="text" class="form-control form-control-sm" 
                       value="<?php echo $this->getProId(); ?>" readonly>
            </div>

            <!-- S.N -->
            <div class="col-md-1">
                <label for="">S.N</label>
                <input type="text" name="pro_serial" class="form-control form-control-sm" 
                       value="<?php echo $this->getProSerial(); ?>">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * التحقق من صحة البيانات
     */
    public function validate()
    {
        $errors = [];

        // التحقق من وجود العميل/المورد
        if (empty($_POST['acc2_id'])) {
            $errors[] = 'يجب اختيار ' . ($this->getClientType() == 'supplier' ? 'المورد' : 'العميل');
        }

        // التحقق من وجود المخزن
        if (empty($_POST['store_id'])) {
            $errors[] = 'يجب اختيار المخزن';
        }

        // التحقق من التاريخ
        if (empty($_POST['pro_date'])) {
            $errors[] = 'يجب إدخال التاريخ';
        }

        return $errors;
    }

    /**
     * الحصول على تسمية العميل/المورد
     */
    private function getClientLabel()
    {
        $clientType = $this->getClientType();
        return $clientType == 'supplier' ? 'المورد' : 'العميل';
    }

    /**
     * الحصول على رابط إضافة عميل/مورد جديد
     */
    private function getAddAccountLink()
    {
        $accountCode = $this->getAccountCode();
        return "add_account.php?parent_id=" . $accountCode;
    }

    /**
     * عرض خيارات العملاء/الموردين
     */
    private function renderAccountOptions()
    {
        foreach ($this->accounts as $account) {
            $selected = '';
            if ($this->isEditMode && $this->data && $this->data['acc2'] == $account['id']) {
                $selected = 'selected';
            }
            echo "<option value='{$account['id']}' {$selected}>{$this->sanitizeInput($account['aname'])}</option>";
        }
    }

    /**
     * عرض خيارات المخازن
     */
    private function renderStoreOptions()
    {
        foreach ($this->stores as $store) {
            $selected = $this->getDefaultSelected('def_store', $store['id']);
            if ($this->isEditMode && $this->data && $this->data['store_id'] == $store['id']) {
                $selected = 'selected';
            }
            echo "<option value='{$store['id']}' {$selected}>{$this->sanitizeInput($store['aname'])}</option>";
        }
    }

    /**
     * عرض خيارات الموظفين
     */
    private function renderEmployeeOptions()
    {
        foreach ($this->employees as $employee) {
            $selected = $this->getDefaultSelected('def_emp', $employee['id']);
            if ($this->isEditMode && $this->data && $this->data['emp_id'] == $employee['id']) {
                $selected = 'selected';
            }
            echo "<option value='{$employee['id']}' {$selected}>{$this->sanitizeInput($employee['aname'])}</option>";
        }
    }

    /**
     * الحصول على القيمة الافتراضية المحددة
     */
    private function getDefaultSelected($optionName, $currentId)
    {
        try {
            $query = "SELECT cur_value FROM myoptions WHERE oname = ?";
            $result = $this->executeSecureQuery($query, [$optionName], 's');
            $row = $result->fetch_assoc();
            return ($row && $row['cur_value'] == $currentId) ? 'selected' : '';
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * الحصول على تاريخ الفاتورة
     */
    private function getProDate()
    {
        if ($this->isEditMode && $this->data) {
            return $this->data['pro_date'];
        }
        return date('Y-m-d');
    }

    /**
     * الحصول على تاريخ الاستحقاق
     */
    private function getAccuralDate()
    {
        if ($this->isEditMode && $this->data) {
            return $this->data['accural_date'];
        }
        return '';
    }

    /**
     * الحصول على رقم الفاتورة
     */
    private function getProId()
    {
        if ($this->isEditMode && $this->data) {
            return $this->data['pro_id'];
        }
        return $this->generateNewProId();
    }

    /**
     * الحصول على الرقم التسلسلي
     */
    private function getProSerial()
    {
        if ($this->isEditMode && $this->data) {
            return $this->data['pro_serial'];
        }
        return '';
    }

    /**
     * توليد رقم فاتورة جديد
     */
    private function generateNewProId()
    {
        try {
            $query = "SELECT MAX(CAST(pro_id AS UNSIGNED)) as max_id FROM ot_head WHERE pro_tybe = ?";
            $result = $this->executeSecureQuery($query, [$this->invoiceType], 'i');
            $row = $result->fetch_assoc();
            return $row ? ($row['max_id'] + 1) : 1;
        } catch (Exception $e) {
            return 1;
        }
    }
}