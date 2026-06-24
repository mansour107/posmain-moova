<?php
require_once __DIR__ . '/../includes/production_guard.php';
production_guard_deny_debug_request('do/doadd_invoice.php');

require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');


// التحقق من المصادقة والصلاحيات
if (PHP_SAPI !== 'cli') {
    require_pos_authenticated();
} elseif (!isset($_SESSION['userid'])) {
    header('Location: ../login.php');
    exit;
}

// التحقق من صحة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../sales.php');
    exit;
}
if (PHP_SAPI !== 'cli') {
    require_csrf('pos_browser');
}

$usid = $_SESSION['userid'];

// تضمين فئات النظام الجديد
require_once('../classes/InvoiceElementFactory.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Sync/DocumentCounterService.php');
require_once('../classes/Sync/SyncOutboxEventService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');
require_once('../classes/Pos/Service/ModifierLineNoteService.php');
require_once('../classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php');
require_once('../classes/Inventory/InventoryInvoiceBridge.php');
require_once('../includes/pos_default_accounts.php');

// تعريف ثوابت أنواع الفواتير
define('INVOICE_TYPES', [
    'PURCHASE' => 4,    // مشتريات
    'SALES' => 3,       // مبيعات
    'POS' => 9,         // كاشير
    'PURCHASE_RETURN' => 10,  // مردود مشتريات
    'SALES_RETURN' => 11,     // مردود مبيعات
    'PURCHASE_ORDER' => 12,   // أمر شراء
    'SALES_ORDER' => 13,      // أمر بيع
    'OFFER' => 14             // عرض سعر
]);

// تعريف أنواع العمليات المحاسبية
define('ACCOUNTING_TYPES', [
    'RECEIPT' => 1,     // سند قبض
    'PAYMENT' => 2,     // سند دفع
    'SALES_DISC' => 7,  // خصم مبيعات
    'PURCHASE_DISC' => 6 // خصم مشتريات
]);

// استخراج وتنظيف البيانات المدخلة
$pro_tybe = isset($_POST['pro_tybe']) ? intval($_POST['pro_tybe']) : 0;
$store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
$pro_serial = isset($_POST['pro_serial']) ? htmlspecialchars(trim($_POST['pro_serial']), ENT_QUOTES, 'UTF-8') : '';
$pro_date = posmain_normalize_invoice_date($_POST['pro_date'] ?? null);
$accural_date = posmain_normalize_invoice_date($_POST['accural_date'] ?? null, $pro_date);
$acc2_id = isset($_POST['acc2_id']) ? intval($_POST['acc2_id']) : 0;
$emp_id = isset($_POST['emp_id']) ? intval($_POST['emp_id']) : 0;
$headtotal = isset($_POST['headtotal']) ? floatval($_POST['headtotal']) : 0;
$headdisc = isset($_POST['headdisc']) ? floatval($_POST['headdisc']) : 0;
$headplus = isset($_POST['headplus']) ? floatval($_POST['headplus']) : 0;
$headnet = isset($_POST['headnet']) ? floatval($_POST['headnet']) : 0;
$fund_id = isset($_POST['fund_id']) ? intval($_POST['fund_id']) : 0;
$info = isset($_POST['info']) ? htmlspecialchars(trim($_POST['info']), ENT_QUOTES, 'UTF-8') : '';
$submit = isset($_POST['submit_action']) ? htmlspecialchars($_POST['submit_action'], ENT_QUOTES, 'UTF-8') : (isset($_POST['submit']) ? htmlspecialchars($_POST['submit'], ENT_QUOTES, 'UTF-8') : 'save');
$is_save_only = ($submit === 'save');
$is_print_receipt_only = ($submit === 'print_receipt');
$is_split_line_payment = ($submit === 'split_cash');
$is_free_table_only = ($submit === 'free_table');
$empty_table_after_payment = !isset($_POST['empty_table_after_payment']) || (string) $_POST['empty_table_after_payment'] !== '0';
$split_receipt_order_id = 0;
$jal_name = isset($_POST['jal_name']) ? htmlspecialchars(trim($_POST['jal_name']), ENT_QUOTES, 'UTF-8') : NULL;
$jal_notes = isset($_POST['jal_notes']) ? htmlspecialchars(trim($_POST['jal_notes']), ENT_QUOTES, 'UTF-8') : NULL;
$jal_amount = isset($_POST['jal_amount']) ? floatval($_POST['jal_amount']) : 0;
$tableOrderService = new TableOrderService();
$pos_split_payment_rows = $is_split_line_payment
    ? posmainInvoiceDecodeSplitRows($_POST['pos_split_payment_payload'] ?? '')
    : [];

// معالجة الدفع المقسم (كاش + صرافة)
$paid_cash = isset($_POST['paid_cash']) ? floatval($_POST['paid_cash']) : 0;
$paid_bank = isset($_POST['paid_bank']) ? floatval($_POST['paid_bank']) : 0;
$payment_fund_id = isset($_POST['payment_fund_id']) ? intval($_POST['payment_fund_id']) : $fund_id;
$payment_bank_id = isset($_POST['payment_bank_id']) ? intval($_POST['payment_bank_id']) : 0;

$posSettingsRow = [];
$posSettingsQuery = $conn->query('SELECT id, def_pos_store, def_pos_employee, def_pos_fund, def_pos_client FROM settings WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1');
if ($posSettingsQuery && $posSettingsQuery->num_rows > 0) {
    $posSettingsRow = $posSettingsQuery->fetch_assoc();
}
$posResolvedAccounts = posmain_resolve_pos_invoice_accounts($conn, $posSettingsRow, [
    'store_id' => $store_id,
    'emp_id' => $emp_id,
    'fund_id' => $fund_id,
    'acc2_id' => $acc2_id,
    'payment_fund_id' => $payment_fund_id,
]);
$store_id = (int) $posResolvedAccounts['store_id'];
$emp_id = (int) $posResolvedAccounts['emp_id'];
$fund_id = (int) $posResolvedAccounts['fund_id'];
$acc2_id = (int) $posResolvedAccounts['acc2_id'];
$payment_fund_id = (int) $posResolvedAccounts['payment_fund_id'];

error_log('=== SPLIT PAYMENT DEBUG ===');
error_log('POST paid_cash: ' . (isset($_POST['paid_cash']) ? $_POST['paid_cash'] : 'NOT SET'));
error_log('POST paid_bank: ' . (isset($_POST['paid_bank']) ? $_POST['paid_bank'] : 'NOT SET'));
error_log('POST payment_fund_id: ' . (isset($_POST['payment_fund_id']) ? $_POST['payment_fund_id'] : 'NOT SET'));
error_log('POST payment_bank_id: ' . (isset($_POST['payment_bank_id']) ? $_POST['payment_bank_id'] : 'NOT SET'));
error_log('Processed: paid_cash=' . $paid_cash . ', paid_bank=' . $paid_bank . ', payment_fund_id=' . $payment_fund_id . ', payment_bank_id=' . $payment_bank_id);
error_log('=========================');

// Get order type from age parameter.
$order_mode = isset($_POST['age']) ? intval($_POST['age']) : 1; // 1 takeaway, 2 table, 3 delivery
$order_type_db = 'takeaway';
if ($order_mode === 2) {
    $order_type_db = 'table';
} elseif ($order_mode === 3) {
    $order_type_db = 'delivery';
}

$table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;
$selected_order_id = 0;
foreach (['selected_order_id', 'edit', 'edit_id'] as $selectedOrderKey) {
    if (isset($_POST[$selectedOrderKey]) && intval($_POST[$selectedOrderKey]) > 0) {
        $selected_order_id = intval($_POST[$selectedOrderKey]);
        break;
    }
}

$db_table_name = '';
if ($order_type_db === 'table') {
    if ($table_id <= 0) {
        die('خطأ: طلب الطاولة يحتاج إلى رقم طاولة صحيح');
    }

    try {
        $tableRow = $tableOrderService->requireTable($conn, $table_id);
        $db_table_name = $tableRow['tname'];
    } catch (Exception $e) {
        posmain_browser_exception_response(
            $e,
            'حدث خطأ أثناء تجهيز طلب الطاولة، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
            500,
            true,
            'invoice_table_lookup'
        );
    }
}

if ($is_free_table_only) {
    if ($order_type_db !== 'table' || $table_id <= 0) {
        die('خطأ: يجب اختيار طاولة لإفراغها');
    }

    try {
        if (!$tableOrderService->setTableFreeIfNoActiveOrder($conn, $table_id)) {
            die('خطأ: لا يمكن إفراغ الطاولة لأن عليها طلب مفتوح');
        }
        try {
            $syncOutbox = new SyncOutboxEventService();
            $syncOutbox->recordTableSnapshot($conn, $table_id, [
                'event_type' => 'table.updated',
                'source_system' => 'pos_cashier_empty_table',
                'active_order_id' => null,
            ]);
        } catch (Throwable $syncException) {
            error_log('POS empty table sync snapshot failed: ' . $syncException->getMessage());
        }
        header('Location: ../pos_barcode.php');
        exit;
    } catch (Throwable $exception) {
        posmain_browser_exception_response(
            $exception,
            'حدث خطأ أثناء إفراغ الطاولة، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
            500,
            true,
            'invoice_empty_table'
        );
    }
}

