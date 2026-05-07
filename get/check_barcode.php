<?php
include '../includes/connect.php'; // تحقق من المسار الصحيح

$barcode = trim($_POST['barcode'] ?? '');

if ($barcode !== '') {
    $stmt = $conn->prepare("SELECT id FROM booking_cards WHERE barcode = ?");
    $stmt->bind_param("s", $barcode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo '<span style="color:red;">❌ الكود مستخدم قبل كدة</span>';
    } else {
        echo '<span style="color:green;">✅ تم التحقق: الكود مش مستخدم</span>';
    }
} else {
    echo '<span style="color:orange;">⚠️ يرجى إدخال كود</span>';
}
