<?php
include('../includes/connect.php');

header('Content-Type: application/json');

$code = intval($_GET['code'] ?? 0);
if (!$code) {
    echo json_encode(['success' => false, 'message' => 'كود مفقود']);
    exit;
}

// استخدام prepared statement لتفادي SQL injection
$stmt = $conn->prepare("SELECT * FROM booking_cards WHERE id = ?");
$stmt->bind_param("i", $code);
$stmt->execute();
$result = $stmt->get_result();
$r = $result->fetch_assoc();

if (!$r) {
    echo json_encode(['success' => false, 'message' => 'كود غير صحيح']);
    exit;
}

if ($r['remain'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'لا توجد وحدات']);
    exit;
}

// تقليل الحصة
$update = $conn->prepare("UPDATE booking_cards SET remain = remain - 1 WHERE id = ?");
$update->bind_param("i", $code);
$update->execute();

// حفظ الملاحظات
$n1 = $r['client'];
$n2 = $r['barcode'];
$n3 = $r['remain']; // القيمة قبل النقصان

$insert = $conn->prepare("INSERT INTO notes (n1, n2, n3) VALUES (?, ?, ?)");
$insert->bind_param("ssi", $n1, $n2, $n3);
$insert->execute();

// طباعة النتيجة الجديدة
echo json_encode([
    'success' => true,
    'remain' => $r['remain'] - 1
]);
