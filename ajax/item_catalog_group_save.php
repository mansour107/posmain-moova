<?php

require_once __DIR__ . '/../includes/connect.php';

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

$canCreate = !empty($role['add_items']) || !empty($role['add_item_groups']);
if (!$canCreate) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'ليس لديك صلاحية إضافة تصنيفات',
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

$type = strtolower(trim((string) ($payload['type'] ?? '')));
$name = trim((string) ($payload['name'] ?? ''));

if (!in_array($type, ['group1', 'group2'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'TYPE_INVALID',
        'message' => 'نوع التصنيف غير صحيح',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($name === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'NAME_REQUIRED',
        'message' => 'اكتب الاسم أولاً',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($name) > 100) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'NAME_TOO_LONG',
        'message' => 'الاسم طويل جداً',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$table = $type === 'group1' ? 'item_group' : 'item_group2';
$label = $type === 'group1' ? 'التصنيف' : 'المجموعة الفرعية';

$stmt = $conn->prepare("SELECT id FROM {$table} WHERE gname = ? AND isdeleted = 0 LIMIT 1");
$stmt->bind_param('s', $name);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    echo json_encode([
        'success' => true,
        'id' => (int) $existing['id'],
        'name' => $name,
        'type' => $type,
        'existing' => true,
        'message' => $label . ' موجود مسبقاً وتم اختياره',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare("INSERT INTO {$table} (gname) VALUES (?)");
$stmt->bind_param('s', $name);
$stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($newId < 1) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code' => 'CREATE_FAILED',
        'message' => 'تعذر إنشاء ' . $label,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($type === 'group1') {
    require_once __DIR__ . '/../classes/Sync/OperationalSyncRecorder.php';
    posmain_record_operational_row_sync($conn, 'item_category', $newId, 'item_catalog_group_picker');
    $conn->query("INSERT INTO `process`(`type`) VALUES ('add group')");
} else {
    $conn->query("INSERT INTO `process`(`type`) VALUES ('add group2')");
}

echo json_encode([
    'success' => true,
    'id' => $newId,
    'name' => $name,
    'type' => $type,
    'existing' => false,
    'message' => 'تم إنشاء ' . $label . ' بنجاح',
], JSON_UNESCAPED_UNICODE);
