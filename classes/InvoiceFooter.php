<?php

require_once 'InvoiceElementBase.php';

/**
 * فئة ذيل الفاتورة - تطبيق polymorphism
 * Invoice Footer class - implementing polymorphism
 */
class InvoiceFooter extends InvoiceElementBase
{
    private $funds = [];

    public function __construct($invoiceType, $isEditMode = false, $data = null, $conn = null)
    {
        parent::__construct($invoiceType, $isEditMode, $data, $conn);
        $this->loadFunds();
    }

    /**
     * تحميل الصناديق
     */
    private function loadFunds()
    {
        if (!$this->conn) return;

        try {
            $query = "SELECT * FROM acc_head WHERE is_fund = 1";
            $result = $this->executeSecureQuery($query);
            $this->funds = $result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Error loading funds: " . $e->getMessage());
        }
    }

    /**
     * عرض ذيل الفاتورة
     */
    public function render()
    {
        ob_start();
        ?>
        <div class="row full bg--200 border">
            <!-- حقل نوع الفاتورة المخفي -->
            <input type="text" name="pro_tybe" hidden value="<?php echo $this->invoiceType; ?>">
            
            <!-- معلومات الصنف -->
            <div class="col-md-3">
                <?php $this->renderItemInfo(); ?>
            </div>
            
            <!-- معلومات التكلفة -->
            <div class="col-md-3">
                <?php $this->renderCostInfo(); ?>
            </div>
            
            <!-- إجماليات الفاتورة -->
            <div class="col-md-3">
                <?php $this->renderTotals(); ?>
            </div>
            
            <!-- الدفع والصندوق -->
            <div class="col-md-3">
                <?php $this->renderPayment(); ?>
            </div>
        </div>
        
        <!-- أزرار التحكم والملاحظات -->
        <div class="row">
            <div class="col-md-4">
                <?php $this->renderActionButtons(); ?>
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control bg-orange-300" name="info" id="info" 
                       placeholder="ملاحظات" value="<?php echo $this->getInfo(); ?>">
            </div>
            <div class="col-md-2" id="showOps">
                <div class="btn">إظهار الفواتير السابقة</div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * عرض معلومات الصنف
     */
    private function renderItemInfo()
    {
        ?>
        <div class="row">
            <div class="col bg-light">الكمية</div>
            <div class="col border border-light">
                <h6 id="storeqty"></h6>
            </div>
        </div>
        
        <div class="row">
            <div class="col bg-light">سعر البيع</div>
            <div class="col border border-light" id="cost_price_div">
                <h6 id="price1"></h6>
            </div>
        </div>
        
        <div class="row">
            <div class="col bg-light">سعر السوق</div>
            <div class="col border border-light" id="cost_price_div">
                <h6 id="market_price"></h6>
            </div>
        </div>
        
        <div class="row">
            <div class="col bg-light">آخر وقت للتعديل</div>
            <div class="col border border-light" id="cost_price_div">
                <h6 id="storemdtime"></h6>
            </div>
        </div>
        <?php
    }

    /**
     * عرض معلومات التكلفة
     */
    private function renderCostInfo()
    {
        ?>
        <div class="row">
            <div class="col bg-light">سعر الشراء المتوسط</div>
            <div class="col border border-light" id="cost_price_div">
                <h6 id="cost_price" class="text-white hover:bg-slate-400"></h6>
            </div>
        </div>
        
        <div class="row">
            <div class="col bg-light">سعر الشراء الأخير</div>
            <div class="col border border-light">
                <h6 id="last_price" class="text-white hover:bg-slate-400"></h6>
            </div>
        </div>
        <?php
    }

    /**
     * عرض الإجماليات
     */
    private function renderTotals()
    {
        ?>
        <div class="row">
            <div class="col col-md-4">
                <label for="">الإجمالي</label>
            </div>
            <div class="col-md-8">
                <input id="headtotal" name="headtotal" type="text" 
                       class="form-control form-control-sm" 
                       value="<?php echo $this->getFatTotal(); ?>" readonly>
            </div>
        </div>
        
        <div class="row">
            <div class="col col-md-4">
                <label for="">الخصم<span class="text-orange-600">(F6)</span></label>
            </div>
            <div class="col">
                <input id="headdisc" name="headdisc" type="text" 
                       class="form-control form-control-sm mid select-all hover:select-all nozero" 
                       value="<?php echo $this->getFatDisc(); ?>">
            </div>
        </div>
        
        <div class="row">
            <div class="col col-md-4">
                <label for="">الإضافي</label>
            </div>
            <div class="col">
                <input id="headplus" name="headplus" type="text" 
                       class="form-control form-control-sm" 
                       value="<?php echo $this->getFatPlus(); ?>">
            </div>
        </div>
        
        <div class="row">
            <div class="col col-md-4">
                <label for="">الصافي</label>
            </div>
            <div class="col-md-8">
                <input id="headnet" name="headnet" type="text" 
                       class="form-control" readonly style="font-size:30px;" 
                       value="<?php echo $this->getProValue(); ?>">
            </div>
        </div>
        <?php
    }

    /**
     * عرض معلومات الدفع
     */
    private function renderPayment()
    {
        // إخفاء جزء الدفع لأوامر الشراء (12) وأوامر البيع (13) وعروض الأسعار (14)
        $hidePayment = in_array($this->invoiceType, [12, 13, 14]);
        
        if ($hidePayment) {
            // عرض رسالة بديلة أو ترك المساحة فارغة
            echo '<div class="alert alert-info text-center" style="margin: 20px;">
                    <i class="fas fa-info-circle"></i> 
                    لا يتطلب هذا المستند معلومات دفع
                  </div>';
            return;
        }
        ?>
        <div class="row">
            <div class="col-md-4">
                <label for="">المدفوع <span class="text-orange-600">(F7)</span></label>
            </div>
            <div class="col-md-8">
                <input id="paid" name="paid" type="number" 
                       class="form-control form-control-lg bg-light last" 
                       style="font-size:30px;" value="<?php echo $this->getPaidAmount(); ?>">
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <label for="">الباقي</label>
            </div>
            <div class="col-md-8">
                <input id="change" type="text" class="form-control form-control-sm" 
                       readonly value="0.00">
            </div>
        </div>
        
        <div class="row">
            <div class="col col-4">
                <label for="">الصندوق</label>
            </div>
            <div class="col col-md-8">
                <select name="fund_id" class="form-control form-control-sm">
                    <?php $this->renderFundOptions(); ?>
                </select>
            </div>
        </div>
        <?php
    }

    /**
     * عرض أزرار التحكم
     */
    private function renderActionButtons()
    {
        $buttonClass = $this->getBackgroundClass();
        $saveText = $this->isEditMode ? 'تحديث' : 'حفظ';
        ?>
        <button id="submit" class="btn <?php echo $buttonClass; ?> btn-block btn-lg dis" 
                type="submit" name="submit" value="save">
            <?php echo $saveText; ?> (F12)
        </button>
        <button id="submit2" class="btn <?php echo $buttonClass; ?> btn-block btn-lg dis" 
                type="submit" name="submit" value="print">
            <?php echo $saveText; ?> وطباعة (F11)
        </button>
        <?php
    }

    /**
     * عرض خيارات الصناديق
     */
    private function renderFundOptions()
    {
        foreach ($this->funds as $fund) {
            $selected = $this->getDefaultSelected('def_fund', $fund['id']);
            if ($this->isEditMode && $this->data && $this->data['fund_id'] == $fund['id']) {
                $selected = 'selected';
            }
            echo "<option value='{$fund['id']}' {$selected}>{$this->sanitizeInput($fund['aname'])}</option>";
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
     * التحقق من صحة البيانات
     */
    public function validate()
    {
        $errors = [];

        // التحقق من الصندوق
        if (empty($_POST['fund_id'])) {
            $errors[] = 'يجب اختيار الصندوق';
        }

        // التحقق من المبلغ المدفوع
        $paid = floatval($_POST['paid'] ?? 0);
        if ($paid < 0) {
            $errors[] = 'المبلغ المدفوع لا يمكن أن يكون سالباً';
        }

        // التحقق من الخصم
        $discount = floatval($_POST['headdisc'] ?? 0);
        if ($discount < 0) {
            $errors[] = 'الخصم لا يمكن أن يكون سالباً';
        }

        return $errors;
    }

    // دوال مساعدة للحصول على القيم
    private function getFatTotal()
    {
        return $this->isEditMode && $this->data ? $this->data['fat_total'] : '0';
    }

    private function getFatDisc()
    {
        return $this->isEditMode && $this->data ? $this->data['fat_disc'] : '0';
    }

    private function getFatPlus()
    {
        return $this->isEditMode && $this->data ? $this->data['fat_plus'] : '0';
    }

    private function getProValue()
    {
        return $this->isEditMode && $this->data ? $this->data['pro_value'] : '0';
    }

    private function getPaidAmount()
    {
        if ($this->isEditMode && $this->data) {
            try {
                $invoiceId = intval($this->data['id']);
                $query = "SELECT SUM(pro_value) as paid FROM ot_head WHERE op2 = ? AND (pro_tybe = 1 OR pro_tybe = 2)";
                $result = $this->executeSecureQuery($query, [$invoiceId], 'i');
                $row = $result->fetch_assoc();
                return $row ? $row['paid'] : '0';
            } catch (Exception $e) {
                return '0';
            }
        }
        return '0';
    }

    private function getInfo()
    {
        return $this->isEditMode && $this->data ? $this->sanitizeInput($this->data['info'] ?? '') : '';
    }
}