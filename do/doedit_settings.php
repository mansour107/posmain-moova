<?php 
error_log('[Settings] doedit_settings.php accessed - Method: ' . $_SERVER['REQUEST_METHOD']);

include('../includes/connect.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pos_default_accounts.php';
require_once __DIR__ . '/../includes/pos_operational_store.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Inventory/NegativeStockSalePolicyService.php';
require_once __DIR__ . '/../classes/Sync/SchemaReadinessGuard.php';

require_admin_or_permission('system.tools.run', $conn);

// التحقق من طريقة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}
require_csrf('settings_write');
(new SyncSchemaReadinessGuard())->assertReady($conn);

// التحقق من تسجيل الدخول
if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('location:../index.php');
    exit();
}

// تنظيف وتأمين البيانات المدخلة
$companyname = trim($_POST['companyname'] ?? '');
$companyadd = trim($_POST['companyadd'] ?? '');
$companytel = trim($_POST['companytel'] ?? '');
$edit_pass = trim($_POST['edit_pass'] ?? '');
$lang = trim($_POST['lang'] ?? 'ar');
$showhr = (int)($_POST['showhr'] ?? 0);
$showpulse = (int)($_POST['showpulse'] ?? 0);
$show_customer_visits = (int)($_POST['show_customer_visits'] ?? 0);
$showatt = (int)($_POST['showatt'] ?? 0);
$showclinc = (int)($_POST['showclinc'] ?? 0);
$showrent = (int)($_POST['showrent'] ?? 0);
$bodycolor = trim($_POST['bodycolor'] ?? '#ffffff');
$showpayroll = (int)($_POST['showpayroll'] ?? 0);
$acc_rent = (int)($_POST['acc_rent'] ?? 0);
$def_pos_client = (int)($_POST['def_pos_client'] ?? 0);
$def_pos_store = (int)($_POST['def_pos_store'] ?? 0);
$def_pos_employee = (int)($_POST['def_pos_employee'] ?? 0);
$def_pos_fund = (int)($_POST['def_pos_fund'] ?? 0);
$pos_type = trim($_POST['pos_type'] ?? 'barcode');
$pos_has_password = isset($_POST['pos_has_password']) ? 1 : 0;
$policyService = new NegativeStockSalePolicyService($appConfig ?? []);
$oldNegativeStockPolicy = $policyService->resolve($conn);
$negativeStockSalePolicy = $policyService->normalize($_POST['negative_stock_sale_policy'] ?? $oldNegativeStockPolicy);
if ($negativeStockSalePolicy !== $oldNegativeStockPolicy && !auth_guard_has_permission('inventory.policy.manage', $conn)) {
    http_response_code(403);
    die('INVENTORY_POLICY_PERMISSION_REQUIRED');
}

// التحقق من صحة البيانات المطلوبة
if (empty($companyname)) {
    die("Error: Company name is required");
}

$resolvedPosStore = posmain_resolve_default_account_id($conn, $def_pos_store, 'is_stock = 1');
if ($def_pos_store > 0 && $resolvedPosStore !== $def_pos_store) {
    die('Error: Default POS store must be a valid active stock account');
}
if ($resolvedPosStore > 0) {
    $def_pos_store = $resolvedPosStore;
}

// استخدام prepared statement لتحديث الإعدادات
$sql = "UPDATE settings 
SET company_name = ?, 
    company_add = ?, 
    company_tel = ?, 
    edit_pass = ?, 
    lang = ?, 
    acc_rent = ?, 
    showhr = ?, 
    showpulse = ?,
    show_customer_visits = ?,
    showatt = ?, 
    showpayroll = ?, 
    bodycolor = ?, 
    showrent = ?, 
    showclinc = ?, 
    def_pos_client = ?, 
    def_pos_store = ?, 
    def_pos_employee = ?, 
    def_pos_fund = ?,
    pos_type = ?,
    pos_has_password = ?,
    negative_stock_sale_policy = ?
WHERE 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssiiiiiisiiiiiisis",
    $companyname, $companyadd, $companytel, $edit_pass, $lang,
    $acc_rent, $showhr, $showpulse, $show_customer_visits, $showatt, $showpayroll, $bodycolor,
    $showrent, $showclinc, $def_pos_client, $def_pos_store, 
    $def_pos_employee, $def_pos_fund, $pos_type, $pos_has_password, $negativeStockSalePolicy
);

$conn->begin_transaction();
try {
    $stmt->execute();
    posmain_sync_operational_store_flags($conn);
    (new SecurityAuditLogger())->record($conn, 'settings_updated', [
        'target_type' => 'settings',
        'metadata' => [
            'companyname' => $companyname,
            'lang' => $lang,
            'pos_type' => $pos_type,
            'def_pos_store' => $def_pos_store,
            'negative_stock_sale_policy_old' => $oldNegativeStockPolicy,
            'negative_stock_sale_policy_new' => $negativeStockSalePolicy,
        ],
    ]);
    $conn->commit();
    header('location:../dashboard.php');
} catch (Throwable $settingsException) {
    $conn->rollback();
    echo htmlspecialchars(posmain_safe_exception_message($settingsException, 'حدث خطأ أثناء تحديث الإعدادات', false), ENT_QUOTES, 'UTF-8');
}

$stmt->close();
?>
