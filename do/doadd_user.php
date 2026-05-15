<?php
include('../includes/connect.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../includes/upload_guard.php';

require_admin_or_permission('users.manage', $conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('location:../users.php');
    exit();
}
require_csrf('users_write');

function doadd_user_audit(mysqli $conn, string $eventType, array $options = []): void
{
    try {
        (new SecurityAuditLogger())->record($conn, $eventType, $options);
    } catch (Throwable $exception) {
        error_log('User add audit skipped: ' . $exception->getMessage());
    }
}

$uname = $_POST['uname'];
$password = $_POST['password'];
$hashpass = PasswordService::hashPassword($password);
$userrole = $_POST['userrole'];
$usertype = $userrole;
$is_waiter = isset($_POST['is_waiter']) ? 1 : 0;
$new_kvr_name = '';

if (!empty($_FILES['img']['name']) && (int) ($_FILES['img']['size'] ?? 0) > 0) {
    try {
        $new_kvr_name = posmain_store_image_upload($_FILES['img'], __DIR__ . '/../uploads', 'user', 2000000);
    } catch (Throwable $exception) {
        echo "<h2>" . htmlspecialchars(posmain_safe_exception_message($exception, 'تعذر رفع صورة المستخدم', true), ENT_QUOTES, 'UTF-8') . "</h2>";
        exit();
    }
}

$stmt = $conn->prepare("INSERT INTO users (uname, password, usertype, userrole, img, is_waiter) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssiisi", $uname, $hashpass, $usertype, $userrole, $new_kvr_name, $is_waiter);
$stmt->execute();
$newUserId = (int) $conn->insert_id;
$stmt->close();
$conn->query("INSERT INTO `process`(`type`) VALUES ('add user')");
doadd_user_audit($conn, 'user_created', [
    'target_type' => 'user',
    'target_id' => $newUserId,
    'metadata' => ['username' => $uname, 'is_waiter' => $is_waiter],
]);

header('location:../users.php');
?>
