<?php
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method');
}

require_login();
require_permission('delivery.zones.manage', $conn);
require_csrf('delivery_zones_write');

$action = trim((string) ($_POST['action'] ?? 'save'));
$id = (int) ($_POST['id'] ?? 0);
$tenant = max(0, (int) ($_SESSION['pos_tenant'] ?? 0));
$branch = max(0, (int) ($_SESSION['pos_branch'] ?? 0));

if ($action === 'delete') {
    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE delivery_zones SET is_active = 0 WHERE id = ? AND tenant = ? AND branch = ?');
        if ($stmt) {
            $stmt->bind_param('iii', $id, $tenant, $branch);
            $stmt->execute();
            $stmt->close();
        }
    }
    $_SESSION['success_message'] = 'تم تعطيل منطقة التوصيل';
    header('Location: ../delivery_zones.php');
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$fee = (float) ($_POST['fee'] ?? 0);
$sortOrder = (int) ($_POST['sort_order'] ?? 0);
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($name === '') {
    die('اسم المنطقة مطلوب');
}

if ($id > 0) {
    $stmt = $conn->prepare('UPDATE delivery_zones SET name = ?, fee = ?, sort_order = ?, is_active = ? WHERE id = ? AND tenant = ? AND branch = ?');
    if (!$stmt) {
        die('فشل تحديث المنطقة');
    }
    $stmt->bind_param('sdiiiii', $name, $fee, $sortOrder, $isActive, $id, $tenant, $branch);
    $stmt->execute();
    $stmt->close();
    $_SESSION['success_message'] = 'تم تحديث منطقة التوصيل';
} else {
    $stmt = $conn->prepare('INSERT INTO delivery_zones (name, fee, sort_order, is_active, tenant, branch) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        die('فشل إضافة المنطقة');
    }
    $stmt->bind_param('sdiiii', $name, $fee, $sortOrder, $isActive, $tenant, $branch);
    $stmt->execute();
    $stmt->close();
    $_SESSION['success_message'] = 'تم إضافة منطقة التوصيل';
}

header('Location: ../delivery_zones.php');
exit;
