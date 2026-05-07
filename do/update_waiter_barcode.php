<?php
/**
 * تحديث باركود الويتر في قاعدة البيانات
 * Update Waiter Barcode in Database
 */

header('Content-Type: application/json');

// التحقق من طريقة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صحيحة']);
    exit;
}

// قراءة البيانات
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_id']) || !isset($input['barcode'])) {
    echo json_encode(['success' => false, 'message' => 'بيانات ناقصة']);
    exit;
}

$user_id = intval($input['user_id']);
$barcode = trim($input['barcode']);

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => 'الباركود فارغ']);
    exit;
}

// الاتصال بقاعدة البيانات
include('../includes/connect.php');

// التحقق من أن المستخدم ويتر
$check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND is_waiter = 1 AND isdeleted = 0");
$check_stmt->bind_param("i", $user_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'المستخدم غير موجود أو ليس ويتر']);
    exit;
}
$check_stmt->close();

// تشفير الباركود بـ MD5
$hashed_barcode = md5($barcode);

// تحديث الباسورد
$update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$update_stmt->bind_param("si", $hashed_barcode, $user_id);

if ($update_stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'تم تحديث الباركود بنجاح',
        'barcode' => $barcode
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ في التحديث: ' . $conn->error
    ]);
}

$update_stmt->close();
$conn->close();
?>
