<?php
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صحيحة');
    }

    $table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;

    if ($table_id <= 0) {
        throw new Exception('رقم الطاولة غير صحيح');
    }

    // هنا يمكنك إضافة كود تشغيل الطاولة في قاعدة البيانات
    // مثال:
    // $pdo = new PDO("mysql:host=localhost;dbname=pos", $username, $password);
    // $stmt = $pdo->prepare("UPDATE tables SET status = 'active' WHERE id = ?");
    // $stmt->execute([$table_id]);

    echo json_encode([
        'success' => true,
        'message' => 'تم تشغيل الطاولة بنجاح',
        'table_id' => $table_id
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>