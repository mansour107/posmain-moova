<?php 
include('../includes/connect.php');

// التحقق من صحة البيانات المدخلة
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}

// تنظيف وتأمين البيانات المدخلة
$code = trim($_POST['code'] ?? '');
$aname = trim($_POST['aname'] ?? '');
$is_basic = (int)($_POST['is_basic'] ?? 0);
$parent_id = (int)($_POST['parent_id'] ?? 0);
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$q = (int)($_POST['q'] ?? 0);

// التحقق من صحة البيانات المطلوبة
if (empty($code) || empty($aname)) {
    die("Error: Required fields are missing");
}

// التحقق من وجود حساب بنفس الاسم باستخدام prepared statement
$check_stmt = $conn->prepare("SELECT aname FROM acc_head WHERE aname = ?");
$check_stmt->bind_param("s", $aname);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows > 0) {
    echo "<center><h1> في حساب بنفس الاسم في قاعدة البيانات , ممكن تغير الاسم او تميزة </h1></center>";
    die;
}

$kind = '';
if ($parent_id != 0) {
    $parent_stmt = $conn->prepare("SELECT kind FROM acc_head WHERE id = ?");
    $parent_stmt->bind_param("i", $parent_id);
    $parent_stmt->execute();
    $parent_result = $parent_stmt->get_result();
    if ($parent_row = $parent_result->fetch_assoc()) {
        $kind = $parent_row['kind'];
    }
}

if (isset($_POST['is_stock'])) {
    $is_stock = 1;    
}else {
    $is_stock = 0;
};



if (isset($_POST['secret'])) {
    $secret = 1;    
}else {
    $secret = 0;
}



if (isset($_POST['rentable'])) {
    $rentable = 1;    
}else {
    $rentable = 0;
}


if (isset($_POST['is_fund'])) {
    $is_fund = 1;    
}else {
    $is_fund = 0;
}


// إدراج الحساب الجديد باستخدام prepared statement
$insert_stmt = $conn->prepare("INSERT INTO acc_head (code, aname, is_basic, rentable, is_fund, parent_id, is_stock, secret, kind, phone, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$insert_stmt->bind_param("ssiiiiiiiss", $code, $aname, $is_basic, $rentable, $is_fund, $parent_id, $is_stock, $secret, $kind, $phone, $address);

if ($insert_stmt->execute()) {
    // تسجيل العملية في الجدول القديم
    $process_stmt = $conn->prepare("INSERT INTO `process`(`type`) VALUES (?)");
    $process_type = "add account >> " . $aname;
    $process_stmt->bind_param("s", $process_type);
    $process_stmt->execute();
    
    // تسجيل في نظام Logging الجديد
    if (isset($logger)) {
        $logger->logSystem('accounts', 'add_account', [
            'account_code' => $code,
            'account_name' => $aname,
            'parent_id' => $parent_id,
            'is_basic' => $is_basic,
            'is_stock' => $is_stock,
            'is_fund' => $is_fund,
            'rentable' => $rentable,
            'secret' => $secret
        ]);
        
        // تسجيل مالي إذا كان حساب مالي
        if ($is_fund || $is_stock) {
            $logger->logFinancial('add_account', 0, null, null, [
                'account_code' => $code,
                'account_name' => $aname,
                'account_type' => $is_fund ? 'fund' : 'stock'
            ]);
        }
        
        // تسجيل إضافة حساب جديد
        if (isset($logger)) {
            $logger->logSystem('accounts', 'account_added', [
                'account_name' => $aname,
                'account_code' => $code,
                'account_type' => $is_fund ? 'fund' : ($is_stock ? 'stock' : 'regular')
            ]);
        }
    }
    
    header("location:../add_account.php?parent_id=" . $q);
} else {
    // تسجيل الخطأ
    if (isset($logger)) {
        $logger->logError("Failed to add account: " . $conn->error, __FILE__, __LINE__, [
            'account_code' => $code,
            'account_name' => $aname
        ]);
    }
    echo "Error: " . $conn->error;
}

?>