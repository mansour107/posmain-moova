<?php
session_start();
if (!isset($_SESSION['login'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

include('includes/connect.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طلب غير صحيح']);
    exit;
}

$new_qty   = $_POST['new_qty']   ?? [];
$new_price = $_POST['new_price'] ?? [];

if (empty($new_qty)) {
    echo json_encode(['success' => false, 'message' => 'لا توجد بيانات للحفظ']);
    exit;
}

$errors = [];
$conn->begin_transaction();

try {
    foreach ($new_qty as $item_id => $qty) {
        $item_id = intval($item_id);
        $qty     = floatval($qty);
        $price   = floatval($new_price[$item_id] ?? 0);

        $stmt = $conn->prepare("UPDATE myitems SET itmqty = ?, cost_price = ? WHERE id = ?");
        $stmt->bind_param('ddi', $qty, $price, $item_id);
        if (!$stmt->execute()) {
            $errors[] = "خطأ في تحديث الصنف رقم $item_id";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'تم الحفظ بنجاح']);
    } else {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
    }
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