// إضافة بيانات العميل للدليفري
$delivery_name = '';
$delivery_phone = '';
$delivery_address = '';
$delivery_client_id = 0;
$delivery_zone_name = '';
$delivery_fee = 0.0;

if ($order_type_db === 'delivery') { // دليفري
    $delivery_name = isset($_POST['delivery_customer_name']) ? htmlspecialchars(trim($_POST['delivery_customer_name']), ENT_QUOTES, 'UTF-8') : '';
    $delivery_phone = isset($_POST['delivery_customer_phone']) ? htmlspecialchars(trim($_POST['delivery_customer_phone']), ENT_QUOTES, 'UTF-8') : '';
    $delivery_address = isset($_POST['delivery_customer_address']) ? htmlspecialchars(trim($_POST['delivery_customer_address']), ENT_QUOTES, 'UTF-8') : '';
    $delivery_client_id = isset($_POST['delivery_client_id']) ? intval($_POST['delivery_client_id']) : 0;

    require_once __DIR__ . '/../classes/Pos/Service/DeliveryZoneService.php';
    $zoneResolved = (new DeliveryZoneService())->resolvePostedZone($conn, $_POST);
    $delivery_zone_name = htmlspecialchars(trim((string) ($zoneResolved['delivery_zone_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $delivery_fee = (float) ($zoneResolved['delivery_fee'] ?? 0);

    if ($delivery_name === '' || $delivery_phone === '' || $delivery_address === '') {
        die('خطأ: يجب إدخال بيانات عميل الدليفري (الاسم، الهاتف، العنوان)');
    }

    if (!empty($delivery_name) && !empty($delivery_phone) && !empty($delivery_address)) {
        $info .= " - العميل: $delivery_name - الهاتف: $delivery_phone - العنوان: $delivery_address";
    }
}

// Build display info from structured order type. Table names are read from DB, not trusted from POST.
$info = $tableOrderService->buildInfo($order_type_db, $db_table_name, $info);

// تحديد المبلغ المدفوع حسب نوع الفاتورة
// أوامر الشراء والبيع وعروض الأسعار لا تحتاج مدفوعات
if(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE_ORDER'], INVOICE_TYPES['SALES_ORDER'], INVOICE_TYPES['OFFER']])) {
    $paid = 0;
} elseif($pro_tybe == INVOICE_TYPES['POS']){
    // If paid amount is sent (which is true for our new POS), use it.
    // Otherwise calculate it (fallback for old behavior)
    if(isset($_POST['paid'])) {
        $paid = floatval($_POST['paid']);
    } else {
        $paid = $headnet;
    }
} else {
    $paid = isset($_POST['paid']) ? floatval($_POST['paid']) : 0;
}

if ($is_save_only || $is_print_receipt_only) {
    $paid = 0;
    $paid_cash = 0;
    $paid_bank = 0;
} elseif ($is_split_line_payment) {
    $paid = 0;
}

// التحقق من صحة البيانات الأساسية
error_log('Validation check - pro_tybe: ' . $pro_tybe . ', store_id: ' . $store_id . ', acc2_id: ' . $acc2_id . ', emp_id: ' . $emp_id);
if ($pro_tybe == 0 || $store_id == 0 || $acc2_id == 0 || $emp_id == 0) {
    error_log('VALIDATION FAILED: Required data missing');
    $missing = [];
    if ($pro_tybe == 0) $missing[] = 'نوع الفاتورة';
    if ($store_id == 0) $missing[] = 'المخزن';
    if ($acc2_id == 0) $missing[] = 'العميل';
    if ($emp_id == 0) $missing[] = 'الموظف';
    die('خطأ: بيانات مطلوبة مفقودة - ' . implode(', ', $missing));
}

// التحقق من وجود أصناف
error_log('Item validation check - itmname set: ' . (isset($_POST['itmname']) ? 'YES' : 'NO'));
if (isset($_POST['itmname'])) {
    error_log('itmname is array: ' . (is_array($_POST['itmname']) ? 'YES' : 'NO'));
    if (is_array($_POST['itmname'])) {
        error_log('itmname array filter count: ' . count(array_filter($_POST['itmname'])));
    }
}
if (!isset($_POST['itmname']) || !is_array($_POST['itmname']) || empty(array_filter($_POST['itmname']))) {
    error_log('VALIDATION FAILED: No items in order');
    die('خطأ: يجب إضافة صنف واحد على الأقل');
}

if ($is_split_line_payment) {
    if ($order_type_db !== 'table') {
        die('خطأ: سداد أصناف محددة متاح لطلبات الطاولات فقط');
    }
    if (!$pos_split_payment_rows) {
        die('خطأ: يجب اختيار صنف واحد على الأقل للسداد');
    }
    if ($paid_cash > 0 && $paid_bank > 0) {
        die('خطأ: سداد الأصناف المحددة يستخدم طريقة دفع واحدة في كل مرة');
    }
    if (($paid_cash + $paid_bank) <= 0) {
        die('خطأ: يجب إدخال مبلغ الدفع قبل تأكيد الدفع');
    }
}

/**
 * دالة الحصول على إعدادات نوع الفاتورة
 * Get invoice type configuration
 */
function posmain_normalize_invoice_date($value, ?string $fallback = null): string
{
    $value = trim((string) $value);
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $fallback = trim((string) ($fallback ?? ''));
    if ($fallback !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
        return $fallback;
    }

    return date('Y-m-d');
}

function getInvoiceConfig($pro_tybe) {
    $configs = [
        INVOICE_TYPES['PURCHASE'] => [
            'note' => 'فاتورة مشتريات',
            'paid_note' => 'سند دفع',
            'disc_type' => ACCOUNTING_TYPES['PURCHASE_DISC'],
            'paid_type' => ACCOUNTING_TYPES['PAYMENT'],
            'cost_account' => 97
        ],
        INVOICE_TYPES['SALES'] => [
            'note' => 'فاتورة مبيعات',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 91
        ],
        INVOICE_TYPES['POS'] => [
            'note' => 'فاتورة ريسيت',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 91
        ],
        INVOICE_TYPES['PURCHASE_RETURN'] => [
            'note' => 'مردود مشتريات',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['PURCHASE_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 97
        ],
        INVOICE_TYPES['SALES_RETURN'] => [
            'note' => 'مردود مبيعات',
            'paid_note' => 'سند دفع',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['PAYMENT'],
            'cost_account' => 91
        ],
        INVOICE_TYPES['PURCHASE_ORDER'] => [
            'note' => 'أمر شراء',
            'paid_note' => 'سند دفع',
            'disc_type' => ACCOUNTING_TYPES['PURCHASE_DISC'],
            'paid_type' => ACCOUNTING_TYPES['PAYMENT'],
            'cost_account' => 97
        ],
        INVOICE_TYPES['SALES_ORDER'] => [
            'note' => 'أمر بيع',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 91
        ],
        INVOICE_TYPES['OFFER'] => [
            'note' => 'عرض سعر',
            'paid_note' => 'سند قبض',
            'disc_type' => ACCOUNTING_TYPES['SALES_DISC'],
            'paid_type' => ACCOUNTING_TYPES['RECEIPT'],
            'cost_account' => 91
        ]
    ];

    return isset($configs[$pro_tybe]) ? $configs[$pro_tybe] : null;
}

/**
 * دالة تحديد الحسابات المحاسبية
 * Get accounting accounts based on invoice type
 */
function getAccountingAccounts($pro_tybe, $store_id, $acc2_id, $fund_id) {
    switch($pro_tybe) {
        case INVOICE_TYPES['PURCHASE']:
            return [
                'acc1' => $store_id,
                'acc2' => $acc2_id,
                'acc3' => $acc2_id,
                'acc4' => 97,
                'acc5' => $acc2_id,
                'acc6' => $fund_id
            ];

        case INVOICE_TYPES['SALES']:
        case INVOICE_TYPES['POS']:
            return [
                'acc1' => $fund_id,      // الصندوق (مدين) - النقدية بتدخل الصندوق
                'acc2' => $acc2_id,      // العميل (دائن) - اللي انتي بتختاريه من الدروب داون
                'acc3' => 91,            // حساب المبيعات (للمرجعية)
                'acc4' => $acc2_id,      // العميل
                'acc5' => $fund_id,      // الصندوق (للدفع)
                'acc6' => $acc2_id       // العميل (للدفع الآجل)
            ];

        case INVOICE_TYPES['PURCHASE_RETURN']:
            return [
                'acc1' => $acc2_id,
                'acc2' => $store_id,
                'acc3' => $acc2_id,
                'acc4' => 97,
                'acc5' => $fund_id,
                'acc6' => $acc2_id
            ];

        case INVOICE_TYPES['SALES_RETURN']:
            return [
                'acc1' => $store_id,
                'acc2' => $acc2_id,
                'acc3' => 91,
                'acc4' => $acc2_id,
                'acc5' => $acc2_id,
                'acc6' => $fund_id
            ];

        case INVOICE_TYPES['PURCHASE_ORDER']:
            return [
                'acc1' => $store_id,
                'acc2' => $acc2_id,
                'acc3' => $acc2_id,
                'acc4' => 97,
                'acc5' => $acc2_id,
                'acc6' => $fund_id
            ];

        case INVOICE_TYPES['SALES_ORDER']:
        case INVOICE_TYPES['OFFER']:
            return [
                'acc1' => $acc2_id,
                'acc2' => $store_id,
                'acc3' => 91,
                'acc4' => $acc2_id,
                'acc5' => $fund_id,
                'acc6' => $acc2_id
            ];

        default:
            throw new InvalidArgumentException('نوع فاتورة غير مدعوم');
    }
}

// الحصول على إعدادات الفاتورة
$config = getInvoiceConfig($pro_tybe);
error_log('Invoice config for pro_tybe ' . $pro_tybe . ': ' . print_r($config, true));
if (!$config) {
    error_log('VALIDATION FAILED: Invalid invoice type');
    die('خطأ: نوع فاتورة غير صحيح');
}

// تحديد الحسابات المحاسبية
$accounts = getAccountingAccounts($pro_tybe, $store_id, $acc2_id, $fund_id);
error_log('Accounting accounts: ' . print_r($accounts, true));

$route_takeaway_service = $pro_tybe === INVOICE_TYPES['POS']
    && $order_type_db === 'takeaway'
    && $submit === 'cash'
    && $selected_order_id <= 0
    && (int) ($_REQUEST['edit_id'] ?? 0) <= 0
    && ($paid_cash + $paid_bank) > 0;

$delivery_v2_enabled = function_exists('posmain_bool')
    ? posmain_bool(getenv('POSMAIN_DELIVERY_V2') !== false ? getenv('POSMAIN_DELIVERY_V2') : '1', true)
    : true;

$route_delivery_service = $delivery_v2_enabled
    && $pro_tybe === INVOICE_TYPES['POS']
    && $order_type_db === 'delivery'
    && $selected_order_id <= 0
    && (int) ($_REQUEST['edit_id'] ?? 0) <= 0;

if ($route_delivery_service) {
    try {
        $deliveryRequest = $_POST;
        $deliveryRequest['store_id'] = $store_id;
        $deliveryRequest['pro_serial'] = $pro_serial;
        $deliveryRequest['pro_date'] = $pro_date;
        $deliveryRequest['accural_date'] = $accural_date;
        $deliveryRequest['acc2_id'] = $acc2_id;
        $deliveryRequest['emp_id'] = $emp_id;
        $deliveryRequest['headtotal'] = $headtotal;
        $deliveryRequest['headdisc'] = $headdisc;
        $deliveryRequest['headplus'] = $headplus;
        $deliveryRequest['headnet'] = $headnet;
        $deliveryRequest['fund_id'] = $fund_id;
        $deliveryRequest['info'] = $info;
        $deliveryRequest['paid_cash'] = $paid_cash;
        $deliveryRequest['paid_bank'] = $paid_bank;
        $deliveryRequest['payment_fund_id'] = $payment_fund_id;
        $deliveryRequest['payment_bank_id'] = $payment_bank_id;
        $deliveryRequest['jal_name'] = $jal_name;
        $deliveryRequest['jal_notes'] = $jal_notes;
        $deliveryRequest['jal_amount'] = $jal_amount;
        $deliveryRequest['delivery_customer_name'] = $delivery_name;
        $deliveryRequest['delivery_customer_phone'] = $delivery_phone;
        $deliveryRequest['delivery_customer_address'] = $delivery_address;
        $deliveryRequest['delivery_client_id'] = $delivery_client_id;
        $deliveryRequest['delivery_zone_name'] = $delivery_zone_name;
        $deliveryRequest['delivery_fee'] = $delivery_fee;
        $deliveryRequest['submit'] = $submit;

        $mutationService = new PosOrderMutationService();
        $serviceResult = $mutationService->createDeliveryOrder($conn, $deliveryRequest, [
            'user_id' => (int) $usid,
        ]);
        $last_op = (int) $serviceResult['data']['order_id'];
        $pro_id = (int) $serviceResult['data']['pro_id'];
        $_SESSION['success_message'] = 'تم حفظ طلب الدليفري بنجاح - رقم الفاتورة: ' . $pro_id;
        if ($submit === 'cash' && ($paid_cash + $paid_bank) > 0) {
            header("Location: ../print/receipt.php?id=$last_op");
        } else {
            $redirectParams = ['edit' => $last_op];
            header('Location: ../pos_barcode.php?' . http_build_query($redirectParams));
        }
        exit;
    } catch (Throwable $e) {
        error_log('ERROR in delivery service route: ' . $e->getMessage());
        error_log('ERROR trace: ' . $e->getTraceAsString());
        posmain_browser_exception_response(
            $e,
            'حدث خطأ أثناء معالجة طلب الدليفري، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
            500,
            false,
            'invoice_delivery_route'
        );
    }
}

if ($route_takeaway_service) {
    try {
        $takeawayRequest = $_POST;
        $takeawayRequest['store_id'] = $store_id;
        $takeawayRequest['pro_serial'] = $pro_serial;
        $takeawayRequest['pro_date'] = $pro_date;
        $takeawayRequest['accural_date'] = $accural_date;
        $takeawayRequest['acc2_id'] = $acc2_id;
        $takeawayRequest['emp_id'] = $emp_id;
        $takeawayRequest['headtotal'] = $headtotal;
        $takeawayRequest['headdisc'] = $headdisc;
        $takeawayRequest['headplus'] = $headplus;
        $takeawayRequest['headnet'] = $headnet;
        $takeawayRequest['fund_id'] = $fund_id;
        $takeawayRequest['info'] = $info;
        $takeawayRequest['paid_cash'] = $paid_cash;
        $takeawayRequest['paid_bank'] = $paid_bank;
        $takeawayRequest['payment_fund_id'] = $payment_fund_id;
        $takeawayRequest['payment_bank_id'] = $payment_bank_id;
        $takeawayRequest['jal_name'] = $jal_name;
        $takeawayRequest['jal_notes'] = $jal_notes;
        $takeawayRequest['jal_amount'] = $jal_amount;

        $mutationService = new PosOrderMutationService();
        $serviceResult = $mutationService->createTakeawayOrder($conn, $takeawayRequest, [
            'user_id' => (int) $usid,
        ]);
        $last_op = (int) $serviceResult['data']['order_id'];
        $pro_id = (int) $serviceResult['data']['pro_id'];
        $_SESSION['success_message'] = 'تم حفظ الطلب بنجاح - رقم الفاتورة: ' . $pro_id;
        header("Location: ../print/receipt.php?id=$last_op");
        exit;
    } catch (Throwable $e) {
        error_log('ERROR in takeaway service route: ' . $e->getMessage());
        error_log('ERROR trace: ' . $e->getTraceAsString());
        posmain_browser_exception_response(
            $e,
            'حدث خطأ أثناء معالجة الفاتورة، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
            500,
            false,
            'invoice_takeaway_route'
        );
    }
}

function nextLegacyInvoiceProId(mysqli $conn, DocumentCounterService $counterService, int $invoiceType): int
{
    $stmt = $conn->prepare("SELECT MAX(CAST(pro_id AS UNSIGNED)) AS max_id FROM ot_head WHERE pro_tybe = ?");
    if (!$stmt) {
        throw new Exception('فشل في تحضير استعلام رقم الفاتورة: ' . $conn->error);
    }
    $stmt->bind_param("i", $invoiceType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $counterService->ensureCounterRow($conn, 0, 0, 'pro_id', 'pro_tybe:' . $invoiceType, $row && $row['max_id'] ? (int) $row['max_id'] : 0);

    return $counterService->nextProId($conn, $invoiceType, 0, 0);
}

function nextLegacyInvoiceJournalId(mysqli $conn, DocumentCounterService $counterService): int
{
    $row = $conn->query("SELECT MAX(journal_id) AS max_id FROM journal_heads")->fetch_assoc();
    $counterService->ensureCounterRow($conn, 0, 0, 'journal_id', 'journal:default', $row && $row['max_id'] ? (int) $row['max_id'] : 0);

    return $counterService->nextJournalId($conn, 0, 0);
}

function posmainInvoiceTableExists(mysqli $conn, string $tableName): bool
{
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");

    return $result && $result->num_rows > 0;
}

function posmainInvoiceDecodeSplitRows($rawPayload): array
{
    $decoded = is_string($rawPayload) && trim($rawPayload) !== ''
        ? json_decode($rawPayload, true)
        : [];
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowIndex = isset($row['row_index']) ? (int) $row['row_index'] : -1;
        $qty = isset($row['qty']) ? (float) $row['qty'] : 0.0;
        if ($rowIndex < 0 || $qty <= 0) {
            continue;
        }
        if (!isset($rows[$rowIndex])) {
            $rows[$rowIndex] = 0.0;
        }
        $rows[$rowIndex] += $qty;
    }

    return $rows;
}

function posmainInvoiceDistributeHeaderDiscountAcrossDetails(mysqli $conn, int $orderId, float $discount): array
{
    if ($orderId <= 0 || $discount <= 0) {
        return ['total' => 0.0, 'net' => 0.0, 'discount' => 0.0, 'profit' => 0.0];
    }

    $stmt = $conn->prepare("
        SELECT id, qty_in, qty_out, discount, det_value, profit
        FROM fat_details
        WHERE fatid = ?
          AND isdeleted = 0
        ORDER BY id ASC
        FOR UPDATE
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $details = [];
    $grossTotal = 0.0;
    while ($row = $result->fetch_assoc()) {
        $row['det_value'] = (float) ($row['det_value'] ?? 0);
        $grossTotal += max(0, $row['det_value']);
        $details[] = $row;
    }
    $stmt->close();

    if (!$details || $grossTotal <= 0) {
        return ['total' => $grossTotal, 'net' => $grossTotal, 'discount' => 0.0, 'profit' => 0.0];
    }

    $discount = min(max(0, $discount), $grossTotal);
    if ($discount <= 0) {
        return ['total' => $grossTotal, 'net' => $grossTotal, 'discount' => 0.0, 'profit' => 0.0];
    }

    $update = $conn->prepare("
        UPDATE fat_details
        SET discount = ?,
            det_value = ?,
            profit = ?
        WHERE id = ?
          AND fatid = ?
    ");

    $remainingDiscount = $discount;
    $lastIndex = count($details) - 1;
    $netTotal = 0.0;
    $profitTotal = 0.0;
    foreach ($details as $index => $detail) {
        $lineGross = max(0, (float) $detail['det_value']);
        if ($index === $lastIndex) {
            $lineDiscount = $remainingDiscount;
        } else {
            $lineDiscount = round($discount * ($lineGross / $grossTotal), 4);
            $lineDiscount = min($lineDiscount, $remainingDiscount);
        }
        $remainingDiscount = max(0, $remainingDiscount - $lineDiscount);

        $qtyBasis = abs((float) ($detail['qty_out'] ?? 0) - (float) ($detail['qty_in'] ?? 0));
        if ($qtyBasis <= 0) {
            $qtyBasis = max(1.0, abs((float) ($detail['qty_out'] ?? 0)), abs((float) ($detail['qty_in'] ?? 0)));
        }

        $newLineDiscount = (float) ($detail['discount'] ?? 0) + ($lineDiscount / $qtyBasis);
        $newValue = max(0, $lineGross - $lineDiscount);
        $newProfit = (float) ($detail['profit'] ?? 0) - $lineDiscount;
        $detailId = (int) $detail['id'];

        $update->bind_param('dddii', $newLineDiscount, $newValue, $newProfit, $detailId, $orderId);
        if (!$update->execute()) {
            throw new Exception('فشل في توزيع الخصم على تفاصيل الطلب');
        }

        $netTotal += $newValue;
        $profitTotal += $newProfit;
    }
    $update->close();

    $stmt = $conn->prepare("
        UPDATE ot_head
        SET pro_value = ?,
            fat_total = ?,
            fat_disc = 0,
            fat_disc_per = 0,
            fat_net = ?,
            profit = ?,
            remaining_amount = GREATEST(0, ? - paid_amount)
        WHERE id = ?
    ");
    $stmt->bind_param('dddddi', $netTotal, $netTotal, $netTotal, $profitTotal, $netTotal, $orderId);
    if (!$stmt->execute()) {
        throw new Exception('فشل في تحديث إجمالي الطلب بعد توزيع الخصم');
    }
    $stmt->close();

    return [
        'total' => $grossTotal,
        'net' => $netTotal,
        'discount' => $discount,
        'profit' => $profitTotal,
    ];
}

function posmainInvoiceLineNoteServiceTablesAvailable(mysqli $conn): bool
{
    foreach (['order_line_notes', 'order_line_modifiers', 'item_modifier_groups', 'modifier_groups', 'modifier_options'] as $tableName) {
        if (!posmainInvoiceTableExists($conn, $tableName)) {
            return false;
        }
    }

    return true;
}

function posmainInvoicePersistKitchenLineNote(
    mysqli $conn,
    ModifierLineNoteService $lineNoteService,
    int $orderId,
    int $detailId,
    int $itemId,
    $note,
    int $userId
): void {
    $note = trim((string) $note);
    if ($note === '' || !posmainInvoiceTableExists($conn, 'order_line_notes')) {
        return;
    }

    if (posmainInvoiceLineNoteServiceTablesAvailable($conn)) {
        try {
            $lineNoteService->saveLineCustomizations(
                $conn,
                $orderId,
                $detailId,
                $itemId,
                [],
                [['note_type' => 'kitchen', 'note_text' => $note]],
                [
                    'modifiers_enabled' => true,
                    'user_id' => $userId,
                ]
            );
            return;
        } catch (Throwable $exception) {
            error_log('Invoice line note service skipped: ' . $exception->getMessage());
        }
    }

    try {
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 500);
        } else {
            $note = substr($note, 0, 500);
        }

        $delete = $conn->prepare("DELETE FROM order_line_notes WHERE order_id = ? AND detail_id = ? AND note_type = 'kitchen'");
        $delete->bind_param('ii', $orderId, $detailId);
        $delete->execute();
        $delete->close();

        $type = 'kitchen';
        $createdBy = $userId > 0 ? $userId : null;
        $insert = $conn->prepare("
            INSERT INTO order_line_notes (order_id, detail_id, note_type, note_text, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->bind_param('iissi', $orderId, $detailId, $type, $note, $createdBy);
        $insert->execute();
        $insert->close();
    } catch (Throwable $exception) {
        error_log('Invoice direct line note persistence skipped: ' . $exception->getMessage());
    }
}

function posmainInvoicePersistLineCustomizations(
    mysqli $conn,
    ModifierLineNoteService $lineNoteService,
    int $orderId,
    int $detailId,
    int $itemId,
    $note,
    $modifiers,
    float $lineQty,
    int $userId
): void {
    $note = trim((string) $note);
    $hasModifierPayload = $modifiers !== null && $modifiers !== '';
    $lineModifiers = posmainInvoiceLineModifiersFromPost($modifiers, $lineQty);
    $notes = $note !== '' ? [['note_type' => 'kitchen', 'note_text' => $note]] : [];

    if (posmainInvoiceLineNoteServiceTablesAvailable($conn) && ($hasModifierPayload || $lineModifiers || $notes)) {
        try {
            $lineNoteService->saveLineCustomizations(
                $conn,
                $orderId,
                $detailId,
                $itemId,
                $lineModifiers,
                $notes,
                [
                    'modifiers_enabled' => true,
                    'user_id' => $userId,
                ]
            );
            return;
        } catch (Throwable $exception) {
            if ($hasModifierPayload || $lineModifiers) {
                throw $exception;
            }
            error_log('Invoice customization service skipped: ' . $exception->getMessage());
        }
    }

    posmainInvoicePersistKitchenLineNote($conn, $lineNoteService, $orderId, $detailId, $itemId, $note, $userId);
}

function posmainInvoiceLineModifiersFromPost($value, float $lineQty): array
{
    if ($value === null || $value === '') {
        return [];
    }

    if (is_string($value)) {
        $decoded = json_decode(trim($value), true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        return [];
    }

    $lineQty = $lineQty > 0 ? $lineQty : 1.0;
    $modifiers = [];
    foreach ($value as $modifier) {
        if (is_array($modifier)) {
            $optionId = (int) ($modifier['option_id'] ?? $modifier['id'] ?? $modifier['modifier_option_id'] ?? 0);
            $qty = (float) ($modifier['qty'] ?? $modifier['quantity'] ?? 1);
        } else {
            $optionId = (int) $modifier;
            $qty = 1.0;
        }
        if ($optionId <= 0 || $qty <= 0) {
            continue;
        }
        $modifiers[] = [
            'option_id' => $optionId,
            'qty' => $qty * $lineQty,
        ];
    }

    return $modifiers;
}
// حساب النسب المئوية للخصم والإضافي
$fat_disc_per = ($headtotal > 0 && $headdisc > 0) ? number_format($headdisc/$headtotal*100, 2) : 0;
$fat_plus_per = ($headtotal > 0 && $headplus > 0) ? number_format($headplus/$headtotal*100, 2) : 0;

// بدء المعاملة لضمان تماسك البيانات
error_log('Starting database transaction');
try {
    error_log('Starting database transaction');
    $conn->begin_transaction();
    error_log('Database transaction started successfully');
    $lockedTableOrder = null;
    $counterService = new DocumentCounterService();
    $lineNoteService = new ModifierLineNoteService();
    $recipeLifecycleBridge = new LegacyInvoiceRecipeLifecycleBridge();
    $inventoryInvoiceBridge = new InventoryInvoiceBridge();
    $pro_id = null;
    $insertedDetailIdsByPostIndex = [];
    $inventoryInvoiceBridgeLines = [];
    $splitPaymentResult = null;

    $edit_id = isset($_REQUEST['edit_id']) ? intval($_REQUEST['edit_id']) : 0;
    if ($order_type_db === 'table' && $selected_order_id > 0) {
        $edit_id = $selected_order_id;
    }

    if ($order_type_db === 'table') {
        if ($edit_id > 0) {
            $lockedTableOrder = $tableOrderService->findActiveOrderByTableAndOrderId($conn, $table_id, $edit_id, true);
            if (!$lockedTableOrder) {
                throw new Exception('الطلب المحدد لا يخص هذه الطاولة أو لم يعد نشطاً');
            }
        } else {
            $existingTableOrder = $tableOrderService->findActiveOrderByTableId($conn, $table_id, true);
            if ($existingTableOrder) {
                throw new Exception('هذه الطاولة لديها طلب نشط بالفعل. أعد تحميل الطلب قبل الحفظ.');
            }
        }
    }

    if ($edit_id > 0) {
        // --- تحديث فاتورة موجودة (UPDATE) ---
        error_log('Updating existing order ID: ' . $edit_id);

        // تحديث رأس الفاتورة (مع الحفاظ على تاريخ الإنشاء الأصلي pro_date)
        $stmt = $conn->prepare(
            "UPDATE ot_head SET
                pro_tybe = ?, info = ?, accural_date = ?,
                pro_serial = ?, store_id = ?, emp_id = ?, emp2_id = ?,
                acc1 = ?, acc2 = ?, pro_value = ?, fat_total = ?,
                fat_disc = ?, fat_disc_per = ?, fat_plus = ?, fat_plus_per = ?,
                fat_net = ?, user = ?, jal_name = ?, jal_notes = ?, jal_amount = ?
            WHERE id = ?"
        );

        if (!$stmt) {
            throw new Exception('فشل في تحضير استعلام تحديث الفاتورة: ' . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssssssssssssssi",
            $pro_tybe, $info, $accural_date,
            $pro_serial, $store_id, $emp_id, $emp_id,
            $accounts['acc1'], $accounts['acc2'], $headtotal, $headtotal,
            $headdisc, $fat_disc_per, $headplus, $fat_plus_per,
            $headnet, $usid, $jal_name, $jal_notes, $jal_amount, $edit_id
        );

        if (!$stmt->execute()) {
            throw new Exception('فشل في تحديث الفاتورة: ' . $stmt->error);
        }
        $stmt->close();

        // Replace editable line items through the canonical fat_details.fatid link.
        $stmt_fetch_pro_id = $conn->prepare("SELECT pro_id FROM ot_head WHERE id = ?");
        $stmt_fetch_pro_id->bind_param("i", $edit_id);
        $stmt_fetch_pro_id->execute();
        $result_pro_id = $stmt_fetch_pro_id->get_result();
        $row_pro_id = $result_pro_id->fetch_assoc();
        $stmt_fetch_pro_id->close();

        if ($row_pro_id) {
            $original_pro_id = $row_pro_id['pro_id'];
            if ($order_type_db === 'table') {
                if ((int) $pro_tybe === INVOICE_TYPES['POS']) {
                    $recipeLifecycleBridge->recordExistingLinesCancelled(
                        $conn,
                        (int) $edit_id,
                        'table',
                        'dine_in',
                        'legacy_invoice_updated',
                        ['user_id' => (int) $usid]
                    );
                }
                $stmt_soft_delete_details = $conn->prepare("UPDATE fat_details SET isdeleted = 1 WHERE fatid = ?");
                $stmt_soft_delete_details->bind_param("i", $edit_id);
                $stmt_soft_delete_details->execute();
                $stmt_soft_delete_details->close();
            } else {
                $conn->query("DELETE FROM fat_details WHERE fatid = '$edit_id'");
            }
            if ($order_type_db !== 'table') {
                // Also delete related journal entries and payment operations if they exist and are linked by op_id/op2.
                $journal_query = $conn->query("SELECT id FROM journal_heads WHERE op_id = '$edit_id'");
                if ($journal_query) {
                    while ($journal_row = $journal_query->fetch_assoc()) {
                        $jid = $journal_row['id'];
                        $conn->query("DELETE FROM journal_entries WHERE journal_id = '$jid'");
                    }
                }
                $conn->query("DELETE FROM journal_heads WHERE op_id = '$edit_id'");
                $conn->query("DELETE FROM ot_head WHERE op2 = '$edit_id'"); // Delete payment/discount operations linked to this invoice.
            }
        } else {
            throw new Exception('فشل في العثور على رقم الفاتورة الأصلي للتحديث.');
        }

        $last_op = $edit_id; // last_op now refers to the primary key of the updated ot_head record
        $pro_id = $original_pro_id;
        error_log('Order header updated successfully for ID: ' . $last_op);

    } else {
        // --- إدخال فاتورة جديدة (INSERT) ---
        $pro_id = nextLegacyInvoiceProId($conn, $counterService, (int) $pro_tybe);
        $stmt = $conn->prepare(
            "INSERT INTO ot_head (
                pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
                accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit,
                fat_total, fat_disc, fat_disc_per, fat_plus, fat_plus_per,
                fat_tax, fat_tax_per, fat_net, user, jal_name, jal_notes, jal_amount
            ) VALUES (
                ?, ?, 1, 1, ?, ?, ?, ?, 1, ?, 1, ?, ?, ?, ?, ?, ?, 0, 1, 0,
                ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?
            )"
        );

        if (!$stmt) {
            throw new Exception('فشل في تحضير استعلام إدخال الفاتورة: ' . $conn->error);
        }

        $stmt->bind_param(
            "sssssssssssssssssssssss",
            $pro_id, $pro_tybe, $pro_tybe, $info, $pro_date, $accural_date,
            $pro_serial, $store_id, $emp_id, $emp_id, $accounts['acc1'],
            $accounts['acc2'], $headtotal, $headtotal, $headdisc,
            $fat_disc_per, $headplus, $fat_plus_per, $headnet, $usid, $jal_name, $jal_notes, $jal_amount
        );

        error_log('Executing order header insert');
        if (!$stmt->execute()) {
            error_log('FAILED to insert order header: ' . $stmt->error);
            throw new Exception('فشل في إدخال الفاتورة: ' . $stmt->error);
        }

        $last_op = $conn->insert_id; // last_op now refers to the primary key of the new ot_head record
	        error_log('Order header inserted successfully with ID: ' . $last_op);
	        $stmt->close();
	    }

        $statusPaidAmount = max(0, $paid_cash + $paid_bank);
        if ($order_type_db === 'table' && ($submit === 'save' || $is_split_line_payment || $is_print_receipt_only)) {
            $statusPaidAmount = $lockedTableOrder ? (float) ($lockedTableOrder['paid_amount'] ?? 0) : 0;
        }
        $statusPaidAmount = min($statusPaidAmount, max(0, $headnet));
        $statusRemainingAmount = max(0, $headnet - $statusPaidAmount);
        if ($statusPaidAmount <= 0) {
            $payment_status_db = 'unpaid';
            $order_status_db = 'active';
            $invoice_status_db = 'draft';
        } elseif ($statusRemainingAmount <= 0.0001) {
            $payment_status_db = 'paid';
            $order_status_db = 'completed';
            $invoice_status_db = 'completed';
        } else {
            $payment_status_db = 'partial';
            $order_status_db = 'active';
            $invoice_status_db = 'draft';
        }

        $waiter_id = $emp_id > 0 ? $emp_id : null;
        if ($order_type_db === 'table') {
            $stmt_structured = $conn->prepare(
                "UPDATE ot_head
                 SET table_id = ?,
                     order_type = 'table',
                     payment_status = ?,
                     invoice_status = ?,
                     order_status = ?,
                     waiter_id = ?,
                     paid_amount = ?,
                     remaining_amount = ?,
                     payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE payment_date END,
                     completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
                 WHERE id = ?"
            );
            $stmt_structured->bind_param(
                "isssiddssi",
                $table_id,
                $payment_status_db,
                $invoice_status_db,
                $order_status_db,
                $waiter_id,
                $statusPaidAmount,
                $statusRemainingAmount,
                $payment_status_db,
                $order_status_db,
                $last_op
            );
        } else {
            $stmt_structured = $conn->prepare(
                "UPDATE ot_head
                 SET table_id = NULL,
                     order_type = ?,
                     payment_status = ?,
                     invoice_status = ?,
                     order_status = ?,
                     paid_amount = ?,
                     remaining_amount = ?,
                     payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE payment_date END,
                     completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
                 WHERE id = ?"
            );
            $stmt_structured->bind_param(
                "ssssddssi",
                $order_type_db,
                $payment_status_db,
                $invoice_status_db,
                $order_status_db,
                $statusPaidAmount,
                $statusRemainingAmount,
                $payment_status_db,
                $order_status_db,
                $last_op
            );
        }
        if (!$stmt_structured->execute()) {
            throw new Exception('فشل في تحديث حالة الطلب: ' . $stmt_structured->error);
        }
        $stmt_structured->close();

    // إنشاء القيود المحاسبية (فقط للفواتير الفعلية، ليس للأوامر أو العروض)
    $should_create_payment_vouchers = !$is_save_only && !$is_split_line_payment;
    if ($should_create_payment_vouchers && !in_array($pro_tybe, [INVOICE_TYPES['PURCHASE_ORDER'], INVOICE_TYPES['SALES_ORDER'], INVOICE_TYPES['OFFER']])) {
        $journal_id = nextLegacyInvoiceJournalId($conn, $counterService);

        // إدخال رأس القيد
        $stmt = $conn->prepare(
            "INSERT INTO journal_heads (journal_id, total, jdate, details, user, op_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $details = $config['note'] . " _ " . $last_op;
        $stmt->bind_param("sdssss", $journal_id, $headnet, $pro_date, $details, $usid, $last_op);

        if (!$stmt->execute()) {
            throw new Exception('فشل في إدخال رأس القيد: ' . $stmt->error);
        }

        $journal_lastid = $conn->insert_id;
        $stmt->close();

        // القيد الأساسي للفاتورة (حسب نوع الفاتورة)
        if(in_array($pro_tybe, [INVOICE_TYPES['SALES'], INVOICE_TYPES['POS']])) {
            // فاتورة مبيعات: مدين العميل / دائن المبيعات

            // المدين: العميل
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
                 VALUES (?, ?, ?, 0, 0, ?)"
            );
            $stmt->bind_param("ssds", $journal_lastid, $acc2_id, $headnet, $last_op);

            if (!$stmt->execute()) {
                throw new Exception('فشل في إدخال القيد المدين: ' . $stmt->error);
            }
            $stmt->close();

            // الدائن: المبيعات (حساب 91)
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
                 VALUES (?, ?, 0, ?, 1, ?)"
            );
            $sales_account = 91;
            $stmt->bind_param("ssds", $journal_lastid, $sales_account, $headnet, $last_op);

            if (!$stmt->execute()) {
                throw new Exception('فشل في إدخال القيد الدائن: ' . $stmt->error);
            }
            $stmt->close();

        } else {
            // فواتير أخرى (مشتريات، مردودات، إلخ)

            // إدخال تفاصيل القيد (المدين)
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
                 VALUES (?, ?, ?, 0, 0, ?)"
            );
            $stmt->bind_param("ssds", $journal_lastid, $accounts['acc1'], $headnet, $last_op);

            if (!$stmt->execute()) {
                throw new Exception('فشل في إدخال القيد المدين: ' . $stmt->error);
            }
            $stmt->close();

            // إدخال تفاصيل القيد (الدائن)
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
                 VALUES (?, ?, 0, ?, 1, ?)"
            );
            $stmt->bind_param("ssds", $journal_lastid, $accounts['acc2'], $headnet, $last_op);

            if (!$stmt->execute()) {
                throw new Exception('فشل في إدخال القيد الدائن: ' . $stmt->error);
            }
            $stmt->close();
        }
    }

    // معالجة المدفوعات إذا وجدت (فقط للفواتير الفعلية، ليس للأوامر أو العروض)
    // الدفع المقسم: كاش + صرافة
    if (!in_array($pro_tybe, [INVOICE_TYPES['PURCHASE_ORDER'], INVOICE_TYPES['SALES_ORDER'], INVOICE_TYPES['OFFER']])) {

        // حساب المبلغ الفعلي الداخل للصندوق (المدفوع - الباقي)
        $total_paid = $paid_cash + $paid_bank;
        $change = max(0, $total_paid - $headnet); // الباقي (المرتجع للعميل)

        // المبلغ الفعلي الداخل = المدفوع - الباقي
        $actual_cash_received = max(0, $paid_cash - $change);
        $actual_bank_received = $paid_bank; // البنك لا يتأثر بالباقي (الباقي يُرد من الكاش فقط)

        // إذا كان الباقي أكبر من الكاش المدفوع، نخصم الفرق من البنك
        if ($change > $paid_cash) {
            $remaining_change = $change - $paid_cash;
            $actual_cash_received = 0;
            $actual_bank_received = max(0, $paid_bank - $remaining_change);
        }

        error_log('=== PAYMENT CALCULATION ===');
        error_log('Total paid: ' . $total_paid);
        error_log('Net amount: ' . $headnet);
        error_log('Change (return): ' . $change);
        error_log('Actual cash received: ' . $actual_cash_received);
        error_log('Actual bank received: ' . $actual_bank_received);
        error_log('==========================');

        // معالجة الدفع الكاش (فقط إذا كان هناك مبلغ فعلي داخل)
        if ($actual_cash_received > 0 && $payment_fund_id > 0) {
            error_log('Processing cash payment: ' . $actual_cash_received . ' to fund: ' . $payment_fund_id);

            // إدخال عملية الدفع الكاش
            $cash_op_id = nextLegacyInvoiceProId($conn, $counterService, (int) $config['paid_type']);
            $stmt = $conn->prepare(
                "INSERT INTO ot_head (
                    pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date,
                    emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
                ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)"
            );

            $cash_info = $info . " - دفع كاش";
            $stmt->bind_param(
                "ssssssdssss",
                $cash_op_id, $config['paid_type'], $config['paid_type'], $cash_info, $pro_date,
                $emp_id, $payment_fund_id, $acc2_id, $actual_cash_received, $usid, $last_op
            );

            if (!$stmt->execute()) {
                throw new Exception('فشل في إدخال عملية الدفع الكاش: ' . $stmt->error);
            }

            $last_cash_paid = $conn->insert_id;
            $stmt->close();

            // إدخال قيد الدفع الكاش
            $journal_id = nextLegacyInvoiceJournalId($conn, $counterService);

            // رأس قيد الدفع الكاش
            $stmt = $conn->prepare(
                "INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $cash_details = $config['paid_note'] . " كاش _ " . $pro_id;
            $stmt->bind_param("ssdssss", $journal_id, $last_cash_paid, $actual_cash_received, $pro_date, $cash_details, $usid, $last_op);
            $stmt->execute();
            $journal_lastid = $conn->insert_id;
            $stmt->close();

            // تفاصيل قيد الدفع الكاش (مدين - الصندوق)
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
                 VALUES (?, ?, ?, 0, 0, ?)"
            );
            $stmt->bind_param("ssds", $journal_lastid, $payment_fund_id, $actual_cash_received, $last_op);
            $stmt->execute();
            $stmt->close();

            // تفاصيل قيد الدفع الكاش (دائن - العميل)
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
                 VALUES (?, ?, 0, ?, 1, ?)"
            );
            $stmt->bind_param("ssds", $journal_lastid, $acc2_id, $actual_cash_received, $last_op);
            $stmt->execute();
            $stmt->close();

            error_log('Cash payment processed successfully');
        }

        // معالجة الدفع الصرافة (البنك)
        if ($actual_bank_received > 0 && $payment_bank_id > 0) {
            error_log('Processing bank payment: ' . $actual_bank_received . ' to bank: ' . $payment_bank_id);

            // إدخال عملية الدفع الصرافة
            $bank_op_id = nextLegacyInvoiceProId($conn, $counterService, (int) $config['paid_type']);
            $stmt = $conn->prepare(
                "INSERT INTO ot_head (
                    pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date,
                    emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
                ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)"
            );

            $bank_info = $info . " - دفع صرافة";
            $stmt->bind_param(
                "ssssssdssss",
                $bank_op_id, $config['paid_type'], $config['paid_type'], $bank_info, $pro_date,
                $emp_id, $payment_bank_id, $acc2_id, $actual_bank_received, $usid, $last_op
            );

            if (!$stmt->execute()) {
                throw new Exception('فشل في إدخال عملية الدفع الصرافة: ' . $stmt->error);
            }

            $last_bank_paid = $conn->insert_id;
            $stmt->close();

            // إدخال قيد الدفع الصرافة
            $journal_id = nextLegacyInvoiceJournalId($conn, $counterService);

            // رأس قيد الدفع الصرافة
            $stmt = $conn->prepare(
                "INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $bank_details = $config['paid_note'] . " صرافة _ " . $pro_id;
            $stmt->bind_param("ssdssss", $journal_id, $last_bank_paid, $actual_bank_received, $pro_date, $bank_details, $usid, $last_op);
            $stmt->execute();
            $journal_lastid = $conn->insert_id;
            $stmt->close();

            // تفاصيل قيد الدفع الصرافة (مدين - البنك)
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
                 VALUES (?, ?, ?, 0, 0, ?)"
            );
            $stmt->bind_param("ssds", $journal_lastid, $payment_bank_id, $actual_bank_received, $last_op);
            $stmt->execute();
            $stmt->close();

            // تفاصيل قيد الدفع الصرافة (دائن - العميل)
            $stmt = $conn->prepare(
                "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
                 VALUES (?, ?, 0, ?, 1, ?)"
            );
            $stmt->bind_param("ssds", $journal_lastid, $acc2_id, $actual_bank_received, $last_op);
            $stmt->execute();
            $stmt->close();

            error_log('Bank payment processed successfully');
        }
    }

    // معالجة تفاصيل الفواتير باستخدام Prepared Statements
    error_log('Processing order items');
    if (isset($_POST['itmname'], $_POST['itmqty'], $_POST['itmprice'], $_POST['itmdisc'])) {
        error_log('All item arrays are set');
        // تحضير استعلام إدخال تفاصيل الفاتورة
        $stmt_details = $conn->prepare(
            "INSERT INTO fat_details (
                pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                discount, det_value, fatid, fat_tybe, det_store, cost_price, profit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt_details) {
            throw new Exception('فشل في تحضير استعلام تفاصيل الفاتورة: ' . $conn->error);
        }

        // تحضير استعلام الحصول على بيانات الصنف
        $stmt_item = $conn->prepare("SELECT cost_price, itmqty FROM myitems WHERE id = ?");
        if (!$stmt_item) {
            throw new Exception('فشل في تحضير استعلام بيانات الصنف: ' . $conn->error);
        }

        // تحضير استعلام تحديث بيانات الصنف
        $stmt_update = $conn->prepare("UPDATE myitems SET last_price = ?, cost_price = ? WHERE id = ?");
        if (!$stmt_update) {
            throw new Exception('فشل في تحضير استعلام تحديث الصنف: ' . $conn->error);
        }

        // معالجة كل صنف
        foreach ($_POST['itmname'] as $index => $itmname) {
            if (empty($itmname)) continue;

            $itmname = intval($itmname);
            $itmqty  = floatval($_POST['itmqty'][$index]  ?? 1);
            $itmprice = floatval($_POST['itmprice'][$index] ?? 0);
            $itmdisc  = floatval($_POST['itmdisc'][$index]  ?? 0);
            $u_val   = floatval($_POST['u_val'][$index]   ?? 1);
            $line_note = $_POST['itmnote'][$index] ?? '';
            $line_modifiers = $_POST['itmmodifiers'][$index] ?? null;
            if ($u_val <= 0) $u_val = 1; // حماية من القسمة على صفر

            // تحديد الكميات حسب نوع الفاتورة
            // أوامر الشراء (12) وأوامر البيع (13) وعروض الأسعار (14) لا تؤثر على المخزون
            if(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE_ORDER'], INVOICE_TYPES['SALES_ORDER'], INVOICE_TYPES['OFFER']])) {
                // أوامر الشراء والبيع وعروض الأسعار → لا تؤثر على المخزون
                $qty_in = 0;
                $qty_out = 0;
            } elseif(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE'], INVOICE_TYPES['SALES_RETURN']])) {
                // مشتريات، مردود مبيعات → كمية واردة
                $qty_in = $itmqty * $u_val;
                $qty_out = 0;
            } elseif(in_array($pro_tybe, [INVOICE_TYPES['SALES'], INVOICE_TYPES['POS'], INVOICE_TYPES['PURCHASE_RETURN']])) {
                // مبيعات، كاشير، مردود مشتريات → كمية منصرفة
                $qty_in = 0;
                $qty_out = $itmqty * $u_val;
            } else {
                $qty_in = 0;
                $qty_out = 0;
            }

            $det_value = $itmqty * ($itmprice - $itmdisc);

            // الحصول على بيانات الصنف الحالية
            $stmt_item->bind_param("i", $itmname);
            $stmt_item->execute();
            $result = $stmt_item->get_result();
            $rowbl = $result->fetch_assoc();

            if (!$rowbl) {
                throw new Exception('صنف غير موجود: ' . $itmname);
            }

            $oldprice = floatval($rowbl['cost_price']);
            $oldqty = floatval($rowbl['itmqty']);
            $cost_price = $oldprice;
            $itmprofit = 0;

            // حساب التكلفة والربح
            if(in_array($pro_tybe, [INVOICE_TYPES['PURCHASE'], INVOICE_TYPES['PURCHASE_ORDER']])) {
                // حساب سعر التكلفة المتوسط
                $unit_price = $itmprice / $u_val;
                $oldbalance = $oldprice * $oldqty;
                $newbalance = $qty_in * $unit_price;
                $total_balance = $oldbalance + $newbalance;
                $total_qty = $oldqty + $qty_in;

                if($total_qty > 0) {
                    $cost_price = $total_balance / $total_qty;
                }

                // تحديث بيانات الصنف
                $stmt_update->bind_param("sss", $unit_price, $cost_price, $itmname);
                if (!$stmt_update->execute()) {
                    throw new Exception('فشل في تحديث بيانات الصنف ' . $itmname);
                }

                $itmprice = $unit_price;

            } elseif (in_array($pro_tybe, [INVOICE_TYPES['SALES'], INVOICE_TYPES['POS'], INVOICE_TYPES['OFFER']])) {
                // حساب الربح للمبيعات
                $unit_price = $itmprice / $u_val;
                $itmprofit = $itmqty * $u_val * ($unit_price - $oldprice);
                $itmprice = $unit_price;
            }

            // إدخال تفاصيل الفاتورة
            $stmt_details->bind_param(
                "ssssssssssssss",
                $pro_tybe, $last_op, $itmname, $u_val, $qty_in, $qty_out,
                $itmprice, $itmdisc, $det_value, $last_op, $pro_tybe,
                $store_id, $cost_price, $itmprofit
            );

            if (!$stmt_details->execute()) {
                throw new Exception('فشل في إدخال تفاصيل الصنف ' . $itmname);
            }
            $insertedDetailId = (int) $conn->insert_id;
            $insertedDetailIdsByPostIndex[(int) $index] = $insertedDetailId;
            $inventoryInvoiceBridgeLines[] = [
                'id' => $insertedDetailId,
                'item_id' => (int) $itmname,
                'qty_in' => (string) $qty_in,
                'qty_out' => (string) $qty_out,
                'u_val' => (string) $u_val,
                'cost_price' => (string) $cost_price,
                'det_store' => (int) $store_id,
            ];

            posmainInvoicePersistLineCustomizations(
                $conn,
                $lineNoteService,
                (int) $last_op,
                $insertedDetailId,
                (int) $itmname,
                $line_note,
                $line_modifiers,
                (float) $itmqty,
                (int) $usid
            );
        }

        // إغلاق الاستعلامات
        $stmt_details->close();
        $stmt_item->close();
        $stmt_update->close();
    }

    if ($inventoryInvoiceBridgeLines && $edit_id <= 0 && !$is_split_line_payment) {
        try {
            $inventoryBridgeResult = $inventoryInvoiceBridge->recordInvoiceLines(
                $conn,
                (int) $pro_tybe,
                (int) $last_op,
                $inventoryInvoiceBridgeLines,
                [
                    'store_id' => (int) $store_id,
                    'user_id' => (int) $usid,
                    'channel' => (int) $pro_tybe === INVOICE_TYPES['POS'] ? 'pos' : 'invoice',
                    'order_type' => $order_type_db,
                    'source_system' => 'legacy_doadd_invoice',
                ]
            );
            if (!empty($inventoryBridgeResult['errors'])) {
                error_log('Inventory invoice bridge shadow errors: ' . json_encode($inventoryBridgeResult['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (Throwable $inventoryBridgeException) {
            error_log('Inventory invoice bridge shadow failed: ' . $inventoryBridgeException->getMessage());
        }
    }

    if ((int) $pro_tybe === INVOICE_TYPES['POS'] && !$is_split_line_payment) {
        $recipeChannel = $order_type_db === 'table' ? 'table' : 'pos';
        $recipeOrderType = $order_type_db === 'table'
            ? 'dine_in'
            : ($order_type_db === 'delivery' ? 'delivery' : 'takeaway');
        $recipeContext = ['user_id' => (int) $usid];
        $recipeLifecycleBridge->recordCurrentLinesAdded(
            $conn,
            (int) $last_op,
            $recipeChannel,
            $recipeOrderType,
            $recipeContext
        );

        if ($payment_status_db === 'paid' && $order_status_db === 'completed') {
            $recipeLifecycleBridge->recordCurrentOrderPaid(
                $conn,
                (int) $last_op,
                $recipeChannel,
                $recipeOrderType,
                $recipeContext
            );
        }
    }
    // تحديث إجمالي الأرباح للمبيعات
    if(in_array($pro_tybe, [INVOICE_TYPES['SALES'], INVOICE_TYPES['POS'], INVOICE_TYPES['OFFER']])) {
        $stmt = $conn->prepare("SELECT SUM(profit) AS tprofit FROM fat_details WHERE fatid = ?");
        $stmt->bind_param("i", $last_op);
        $stmt->execute();
        $result = $stmt->get_result();
        $rowprofit = $result->fetch_assoc();
        $ot_profit = $rowprofit['tprofit'] ?? 0;
        $stmt->close();

        // تحديث رقم الربح في رأس الفاتورة
        $stmt = $conn->prepare("UPDATE ot_head SET profit = ? WHERE id = ?");
        $stmt->bind_param("ss", $ot_profit, $last_op);
        $stmt->execute();
        $stmt->close();
    }

    if ($is_split_line_payment && $headdisc > 0) {
        $discountedTotals = posmainInvoiceDistributeHeaderDiscountAcrossDetails(
            $conn,
            (int) $last_op,
            (float) $headdisc
        );
        $headdisc = 0;
        $fat_disc_per = 0;
        $headtotal = (float) ($discountedTotals['net'] ?? $headtotal);
        $headnet = (float) ($discountedTotals['net'] ?? $headnet);
    }

    if ($order_type_db === 'table') {
        if ($order_status_db === 'active' && in_array($payment_status_db, ['unpaid', 'partial'], true)) {
            $tableOrderService->markTableOccupied($conn, $table_id);
        } elseif (!$empty_table_after_payment) {
            $tableOrderService->markTableOccupied($conn, $table_id);
        } else {
            $tableOrderService->setTableFreeIfNoActiveOrder($conn, $table_id);
        }
    }

    if ($is_split_line_payment) {
        $splitItems = [];
        foreach ($pos_split_payment_rows as $rowIndex => $qty) {
            $detailId = $insertedDetailIdsByPostIndex[(int) $rowIndex] ?? 0;
            if ($detailId <= 0) {
                throw new Exception('تعذر ربط الأصناف المحددة بتفاصيل الطلب');
            }
            $splitItems[] = [
                'detail_id' => $detailId,
                'qty' => (float) $qty,
            ];
        }

        $splitPaymentMethod = trim((string) ($_POST['pos_split_payment_method'] ?? ''));
        if ($splitPaymentMethod === '') {
            $splitPaymentMethod = $paid_bank > 0 ? 'bank' : 'cash';
        }
        $splitPaidAmount = (float) ($paid_cash + $paid_bank);
        $mutationService = new PosOrderMutationService();
        $splitPaymentResult = $mutationService->splitTablePayment($conn, [
            'order_id' => (int) $last_op,
            'table_id' => (int) $table_id,
            'items' => $splitItems,
            'paid_amount' => $splitPaidAmount,
            'payment_method' => $splitPaymentMethod,
            'user_id' => (int) $usid,
        ], [
            'user_id' => (int) $usid,
            'in_transaction' => true,
            'event_source' => 'pos_cashier_split_payment',
        ]);
        $split_receipt_order_id = (int) ($splitPaymentResult['data']['new_invoice_id'] ?? 0);
    }

    if ((int) $pro_tybe === INVOICE_TYPES['POS']) {
        $syncOutbox = new SyncOutboxEventService();
        $syncOutbox->recordOrderSnapshot($conn, (int) $last_op, [
            'event_type' => $is_split_line_payment ? 'order.updated' : ($edit_id > 0 ? 'order.updated' : 'order.saved'),
            'source_system' => $is_split_line_payment ? 'pos_cashier_split_payment' : 'pos_cashier',
        ]);
        if ($is_split_line_payment && $split_receipt_order_id > 0) {
            $syncOutbox->recordOrderSnapshot($conn, $split_receipt_order_id, [
                'event_type' => 'order.split_paid',
                'source_system' => 'pos_cashier_split_payment',
            ]);
        }
        if ($order_type_db === 'table' && $table_id > 0) {
            $activeOrderId = $order_status_db === 'active' ? (int) $last_op : null;
            if ($is_split_line_payment && is_array($splitPaymentResult)) {
                $activeOrderId = $splitPaymentResult['data']['active_order_id'] ?? null;
            }
            $syncOutbox->recordTableSnapshot($conn, $table_id, [
                'event_type' => 'table.updated',
                'source_system' => $is_split_line_payment ? 'pos_cashier_split_payment' : 'pos_cashier',
                'active_order_id' => $activeOrderId,
            ]);
        }
    }

	    // إتمام المعاملة
	    error_log('Committing transaction');
    $conn->commit();
    error_log('Transaction committed successfully');

    // تسجيل العملية
    $process_types = [
        INVOICE_TYPES['PURCHASE'] => 'add buy',
        INVOICE_TYPES['SALES'] => 'add sales',
        INVOICE_TYPES['POS'] => 'add cash'
    ];

    $process_type = $process_types[$pro_tybe] ?? 'add invoice';
    $stmt = $conn->prepare("INSERT INTO process (type) VALUES (?)");
    $stmt->bind_param("s", $process_type);
    $stmt->execute();
    $stmt->close();

    // تعيين رسالة نجاح
    $_SESSION['success_message'] = 'تم حفظ الطلب بنجاح - رقم الفاتورة: ' . $pro_id;

} catch (Exception $e) {
    // إلغاء المعاملة في حالة الخطأ
    error_log('ERROR in transaction: ' . $e->getMessage());
    error_log('ERROR trace: ' . $e->getTraceAsString());
    $conn->rollback();
    error_log('خطأ في معالجة الفاتورة: ' . $e->getMessage());
    posmain_browser_exception_response(
        $e,
        'حدث خطأ أثناء معالجة الفاتورة، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
        500,
        false,
        'invoice_transaction'
    );
}

// إعادة التوجيه حسب نوع العملية
error_log('=== REDIRECT START ===');
error_log('Submit value: ' . $submit);
error_log('Submit raw from POST: ' . (isset($_POST['submit']) ? $_POST['submit'] : 'NOT SET'));
error_log('Invoice type (pro_tybe): ' . $pro_tybe);
error_log('Last operation ID: ' . $last_op);
error_log('All POST keys: ' . implode(', ', array_keys($_POST)));

if ($submit == 'print') {
    error_log('CONDITION MATCHED: submit == print');
    $redirect_url = "../print/print_sales.php?id=$last_op";
    error_log('Redirecting to: ' . $redirect_url);
    header("Location: $redirect_url");
    error_log('Header sent - this should not appear if redirect works');
    exit;
} elseif ($submit == 'cash') {
    error_log('CONDITION MATCHED: submit == cash');
    $redirect_url = "../print/receipt.php?id=$last_op";
    error_log('Redirecting to: ' . $redirect_url);

    // التحقق من طلب القفل بعد الحفظ والطباعة
    if (isset($_POST['lock_after_save']) && $_POST['lock_after_save'] == '1') {
        error_log('Lock after save and print requested');
        $_SESSION['lock_after_print'] = true;
    }

    header("Location: $redirect_url");
    error_log('Header sent - this should not appear if redirect works');
    exit;
} elseif ($submit == 'split_cash') {
    error_log('CONDITION MATCHED: submit == split_cash');
    $receipt_id = $split_receipt_order_id > 0 ? $split_receipt_order_id : $last_op;
    $redirect_url = "../print/receipt.php?id=$receipt_id";
    error_log('Redirecting to split receipt: ' . $redirect_url);
    header("Location: $redirect_url");
    exit;
} elseif ($submit == 'print_receipt') {
    error_log('CONDITION MATCHED: submit == print_receipt');
    $redirect_url = "../print/receipt.php?id=$last_op";
    error_log('Redirecting to: ' . $redirect_url);
    header("Location: $redirect_url");
    exit;
} elseif ($submit == 'save') {
    error_log('Redirecting with save action');
    // For save action, redirect back to POS for POS invoices, or to sales page for others
    if ($pro_tybe == INVOICE_TYPES['POS']) {
        error_log('Redirecting to POS barcode page');

        // التحقق من طلب القفل بعد الحفظ
        if (isset($_POST['lock_after_save']) && $_POST['lock_after_save'] == '1') {
            error_log('Lock after save requested - redirecting to logout');
            header("Location: ../pos_barcode.php?logout=1");
        } else {
            $redirectParams = ['edit' => (int) $last_op];
            if ($order_type_db === 'table' && $table_id > 0) {
                $redirectParams['table'] = (int) $table_id;
            }
            $redirectUrl = '../pos_barcode.php?' . http_build_query($redirectParams);
            error_log('Header: Location: ' . $redirectUrl);
            header('Location: ' . $redirectUrl);
        }
    } else {
        $redirects = [
            INVOICE_TYPES['PURCHASE'] => '../sales.php?q=sale',  // مشتريات
            INVOICE_TYPES['SALES'] => '../sales.php?q=buy',      // مبيعات
            INVOICE_TYPES['PURCHASE_RETURN'] => '../sales.php?q=resale',  // مردود مشتريات
            INVOICE_TYPES['SALES_RETURN'] => '../sales.php?q=rebuy'       // مردود مبيعات
        ];
        $redirect = $redirects[$pro_tybe] ?? '../sales.php';
        error_log('Redirecting to: ' . $redirect);
        error_log('Header: Location: ' . $redirect);
        header("Location: $redirect");
    }
} else {
    error_log('Redirecting with default action');
    // إعادة توجيه افتراضية حسب نوع الفاتورة
    $redirects = [
        INVOICE_TYPES['PURCHASE'] => '../sales.php?q=sale',
        INVOICE_TYPES['SALES'] => '../sales.php?q=buy',
        INVOICE_TYPES['POS'] => '../pos_barcode.php',
        INVOICE_TYPES['PURCHASE_RETURN'] => '../sales.php?q=resale',
        INVOICE_TYPES['SALES_RETURN'] => '../sales.php?q=rebuy'
    ];

    $redirect = $redirects[$pro_tybe] ?? '../sales.php';
    error_log('Redirecting to default: ' . $redirect);
    error_log('Header: Location: ' . $redirect);
    header("Location: $redirect");
}
error_log('Exiting script');
exit;
?>
