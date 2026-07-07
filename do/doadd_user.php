<?php
include('../includes/connect.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';
require_once __DIR__ . '/../includes/upload_guard.php';

require_admin_or_permission('users.manage', $conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('location:../team.php?tab=staff');
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

$uname = trim((string) ($_POST['uname'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$displayName = trim((string) ($_POST['display_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$pin = trim((string) ($_POST['pin'] ?? ''));
$generatePin = !empty($_POST['generate_pin']);
$userrole = $_POST['userrole'];
$usertype = $userrole;
$is_waiter = isset($_POST['is_waiter']) ? 1 : 0;
$new_kvr_name = '';
$lifecycleGuard = new UserLifecycleGuardService();
$actorUserId = function_exists('current_user_id') ? current_user_id() : (int) ($_SESSION['userid'] ?? 0);

try {
    $lifecycleGuard->assertDisplayNameUnique($conn, $displayName);
    $lifecycleGuard->assertNoPrivilegeEscalation($conn, $actorUserId, null, (int) $userrole);
} catch (RuntimeException $exception) {
    $message = UserLifecycleGuardService::privilegeEscalationMessage($exception->getMessage());
    echo '<h2>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</h2>';
    exit();
}

$hashpass = PasswordService::hashPassword($password);

if (!empty($_FILES['img']['name']) && (int) ($_FILES['img']['size'] ?? 0) > 0) {
    try {
        $new_kvr_name = posmain_store_image_upload($_FILES['img'], __DIR__ . '/../uploads', 'user', 2000000);
    } catch (Throwable $exception) {
        echo "<h2>" . htmlspecialchars(posmain_safe_exception_message($exception, 'تعذر رفع صورة المستخدم', true), ENT_QUOTES, 'UTF-8') . "</h2>";
        exit();
    }
}

$stmt = $conn->prepare("INSERT INTO users (uname, password, usertype, userrole, img, is_waiter, display_name, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssiisiss", $uname, $hashpass, $usertype, $userrole, $new_kvr_name, $is_waiter, $displayName, $phone);
$stmt->execute();
$newUserId = (int) $conn->insert_id;
$stmt->close();

if ($newUserId < 1) {
    echo '<h2>' . htmlspecialchars('تعذر إنشاء المستخدم', ENT_QUOTES, 'UTF-8') . '</h2>';
    exit();
}

$pinService = new PinService();
if ($pin === '') {
    for ($i = 0; $i < 30; $i++) {
        $pin = (string) random_int(1000, 9999);
        try {
            posmain_pin_secret();
            $pinService->validatePinFormat($pin);
            if (!$pinService->findUserByPin($conn, $pin)) {
                $generatePin = true;
                break;
            }
        } catch (Throwable) {
            continue;
        }
    }
}
if ($pin === '') {
    $conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $newUserId);
    echo '<h2>' . htmlspecialchars('PIN_REQUIRED', ENT_QUOTES, 'UTF-8') . '</h2>';
    exit();
}
try {
    $pinService->setPinForUser($conn, $newUserId, $pin);
    $_SESSION['posmain_one_time_pin_reveal'] = [
        'user_id' => $newUserId,
        'pin' => $pin,
        'expires' => time() + 120,
    ];
} catch (Throwable $pinException) {
    $conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $newUserId);
    echo '<h2>' . htmlspecialchars($pinException->getMessage(), ENT_QUOTES, 'UTF-8') . '</h2>';
    exit();
}
$conn->query("INSERT INTO `process`(`type`) VALUES ('add user')");
doadd_user_audit($conn, 'user_created', [
    'target_type' => 'user',
    'target_id' => $newUserId,
    'metadata' => ['username' => $uname, 'is_waiter' => $is_waiter],
]);

(new PermissionService($conn))->bumpPermissionsVersion();

header('location:../team.php?tab=staff' . ($generatePin ? '&pin_reveal=1' : ''));
?>
