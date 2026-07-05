<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/item_catalog_unit_save.php');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'طريقة الطلب غير صحيحة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['login']) || (int) ($_SESSION['userid'] ?? 0) < 1) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'code' => 'UNAUTHORIZED',
        'message' => 'يجب تسجيل الدخول',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$canCreate = !empty($role['add_items']);
if (!$canCreate) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'ليس لديك صلاحية إضافة وحدات',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = [];
if ($raw !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (!$payload) {
    $payload = $_POST;
}

$name = trim((string) ($payload['name'] ?? ''));

if ($name === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'NAME_REQUIRED',
        'message' => 'اكتب اسم الوحدة أولاً',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($name) < 2) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'NAME_TOO_SHORT',
        'message' => 'اسم الوحدة يجب أن يكون حرفين على الأقل',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($name) > 60) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'NAME_TOO_LONG',
        'message' => 'اسم الوحدة طويل جداً',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare('SELECT id FROM myunits WHERE uname = ? AND COALESCE(isdeleted, 0) = 0 LIMIT 1');
$stmt->bind_param('s', $name);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    echo json_encode([
        'success' => true,
        'id' => (int) $existing['id'],
        'name' => $name,
        'existing' => true,
        'message' => 'الوحدة موجودة مسبقاً وتم اختيارها',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare('INSERT INTO myunits (uname) VALUES (?)');
$stmt->bind_param('s', $name);
$stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($newId < 1) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code' => 'CREATE_FAILED',
        'message' => 'تعذر إنشاء الوحدة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conn->query("INSERT INTO `process`(`type`) VALUES ('add unit')");

echo json_encode([
    'success' => true,
    'id' => $newId,
    'name' => $name,
    'existing' => false,
    'message' => 'تم إنشاء الوحدة بنجاح',
], JSON_UNESCAPED_UNICODE);
