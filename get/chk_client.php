<?php
include '../includes/connect.php'; // تأكد من مسار الاتصال الصحيح

$cname = trim($_POST['cname'] ?? '');

if ($cname !== '') {
    $stmt = $conn->prepare("SELECT id FROM clients WHERE name = ?");
    $stmt->bind_param("s", $cname);
    $stmt->execute();
    $result = $stmt->get_result();

    echo ($result->num_rows > 0) 
        ? "✅ العميل موجود "
        : "❌ العميل مش موجود، بمجرد الحجز هضيفه في قايمة العملاء";
} else {
    echo "⚠️ يرجى إدخال اسم العميل";
}
