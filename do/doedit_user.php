<?php
// edit_user.php
// يفترض أن include('../includes/connect.php') يعرّف $conn (mysqli)

include('../includes/connect.php');

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

// كلمة المرور: خزنها كـ MD5 فقط (حسب طلبك)
if ($password !== '') {
    $md5_pass = md5($password);
    $fields[] = "password = ?";
    $types .= "s";
    $values[] = $md5_pass;
}

// حالة الويتر
$fields[] = "is_waiter = ?";
$types .= "i";
$values[] = $is_waiter;

// التعامل مع رفع الصورة (لو تم رفعها)
if (!empty($_FILES['img']['name'])) {
    $fileName = $_FILES['img']['name'];
    $fileTmp  = $_FILES['img']['tmp_name'];
    $fileSize = $_FILES['img']['size'];

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($ext, $allowed)) {
        echo "<h2>امتداد الملف غير مسموح به</h2>";
        exit();
    }

    if ($fileSize > 50 * 1024 * 1024) { // 50MB
        echo "<h2>حجم الملف أكبر من المسموح (50 ميجا)</h2>";
        exit();
    }

    $baseName = pathinfo($fileName, PATHINFO_FILENAME);
    $newName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName) . '_' . rand(1000,999999) . '.' . $ext;
    $target = __DIR__ . "/../uploads/" . $newName;

    if (!move_uploaded_file($fileTmp, $target)) {
        echo "<h2>فشل في رفع الملف</h2>";
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
    echo "خطأ في تحضير الاستعلام: " . $conn->error;
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
    $stmt->close();
    header('Location: ../users.php');
    exit();
} else {
    echo "خطأ أثناء التحديث: " . $stmt->error;
    $stmt->close();
    exit();
}
?>
