<?php
// edit_user.php
// يفترض أن include('../includes/connect.php') يعرّف $conn (mysqli)

include('../includes/connect.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../includes/upload_guard.php';

require_admin_or_permission('users.manage', $conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../users.php');
    exit();
}
require_csrf('users_write');

function doedit_user_audit(mysqli $conn, string $eventType, array $options = []): void
{
    try {
        (new SecurityAuditLogger())->record($conn, $eventType, $options);
    } catch (Throwable $exception) {
        error_log('User edit audit skipped: ' . $exception->getMessage());
    }
}

// تأكد من أن id رقم صحيح
if (!isset($_GET['id'])) {
    echo "Missing ID";
    exit();
}
$id = (int) $_GET['id'];

// التقاط القيم المرسلة
$uname = $_POST['uname'] ?? '';
$usertype = $_POST['usertype'] ?? '';
$userrole = $_POST['userrole'] ?? '';
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$is_waiter = isset($_POST['is_waiter']) ? 1 : 0;

// التحقق من تطابق كلمات المرور (لو أدخلت)
if ($password !== '' && $password !== $confirm_password) {
    echo "<script>alert('كلمات المرور غير متطابقة'); history.back();</script>";
    exit();
}

// بناء قائمة الحقول التي سيتم تحديثها
$fields = [];
$types = '';      // سلسلة أنواع للـ bind_param
$values = [];     // القيم المرتبطة

// اسم المستخدم
if ($uname !== '') {
    $fields[] = "uname = ?";
    $types .= "s";
    $values[] = $uname;
}

// usertype (اختياري)
if ($usertype !== '') {
    $fields[] = "usertype = ?";
    $types .= "s";
    $values[] = $usertype;
}

// userrole
if ($userrole !== '') {
    $fields[] = "userrole = ?";
    $types .= "s";
    $values[] = $userrole;
}

// كلمة المرور: خزنها باستخدام password_hash مع استمرار دعم تسجيل دخول MD5 القديم.
if ($password !== '') {
    $password_hash = PasswordService::hashPassword($password);
    $fields[] = "password = ?";
    $types .= "s";
    $values[] = $password_hash;
}

// حالة الويتر
$fields[] = "is_waiter = ?";
$types .= "i";
$values[] = $is_waiter;

// التعامل مع رفع الصورة (لو تم رفعها)
if (!empty($_FILES['img']['name'])) {
    try {
        $newName = posmain_store_image_upload($_FILES['img'], __DIR__ . '/../uploads', 'user', 50 * 1024 * 1024);
    } catch (Throwable $exception) {
        echo "<h2>" . htmlspecialchars(posmain_safe_exception_message($exception, 'تعذر رفع صورة المستخدم', true), ENT_QUOTES, 'UTF-8') . "</h2>";
        exit();
    }

    $fields[] = "img = ?";
    $types .= "s";
    $values[] = $newName;
}

// إذا ما فيش حقول للتحديث، رجع
if (count($fields) === 0) {
    header('Location: ../users.php');
    exit();
}

// بناء جملة الاستعلام والـ prepared statement
$set_clause = implode(", ", $fields);
$sql = "UPDATE users SET $set_clause WHERE id = ?";

// أضف الـ id كقيمة أخيرة للـ bind
$types .= "i";
$values[] = $id;

// تحضير البيان
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    $prepareException = new RuntimeException($conn->error ?: 'prepare failed');
    echo htmlspecialchars(posmain_safe_exception_message($prepareException, 'حدث خطأ أثناء تجهيز تحديث المستخدم', false), ENT_QUOTES, 'UTF-8');
    exit();
}

// ربط المعاملات - need references for call_user_func_array
$params = [];
$params[] = & $types;
for ($i = 0; $i < count($values); $i++) {
    $params[] = & $values[$i];
}

// bind dynamically
call_user_func_array([$stmt, 'bind_param'], $params);

// تنفيذ
if ($stmt->execute()) {
    doedit_user_audit($conn, 'user_updated', [
        'target_type' => 'user',
        'target_id' => $id,
        'metadata' => ['username' => $uname, 'is_waiter' => $is_waiter],
    ]);
    $stmt->close();
    header('Location: ../users.php');
    exit();
} else {
    $updateException = new RuntimeException($stmt->error ?: 'update failed');
    echo htmlspecialchars(posmain_safe_exception_message($updateException, 'حدث خطأ أثناء تحديث المستخدم', false), ENT_QUOTES, 'UTF-8');
    $stmt->close();
    exit();
}
?>
