<?php
// edit_user.php
// يفترض أن include('../includes/connect.php') يعرّف $conn (mysqli)

include('../includes/connect.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../includes/upload_guard.php';

require_admin_or_permission('users.manage', $conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../team.php?tab=staff');
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
$displayName = trim((string) ($_POST['display_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$pin = trim((string) ($_POST['pin'] ?? ''));
$clearPin = isset($_POST['clear_pin']);
$lifecycleGuard = new UserLifecycleGuardService();
$actorUserId = function_exists('current_user_id') ? current_user_id() : (int) ($_SESSION['userid'] ?? 0);
$previousRoleId = null;
$previousUserType = null;
$previousIsWaiter = null;
$prevStmt = $conn->prepare(
    'SELECT userrole, usertype, is_waiter FROM users WHERE id = ? LIMIT 1'
);
$prevStmt->bind_param('i', $id);
$prevStmt->execute();
$prevRow = $prevStmt->get_result()->fetch_assoc();
$prevStmt->close();
if ($prevRow) {
    $previousRoleId = (int) ($prevRow['userrole'] ?? 0);
    $previousUserType = (int) ($prevRow['usertype'] ?? 0);
    $previousIsWaiter = (int) ($prevRow['is_waiter'] ?? 0);
}

try {
    $lifecycleGuard->assertDisplayNameUnique($conn, $displayName, $id);
    $lifecycleGuard->assertNoPrivilegeEscalation(
        $conn,
        $actorUserId,
        $id,
        $userrole !== '' ? (int) $userrole : null
    );
} catch (RuntimeException $exception) {
    $message = UserLifecycleGuardService::privilegeEscalationMessage($exception->getMessage());
    echo "<script>alert('" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "'); history.back();</script>";
    exit();
}
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

if ($displayName !== '') {
    $fields[] = 'display_name = ?';
    $types .= 's';
    $values[] = $displayName;
}

$fields[] = 'phone = ?';
$types .= 's';
$values[] = $phone;

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
    header('Location: ../team.php?tab=staff');
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
    $pinService = new PinService();
    if ($clearPin) {
        $pinService->clearPinForUser($conn, $id);
    } elseif ($pin !== '') {
        try {
            $pinService->setPinForUser($conn, $id, $pin);
        } catch (Throwable $pinException) {
            echo htmlspecialchars($pinException->getMessage(), ENT_QUOTES, 'UTF-8');
            $stmt->close();
            exit();
        }
    }
    doedit_user_audit($conn, 'user_updated', [
        'target_type' => 'user',
        'target_id' => $id,
        'metadata' => ['username' => $uname, 'is_waiter' => $is_waiter],
    ]);

    if ($userrole !== '' && $previousRoleId !== null && (int) $userrole !== $previousRoleId) {
        if (!class_exists('UserPermissionGrantService', false)) {
            require_once __DIR__ . '/../classes/Security/UserPermissionGrantService.php';
        }
        $grantService = new UserPermissionGrantService();
        if ($grantService->tableExists($conn)) {
            $clearGrants = $conn->prepare('DELETE FROM user_permission_grants WHERE user_id = ?');
            $clearGrants->bind_param('i', $id);
            $clearGrants->execute();
            $clearGrants->close();
        }
        $modeStmt = $conn->prepare("UPDATE users SET permission_mode = 'role_only' WHERE id = ?");
        $modeStmt->bind_param('i', $id);
        $modeStmt->execute();
        $modeStmt->close();
        auth_guard_invalidate_capabilities_cache();
        $grantService->invalidateSessionCapabilities();
        doedit_user_audit($conn, 'user_role_changed', [
            'target_type' => 'user',
            'target_id' => $id,
            'metadata' => ['previous_role_id' => $previousRoleId, 'new_role_id' => (int) $userrole],
        ]);
        require_once __DIR__ . '/../classes/Security/PermissionService.php';
        (new PermissionService($conn))->bumpPermissionsVersion();
    }

    $securityIdentityChanged = (
        $userrole !== ''
        && $previousRoleId !== null
        && (int) $userrole !== $previousRoleId
    ) || (
        $usertype !== ''
        && $previousUserType !== null
        && (int) $usertype !== $previousUserType
    ) || (
        $previousIsWaiter !== null
        && $is_waiter !== $previousIsWaiter
    );
    if ($securityIdentityChanged && $pin === '' && !$clearPin) {
        $pinService->bumpAuthVersion($conn, $id);
    }

    $stmt->close();
    header('Location: ../team.php?tab=staff');
    exit();
} else {
    $updateException = new RuntimeException($stmt->error ?: 'update failed');
    echo htmlspecialchars(posmain_safe_exception_message($updateException, 'حدث خطأ أثناء تحديث المستخدم', false), ENT_QUOTES, 'UTF-8');
    $stmt->close();
    exit();
}
?>
